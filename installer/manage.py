"""Guided management of an existing native installation from the verified bundle."""
import fcntl
import getpass
import hashlib
import json
import os
from pathlib import Path
import pwd
import re
import shutil
import stat
import subprocess
import urllib.parse
import zipfile
from runtime_env import parse_environment_text, RuntimeEnvironmentError


class ManagementError(Exception):
    pass


def protected(path, public=False):
    path = Path(path)
    info = path.lstat()
    if path.is_symlink() or not stat.S_ISREG(info.st_mode) or info.st_nlink != 1 or info.st_uid != 0 or info.st_mode & (0o022 if public else 0o027):
        raise ManagementError('The installation configuration is not a protected, root-owned regular file. It was not used.')
    for parent in path.parents:
        metadata = parent.lstat()
        if parent.is_symlink() or metadata.st_uid != 0 or metadata.st_mode & 0o022:
            raise ManagementError('The selected installation has a linked configuration parent. It was not used.')
    return path


def read_environment(path):
    try:
        return parse_environment_text(protected(path).read_text())
    except RuntimeEnvironmentError as error:
        raise ManagementError(str(error)) from None


class Manager:
    def __init__(self, installer, configuration):
        self.installer = installer
        self.error = ManagementError
        self.root = Path(configuration).parent
        self.original_configuration = Path(configuration)
        self.configuration = self.original_configuration
        pointer = self.root / 'active-installation.json'
        if pointer.exists():
            selected = json.loads(protected(pointer).read_text())
            product = selected.get('product', '')
            if not re.fullmatch(r'emailer-[a-z]+', product):
                raise ManagementError('The active installation edition is invalid.')
            candidates = [Path('/etc') / (self.root.name + '-' + product.removeprefix('emailer-') + '-' + suffix) / 'runtime.env' for suffix in ('110', '120')]
            expected = Path(selected.get('runtime', ''))
            if selected.get('format') != 'emailer-active-installation-v1' or expected not in candidates:
                raise ManagementError('The active installation pointer is invalid. It was preserved.')
            self.configuration = expected
        self.values = read_environment(self.configuration)
        self.releases = Path(self.values['EMAILER_RELEASES_DIR'])
        self.private = Path(self.values['EMAILER_PRIVATE_DIR'])
        self.current = (self.releases / 'current').resolve(strict=True)
        if self.current.parent != self.releases.resolve() or self.releases.is_symlink():
            raise ManagementError('The current application is outside its recorded releases folder.')
        lock_path = self.private / 'install.lock'
        lock_stat = lock_path.lstat()
        if not stat.S_ISREG(lock_stat.st_mode) or lock_stat.st_nlink != 1 or lock_stat.st_mode & 0o077:
            raise ManagementError('The installation completion record is missing or unsafe. Resume its original setup first.')
        identity = json.loads(lock_path.read_text())
        self.product = identity.get('product_id')
        if self.product is None:
            self.product = 'emailer-light'
        if self.product != 'emailer-light':
            raise ManagementError('Use the official installation package for the installed edition.')
        self.source_version = self.current.name.removeprefix('ripe-')
        self.prefix = self.root.name
        if not re.fullmatch(r'emailer(?:-light)?', self.prefix):
            raise ManagementError('This hosting profile requires the standard protected Emailer settings folder.')
        self.directory = self.root / 'installer' / installer.product / installer.version
        self.directory.mkdir(mode=0o700, parents=True, exist_ok=True)
        info = self.directory.lstat()
        if not stat.S_ISDIR(info.st_mode) or info.st_uid != 0 or info.st_mode & 0o077 or self.directory.is_symlink():
            raise ManagementError('The installer recovery folder is unsafe.')
        self.record_file = self.directory / 'management.json'
        self.record = json.loads(protected(self.record_file).read_text()) if self.record_file.exists() else {}
        self.record.setdefault('source_configuration', str(self.configuration))
        self.original_configuration = protected(self.record['source_configuration'])
        self.source_values = read_environment(self.original_configuration)
        self.source_service_prefix = self.original_configuration.parent.name
        self.save()
        self.account = pwd.getpwuid(Path(self.source_values['EMAILER_SECRET_KEY_FILE']).stat().st_uid)
        if self.account.pw_uid == 0:
            raise ManagementError('The application key belongs to root. A non-root application service identity is required.')
        self.active_units = []
        self.bootstrap = None
        self.package = None
        self.environment = None

    def save(self):
        self.installer.durable(self.record_file, json.dumps(self.record, indent=2) + '\n')

    def call(self, arguments, *, environment=None, data=None):
        result = subprocess.run(arguments, input=data, text=True, env={**(os.environ if environment is None else environment), 'EMAILER_INSTALLER_DIAGNOSTICS': '1'}, capture_output=True)
        if result.returncode:
            step = arguments[2] if arguments[0].endswith('php8.4') and len(arguments) > 2 else Path(arguments[0]).name
            diagnostic = {'step': step, 'exit_code': result.returncode}
            try:
                report = json.loads(result.stdout)
                blockers = report.get('runtime_blockers', [])
                if isinstance(blockers, list) and all(isinstance(code, str) and re.fullmatch(r'[a-z0-9_]+', code) for code in blockers):
                    diagnostic['runtime_blockers'] = blockers
            except (ValueError, AttributeError):
                report = {}
            guidance = {
                'database_endpoint': 'The application and maintenance sign-ins could not be proved to use the same database server and data directory. No update or account fencing was started. Keep the configured socket/port and provide administrator access to that exact server; do not substitute the shared server.',
                'finish_required': 'The application update committed. Choose Finish (or Accept for a Light to Ripe upgrade); recovery cannot undo a completed operation.',
                'completed_operation': 'This operation already completed. Choose Check the installation. Recovery cannot downgrade it.',
                'delivery_busy': 'Delivery still has active work. Let it finish; no delivery claim was reset. If an operation was recorded, choose Recover before retrying.',
                'operation_busy': 'Another operation already owns this installation. Let it finish, then start this package again.',
                'recovery_required': 'An interrupted operation requires recovery. Start this same package and choose Recover before trying the update again.',
                'schema_mismatch': 'The database does not match the supported schema or its saved recovery proof. Keep delivery held and retain the recovery material. Use the schema mismatch troubleshooting entry; do not edit migration records.',
                'package_mismatch': 'A release package, installed file or saved identity failed verification. Keep the recovery material and use the original official package. Do not replace signing keys or overwrite application files.',
                'source_unsupported': 'This installation does not match a supported source release. No unsupported conversion is allowed. Check the supported version combinations in the upgrade guide.',
                'verification_failed': 'An installation safety check failed. Keep delivery held and retain the recovery material. Read the redacted diagnostic category and the verification troubleshooting entry before choosing Recover, Finish or Accept.',
            }
            if not report:
                for line in reversed(result.stdout.splitlines()):
                    try:
                        candidate = json.loads(line)
                    except ValueError:
                        continue
                    if isinstance(candidate, dict) and candidate.get('installer_error') in guidance:
                        report = candidate
                        break
            code = report.get('installer_error', 'verification_failed')
            if code not in guidance:
                code = 'verification_failed'
            diagnostic['error_code'] = code
            if diagnostic.get('runtime_blockers'):
                message = 'Application checks need attention: ' + ', '.join(diagnostic['runtime_blockers']) + '. Open Doctor for the matching repair steps; delivery remains blocked.'
            else:
                message = guidance[code]
            self.installer.durable(self.directory / 'last-error.json', json.dumps(diagnostic) + '\n')
            raise ManagementError(message + ' Redacted diagnostics: ' + str(self.directory / 'last-error.json'))
        return result.stdout

    def stage(self):
        host = self.installer
        version = host.version
        package_dir = self.directory / 'packages' / host.product / version
        package_dir.mkdir(mode=0o700, parents=True, exist_ok=True)
        for name, digest in host.meta['files'].items():
            destination = package_dir / name
            if destination.exists():
                protected(destination, public=True)
                if hashlib.sha256(destination.read_bytes()).hexdigest() != digest:
                    raise ManagementError('The retained setup package differs from this download. Keep its recovery material and use the original package.')
            else:
                self.installer.durable(destination, (host.bundle / name).read_bytes(), 0o400)
        self.package = package_dir / host.meta['archive']
        self.call(['/usr/bin/php8.4', str(package_dir / 'verify-package.php'), '--archive=' + str(self.package), '--public-key=' + str(package_dir / 'release.pub'), '--current-version=' + version, '--expected-version=' + version, '--expected-source=' + host.meta['source_commit']])
        self.bootstrap = package_dir / 'runtime'
        manifest = json.loads((package_dir / (host.meta['archive'] + '.manifest.json')).read_text())
        if not self.bootstrap.exists():
            staging = package_dir / '.runtime-staging'
            if staging.exists():
                if self.record.get('staging_runtime') != str(staging) or self.record.get('staging_package') != host.meta['files'][host.meta['archive']]:
                    raise ManagementError('An unrecorded staging folder was preserved. Use the troubleshooting reference for retained package evidence.')
                metadata = staging.lstat()
                if staging.is_symlink() or not stat.S_ISDIR(metadata.st_mode) or metadata.st_uid != 0:
                    raise ManagementError('The interrupted staging root is unsafe. It was preserved.')
                for directory, directories, files in os.walk(staging):
                    for path in [Path(directory), *(Path(directory) / name for name in directories + files)]:
                        info = path.lstat()
                        if path.is_symlink() or info.st_uid != 0 or not (stat.S_ISDIR(info.st_mode) or stat.S_ISREG(info.st_mode)):
                            raise ManagementError('The interrupted staging folder has unsafe ownership or entries. It was preserved.')
                shutil.rmtree(staging)
            self.record['staging_runtime'] = str(staging)
            self.record['staging_package'] = host.meta['files'][host.meta['archive']]
            self.save()
            staging.mkdir(mode=0o700)
            with zipfile.ZipFile(self.package) as archive:
                archive.extractall(staging)
            for directory, _, files in os.walk(staging):
                for name in files:
                    os.chmod(Path(directory) / name, 0o444)
                os.chmod(directory, 0o555)
            os.rename(staging, self.bootstrap)
            descriptor = os.open(package_dir, os.O_RDONLY | os.O_DIRECTORY)
            try:
                os.fsync(descriptor)
            finally:
                os.close(descriptor)
            self.record.pop('staging_runtime', None)
            self.record.pop('staging_package', None)
            self.save()
        observed = []
        for directory, directories, files in os.walk(self.bootstrap):
            for name in directories + files:
                entry = Path(directory) / name
                if entry.is_symlink() or entry.stat().st_uid != 0 or entry.stat().st_mode & 0o222:
                    raise ManagementError('Retained installer runtime ownership is unsafe.')
            for name in files:
                path = Path(directory) / name
                relative = str(path.relative_to(self.bootstrap))
                if hashlib.sha256(path.read_bytes()).hexdigest() != manifest['files'].get(relative):
                    raise ManagementError('Retained installer runtime verification failed.')
                observed.append(relative)
        if set(observed) != set(manifest['files']):
            raise ManagementError('Retained installer runtime inventory is incomplete.')

    def maintenance_environment(self):
        params = urllib.parse.urlsplit(self.values['DATABASE_URL'])
        database = urllib.parse.unquote(params.path.removeprefix('/'))
        if not re.fullmatch(r'[A-Za-z0-9_]+', database):
            raise ManagementError('The recorded database name is outside the supported hosting profile.')
        query = urllib.parse.parse_qs(params.query)
        socket_path = Path(query.get('unix_socket', ['/run/mysqld/mysqld.sock'])[0])
        if params.hostname in ('127.0.0.1', 'localhost', '::1') and params.port in (None, 3306) and socket_path.is_socket():
            dsn = 'mysql://root@localhost/' + database + '?' + urllib.parse.urlencode({'unix_socket': str(socket_path), 'charset': 'utf8mb4'}, safe='/')
        else:
            dsn = ''
        runtime_dir = self.directory / 'console' / self.installer.product / self.installer.version / self.source_version
        runtime_dir.mkdir(mode=0o700, parents=True, exist_ok=True)
        pin = Path(self.source_values['EMAILER_RELEASE_PUBLIC_KEY_FILE'])
        if self.product == self.installer.product and protected(pin, public=True).read_text().strip() != (self.package.parent / 'release.pub').read_text().strip():
            raise ManagementError('The installed signing identity differs from the official setup package. It was not replaced.')
        values = {**self.source_values, 'EMAILER_INSTALLER_SOURCE_SERVICE_PREFIX': self.source_service_prefix, 'EMAILER_RUNTIME_CONFIGURATION_FILE': str(self.original_configuration), 'EMAILER_RUNTIME_DIR': str(runtime_dir),
                  'EMAILER_RELEASE_PUBLIC_KEY_FILE': str(pin), 'EMAILER_UPDATE_SOURCE_VERSION': self.source_version}
        if self.record.get('maintenance_credentials'):
            dsn = protected(self.directory / 'maintenance-url').read_text().strip()
        def environment(url):
            return {'PATH': '/usr/bin:/bin', 'LANG': 'C.UTF-8', **values, 'DATABASE_URL': url}
        check = ['/usr/bin/php8.4', str(self.bootstrap / 'bin/console'), 'dbal:run-sql', 'SELECT 1', '--no-interaction']
        if not dsn or subprocess.run(check, env=environment(dsn), capture_output=True).returncode:
            print('The database needs separate permission for a safe backup and update. Your application password is kept unchanged.')
            username = input('Database administrator name: ').strip()
            password = getpass.getpass('Database administrator password (hidden): ')
            hostname = params.hostname or 'localhost'
            hostname = '[' + hostname + ']' if ':' in hostname else hostname
            dsn = 'mysql://' + urllib.parse.quote(username, safe='') + ':' + urllib.parse.quote(password, safe='') + '@' + hostname + ':' + str(params.port or 3306) + '/' + database + '?' + urllib.parse.urlencode({'charset': 'utf8mb4', **({'unix_socket': query['unix_socket'][0]} if 'unix_socket' in query else {})}, safe='/')
            self.call(check, environment=environment(dsn))
            self.installer.durable(self.directory / 'maintenance-url', dsn + '\n')
            self.record['maintenance_credentials'] = True
            self.save()
        self.environment = environment(dsn)
        endpoint_input = {'action': 'verify-endpoint', 'bootstrap': str(self.bootstrap), 'maintenance_url': dsn, 'source_url': self.source_values['DATABASE_URL']}
        if self.record.get('database_endpoint'):
            endpoint_input['expected_endpoint'] = self.record['database_endpoint']
        proof = self.call(['/usr/bin/php8.4', str(self.package.parent / 'host-database.php')], data=json.dumps(endpoint_input))
        self.record['database_endpoint'] = json.loads(proof)
        self.save()
        server = self.console(['dbal:run-sql', 'SELECT VERSION()', '--no-interaction'])
        if not re.search(r'\b(?:10\.11|11\.8)\.\d+[-\w ]*MariaDB', server):
            raise ManagementError('This database server is outside the tested MariaDB 10.11 and 11.8 versions. It was not upgraded or replaced.')
        return database

    def console(self, arguments, *, data=None):
        return self.call(['/usr/bin/php8.4', str(self.bootstrap / 'bin/console'), *arguments], environment=self.environment, data=data)

    def stop_services(self):
        units = []
        for suffix in ('scheduler.timer', 'operations.timer', 'scheduler.service', 'operations.service', 'worker.service', 'fpm.service'):
            unit = self.source_service_prefix + '-' + suffix
            text = subprocess.run(['systemctl', 'show', unit, '--property=LoadState', '--property=ExecStart', '--property=EnvironmentFiles', '--property=WorkingDirectory'], capture_output=True, text=True).stdout
            if 'LoadState=not-found' in text:
                continue
            if suffix.endswith('.timer'):
                service = unit.removesuffix('.timer') + '.service'
                service_text = subprocess.run(['systemctl', 'show', service, '--property=EnvironmentFiles', '--property=WorkingDirectory'], capture_output=True, text=True).stdout
                if str(self.original_configuration) not in service_text or str(self.releases / 'current') not in service_text:
                    raise ManagementError('A scheduled service belongs to another website. It was not stopped.')
            elif str(self.original_configuration) not in text or (str(self.releases / 'current') not in text and '--fpm-config ' + str(self.original_configuration.parent / 'fpm.conf') not in text):
                raise ManagementError('An Emailer service name is used by another installation. It was not stopped.')
            units.append(unit)
        for unit in units:
            if subprocess.run(['systemctl', 'is-active', '--quiet', unit]).returncode == 0:
                self.active_units.append(unit)
        self.record.setdefault('active_units', self.active_units)
        self.record.setdefault('source_units', units)
        self.save()
        for unit in units:
            self.call(['systemctl', 'stop', unit])

    def recover_host_preparation(self):
        from host import HostHandoff
        handoff = HostHandoff(self)
        if not handoff.state:
            raise ManagementError('There is no retained hosting preparation to recover.')
        handoff.stop_target()
        handoff.recover_host()
        self.restore_services()
        return {'delivery_held': True}

    def restore_services(self):
        for unit in self.record.get('active_units', []):
            self.call(['systemctl', 'start', unit])

    def run(self):
        print(f'\nFound {self.product} {self.source_version} at {self.values["DEFAULT_URI"]}')
        print('Your administrator account, content, addresses and encryption keys will be retained.')
        print('1. Update with a verified backup\n2. Recover an interrupted update\n3. Finish a committed update\n4. Check the installation\n5. Resume delivery after reviewing readiness\n0. Exit')
        choice = input('Choose an action [0]: ').strip() or '0'
        if choice == '0':
            return
        if choice == '1' and self.source_version == self.installer.version and (not self.record.get('host_handoff') or self.record['host_handoff'].get('phase') == 'complete'):
            report = json.loads(self.call(['/usr/local/sbin/' + self.prefix, 'emailer:health:verify-runtime', '--allow-operational-hold']))
            print('This release is already installed. No settings, services or delivery state were changed.')
            print('Sign in: ' + self.values['DEFAULT_URI'] + '/login')
            return
        if choice in ('4', '5') and self.source_version != self.installer.version:
            raise ManagementError('This older release does not include the guided health and delivery controls. Choose Update for a verified backup and installation checks first.')
        if choice == '4':
            report = json.loads(self.call(['/usr/local/sbin/' + self.prefix, 'emailer:health:verify-runtime', '--allow-operational-hold']))
            print('Application runtime verified. Delivery ' + ('is ready.' if report['delivery_ready'] else 'is held or needs configuration; open Doctor in the application.'))
            return
        if choice == '5':
            print('This removes the operational delivery hold only if the normal sending checks pass. It does not approve a campaign.')
            if input('Resume delivery now? [y/N]: ').strip().lower() != 'y':
                return
            self.call(['/usr/local/sbin/' + self.prefix, 'emailer:delivery:resume', '--no-interaction'])
            self.call(['/usr/local/sbin/' + self.prefix, 'emailer:health:verify-runtime', '--record'])
            print('Delivery readiness passed and the operational hold was lifted.')
            return
        if choice not in ('1', '2', '3'):
            raise ManagementError('Choose one of the displayed actions.')
        self.stage()
        self.maintenance_environment()
        result = self.native_action(choice)
        if choice == '2':
            self.restore_services()
        self.record.pop('active_units', None)
        self.save()
        print('Operation verified. Sign in: ' + self.values['DEFAULT_URI'] + '/login')
        print(('Delivery remains held. When ready, start setup again and choose Resume delivery. ' if result['delivery_held'] else 'Delivery is already enabled. ') + 'Recovery material: ' + str(self.directory))

    def native_action(self, choice):
        source = self.package.parent / ('emailer-light-' + self.source_version + '.zip')
        if self.product != 'emailer-light' or self.installer.product != 'emailer-light':
            raise ManagementError('Use the installation package for the installed edition.')
        source_version = self.record.get('native_source', self.source_version)
        operation = self.root / ('native-updates/light-' + source_version + '-to-' + self.installer.version + '.json')
        if self.source_version == self.installer.version and choice == '1' and not operation.exists():
            raise ManagementError('This release is already installed. Choose Check the installation.')
        if source_version not in ('1.0.0', '1.1.0'):
            raise ManagementError('This installer supports native Light updates from 1.0.0 or 1.1.0.')
        self.record['native_source'] = source_version
        self.save()
        source = self.package.parent / ('emailer-light-' + source_version + '.zip')
        self.environment['EMAILER_UPDATE_SOURCE_VERSION'] = source_version
        if choice == '2' and operation.exists() and json.loads(protected(operation).read_text()).get('phase') == 'completed':
            raise ManagementError('This update already succeeded. Choose Check; recovery cannot downgrade it.')
        if choice == '3' and not operation.exists():
            raise ManagementError('There is no retained native operation to finish.')
        if choice == '2' and not operation.exists():
            return self.recover_host_preparation()
        from host import HostHandoff
        handoff = HostHandoff(self)
        handoff.prepare()
        arguments = ['emailer:release:update-native', str(self.package), str(source), '--no-interaction']
        if choice == '2':
            handoff.stop_target()
            self.stop_services()
            result = json.loads(self.console([*arguments, '--recover']))
            handoff.recover_host()
            return result
        if choice == '1' and self.source_version != self.installer.version:
            handoff.drain()
            try:
                self.console(arguments)
            except ManagementError:
                if not operation.exists():
                    handoff.recover_host()
                    self.restore_services()
                raise
        handoff.adopt()
        result = json.loads(self.console(arguments))
        handoff.complete()
        return result


