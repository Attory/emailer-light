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


class ManagementError(Exception):
    pass


def protected(path, public=False):
    path = Path(path)
    info = path.lstat()
    if path.is_symlink() or not stat.S_ISREG(info.st_mode) or info.st_nlink != 1 or info.st_uid != 0 or info.st_mode & (0o022 if public else 0o027):
        raise ManagementError('The installation configuration is not a protected, root-owned regular file. It was not used.')
    for parent in path.parents:
        if parent.is_symlink():
            raise ManagementError('The selected installation has a linked configuration parent. It was not used.')
    return path


def read_environment(path):
    result = {}
    for line in protected(path).read_text().splitlines():
        if not line.strip() or line.lstrip().startswith('#'):
            continue
        if '=' not in line:
            raise ManagementError('The saved hosting settings have an unsupported line. No settings were changed.')
        key, value = line.split('=', 1)
        if not re.fullmatch(r'[A-Z][A-Z0-9_]*', key) or key in result:
            raise ManagementError('The saved hosting settings contain duplicate or invalid names.')
        if value.startswith("'") and value.endswith("'"):
            value = value[1:-1]
            if "'" in value:
                raise ManagementError('The saved hosting settings use an unsupported quoted value.')
        elif value.startswith('"'):
            try:
                value = json.loads(value)
            except ValueError:
                raise ManagementError('The saved hosting settings use an unsupported quoted value.') from None
        elif any(character.isspace() for character in value):
            raise ManagementError('The saved hosting settings contain an ambiguous unquoted value.')
        if not isinstance(value, str) or '\x00' in value:
            raise ManagementError('The saved hosting settings contain an invalid value.')
        result[key] = value
    return result


