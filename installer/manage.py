"""Guided management of an existing native installation from the verified bundle."""
import contextlib
import errno
import fcntl
import getpass
import hashlib
import json
import os
from pathlib import Path
import pwd
import re
import secrets
import shutil
import stat
import sys
import subprocess
import time
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
        self.invocation_id = secrets.token_hex(16)
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
            candidates = [Path('/etc') / (self.root.name + '-' + product.removeprefix('emailer-') + '-' + suffix) / 'runtime.env' for suffix in ('110', '120', '121', '122', '123', '124', '125', '126', '127', '128', '129', '1210')]
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
        self.validate_launcher()

    def validate_launcher(self):
        launcher = Path('/usr/local/sbin') / self.prefix
        if not os.path.lexists(launcher):
            return  # Published pre-automated installations did not require this launcher.
        from provision import generated_launcher
        values = read_environment(self.configuration)
        account = pwd.getpwuid(Path(values['EMAILER_SECRET_KEY_FILE']).stat().st_uid)
        expected = [generated_launcher(self.installer.bundle, self.configuration.parent.name, account.pw_name, self.configuration, self.releases / 'current', php=php).encode() for php in ('/usr/bin/php8.4', '/usr/bin/php8.5')]
        actual = protected(launcher, public=True).read_bytes()
        if actual in expected:
            return
        handoff = self.record.get('host_handoff', {})
        entry = handoff.get('resource_claims', {}).get(str(launcher))
        if entry is not None:
            from resources import ResourceClaims
            ResourceClaims(handoff['resource_claims'], self.save, ManagementError).check(launcher)
            name = self.prefix + '-' + self.installer.product.removeprefix('emailer-') + '-' + self.installer.version.replace('.', '')
            candidates = [(name, name, Path('/etc') / name / 'runtime.env')]
            source_user = handoff.get('source_user')
            if isinstance(source_user, str) and re.fullmatch(r'[a-z_][a-z0-9_-]*', source_user):
                candidates.append((self.original_configuration.parent.name, source_user, self.original_configuration))
            for candidate_name, user, configuration in candidates:
                candidate = [generated_launcher(self.installer.bundle, candidate_name, user, configuration, self.releases / 'current', php=php).encode() for php in ('/usr/bin/php8.4', '/usr/bin/php8.5')]
                if actual in candidate:
                    return
        raise ManagementError('The Emailer command name is occupied by an unrelated or custom launcher. It was preserved and was not executed. Move that command to another name or restore the original generated Emailer launcher, then retry setup; no replacement launcher needs to be assembled.')

    def save(self):
        self.installer.durable(self.record_file, json.dumps(self.record, indent=2) + '\n')

    def call(self, arguments, *, environment=None, data=None):
        descriptor = getattr(self, 'maintenance_descriptor', None)
        inherit = (descriptor,) if descriptor is not None and self.bootstrap is not None and arguments[:2] == [self.installer.php, str(self.bootstrap / 'bin/console')] else ()
        result = subprocess.run(arguments, input=data, text=True, env={**(os.environ if environment is None else environment), 'EMAILER_INSTALLER_DIAGNOSTICS': '1'}, capture_output=True, pass_fds=inherit)
        if result.returncode:
            step = arguments[2] if arguments[0] in ('/usr/bin/php8.4', '/usr/bin/php8.5') and len(arguments) > 2 else Path(arguments[0]).name
            diagnostic = {'step': step, 'exit_code': result.returncode}
            try:
                parsed = json.loads(result.stdout)
                report = parsed if isinstance(parsed, dict) else {}
            except (ValueError, AttributeError):
                report = {}
            guidance = {
                'database_endpoint': 'The application and maintenance sign-ins could not be proved to use the same database server and data directory. No update or account fencing was started. Keep the configured socket/port and provide administrator access to that exact server; do not substitute the shared server.',
                'finish_required': 'The application update committed. Choose Finish (or Accept for a Light to Ripe upgrade); recovery cannot undo a completed operation.',
                'completed_operation': 'This operation already completed. Choose Check the installation. Recovery cannot downgrade it.',
                'delivery_state': 'Delivery state could not be verified. Sending was not announced as paused. Keep the saved operation; choose Recover if it was interrupted, then retry after correcting the reported state.',
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
            blockers = report.get('runtime_blockers', [])
            if isinstance(blockers, list) and len(blockers) <= 64 and all(isinstance(item, str) and re.fullmatch(r'[a-z][a-z0-9_]{1,79}', item) for item in blockers):
                diagnostic['runtime_blockers'] = blockers
            code = report.get('installer_error', 'verification_failed')
            if code not in guidance:
                code = 'verification_failed'
            diagnostic['error_code'] = code
            if code == 'delivery_state' and isinstance(report.get('message'), str) and len(report['message']) <= 1200:
                message = report['message'] + ' ' + guidance[code]
            elif diagnostic.get('runtime_blockers'):
                message = 'Application checks need attention: ' + ', '.join(diagnostic['runtime_blockers']) + '. '
                if step in ('emailer:release:update-native', 'emailer:release:update'):
                    message += 'Keep delivery held. Start this same setup file and choose Recover, then repair the matching Doctor findings before retrying.'
                elif self.record.get('host_handoff', {}).get('phase') not in (None, 'complete', 'recovered'):
                    message += 'Keep delivery held. Repair the matching Doctor findings and choose Finish in this setup file.'
                else:
                    message += 'Open Doctor for the matching repair steps; delivery remains blocked.'
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
        self.call([self.installer.php, str(package_dir / 'verify-package.php'), '--archive=' + str(self.package), '--public-key=' + str(package_dir / 'release.pub'), '--current-version=' + version, '--expected-version=' + version, '--expected-source=' + host.meta['source_commit']])
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
        check = [self.installer.php, str(self.bootstrap / 'bin/console'), 'dbal:run-sql', 'SELECT 1', '--no-interaction']
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
        proof = self.call([self.installer.php, str(self.package.parent / 'host-database.php')], data=json.dumps(endpoint_input))
        self.record['database_endpoint'] = json.loads(proof)
        self.save()
        server = self.console(['dbal:run-sql', 'SELECT VERSION()', '--no-interaction'])
        if not re.search(r'\b(?:10\.11|11\.8)\.\d+[-\w ]*MariaDB', server):
            raise ManagementError('This database server is outside the tested MariaDB 10.11 and 11.8 versions. It was not upgraded or replaced.')
        return database

    def console(self, arguments, *, data=None):
        return self.call([self.installer.php, str(self.bootstrap / 'bin/console'), *arguments], environment=self.environment, data=data)

    def source_service_units(self):
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
        return units

    def source_web_exit_proof(self, unit):
        return dict(line.split('=', 1) for line in self.call(['systemctl', 'show', unit, '--property=Result,ExecMainCode,ExecMainStatus,ActiveState']).splitlines() if '=' in line)

    @staticmethod
    def clean_web_exit(proof):
        return proof.get('Result') == 'success' and proof.get('ExecMainCode') == '1' and proof.get('ExecMainStatus') == '0' and proof.get('ActiveState') == 'inactive'

    def verify_source_web_runtime(self):
        unit = self.source_service_prefix + '-fpm.service'
        if unit not in self.source_service_units():
            raise ManagementError('This website does not have a recorded dedicated Emailer PHP service. Its shared or custom PHP runtime was preserved. Automatic updating cannot prove that an existing web-triggered mail test finished; use the advanced supervised update path with verified web-request quiescence. No update hold or website change was started.')
        if subprocess.run(['systemctl', 'is-active', '--quiet', unit]).returncode != 0 and not self.clean_web_exit(self.source_web_exit_proof(unit)):
            self.record['source_web_drain_uncertain'] = True
            self.save()
            raise ManagementError('The source Emailer PHP service has no clean graceful-exit proof. Keep delivery held and review any interrupted test message; Recover the saved operation before retrying. Shared PHP services were preserved.')

    def stop_services(self, *, web_only=False):
        units = self.source_service_units()
        if web_only:
            self.verify_source_web_runtime()
        for unit in units:
            if subprocess.run(['systemctl', 'is-active', '--quiet', unit]).returncode == 0:
                if unit not in self.active_units:
                    self.active_units.append(unit)
        self.record.setdefault('active_units', self.active_units)
        self.record.setdefault('source_units', units)
        self.save()
        for unit in units:
            if web_only and not unit.endswith('-fpm.service'):
                continue
            if unit.endswith('-fpm.service') and subprocess.run(['systemctl', 'is-active', '--quiet', unit]).returncode == 0:
                # FPM SIGTERM may abort a synchronous SMTP test. SIGQUIT drains
                # existing requests; never replace a timeout with forced termination.
                self.record.setdefault('source_web_drains', {})[unit] = {'state': 'requested', 'signal': 'SIGQUIT'}
                self.save()
                self.call(['systemctl', 'kill', '--kill-whom=main', '--signal=SIGQUIT', unit])
                until = time.monotonic() + 120
                while True:
                    pid = self.call(['systemctl', 'show', unit, '--property=MainPID', '--value']).strip()
                    if pid == '0':
                        break
                    if not pid.isdigit() or time.monotonic() >= until:
                        self.record['source_web_drain_uncertain'] = True
                        self.save()
                        raise ManagementError('An Emailer web request has not finished gracefully. No request was forcibly killed. Keep the operation, let the request finish, then choose Recover before retrying; an unfinished SMTP test must not be assumed unsent.')
                    time.sleep(0.25)
                proof = self.source_web_exit_proof(unit)
                if not self.clean_web_exit(proof):
                    self.record['source_web_drain_uncertain'] = True
                    self.save()
                    raise ManagementError('The old Emailer PHP service did not confirm a clean graceful exit. An interrupted mail test must be treated as uncertain. Its recovery evidence was retained; no automatic continuation is allowed.')
                self.record.setdefault('source_web_drains', {})[unit] = {'signal': 'SIGQUIT', **proof}
                self.save()
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

    @contextlib.contextmanager
    def guarded_action(self):
        try:
            yield
        except (Exception, KeyboardInterrupt) as error:
            if isinstance(error, ManagementError):
                reason = str(error)
            elif isinstance(error, (KeyboardInterrupt, EOFError)):
                reason = 'Setup was interrupted. Its recovery material has been retained.'
            elif isinstance(error, OSError):
                reason = {errno.ENOSPC: 'The server ran out of disk space. Free disk space before retrying.',
                          errno.EACCES: 'A required protected host file could not be accessed. Restore its recorded ownership and permissions.',
                          errno.EROFS: 'The required host filesystem is read-only. Restore writable storage before retrying.'}.get(error.errno, 'Emailer could not complete a protected host file operation. Check required files and storage permissions.')
            elif isinstance(error, getattr(self.installer, 'error', ManagementError)):
                reason = 'The dedicated Emailer services could not be installed or started.' if getattr(self, 'failure_stage', '') == 'services' else 'Emailer host setup did not finish. Check the dedicated host prerequisites.'
            else:
                reason = 'An internal setup or saved-state check did not finish. No recovery or sending was started automatically.'
            action = getattr(self, 'failure_action', 'recover')
            next_step = {'finish': 'Start this same setup file and choose Finish after repairing the reported issue.',
                         'accept': 'Start this same setup file and use Recover or Accept as indicated by the saved edition operation.',
                         'recover': 'Start this same setup file and choose Recover before retrying an interrupted uncommitted operation.'}.get(action)
            reason = (reason[:1200] + ' ' + next_step) if next_step else reason[:1200]
            from host import HostHandoff
            completed = False
            try:
                handoff = HostHandoff(self)
                snapshot = handoff.current_update_snapshot()
                completed = bool(snapshot and snapshot.get('phase') == 'complete' and (not handoff.owned_delivery() or self.record.get('control_release_completed') is True))
                if completed:
                    reason = 'This update already completed. Its verified receipt and delivery state were preserved. Choose Check the installation; recovery cannot downgrade it.'
                else:
                    handoff.block_update(reason, phase='failed', public_reason='host_storage' if isinstance(error, OSError) else 'host_services' if getattr(self, 'failure_stage', '') == 'services' else 'verification')
            except Exception:
                print('The protected update failure record could not be fully saved. Sending has not been resumed. Keep recovery material and repair storage or permissions before starting this setup file again.', file=sys.stderr, flush=True)
            if isinstance(error, (KeyboardInterrupt, EOFError)):
                raise
            if isinstance(error, ManagementError) and not completed:
                raise
            raise ManagementError(reason) from None
        finally:
            context = self.__dict__.pop('owned_maintenance_context', None)
            if context is not None:
                context.__exit__(None, None, None)

    def run(self):
        with self.guarded_action():
            return self.run_menu()

    def run_menu(self):
        print(f'\nFound {self.product} {self.source_version} at {self.values["DEFAULT_URI"]}')
        print('Your administrator account, content, addresses and encryption keys will be retained.')
        print('1. Update with a verified backup\n2. Recover an interrupted update\n3. Finish a committed update\n4. Check the installation\n5. Resume delivery after reviewing readiness\n0. Exit')
        choice = input('Choose an action [0]: ').strip() or '0'
        if choice == '0':
            return
        if choice == '1' and self.source_version == self.installer.version and (not self.record.get('host_handoff') or self.record['host_handoff'].get('phase') == 'complete'):
            from host import HostHandoff
            handoff = HostHandoff(self)
            snapshot = handoff.current_update_snapshot()
            if handoff.owned_delivery() and self.record.get('control_operation_id') and not self.record.get('control_release_completed') and handoff.state.get('phase') == 'complete':
                self.stage()
                self.maintenance_environment()
                handoff.complete()
                self.report_update_completion()
                return
            if snapshot and snapshot.get('phase') not in ('complete', 'recovered'):
                self.stage()
                self.maintenance_environment()
                handoff.restore_update_delivery()
                self.report_update_completion()
                return
            report = json.loads(self.call(['/usr/local/sbin/' + self.prefix, 'emailer:health:verify-runtime', '--allow-operational-hold']))
            print('This release is already installed. No settings, services or delivery state were changed.')
            print('Sign in: ' + self.values['DEFAULT_URI'] + '/login')
            return
        if choice in ('4', '5') and self.source_version != self.installer.version:
            from host import HostHandoff
            recovered_handoff = HostHandoff(self)
            recovered_snapshot = recovered_handoff.current_update_snapshot()
            if not (choice == '5' and recovered_handoff.state.get('phase') == 'recovered' and recovered_snapshot and recovered_snapshot.get('phase') in ('recovered', 'blocked', 'resuming')):
                raise ManagementError('This source release needs Update for guided runtime checks. A recovered source may instead use Resume delivery after explicitly reviewing readiness.')
        if choice == '4':
            report = json.loads(self.call(['/usr/local/sbin/' + self.prefix, 'emailer:health:verify-runtime', '--allow-operational-hold']))
            from host import HostHandoff
            snapshot = HostHandoff(self).current_update_snapshot()
            if snapshot and snapshot.get('phase') != 'complete':
                print('Application runtime verified. Sending is blocked by the update: ' + snapshot.get('reason', 'Choose Finish to complete delivery-state verification.'))
            else:
                print('Application runtime verified. Delivery ' + ('is ready.' if report['delivery_ready'] else 'is held or needs configuration; open Doctor in the application.'))
            return
        if choice == '5':
            from host import HostHandoff
            recovery = HostHandoff(self)
            if recovery.owned_delivery() and self.source_version != self.installer.version and recovery.state.get('phase') == 'recovered':
                raise ManagementError('The recovered source remains safely held. Use this verified setup to retry and complete the update before Resume; the old release cannot enforce the new independent holds. Keep its recovery material and do not invoke the historical raw Resume command.')
            print('This removes the operational delivery hold only if the normal sending checks pass. It does not approve a campaign or retry uncertain messages. Review any reported uncertain delivery history first.')
            if input('Resume delivery now? [y/N]: ').strip().lower() != 'y':
                return
            from host import HostHandoff
            handoff = HostHandoff(self)
            snapshot = handoff.current_update_snapshot()
            if handoff.owned_delivery() and self.record.get('delivery_review_operation_id'):
                self.stage()
                self.maintenance_environment()
                if input('Have you reviewed the uncertain delivery outcomes without retrying them? [y/N]: ').strip().lower() != 'y':
                    return
                self.record['delivery_review_acknowledged'] = True
                self.save()
                handoff.database('control-review-ack')
            if snapshot and snapshot.get('phase') != 'complete':
                if handoff.state.get('phase') not in ('complete', 'recovered'):
                    raise ManagementError('Host setup is unfinished. Choose Finish before resuming delivery.')
                self.stage()
                self.maintenance_environment()
                inspection = json.loads(handoff.database('inspect-update'))
                if inspection['active_claims'] or inspection['processing'] or inspection['test_processing']:
                    raise ManagementError('A message is still in progress. Wait for it to finish; no message was retried.')
                handoff.database('retain-update-hold')
                handoff.update_delivery_phase('resuming', explicit=True)
            try:
                self.call(['/usr/local/sbin/' + self.prefix, 'emailer:delivery:resume', '--no-interaction'])
            except ManagementError as error:
                if snapshot and snapshot.get('phase') != 'complete':
                    handoff.database('retain-update-hold')
                    handoff.update_delivery_phase('blocked', str(error))
                raise
            if snapshot and snapshot.get('phase') != 'complete':
                handoff.update_delivery_phase('complete')
            if self.source_version == self.installer.version:
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
        if choice == '2':
            print('Recovery verified. Delivery remains held. Sign in: ' + self.values['DEFAULT_URI'].rstrip('/') + '/login')
        else:
            self.report_update_completion()
        print('Recovery material: ' + str(self.directory))

    def report_update_completion(self):
        from host import HostHandoff
        snapshot = HostHandoff(self).current_update_snapshot()
        url = self.values['DEFAULT_URI'].rstrip('/') + '/login'
        if snapshot and snapshot.get('phase') == 'complete':
            print('Update successful. Sign in: ' + url)
        elif snapshot:
            print('Update needs attention. Sending remains blocked: ' + snapshot.get('reason', 'Choose Finish to complete delivery-state verification.'))
            print('Sign in: ' + url)
        else:
            print('Update applied. The prior delivery state could not be confirmed; its hold was retained. Review readiness before using Resume delivery.')
            print('Sign in: ' + url)

    def native_action(self, choice):
        self.record['control_requested_action'] = choice
        self.save()
        source = self.package.parent / ('emailer-light-' + self.source_version + '.zip')
        if self.product != 'emailer-light' or self.installer.product != 'emailer-light':
            raise ManagementError('Use the installation package for the installed edition.')
        source_version = self.record.get('native_source', self.source_version)
        operation = self.original_configuration.parent / ('native-updates/light-' + source_version + '-to-' + self.installer.version + '.json')
        if self.source_version == self.installer.version and choice == '1' and not operation.exists():
            raise ManagementError('This release is already installed. Choose Check the installation.')
        if source_version not in ('1.0.0', '1.1.0', '1.2.0', '1.2.1', '1.2.2', '1.2.3', '1.2.4', '1.2.5', '1.2.6', '1.2.7', '1.2.8', '1.2.9', '1.2.10'):
            raise ManagementError('This installer supports native Light updates from 1.0.0, 1.1.0, 1.2.0, 1.2.1, 1.2.2, 1.2.3, 1.2.4, 1.2.5, 1.2.6, 1.2.7, 1.2.8, 1.2.9 or 1.2.10.')
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
        handoff.restore_update_delivery()
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
