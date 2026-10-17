#!/usr/bin/env python3
"""Emailer native-host provisioner. Executed only from a verified, root-frozen bundle."""
import fcntl
import getpass
import hashlib
import json
import os
from pathlib import Path
import platform
import pwd
import re
import secrets
import shutil
import socket
import stat
import subprocess
import sys
import tempfile
import urllib.parse
import zipfile


class SetupError(Exception):
    pass


def run(args, *, data=None, capture=True, allowed=(0,)):
    result = subprocess.run(args, input=data, text=True, stdout=subprocess.PIPE if capture else None,
                            stderr=subprocess.PIPE if capture else None, env={**os.environ, 'DEBIAN_FRONTEND': 'noninteractive', 'NEEDRESTART_MODE': 'l', 'NEEDRESTART_SUSPEND': '1'})
    if result.returncode not in allowed:
        # Commands may handle credentials: never render argv, SQL, environment or raw output.
        raise SetupError('A required host operation did not finish. Your progress is saved. Retry setup; if it repeats, use the troubleshooting reference for this step.')
    return result.stdout or ''


def durable(path, content, mode=0o600):
    path = Path(path)
    if path.is_symlink():
        raise SetupError('A configuration path is a symbolic link. Setup will not overwrite it.')
    path.parent.mkdir(parents=True, exist_ok=True)
    fd, temporary = tempfile.mkstemp(prefix='.' + path.name, dir=path.parent)
    try:
        os.fchmod(fd, mode)
        with os.fdopen(fd, 'wb' if isinstance(content, bytes) else 'w') as stream:
            stream.write(content)
            stream.flush()
            os.fsync(stream.fileno())
        os.replace(temporary, path)
        directory = os.open(path.parent, os.O_RDONLY | os.O_DIRECTORY)
        try:
            os.fsync(directory)
        finally:
            os.close(directory)
    finally:
        if os.path.exists(temporary):
            os.unlink(temporary)


def ask_hostname():
    print('Choose the address people will use, for example mailer.your-domain.com.')
    print('Its DNS records must point to this server. The machine name is not used as a public address.')
    host = input('Application hostname: ').strip().lower()
    if len(host) > 253 or not re.fullmatch(r'(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z][a-z0-9-]*', host):
        raise SetupError('Enter only a public hostname, without https://, a port or a path.')
    return host


def root_directory(path, group, mode):
    """Set required traversal explicitly; caller umask must not break service access."""
    path = Path(path)
    try:
        path.mkdir(mode=0o700)
    except FileExistsError:
        pass
    flags = os.O_RDONLY | os.O_DIRECTORY | os.O_NOFOLLOW | os.O_CLOEXEC
    descriptor = os.open('/', flags)
    try:
        for part in path.parts[1:]:
            child = os.open(part, flags, dir_fd=descriptor)
            os.close(descriptor)
            descriptor = child
        info = os.fstat(descriptor)
        if info.st_uid != 0 or info.st_mode & 0o022:
            raise SetupError('A shared application directory is not protected and root owned. It was not changed.')
        os.fchown(descriptor, 0, group)
        os.fchmod(descriptor, mode)
        info = os.fstat(descriptor)
        if (info.st_uid, info.st_gid, stat.S_IMODE(info.st_mode)) != (0, group, mode):
            raise SetupError('The required service access could not be verified. Keep installation state and retry.')
    finally:
        os.close(descriptor)