class Manager:
    def __init__(self, installer, configuration):
        self.installer = installer
        self.configuration = Path(configuration)
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
            self.product = 'emailer-light' if (self.current / 'release/emailer-light-release.pub').is_file() else 'emailer-ripe'
        self.source_version = self.current.name.removeprefix('ripe-')
        self.prefix = self.configuration.parent.name
        if not re.fullmatch(r'emailer(?:-light)?', self.prefix):
            raise ManagementError('This hosting profile requires the standard protected Emailer settings folder.')
        self.directory = self.configuration.parent / 'installer'
        self.directory.mkdir(mode=0o700, exist_ok=True)
        info = self.directory.lstat()
        if not stat.S_ISDIR(info.st_mode) or info.st_uid != 0 or info.st_mode & 0o077 or self.directory.is_symlink():
            raise ManagementError('The installer recovery folder is unsafe.')
        self.record_file = self.directory / 'management.json'
        self.record = json.loads(protected(self.record_file).read_text()) if self.record_file.exists() else {}
        self.account = pwd.getpwuid(Path(self.values['EMAILER_SECRET_KEY_FILE']).stat().st_uid)
        if self.account.pw_uid == 0:
            raise ManagementError('The application key belongs to root. A non-root application service identity is required.')
        self.active_units = []
        self.bootstrap = None
        self.package = None
        self.environment = None

    def save(self):
        self.installer.durable(self.record_file, json.dumps(self.record, indent=2) + '\n')

    def call(self, arguments, *, environment=None, data=None):
        result = subprocess.run(arguments, input=data, text=True, env=environment, capture_output=True)
        if result.returncode:
            step = arguments[2] if arguments[0].endswith('php8.4') and len(arguments) > 2 else Path(arguments[0]).name
            diagnostic = {'step': step, 'exit_code': result.returncode}
            try:
                report = json.loads(result.stdout)
                blockers = report.get('runtime_blockers', [])
                if isinstance(blockers, list) and all(isinstance(code, str) and re.fullmatch(r'[a-z0-9_]+', code) for code in blockers):
                    diagnostic['runtime_blockers'] = blockers
            except (ValueError, AttributeError):
                pass
            self.installer.durable(self.directory / 'last-error.json', json.dumps(diagnostic) + '\n')
            raise ManagementError('Step ' + step + ' stopped safely (status ' + str(result.returncode) + '). Delivery may remain held. Start this package again and choose Recover or Finish. Redacted diagnostics: ' + str(self.directory / 'last-error.json'))
        return result.stdout

    def stage(self):
        host = self.installer
        version = host.version
        package_dir = self.directory / 'packages' / version
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
            self.bootstrap.mkdir(mode=0o700)
            with zipfile.ZipFile(self.package) as archive:
                archive.extractall(self.bootstrap)
            for directory, _, files in os.walk(self.bootstrap):
                for name in files:
                    os.chmod(Path(directory) / name, 0o444)
                os.chmod(directory, 0o555)
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
        socket_path = Path('/run/mysqld/mysqld.sock')
        if params.hostname in ('127.0.0.1', 'localhost', '::1') and socket_path.exists():
            dsn = 'mysql://root@localhost/' + database + '?' + urllib.parse.urlencode({'unix_socket': str(socket_path), 'charset': 'utf8mb4'})
        else:
            dsn = ''
        runtime_dir = self.directory / 'console'
        runtime_dir.mkdir(mode=0o700, exist_ok=True)
        pin = Path(self.values['EMAILER_RELEASE_PUBLIC_KEY_FILE'])
        if self.product == self.installer.product and protected(pin, public=True).read_text().strip() != (self.package.parent / 'release.pub').read_text().strip():
            raise ManagementError('The installed signing identity differs from the official setup package. It was not replaced.')
        values = {**self.values, 'EMAILER_RUNTIME_CONFIGURATION_FILE': str(self.configuration), 'EMAILER_RUNTIME_DIR': str(runtime_dir),
                  'EMAILER_RELEASE_PUBLIC_KEY_FILE': str(pin), 'EMAILER_UPDATE_SOURCE_VERSION': self.source_version}
        if self.record.get('maintenance_credentials'):
            dsn = protected(self.directory / 'maintenance-url').read_text().strip()
        def environment(url):
            return {'PATH': '/usr/bin:/bin', 'LANG': 'C.UTF-8', **values, 'DATABASE_URL': url}
        check = ['/usr/bin/php8.4', str(self.bootstrap / 'bin/console'), 'doctrine:query:sql', 'SELECT 1', '--no-interaction']
        if not dsn or subprocess.run(check, env=environment(dsn), capture_output=True).returncode:
            print('The database needs separate permission for a safe backup and update. Your application password is kept unchanged.')
            username = input('Database administrator name: ').strip()
            password = getpass.getpass('Database administrator password (hidden): ')
            hostname = params.hostname or 'localhost'
            hostname = '[' + hostname + ']' if ':' in hostname else hostname
            dsn = 'mysql://' + urllib.parse.quote(username, safe='') + ':' + urllib.parse.quote(password, safe='') + '@' + hostname + ':' + str(params.port or 3306) + '/' + database + '?charset=utf8mb4'
            self.call(check, environment=environment(dsn))
            self.installer.durable(self.directory / 'maintenance-url', dsn + '\n')
            self.record['maintenance_credentials'] = True
            self.save()
        self.environment = environment(dsn)
        return database

    def console(self, arguments, *, data=None):
        return self.call(['/usr/bin/php8.4', str(self.bootstrap / 'bin/console'), *arguments], environment=self.environment, data=data)

    def stop_services(self):
        units = []
        for suffix in ('scheduler.timer', 'operations.timer', 'scheduler.service', 'operations.service', 'worker.service', 'fpm.service'):
            unit = self.prefix + '-' + suffix
            text = subprocess.run(['systemctl', 'show', unit, '--property=LoadState', '--property=ExecStart', '--property=EnvironmentFiles', '--property=WorkingDirectory'], capture_output=True, text=True).stdout
            if 'LoadState=not-found' in text:
                continue
            if suffix.endswith('.timer'):
                service = unit.removesuffix('.timer') + '.service'
                service_text = subprocess.run(['systemctl', 'show', service, '--property=EnvironmentFiles', '--property=WorkingDirectory'], capture_output=True, text=True).stdout
                if str(self.configuration) not in service_text or str(self.releases / 'current') not in service_text:
                    raise ManagementError('A scheduled service belongs to another website. It was not stopped.')
            elif str(self.configuration) not in text or (str(self.releases / 'current') not in text and '--fpm-config ' + str(self.configuration.parent / 'fpm.conf') not in text):
                raise ManagementError('An Emailer service name is used by another installation. It was not stopped.')
            units.append(unit)
        for unit in units:
            if subprocess.run(['systemctl', 'is-active', '--quiet', unit]).returncode == 0:
                self.active_units.append(unit)
        self.record.setdefault('active_units', self.active_units)
        self.save()
        for unit in units:
            self.call(['systemctl', 'stop', unit])

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
        self.stage()
        self.maintenance_environment()
        if choice == '4':
            report = json.loads(self.console(['emailer:health:verify-runtime']))
            print('Application runtime verified. Delivery ' + ('is ready.' if report['delivery_ready'] else 'is held or needs configuration; open Doctor in the application.'))
            return
        if choice == '5':
            print('This removes the operational delivery hold only if the normal sending checks pass. It does not approve a campaign.')
            if input('Resume delivery now? [y/N]: ').strip().lower() != 'y':
                return
            self.console(['emailer:delivery:resume', '--no-interaction'])
            print('Delivery readiness passed and the operational hold was lifted.')
            return
        if choice not in ('1', '2', '3'):
            raise ManagementError('Choose one of the displayed actions.')
        result = self.native_action(choice)
        self.restore_services()
        self.record.pop('active_units', None)
        self.save()
        print('Operation verified. Sign in: ' + self.values['DEFAULT_URI'] + '/login')
        print(('Delivery remains held. When ready, start setup again and choose Resume delivery. ' if result['delivery_held'] else 'Delivery is already enabled. ') + 'Recovery material: ' + str(self.directory))

    def native_action(self, choice):
        source = self.package.parent / ('emailer-light-' + self.source_version + '.zip')
        if self.product != 'emailer-light' or self.installer.product != 'emailer-light':
            raise ManagementError('This edition action requires the private edition management extension.')
        operation = self.configuration.parent / 'native-updates/light-1.0.0-to-1.1.0.json'
        if self.source_version == '1.1.0' and choice == '1' and not operation.exists():
            raise ManagementError('This release is already installed. Choose Check the installation.')
        if self.source_version == '1.1.0' and operation.exists():
            source = self.package.parent / 'emailer-light-1.0.0.zip'
            self.environment['EMAILER_UPDATE_SOURCE_VERSION'] = '1.0.0'
        if choice == '3' and not operation.exists():
            raise ManagementError('There is no retained native operation to finish.')
        self.stop_services()
        result = json.loads(self.console(['emailer:release:update-native', str(self.package), str(source), *(['--recover'] if choice == '2' else []), '--no-interaction']))
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
        # The same OS, architecture, web-server and storage checks apply to management.
        installer.preflight(managing=True)
        manager = manager_type(installer, candidates[0])
        descriptor = os.open(manager.directory / 'operation.lock', os.O_WRONLY | os.O_CREAT | os.O_NOFOLLOW, 0o600)
        with os.fdopen(descriptor, 'a') as lock:
            try:
                fcntl.flock(lock, fcntl.LOCK_EX | fcntl.LOCK_NB)
            except BlockingIOError:
                raise ManagementError('Another setup action is active. Wait for it to finish.') from None
            manager.run()
        return True
    except ManagementError as error:
        raise error_type(str(error)) from None