def dispatch(installer, error_type, manager_type=Manager, paths=None):
    if os.geteuid() != 0:
        return False
    paths = paths or [Path('/etc/emailer-light/runtime.env')]
    candidates = []
    try:
        for path in paths:
            if not path.exists():
                continue
            if installer.journal.exists():
                saved = json.loads(installer.journal.read_text())
                if saved.get('product') == installer.product and saved.get('version') == installer.version and 'Checking application health and initial backup' not in saved.get('completed', []):
                    return False
            candidates.append(path)
        if not candidates:
            return False
        if len(candidates) != 1:
            raise ManagementError('Several installations were found. Select a separate supported hosting profile.')
        # Fresh setup and every edition/version share this installation-wide lock.
        # Acquire it before reading/saving operation-specific source bindings.
        selected = protected(candidates[0])
        prefix = selected.parent.name
        if not re.fullmatch(r'emailer(?:-light)?', prefix):
            raise ManagementError('The selected hosting profile is unsupported.')
        lock_directory = Path('/var/lib') / (prefix + '-installer')
        lock_directory.mkdir(mode=0o700, exist_ok=True)
        metadata = lock_directory.lstat()
        if lock_directory.is_symlink() or metadata.st_uid != 0 or not stat.S_ISDIR(metadata.st_mode) or metadata.st_mode & 0o077:
            raise ManagementError('The shared installation lock folder is unsafe.')
        descriptor = os.open(lock_directory / 'operation.lock', os.O_WRONLY | os.O_CREAT | os.O_NOFOLLOW, 0o600)
        with os.fdopen(descriptor, 'a') as lock:
            metadata = os.fstat(lock.fileno())
            if metadata.st_uid != 0 or not stat.S_ISREG(metadata.st_mode) or metadata.st_nlink != 1 or metadata.st_mode & 0o077:
                raise ManagementError('The shared installation lock file is unsafe.')
            try:
                fcntl.flock(lock, fcntl.LOCK_EX | fcntl.LOCK_NB)
            except BlockingIOError:
                raise ManagementError('Another setup action is active for this installation. Wait for it to finish.') from None
            installer.preflight(managing=True)
            installer.dependencies(runtime_only=True)
            manager = manager_type(installer, selected)
            manager.run()
        return True
    except ManagementError as error:
        raise error_type(str(error)) from None