class Installer:
    def __init__(self, bundle):
        self.error = SetupError
        self.php = '/usr/bin/php8.4'
        self.database_cli = ['mariadb', '--no-defaults', '--protocol=socket', '--socket=/run/mysqld/mysqld.sock', '--user=root']
        self.database_environment = {'PATH': '/usr/bin:/bin', 'LANG': 'C.UTF-8'}
        self.bundle = Path(bundle)
        self.meta = json.loads((self.bundle / 'installer.json').read_text())
        self.product = self.meta['product']
        if self.product != 'emailer-light':
            raise SetupError('This package has an unknown edition.')
        self.name = 'emailer-light'
        self.version = self.meta['version']
        self.base = Path('/srv') / self.name
        self.state_dir = Path('/var/lib') / (self.name + '-installer')
        self.package_policy_directory = Path('/var/lib/emailer-host-installer')
        self.config = Path('/etc') / self.name
        self.private = Path('/var/lib') / self.name / 'private'
        self.keys = self.private.parent / 'keys'
        self.backups = Path('/var/backups') / self.name
        self.journal = self.state_dir / 'state.json'
        self.user = self.name
        self.state = {}
        self.step = 'Checking this server'
        self.durable = durable

    def save(self):
        durable(self.journal, json.dumps(self.state, indent=2) + '\n')

    def phase(self, name, action):
        self.step = name
        print('\n' + name + ' …', flush=True)
        if name in self.state['completed']:
            print('Already completed; keeping existing settings.')
            return
        action()
        self.state['completed'].append(name)
        self.save()
        print('Done.', flush=True)

    def preflight(self, managing=False):
        if os.geteuid() != 0:
            raise SetupError('Start setup with sudo. This grants permission once for server provisioning; Emailer itself runs as a dedicated account.')
        values = dict(line.split('=', 1) for line in Path('/etc/os-release').read_text().splitlines() if '=' in line)
        system = (values.get('ID', '').strip('"'), values.get('VERSION_ID', '').strip('"'))
        from hosting import WebServer
        self.system = system
        self.web = WebServer.discover(system, SetupError)
        if platform.machine() != 'x86_64' or not Path('/run/systemd/system').is_dir():
            raise SetupError('Setup needs an amd64 supported VPS booted with systemd. File-upload hosting cannot run the required background services.')
        if shutil.disk_usage('/').free < 2 * 1024 ** 3:
            raise SetupError('At least 2 GiB of free disk space is needed before setup can continue.')
        if not self.journal.exists() and not managing:
            for path in (self.base, self.private.parent, self.backups, self.config):
                if path.exists() or path.is_symlink():
                    raise SetupError('An existing Emailer location was found. It needs the guided update or edition-upgrade action; fresh setup will not change it.')
            try:
                pwd.getpwnam(self.user)
            except KeyError:
                pass
            else:
                raise SetupError('The dedicated service account already exists. Setup will not reuse an unrelated account.')
        if not managing:
            from isolated_database import DatabaseProfile
            DatabaseProfile(self).preflight()
            if self.database_profile == 'shared' and shutil.which('mariadb'):
                self.database_authority()
                version = self.database_run(['--batch', '--skip-column-names', '-e', 'SELECT VERSION()']).strip()
                if not re.match(r'(10\.11\.|11\.8\.)', version) or 'MariaDB' not in version:
                    raise SetupError('The existing database is not a supported MariaDB 10.11 or 11.8 server. It will not be replaced or upgraded.')
            elif self.database_profile == 'shared' and (shutil.which('mysqld') or shutil.which('mysql')):
                raise SetupError('An unrecognised database server already exists. Setup will not replace shared database software.')
        if shutil.which(self.web.kind):
            result = self.web.configuration()
        if self.web.kind == 'nginx' and shutil.which('nginx'):
            if re.search(r'listen\s+[^;]*(?:\b80\b|\b443\b)', result):
                # Arbitrary scoped/default address changes need separate profile tests.
                if re.search(r'listen\s+(?!\[::\]:|80\b|443\b|0\.0\.0\.0:)[^;]*:(?:80|443)\b', result):
                    raise SetupError('Nginx uses address-specific public listeners. This profile needs an operator configuration review; no existing website was changed.')
        self.check_public_listeners()

    def check_public_listeners(self):
        try:
            listeners = {}
            for table in ('tcp', 'tcp6'):
                path = Path('/proc/net/' + table)
                if table == 'tcp6' and not path.exists():
                    continue
                for line in path.read_text().splitlines()[1:]:
                    fields = line.split()
                    if fields[3] == '0A':
                        port = int(fields[1].rsplit(':', 1)[1], 16)
                        if port in (80, 443):
                            listeners.setdefault(port, set()).add(fields[9])
            if not listeners:
                return
            web_kind = getattr(getattr(self, 'web', None), 'kind', 'nginx')
            service = subprocess.run(['systemctl', 'show', web_kind + '.service', '--property=MainPID', '--value'], capture_output=True, text=True)
            pid = service.stdout.strip()
            owned = set()
            if service.returncode == 0 and pid.isdecimal() and int(pid) > 0 and Path('/proc/' + pid + '/comm').read_text().strip() == web_kind:
                for descriptor in Path('/proc/' + pid + '/fd').iterdir():
                    try:
                        target = os.readlink(descriptor)
                    except FileNotFoundError:
                        continue
                    match = re.fullmatch(r'socket:\[([0-9]+)\]', target)
                    if match:
                        owned.add(match.group(1))
            for port, inodes in listeners.items():
                if not inodes.issubset(owned):
                    raise SetupError(f'Port {port} is occupied by a service outside the selected {web_kind} website service. Setup kept it running. Free that port for {web_kind} or use a separate supported VPS, then start setup again.')
        except OSError:
            raise SetupError('Setup could not safely identify the services using web ports 80 and 443. Retain existing websites and restore normal Linux /proc service inspection before retrying; no website was changed.') from None

    def database_run(self, arguments, data=None):
        result = subprocess.run([*self.database_cli, *arguments], input=data, capture_output=True, text=True, env=self.database_environment)
        if result.returncode and re.search(r'access denied|command denied|permission denied', result.stderr, re.I):
            self.database_authority(force=True)
            result = subprocess.run([*self.database_cli, *arguments], input=data, capture_output=True, text=True, env=self.database_environment)
            if result.returncode and re.search(r'access denied|command denied|permission denied', result.stderr, re.I):
                raise SetupError('That database sign-in cannot add Emailer’s dedicated database and account. Start setup again with a database administrator allowed to create a database, create its account and grant permissions. Existing databases were preserved.')
        if result.returncode:
            raise SetupError('MariaDB could not complete this setup step. Check free disk space and the local MariaDB service log, then start this same package again. Saved application data and other databases were retained.')
        return result.stdout

    def database_authority(self, force=False):
        saved = self.state_dir / 'database-client.cnf'
        if saved.exists() or saved.is_symlink():
            info = saved.lstat()
            if saved.is_symlink() or info.st_uid != 0 or not stat.S_ISREG(info.st_mode) or info.st_nlink != 1 or info.st_mode & 0o077:
                raise SetupError('The retained database authorisation file is unsafe. It was not used.')
            self.database_cli = ['mariadb', '--defaults-file=' + str(saved)]
        probe = subprocess.run([*self.database_cli, '-NBe', 'SELECT VERSION()'], capture_output=True, text=True, env=self.database_environment)
        if probe.returncode == 0 and not force:
            return
        if not Path('/run/mysqld/mysqld.sock').is_socket():
            raise SetupError('The installed MariaDB server is not listening on its local socket. Start that existing database service before retrying; setup will not replace it.')
        print('Your database requires its own administrator sign-in to add Emailer’s dedicated database. Existing databases and passwords will be kept unchanged.')
        username = input('Database administrator name [root]: ').strip() or 'root'
        password = getpass.getpass('Database administrator password (hidden): ')
        if any(character in username + password for character in ('\x00', '\n', '\r')):
            raise SetupError('The database sign-in contains an unsupported control character. No credentials were saved.')
        def quote(value):
            return '"' + value.replace('\\', '\\\\').replace('"', '\\"').replace('\t', '\\t') + '"'
        durable(saved, '[client]\nuser=' + quote(username) + '\npassword=' + quote(password) + '\nprotocol=socket\nsocket=/run/mysqld/mysqld.sock\n')
        self.database_cli = ['mariadb', '--defaults-file=' + str(saved)]
        if subprocess.run([*self.database_cli, '-NBe', 'SELECT VERSION()'], capture_output=True, env=self.database_environment).returncode:
            raise SetupError('The database administrator sign-in was not accepted. Start setup again to enter it again; existing databases were not changed.')

    def initialize(self):
        self.state_dir.mkdir(mode=0o700, parents=True, exist_ok=True)
        os.chmod(self.state_dir, 0o700)
        if self.journal.exists():
            info = self.journal.lstat()
            if not stat.S_ISREG(info.st_mode) or info.st_uid != 0 or info.st_mode & 0o077 or info.st_nlink != 1:
                raise SetupError('The setup journal has unsafe ownership or permissions. It was not used.')
            self.state = json.loads(self.journal.read_text())
            if self.state.get('product') != self.product or self.state.get('version') != self.version:
                raise SetupError('This saved operation belongs to a different edition or version. Use its original package to resume.')
            if self.state.get('package_sha256') != self.meta['files'][self.meta['archive']]:
                raise SetupError('This package differs from the saved operation. Resume with the original verified package.')
        else:
            self.state = {'format': 1, 'product': self.product, 'version': self.version, 'hostname': ask_hostname(),
                          'database': self.name.replace('-', '_') + '_' + secrets.token_hex(3),
                          'package_sha256': self.meta['files'][self.meta['archive']], 'database_owner': secrets.token_hex(24), 'completed': []}
            self.save()
        if self.state.get('web_server', self.web.kind) != self.web.kind:
            raise SetupError('The host web server differs from the saved installation. Its websites were preserved; resume on the original supported profile.')
        self.state.setdefault('web_preexisting', bool(shutil.which(self.web.kind)))
        self.state['web_server'] = self.web.kind
        self.state['database_profile'] = self.database_profile
        self.save()
        self.php = '/usr/bin/php8.4'
        self.release = self.base / 'releases' / self.version
        self.site = self.web.directory / 'sites-available' / (self.name + '.conf')
        print(f"\nInstalling {self.product} {self.version} at https://{self.state['hostname']}\nApplication files: {self.base}\nAn existing website, if present, will retain its PHP runtime and settings.")

    def dependencies(self, runtime_only=False):
        packages = ['ca-certificates', 'curl', 'unzip', 'openssl', 'certbot', 'php8.4-cli', 'php8.4-fpm', 'php8.4-curl',
                    'php8.4-gd', 'php8.4-intl', 'php8.4-mbstring', 'php8.4-mysql', 'php8.4-xml', 'php8.4-zip']
        from packages import ubuntu_php_source
        ubuntu_php_source(self)
        if not runtime_only and not shutil.which(self.web.kind):
            packages.append(self.web.kind)
        if not runtime_only and self.database_profile == 'shared' and not shutil.which('mariadb'):
            packages.append('mariadb-server')
        if not runtime_only and self.database_profile == 'isolated':
            packages.extend(['libaio1', 'libncurses6', 'libnuma1'])
        if runtime_only:
            packages = [package for package in packages if package.startswith('php8.4-') or package in ('ca-certificates', 'openssl', 'curl', 'unzip')]
        # No global PHP alternatives switch; no automatic unrelated service restart.
        missing = []
        for package in packages:
            status = subprocess.run(['dpkg-query', '-W', '-f=${Status}', package], capture_output=True, text=True)
            if status.returncode or status.stdout != 'install ok installed':
                missing.append(package)
        selections = [('php', '/usr/bin/php', 'php8.4-cli'), ('php-fpm.sock', '/run/php/php-fpm.sock', 'php8.4-fpm')]
        common = subprocess.run(['dpkg-query', '-W', '-f=${Status} ${Version}', 'php8.4-common'], capture_output=True, text=True)
        if common.returncode == 0 and common.stdout.startswith('install ok installed '):
            installed_php_version = common.stdout.split()[-1]
            missing = [package + '=' + installed_php_version if package.startswith('php8.4-') else package for package in missing]
        # Inspect registered selections even when /run links have disappeared after boot.
        # Retain original receipts across an interrupted APT task and already-installed reruns.
        retained = []
        for name, link, package in selections:
            record = self.preserve_php_selection(link, missing, name=name, package=package)
            if record is not None:
                retained.append((name, link, record))
        try:
            if missing:
                self.install_missing_packages(missing)
        finally:
            for name, link, record in retained:
                self.verify_php_selection(name, link, record)
        run([self.php, '-r', 'foreach (["curl","dom","gd","intl","mbstring","pdo_mysql","sodium","zip","posix"] as $e) {if (!extension_loaded($e)) exit(1);}'])
        if not runtime_only:
            if self.database_profile == 'isolated':
                from isolated_database import DatabaseProfile
                DatabaseProfile(self).provision()
            else:
                run(['systemctl', 'start', 'mariadb'])

    def preserve_php_selection(self, link, missing, *, name='php', package='php8.4-cli'):
        receipt = self.state_dir / (name.replace('.', '-') + '-selection.json')
        selection = subprocess.run(['update-alternatives', '--query', name], capture_output=True, text=True)
        current = re.search(r'^Value: (/.+)$', selection.stdout, re.M)
        mode = re.search(r'^Status: (auto|manual)$', selection.stdout, re.M)
        relevant = any(item.split('=', 1)[0] == package for item in missing)
        if receipt.exists() or receipt.is_symlink():
            info = receipt.lstat()
            if receipt.is_symlink() or not stat.S_ISREG(info.st_mode) or info.st_uid != 0 or info.st_nlink != 1 or info.st_mode & 0o077:
                raise SetupError('The saved PHP selection is unsafe. It was not replaced.')
            record = json.loads(receipt.read_text())
            if not isinstance(record, dict) or record.get('policy') != 'keep_existing_version' or record.get('previous_mode') not in ('auto', 'manual') or not isinstance(record.get('previous_value'), str) or not record['previous_value'].startswith('/'):
                raise SetupError('The saved PHP selection is invalid. It was not replaced.')
            if selection.returncode or not current or not mode or current.group(1) != record['previous_value']:
                raise SetupError('The registered PHP selection differs from the saved installation record. No PHP packages were installed. Restore the recorded selection before resuming setup.')
        else:
            if not relevant:
                return None
            if selection.returncode:
                if not os.path.lexists(link):
                    return None
                raise SetupError('The existing PHP command or shared FPM socket is outside Debian’s alternatives system. No PHP packages were installed. Register that existing selection as a Debian alternative or use a separate supported VPS; setup will not replace it.')
            if not current or not mode:
                raise SetupError('The registered PHP command or shared FPM socket selection is ambiguous. No PHP packages were installed.')
            record = {'previous_value': current.group(1), 'previous_mode': mode.group(1), 'policy': 'keep_existing_version'}
            self.state_dir.mkdir(mode=0o700, parents=True, exist_ok=True)
            metadata = self.state_dir.lstat()
            if self.state_dir.is_symlink() or metadata.st_uid != 0 or metadata.st_mode & 0o077:
                raise SetupError('The installer folder cannot safely retain the previous PHP selection.')
            if os.path.lexists(link) and os.path.realpath(link) != os.path.realpath(record['previous_value']):
                raise SetupError('The live PHP command or shared FPM socket differs from its registered selection. No PHP packages were installed.')
            durable(receipt, json.dumps(record) + '\n')
        if relevant and mode.group(1) == 'auto':
            # Prevent even a temporary global command/socket switch during Debian package hooks.
            run(['update-alternatives', '--set', name, record['previous_value']])
        return record

    def verify_php_selection(self, name, link, record):
        selection = subprocess.run(['update-alternatives', '--query', name], capture_output=True, text=True)
        current = re.search(r'^Value: (/.+)$', selection.stdout, re.M)
        best = re.search(r'^Best: (/.+)$', selection.stdout, re.M)
        if selection.returncode or not current:
            raise SetupError('The saved PHP command or shared FPM socket selection is unavailable. Retain the setup record and restore that registered alternative before resuming.')
        if current.group(1) != record['previous_value'] or (os.path.lexists(link) and os.path.realpath(link) != os.path.realpath(record['previous_value'])):
            run(['update-alternatives', '--set', name, record['previous_value']])
        if record['previous_mode'] == 'auto' and best and best.group(1) == record['previous_value']:
            run(['update-alternatives', '--auto', name])
        final = subprocess.run(['update-alternatives', '--query', name], capture_output=True, text=True)
        selected = re.search(r'^Value: (/.+)$', final.stdout, re.M)
        if final.returncode or not selected or selected.group(1) != record['previous_value'] or (os.path.lexists(link) and os.path.realpath(link) != os.path.realpath(record['previous_value'])):
            raise SetupError('The existing PHP command or shared FPM socket could not be preserved. Setup stopped before changing application resources.')

    def install_missing_packages(self, missing):
        catalogue = subprocess.run(['apt-get', '--error-on=any', '-o', 'Acquire::Retries=0', '-o', 'Acquire::http::Timeout=10', '-o', 'Acquire::https::Timeout=10', 'update'], capture_output=True, text=True)
        if catalogue.returncode:
            evidence = catalogue.stdout + catalogue.stderr
            if re.search(r'Could not get lock|Unable to acquire.*lock', evidence, re.I):
                reason = 'Another operating-system package task is running. Let it finish before starting setup again.'
            elif re.search(r'expired|not valid yet|created after.*not-after|not live until', evidence, re.I):
                reason = 'The signed operating-system package catalogue is expired or not yet valid. Check the server clock and configured mirror freshness.'
            elif re.search(r'NO_PUBKEY|EXPKEYSIG|not signed|signatures.*verified', evidence, re.I):
                reason = 'The operating system could not authenticate its package catalogue. Restore the operating-system repository signing configuration; setup will not bypass signature checks.'
            elif re.search(r'Could not resolve|Temporary failure resolving', evidence, re.I):
                reason = 'The server could not resolve its configured package server. Restore working DNS for the configured operating-system repositories.'
            else:
                reason = 'The server could not reach a usable signed operating-system package catalogue. Check its internet connection, package-download proxy and configured operating-system repository addresses.'
            raise SetupError(reason + ' Then start the same setup package again; completed steps are retained.')
        from packages import validate_plan, ServicePolicy
        plan = subprocess.run(['apt-get', '--simulate', 'install', '--no-install-recommends', *missing], capture_output=True, text=True)
        if plan.returncode and any(name.startswith('php8.4-') and '=' in name for name in missing):
            raise SetupError('The matching PHP 8.4 patch packages are unavailable. Setup kept that PHP version unchanged. Restore matching signed extension packages or arrange normal maintenance for the existing PHP runtime, then retry.')
        if plan.returncode:
            raise SetupError('The supported package transaction could not be resolved. No packages were installed; inspect apt-cache policy for the required PHP 8.4 packages and retain the current host packages.')
        validate_plan(plan.stdout, SetupError, ('php-common', 'libgd3') if getattr(self, 'system', ('debian', '13'))[0] == 'ubuntu' else ())
        planned = {}
        for line in plan.stdout.splitlines():
            match = re.match(r'^Inst ([A-Za-z0-9+.:~-]+)(?: \[([^]]+)\])? \(([^ ]+)', line)
            if match:
                planned[match.group(1)] = {'version': match.group(3), 'previous': match.group(2)}
        receipt = self.state_dir / 'apt-plan.json'
        durable(receipt, json.dumps({'format': 'emailer-apt-plan-v1', 'packages': planned}) + '\n')
        gate = '/usr/bin/python3 ' + str(self.bundle / 'apt-guard.py') + ' ' + str(receipt)
        with ServicePolicy(self) as policy:
            installation = subprocess.run(['apt-get', '-o', 'APT::Keep-Fds::=' + str(policy.descriptor), '-o', 'DPkg::Pre-Install-Pkgs::=' + gate, 'install', '-y', '--no-remove', '--no-install-recommends', '-o', 'Dpkg::Options::=--force-confold', *missing],
                                      capture_output=True, text=True, pass_fds=(policy.descriptor,), env={**os.environ, 'DEBIAN_FRONTEND': 'noninteractive', 'NEEDRESTART_MODE': 'l', 'NEEDRESTART_SUSPEND': '1'})
        if installation.returncode:
            evidence = installation.stdout + installation.stderr
            pinned_php = any(name.startswith('php8.4-') and '=' in name for name in missing)
            if 'EMAILER_APT_PLAN_CHANGED' in evidence:
                reason = 'The actual host package transaction changed after validation. No package was unpacked. Let other operating-system package tasks finish, then rerun setup.'
            elif pinned_php and re.search(r'was not found|not available|no installation candidate|unmet dependencies|held broken packages', evidence, re.I):
                reason = 'The configured repositories no longer offer extensions matching the PHP 8.4 patch already used on this server. Setup kept that PHP version unchanged to protect existing websites. Restore the matching signed packages, or arrange a coordinated PHP patch update for those websites, then run setup again. A separate supported VPS also avoids this conflict.'
            elif re.search(r'Could not get lock|Unable to acquire.*lock|dpkg was interrupted', evidence, re.I):
                reason = 'Another operating-system package task is running, or an earlier task was interrupted. Finish that operating-system package task before starting setup again.'
            elif re.search(r'Unable to locate package|no installation candidate', evidence, re.I):
                reason = 'The configured operating-system repositories do not offer the required supported packages. Enable the supported OS repositories and the documented Ubuntu PHP source, then start setup again; setup will not add an unauthenticated repository.'
            elif re.search(r'No space left|not enough free space', evidence, re.I):
                reason = 'The operating system has insufficient free space for the missing packages. Free space on the reported filesystem before starting setup again.'
            elif re.search(r'Could not resolve|Temporary failure resolving|Failed to fetch|Connection failed|Connection timed out', evidence, re.I):
                reason = 'The package manager could not download a required package. Restore its repository connection, DNS or package-download proxy, then start setup again.'
            else:
                reason = 'The package manager could not install the missing supported packages. Its package manager records details in /var/log/apt/term.log. Resolve that reported package error, then start setup again.'
            raise SetupError(reason + ' Completed setup steps and existing configuration files were retained.')

    def verify_runtime(self):
        archive = self.bundle / self.meta['archive']
        run([self.php, str(self.bundle / 'verify-package.php'), '--archive=' + str(archive),
             '--public-key=' + str(self.bundle / 'release.pub'), '--current-version=' + self.version,
             '--expected-version=' + self.version, '--expected-source=' + self.meta['source_commit']])

    def resources(self):
        if not self.state.get('resources_reserved'):
            reserved = [self.site, self.web.directory / 'sites-enabled' / ('zzzz-' + self.site.name),
                        Path('/usr/local/sbin') / self.name, Path('/etc/letsencrypt/renewal-hooks/deploy') / self.name,
                        Path('/var/lib') / (self.name + '-acme')]
            reserved += [Path('/etc/systemd/system') / (self.name + '-' + suffix) for suffix in ('fpm.service', 'worker.service', 'scheduler.service', 'scheduler.timer', 'operations.service', 'operations.timer')]
            for path in reserved:
                if path.exists() or path.is_symlink():
                    raise SetupError('An Emailer website, command or service name is already in use. Setup will not overwrite it.')
            configuration = self.web.configuration()
            host = self.state['hostname']
            declarations = self.web.names(configuration)
            if any(name.startswith('~') for name in declarations):
                raise SetupError('Nginx uses a regular-expression website address rule that this profile cannot safely assign around. Keep that site running; use a dedicated standard hostname configuration or a separate supported VPS. No site was changed.')
            while self.web.conflicts(host, declarations):
                print('That address already serves another website. It has been kept unchanged. Choose a different dedicated address for Emailer.')
                host = ask_hostname()
                self.state['hostname'] = host
                self.save()
            for suffix in ('fpm.service', 'worker.service', 'scheduler.service', 'scheduler.timer', 'operations.service', 'operations.timer'):
                loaded = run(['systemctl', 'show', self.name + '-' + suffix, '--property=LoadState'])
                if 'LoadState=not-found' not in loaded:
                    raise SetupError('An Emailer service name is already used by an existing host service. It was not replaced.')
            names = sorted(path.name for path in (self.web.directory / 'sites-enabled').iterdir())
            link_name = 'zzzz-' + self.site.name
            if names and names[-1] >= link_name:
                link_name = names[-1] + '-' + self.name
            if len(link_name.encode()) > 255:
                raise SetupError('The existing website filename is too long to preserve its default-site ordering automatically. Shorten that enabled filename without changing its site, then rerun setup.')
            self.state['site_link_name'] = link_name
            self.state['resources_reserved'] = True
            self.save()
        try:
            pwd.getpwnam(self.user)
        except KeyError:
            run(['useradd', '--system', '--user-group', '--home-dir', str(self.private.parent), '--shell', '/usr/sbin/nologin', self.user])
        account = pwd.getpwnam(self.user)
        if self.database_profile == 'isolated':
            run(['usermod', '--append', '--groups', 'emailer-db', self.user])
        root_directory(self.private.parent, 0, 0o755)
        for path in (self.private, self.keys, self.backups, self.private / 'locks'):
            path.mkdir(mode=0o700, parents=True, exist_ok=True)
            os.chmod(path, 0o700)
            os.chown(path, account.pw_uid, account.pw_gid)
        root_directory(self.config, account.pw_gid, 0o750)
        root_directory(self.base, 0, 0o755)
        root_directory(self.base / 'releases', 0, 0o755)
        runtime = self.config / 'runtime.env'
        if not runtime.exists():
            password = secrets.token_hex(32)
            db = self.state['database']
            server = self.database_run(['-NBe', 'SELECT VERSION()']).strip()
            version = re.match(r'(10\.11\.\d+|11\.8\.\d+)', server)
            if not version:
                raise SetupError('The database version is unsupported. It has not been changed.')
            dsn = (f'mysql://{db}:{password}@localhost/{db}?unix_socket=/run/emailer-database/mysql.sock&serverVersion={version.group(1)}-MariaDB&charset=utf8mb4' if self.database_profile == 'isolated' else f'mysql://{db}:{password}@127.0.0.1:3306/{db}?serverVersion={version.group(1)}-MariaDB&charset=utf8mb4')
            values = {'APP_ENV': 'prod', 'APP_DEBUG': '0', 'APP_SECRET': secrets.token_hex(32), 'DEFAULT_URI': 'https://' + self.state['hostname'],
                      'DATABASE_URL': dsn, 'EMAILER_RUNTIME_CONFIGURATION_FILE': str(runtime), 'EMAILER_PRIVATE_DIR': str(self.private), 'EMAILER_RUNTIME_DIR': str(self.private),
                      'EMAILER_SECRET_KEY_FILE': str(self.keys / 'master.key'), 'EMAILER_RECOVERY_KEY_FILE': str(self.keys / 'recovery-key.enxkey'),
                      'EMAILER_INSTALL_PROOF_FILE': str(self.private / 'setup' / '.emailer-install-proof'),
                      'EMAILER_RELEASE_PUBLIC_KEY_FILE': str(self.config / 'release-signing.pub'), 'EMAILER_RELEASES_DIR': str(self.base / 'releases'),
                      'EMAILER_BACKUP_DESTINATION_DIR': str(self.backups), 'TRUSTED_PROXIES': '', 'EMAILER_HTTP_MODE': 'direct-fpm', 'TRUSTED_HOSTS': '^' + re.escape(self.state['hostname']) + '$',
                      'MAILER_DSN': 'null://null', 'MESSENGER_TRANSPORT_DSN': 'doctrine://default', 'LOCK_DSN': 'flock://' + str(self.private / 'locks')}
            durable(runtime, ''.join(f"{key}='{value}'\n" for key, value in values.items()), 0o640)
            os.chown(runtime, 0, account.pw_gid)
        durable(self.config / 'release-signing.pub', (self.bundle / 'release.pub').read_text(), 0o644)
        # Recovery credentials and package evidence are outside web/private state, root controlled.
        evidence = self.state_dir / 'packages' / self.version
        evidence.mkdir(mode=0o700, parents=True, exist_ok=True)
        for suffix in ('', '.manifest.json', '.sha256', '.spdx.json', '.sig'):
            shutil.copyfile(self.bundle / (self.meta['archive'] + suffix), evidence / (self.meta['archive'] + suffix))

    def database(self):
        db = self.state['database']
        params = urllib.parse.urlsplit(self.environment()['DATABASE_URL'])
        password = params.password
        if not re.fullmatch(r'[a-z0-9_]+', db) or not password or not re.fullmatch(r'[a-f0-9]+', password):
            raise SetupError('Saved database configuration is inconsistent. It has not been changed.')
        if not self.state.get('database_reserved'):
            count = self.database_run(['-NBe', f"SELECT COUNT(*) FROM information_schema.schemata WHERE schema_name='{db}'"]).strip()
            users = self.database_run(['-NBe', f"SELECT COUNT(*) FROM mysql.user WHERE User='{db}'"]).strip()
            if count != '0' or users != '0':
                raise SetupError('The selected dedicated database name is already in use. No existing database or account was changed.')
            self.state['database_reserved'] = True
            self.save()
        database_host = 'localhost' if 'unix_socket' in urllib.parse.parse_qs(params.query) else '127.0.0.1'
        sql = f"CREATE DATABASE IF NOT EXISTS `{db}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci; CREATE USER IF NOT EXISTS '{db}'@'{database_host}' IDENTIFIED BY '{password}'; GRANT SELECT, INSERT, UPDATE, DELETE, CREATE, ALTER, INDEX, DROP, REFERENCES, CREATE TEMPORARY TABLES, LOCK TABLES, TRIGGER ON `{db}`.* TO '{db}'@'{database_host}';"
        self.database_run(['--batch'], data=sql)
        owner = self.state['database_owner']
        self.database_run(['--batch'], data=f"CREATE TABLE IF NOT EXISTS `{db}`.`_emailer_provisioning` (operation_token CHAR(48) PRIMARY KEY); INSERT IGNORE INTO `{db}`.`_emailer_provisioning` VALUES ('{owner}');")

    def environment(self):
        values = {}
        for line in (self.config / 'runtime.env').read_text().splitlines():
            key, value = line.split('=', 1)
            if not value.startswith("'") or not value.endswith("'"):
                raise SetupError('Protected runtime configuration has an unsupported format.')
            values[key] = value[1:-1]
        return values

    def publish(self):
        if not self.release.exists():
            staging = Path(tempfile.mkdtemp(prefix='.release-', dir=self.base / 'releases'))
            with zipfile.ZipFile(self.bundle / self.meta['archive']) as archive:
                archive.extractall(staging)  # PackageInspector has already checked every path and digest.
            for directory, _, files in os.walk(staging):
                for file in files:
                    os.chmod(Path(directory) / file, 0o444)
                os.chmod(directory, 0o555)
            os.rename(staging, self.release)
        else:
            manifest = json.loads((self.bundle / (self.meta['archive'] + '.manifest.json')).read_text())
            for relative, digest in manifest['files'].items():
                path = self.release / relative
                if path.is_symlink() or not path.is_file() or hashlib.sha256(path.read_bytes()).hexdigest() != digest:
                    raise SetupError('An incomplete release differs from the verified package. It has been retained for inspection.')
        current = self.base / 'releases' / 'current'
        if current.is_symlink():
            if current.resolve() != self.release:
                raise SetupError('An existing application release is active. Fresh setup will not switch it.')
        elif current.exists():
            raise SetupError('The current-release location is already occupied.')
        else:
            temporary = current.with_name('.current-' + secrets.token_hex(4))
            temporary.symlink_to(self.release)
            os.replace(temporary, current)

    def console(self, args, data=None, interactive=False):
        account = pwd.getpwnam(self.user)
        env = {'PATH': '/usr/bin:/bin', **self.environment()}
        def drop():
            os.umask(0o077)
            os.initgroups(self.user, account.pw_gid)
            os.setgid(account.pw_gid)
            os.setuid(account.pw_uid)
        result = subprocess.run([self.php, str(self.release / 'bin/console'), *args], env=env, text=True, input=data,
                                stdout=None if interactive else subprocess.PIPE, stderr=None if interactive else subprocess.PIPE,
                                preexec_fn=drop)
        if result.returncode:
            diagnostic = {'step': args[0], 'exit_code': result.returncode}
            try:
                report = json.loads(result.stdout or '{}')
                blockers = report.get('runtime_blockers', [])
                if isinstance(blockers, list) and all(isinstance(code, str) and re.fullmatch(r'[a-z0-9_]+', code) for code in blockers):
                    diagnostic['runtime_blockers'] = blockers
            except (ValueError, AttributeError):
                pass
            durable(self.state_dir / 'last-error.json', json.dumps(diagnostic) + '\n')
            reason = ('Application checks need attention: ' + ', '.join(diagnostic['runtime_blockers']) + '. Use the matching Doctor check in the troubleshooting reference. ') if diagnostic.get('runtime_blockers') else ('Correct the displayed setup message. ' if interactive else 'The protected application operation failed; its redacted step is recorded. ')
            raise SetupError(reason + 'Start this same setup action again after correcting the problem; your completed steps and account are retained. Redacted diagnostics: ' + str(self.state_dir / 'last-error.json'))
        return result.stdout or ''

    def application_completed(self):
        path = self.private / 'install.lock'
        if not path.exists() and not path.is_symlink():
            return False
        info = path.lstat()
        account = pwd.getpwnam(self.user)
        if path.is_symlink() or not stat.S_ISREG(info.st_mode) or info.st_nlink != 1 or info.st_uid != account.pw_uid or info.st_mode & 0o077:
            raise SetupError('The account-completion record has unsafe ownership or permissions. It was retained; no background services or website were installed.')
        metadata = json.loads(path.read_text())
        identity = metadata.get('installation_uuid')
        if metadata.get('product_id') != self.product or metadata.get('product_version') != self.version or not isinstance(identity, str) or not re.fullmatch(r'[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}', identity):
            raise SetupError('The account-completion record does not match this installation. It was preserved; retain its state and use the setup-recovery reference.')
        db = self.state['database']
        verified = self.database_run(['-NBe', f"SELECT COUNT(*) FROM `{db}`.installation_state s JOIN `{db}`.product_identity p ON p.singleton_key='1' WHERE s.singleton_key='1' AND s.completed_at IS NOT NULL AND s.installation_uuid='{identity}' AND p.installation_uuid=s.installation_uuid AND p.product_id='{self.product}' AND p.product_version='{self.version}' AND (SELECT COUNT(*) FROM `{db}`.app_user WHERE active=1)=1 AND (SELECT COUNT(*) FROM `{db}`.app_user)=1"]).strip()
        if verified != '1':
            raise SetupError('Account setup is not complete in the saved application database. Your data was retained; start this same setup file again to finish account setup before background services and the website are enabled.')
        return True

    def application(self):
        if self.application_completed():
            return
        db = self.state['database']
        # A failed baseline DDL is recoverable only before ANY administrator/input data exists.
        # Ownership marker belongs solely to this fresh-install operation, never an existing app.
        pending = self.database_run(['-NBe', f"SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='{db}' AND table_name='doctrine_migration_versions'"]).strip()
        migrated = False
        if pending == '1':
            migrated = self.database_run(['-NBe', f"SELECT COUNT(*) FROM `{db}`.doctrine_migration_versions WHERE version LIKE '%{self.meta['schema_version']}'"]).strip() == '1'
        if self.state.get('migration_started') and not migrated:
            token = self.database_run(['-NBe', f'SELECT operation_token FROM `{db}`._emailer_provisioning']).strip()
            if token != self.state['database_owner']:
                raise SetupError('The incomplete database ownership marker differs. No database was reset.')
            app_table = self.database_run(['-NBe', f"SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='{db}' AND table_name='app_user'"]).strip()
            if app_table == '1' and self.database_run(['-NBe', f'SELECT COUNT(*) FROM `{db}`.app_user']).strip() != '0':
                raise SetupError('An account exists in an incomplete schema. It was preserved; use the recovery reference before continuing.')
            self.database_run(['--batch'], data=f'DROP DATABASE `{db}`; CREATE DATABASE `{db}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;')
            self.database()
            print('Recovered this operation’s interrupted empty baseline schema. No administrator or campaign data existed.')
        self.state['migration_started'] = True
        self.save()
        self.console(['doctrine:migrations:migrate', '--no-interaction'])
        self.database_run(['--batch'], data=f'DROP TABLE IF EXISTS `{db}`._emailer_provisioning;')
        self.console(['emailer:setup:install', '--guided'], interactive=True)
        if not self.application_completed():
            raise SetupError('Account setup was interrupted before completion. Your saved settings were retained. Start this same setup file again to finish; background services and the public website were not enabled.')

    def service_file(self, name, content):
        durable(Path('/etc/systemd/system') / name, content, 0o644)

    def services(self):
        runtime = self.config / 'runtime.env'
        database_service = 'emailer-database.service' if self.database_profile == 'isolated' else 'mariadb.service'
        socket_path = f'/run/{self.name}-fpm/php.sock'
        durable(self.config / 'fpm.conf', f'''[global]
pid = /run/{self.name}-fpm/php.pid
error_log = syslog
daemonize = no
[{self.name}]
user = {self.user}
group = {self.user}
listen = {socket_path}
listen.owner = www-data
listen.group = www-data
listen.mode = 0660
pm = ondemand
pm.max_children = 8
pm.process_idle_timeout = 10s
clear_env = no
chdir = {self.base}/releases/current
catch_workers_output = no
''', 0o644)
        common = f'''User={self.user}
Group={self.user}
WorkingDirectory={self.base}/releases/current
EnvironmentFile={runtime}
UMask=0077
NoNewPrivileges=yes
PrivateTmp=yes
ProtectSystem=strict
ReadWritePaths={self.private} {self.keys} {self.backups}
'''
        self.service_file(self.name + '-fpm.service', f'''[Unit]
Description={self.product} isolated PHP runtime
After=network.target {database_service}
Wants={database_service}
[Service]
ExecStart=/usr/sbin/php-fpm8.4 --nodaemonize --fpm-config {self.config}/fpm.conf
EnvironmentFile={runtime}
RuntimeDirectory={self.name}-fpm
Restart=on-failure
RestartSec=3
UMask=0077
NoNewPrivileges=yes
PrivateTmp=yes
ProtectSystem=strict
ReadWritePaths={self.private} {self.keys} {self.backups} /run/{self.name}-fpm
[Install]
WantedBy=multi-user.target
''')
        commands = {'worker': 'messenger:consume async --keepalive=5 --time-limit=3600 --memory-limit=256M --failure-limit=10',
                    'scheduler': 'emailer:delivery:scheduler', 'operations': 'emailer:operations:run'}
        # Resolve exact existing command names from the reviewed deployment examples.
        for role in ('scheduler', 'operations'):
            template_prefix = 'emailer-light' if self.product == 'emailer-light' else 'emailer'
            example = self.release / 'deployment' / (template_prefix + '-' + role + '.service.example')
            command = next(line.split('bin/console ', 1)[1] for line in example.read_text().splitlines() if line.startswith('ExecStart='))
            commands[role] = command + (' --wait-for-configuration' if role == 'scheduler' else '')
        for role, command in commands.items():
            worker = role == 'worker'
            self.service_file(self.name + '-' + role + '.service', f'[Unit]\nDescription={self.product} {role}\nAfter={database_service}\nWants={database_service}\n[Service]\nType=' + ('simple' if worker else 'oneshot') + '\n' + common + f'ExecStart={self.php} {self.base}/releases/current/bin/console {command}\n' + ('Restart=always\nRestartSec=5\n[Install]\nWantedBy=multi-user.target\n' if worker else ''))
            if not worker:
                interval = '60s' if role == 'scheduler' else '15min'
                self.service_file(self.name + '-' + role + '.timer', f'[Unit]\nDescription={self.product} {role} timer\n[Timer]\nOnBootSec=60s\nOnUnitActiveSec={interval}\nPersistent=true\n[Install]\nWantedBy=timers.target\n')
        run(['/usr/sbin/php-fpm8.4', '--test', '--fpm-config', str(self.config / 'fpm.conf')])
        run(['systemctl', 'daemon-reload'])
        for unit in ('fpm.service', 'worker.service', 'scheduler.timer', 'operations.timer'):
            run(['systemctl', 'enable', '--now', self.name + '-' + unit])
        run(['systemctl', 'start', self.name + '-scheduler.service'])
        # Self-contained least-privilege command launcher, no shell expansion of runtime secrets.
        command = (self.bundle / 'command.py').read_text().replace('@NAME@', self.name).replace('@USER@', self.user).replace('@RUNTIME_FILE@', str(runtime)).replace('@CURRENT_RELEASE@', str(self.base / 'releases/current'))
        launcher = '#!/usr/bin/python3\n' + (self.bundle / 'runtime_env.py').read_text() + '\n' + command.partition('\n')[2]
        durable('/usr/local/sbin/' + self.name, launcher, 0o755)

    def validated_site(self, text, enabled=None):
        previous = self.site.read_bytes() if self.site.exists() else None
        linked = enabled is not None and not enabled.exists()
        durable(self.site, text, 0o644)
        if linked:
            enabled.symlink_to(self.site)
        try:
            run(self.web.check_command)
        except SetupError:
            if previous is None:
                self.site.unlink(missing_ok=True)
            else:
                durable(self.site, previous, 0o644)
            if linked:
                enabled.unlink(missing_ok=True)
            raise SetupError('The proposed Emailer website failed web-server validation. Its previous file was restored; the web server was not reloaded. Correct the reported website conflict and start setup again.') from None

    def website(self):
        host = self.state['hostname']
        webroot = Path('/var/lib') / (self.name + '-acme')
        for directory in (webroot, webroot / '.well-known', webroot / '.well-known/acme-challenge'):
            root_directory(directory, 0, 0o755)
        self.web.modules(run)
        base = self.web.site(hostname=host, name=self.name, public=str(self.base / 'releases/current/public'), webroot=str(webroot))
        enabled = self.web.directory / 'sites-enabled' / self.state.get('site_link_name', 'zzzz-' + self.site.name)
        self.validated_site(base, enabled)
        run(['systemctl', 'start', self.web.service] if self.state.get('web_preexisting') else ['systemctl', 'enable', '--now', self.web.service])
        run(['systemctl', 'reload', self.web.service])
        try:
            socket.getaddrinfo(host, 443)
        except socket.gaierror:
            raise SetupError(f'DNS for {host} is not ready. Point its A/AAAA records to this VPS, then start setup again. Your account and database are saved; no application page is public yet.') from None
        result = subprocess.run(['certbot', 'certonly', '--webroot', '-w', str(webroot), '-d', host, '--non-interactive', '--agree-tos', '--register-unsafely-without-email'], stdout=subprocess.PIPE, stderr=subprocess.PIPE)
        if result.returncode:
            raise SetupError(f'Trusted HTTPS could not be issued for {host}. Check that every A/AAAA record reaches this VPS and ports 80 and 443 are reachable, then run setup again. Your progress is saved. No trusted certificate is claimed.')
        https = self.web.site(hostname=host, name=self.name, public=str(self.base / 'releases/current/public'), webroot=str(webroot), certificate=f'/etc/letsencrypt/live/{host}/fullchain.pem', certificate_key=f'/etc/letsencrypt/live/{host}/privkey.pem')
        self.validated_site(https)
        run(['systemctl', 'reload', self.web.service])
        durable('/etc/letsencrypt/renewal-hooks/deploy/' + self.name, '#!/bin/sh\n' + ' '.join(self.web.check_command) + ' && systemctl reload ' + self.web.service + '\n', 0o755)
        run(['systemctl', 'enable', '--now', 'certbot.timer'])

    def recovery(self):
        phrase_file = self.state_dir / 'recovery-password'
        if not phrase_file.exists():
            durable(phrase_file, secrets.token_urlsafe(36) + '\n')
        phrase = phrase_file.read_text().strip()
        options = ['emailer:secrets:export-recovery-key', '--passphrase-stdin']
        if (self.keys / 'recovery-key.enxkey').exists():
            options.append('--verify-existing')
        self.console(options, data=phrase + '\n' + phrase + '\n')
        self.state['initial_backup'] = json.loads(self.console(['emailer:backup:create', '--json']))
        self.save()
        self.console(['emailer:operations:run'])

    def health(self):
        if 'initial_backup' not in self.state:
            self.recovery()
        backup = self.state['initial_backup']
        path = Path(backup['path'])
        if path.is_symlink() or not path.is_file() or hashlib.sha256(path.read_bytes()).hexdigest() != backup['sha256']:
            raise SetupError('The initial backup is missing or has changed. It was not replaced. Restore its saved copy or use the backup recovery reference before continuing.')
        self.console(['emailer:backup:verify', str(path)])
        report = json.loads(self.console(['emailer:health:verify-runtime', '--record']))
        codes = {finding['code'] for finding in report['findings']}
        self.state['mail_status'] = 'not configured; open Mail settings when ready' if 'sender_identity' in codes else ('configured; complete the connection check in Mail settings' if 'smtp_transport_security' in codes else 'configured')
        self.save()
        phrase = (self.state_dir / 'recovery-password').read_text().strip()
        self.console(['emailer:secrets:export-recovery-key', '--passphrase-stdin', '--verify-existing'], data=phrase + '\n' + phrase + '\n')
        for unit in ('fpm.service', 'worker.service', 'scheduler.timer', 'operations.timer'):
            run(['systemctl', 'is-active', '--quiet', self.name + '-' + unit])
        run(['curl', '--fail', '--silent', '--show-error', 'https://' + self.state['hostname'] + '/health/live'])
        run(['curl', '--fail', '--silent', '--show-error', 'https://' + self.state['hostname'] + '/login'])

    def execute(self):
        if os.geteuid() != 0:
            self.preflight()
        if self.state_dir.is_symlink():
            raise SetupError('The setup state directory is a symbolic link; it was not used.')
        self.state_dir.mkdir(mode=0o700, parents=True, exist_ok=True)
        info = self.state_dir.lstat()
        if info.st_uid != 0 or info.st_mode & 0o077:
            raise SetupError('The setup state directory has unsafe ownership or permissions.')
        lock_descriptor = os.open(self.state_dir / 'operation.lock', os.O_WRONLY | os.O_CREAT | os.O_NOFOLLOW, 0o600)
        lock = os.fdopen(lock_descriptor, 'a')
        try:
            fcntl.flock(lock, fcntl.LOCK_EX | fcntl.LOCK_NB)
        except BlockingIOError:
            raise SetupError('Another setup process is running for this installation. Let it finish, then retry.') from None
        from packages import ServicePolicy
        ServicePolicy(self).recover()
        self.preflight()
        self.initialize()
        if 'Checking application health and initial backup' in self.state['completed']:
            self.state['completed'].remove('Checking application health and initial backup')
        for name, action in [('Installing supported dependencies', self.dependencies), ('Verifying the production runtime', self.verify_runtime),
                             ('Preparing dedicated resources', self.resources), ('Preparing the database', self.database),
                             ('Publishing verified application files', self.publish), ('Creating your administrator account', self.application),
                             ('Installing background services', self.services), ('Creating recovery material and initial backup', self.recovery), ('Setting up trusted HTTPS', self.website),
                             ('Checking application health and initial backup', self.health)]:
            self.phase(name, action)
        print(f"\nInstallation successful. Sign in: https://{self.state['hostname']}/login\nEmail delivery: {self.state.get('mail_status', 'not configured') }.\nVerified backups: {self.private}/backups\nRecovery package: {self.keys}/recovery-key.enxkey\nRecovery password file (keep separately): {self.state_dir}/recovery-password\nLocal backup destination: {self.backups}\nServer command: sudo {self.name}\nKeep this package to resume or recover the installation.")


def main():
    installer = None
    try:
        installer = Installer(sys.argv[1])
        from manage import dispatch
        from packages import HostLock, ServicePolicy
        with HostLock('/run/lock/emailer-host-installation.lock', SetupError):
            ServicePolicy(installer).recover()
            if not dispatch(installer, SetupError):
                installer.execute()
    except (SetupError, OSError, ValueError, KeyError) as error:
        print('\nSetup stopped during: ' + (installer.step if installer else 'Package validation'), file=sys.stderr)
        print(str(error) if isinstance(error, SetupError) else 'A protected file or host prerequisite could not be read. Existing resources have been retained; see the troubleshooting reference.', file=sys.stderr)
        print('Resume: start the same setup action again. Do not delete installation state or database files.', file=sys.stderr)
        return 1
    except (EOFError, KeyboardInterrupt):
        print('\nSetup interrupted. Start the same action again to resume. Your saved settings are retained.', file=sys.stderr)
        return 1
    return 0


if __name__ == '__main__':
    sys.exit(main())
