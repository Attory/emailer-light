#!/usr/bin/env python3
"""Emailer native-host provisioner. Executed only from a verified, root-frozen bundle."""
import fcntl
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
                            stderr=subprocess.PIPE if capture else None, env={**os.environ, 'DEBIAN_FRONTEND': 'noninteractive', 'NEEDRESTART_MODE': 'l'})
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
        with os.fdopen(fd, 'w') as stream:
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


class Installer:
    def __init__(self, bundle):
        self.bundle = Path(bundle)
        self.meta = json.loads((self.bundle / 'installer.json').read_text())
        self.product = self.meta['product']
        if self.product not in ('emailer-light', 'emailer-ripe'):
            raise SetupError('This package has an unknown edition.')
        self.name = 'emailer-light' if self.product == 'emailer-light' else 'emailer'
        self.version = self.meta['version']
        self.base = Path('/srv') / self.name
        self.state_dir = Path('/var/lib') / (self.name + '-installer')
        self.config = Path('/etc') / self.name
        self.private = Path('/var/lib') / self.name / 'private'
        self.keys = self.private.parent / 'keys'
        self.backups = Path('/var/backups') / self.name
        self.journal = self.state_dir / 'state.json'
        self.user = self.name
        self.state = {}
        self.step = 'Checking this server'

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

    def preflight(self):
        if os.geteuid() != 0:
            raise SetupError('Start setup with sudo. This grants permission once for server provisioning; Emailer itself runs as a dedicated account.')
        values = dict(line.split('=', 1) for line in Path('/etc/os-release').read_text().splitlines() if '=' in line)
        system = (values.get('ID', '').strip('"'), values.get('VERSION_ID', '').strip('"'))
        if system != ('debian', '13'):
            raise SetupError('This installer supports Debian 13 on an amd64 VPS with systemd. This host is unsupported; nothing has been installed.')
        if platform.machine() != 'x86_64' or not Path('/run/systemd/system').is_dir():
            raise SetupError('Setup needs an amd64 Debian 13 VPS booted with systemd. File-upload hosting cannot run the required background services.')
        if shutil.disk_usage('/').free < 2 * 1024 ** 3:
            raise SetupError('At least 2 GiB of free disk space is needed before setup can continue.')
        if not self.journal.exists():
            for path in (self.base, self.private.parent, self.backups, self.config):
                if path.exists() or path.is_symlink():
                    raise SetupError('An existing Emailer location was found. It needs the guided update or edition-upgrade action; fresh setup will not change it.')
            try:
                pwd.getpwnam(self.user)
            except KeyError:
                pass
            else:
                raise SetupError('The dedicated service account already exists. Setup will not reuse an unrelated account.')
        if shutil.which('apache2') or shutil.which('caddy'):
            raise SetupError('This installation path supports Nginx. Apache or Caddy was detected; no website has been changed. Use a separate supported VPS until that coexistence profile is verified.')
        if shutil.which('mariadb'):
            version = run(['mariadb', '--batch', '--skip-column-names', '-e', 'SELECT VERSION()']).strip()
            if not re.match(r'(10\.11\.|11\.8\.)', version) or 'MariaDB' not in version:
                raise SetupError('The existing database is not a supported MariaDB 10.11 or 11.8 server. It will not be replaced or upgraded.')
        elif shutil.which('mysqld') or shutil.which('mysql'):
            raise SetupError('An unrecognised database server already exists. Setup will not replace shared database software.')
        if shutil.which('nginx'):
            result = run(['nginx', '-T'])
            if re.search(r'listen\s+[^;]*(?:\b80\b|\b443\b)', result):
                # Arbitrary scoped/default address changes need separate profile tests.
                if re.search(r'listen\s+(?!\[::\]:|80\b|443\b|0\.0\.0\.0:)[^;]*:(?:80|443)\b', result):
                    raise SetupError('Nginx uses address-specific public listeners. This profile needs an operator configuration review; no existing website was changed.')
        else:
            for port in (80, 443):
                with socket.socket() as probe:
                    try:
                        probe.bind(('0.0.0.0', port))
                    except OSError:
                        raise SetupError(f'Port {port} is occupied by an unknown service. Keep that website running and use a separate supported VPS.') from None

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
        self.php = '/usr/bin/php8.4'
        self.release = self.base / 'releases' / self.version
        self.site = Path('/etc/nginx/sites-available') / (self.name + '.conf')
        print(f"\nInstalling {self.product} {self.version} at https://{self.state['hostname']}\nApplication files: {self.base}\nAn existing website, if present, will retain its PHP runtime and settings.")

    def dependencies(self):
        packages = ['ca-certificates', 'curl', 'unzip', 'openssl', 'certbot', 'php8.4-cli', 'php8.4-fpm', 'php8.4-curl',
                    'php8.4-gd', 'php8.4-intl', 'php8.4-mbstring', 'php8.4-mysql', 'php8.4-xml', 'php8.4-zip']
        if not shutil.which('nginx'):
            packages.append('nginx')
        if not shutil.which('mariadb'):
            packages.append('mariadb-server')
        # No global PHP alternatives switch; no automatic unrelated service restart.
        missing = []
        for package in packages:
            status = subprocess.run(['dpkg-query', '-W', '-f=${Status}', package], capture_output=True, text=True)
            if status.returncode or status.stdout != 'install ok installed':
                missing.append(package)
        previous_php = os.path.realpath('/usr/bin/php') if Path('/usr/bin/php').exists() else None
        common = subprocess.run(['dpkg-query', '-W', '-f=${Status} ${Version}', 'php8.4-common'], capture_output=True, text=True)
        if common.returncode == 0 and common.stdout.startswith('install ok installed '):
            installed_php_version = common.stdout.split()[-1]
            missing = [package + '=' + installed_php_version if package.startswith('php8.4-') else package for package in missing]
        if missing:
            run(['apt-get', '-o', 'Acquire::Retries=0', '-o', 'Acquire::http::Timeout=10', '-o', 'Acquire::https::Timeout=10', 'update'])
            run(['apt-get', 'install', '-y', '--no-install-recommends', '-o', 'Dpkg::Options::=--force-confold', *missing])
        if previous_php and os.path.realpath('/usr/bin/php') != previous_php:
            run(['update-alternatives', '--set', 'php', previous_php])
        if previous_php and os.path.realpath('/usr/bin/php') != previous_php:
            raise SetupError('The existing global PHP selection could not be preserved. Setup stopped before changing application resources.')
        run([self.php, '-r', 'foreach (["curl","dom","gd","intl","mbstring","pdo_mysql","sodium","zip","posix"] as $e) {if (!extension_loaded($e)) exit(1);}'])
        run(['systemctl', 'start', 'mariadb'])

    def verify_runtime(self):
        archive = self.bundle / self.meta['archive']
        run([self.php, str(self.bundle / 'verify-package.php'), '--archive=' + str(archive),
             '--public-key=' + str(self.bundle / 'release.pub'), '--current-version=' + self.version,
             '--expected-version=' + self.version, '--expected-source=' + self.meta['source_commit']])

    def resources(self):
        if not self.state.get('resources_reserved'):
            reserved = [self.site, Path('/etc/nginx/sites-enabled') / ('zzzz-' + self.site.name),
                        Path('/usr/local/sbin') / self.name, Path('/etc/letsencrypt/renewal-hooks/deploy') / self.name,
                        Path('/var/lib') / (self.name + '-acme')]
            reserved += [Path('/etc/systemd/system') / (self.name + '-' + suffix) for suffix in ('fpm.service', 'worker.service', 'scheduler.service', 'scheduler.timer', 'operations.service', 'operations.timer')]
            for path in reserved:
                if path.exists() or path.is_symlink():
                    raise SetupError('An Emailer website, command or service name is already in use. Setup will not overwrite it.')
            configuration = run(['nginx', '-T'])
            host = self.state['hostname']
            for declaration in re.findall(r'server_name\s+([^;]+);', configuration):
                for name in declaration.split():
                    if name == host or name.startswith('~') or (name.startswith('*.') and host.endswith(name[1:])) or (name.startswith('.') and (host == name[1:] or host.endswith(name))) or (name.endswith('.*') and host.startswith(name[:-1])):
                        raise SetupError('That hostname is already claimed by an existing website or an ambiguous routing rule. Choose a dedicated hostname; the existing website was not changed.')
            existing_names = [path.name for path in Path('/etc/nginx/sites-enabled').iterdir()]
            if any(name >= 'zzzz-' + self.site.name for name in existing_names):
                raise SetupError('The existing Nginx site ordering needs review to preserve its default website. No site was changed.')
            self.state['resources_reserved'] = True
            self.save()
        try:
            pwd.getpwnam(self.user)
        except KeyError:
            run(['useradd', '--system', '--user-group', '--home-dir', str(self.private.parent), '--shell', '/usr/sbin/nologin', self.user])
        account = pwd.getpwnam(self.user)
        for path in (self.private, self.keys, self.backups, self.private / 'locks'):
            path.mkdir(mode=0o700, parents=True, exist_ok=True)
            os.chmod(path, 0o700)
            os.chown(path, account.pw_uid, account.pw_gid)
        self.config.mkdir(mode=0o750, parents=True, exist_ok=True)
        os.chown(self.config, 0, account.pw_gid)
        self.base.mkdir(mode=0o755, parents=True, exist_ok=True)
        (self.base / 'releases').mkdir(mode=0o755, exist_ok=True)
        runtime = self.config / 'runtime.env'
        if not runtime.exists():
            password = secrets.token_hex(32)
            db = self.state['database']
            server = run(['mariadb', '-NBe', 'SELECT VERSION()']).strip()
            version = re.match(r'(10\.11\.\d+|11\.8\.\d+)', server)
            if not version:
                raise SetupError('The database version is unsupported. It has not been changed.')
            dsn = f'mysql://{db}:{password}@127.0.0.1:3306/{db}?serverVersion={version.group(1)}-MariaDB&charset=utf8mb4'
            values = {'APP_ENV': 'prod', 'APP_DEBUG': '0', 'APP_SECRET': secrets.token_hex(32), 'DEFAULT_URI': 'https://' + self.state['hostname'],
                      'DATABASE_URL': dsn, 'EMAILER_PRIVATE_DIR': str(self.private), 'EMAILER_RUNTIME_DIR': str(self.private),
                      'EMAILER_SECRET_KEY_FILE': str(self.keys / 'master.key'), 'EMAILER_RECOVERY_KEY_FILE': str(self.keys / 'recovery-key.enxkey'),
                      'EMAILER_INSTALL_PROOF_FILE': str(self.private / 'setup' / '.emailer-install-proof'),
                      'EMAILER_RELEASE_PUBLIC_KEY_FILE': str(self.config / 'release-signing.pub'), 'EMAILER_RELEASES_DIR': str(self.base / 'releases'),
                      'EMAILER_BACKUP_DESTINATION_DIR': str(self.backups), 'TRUSTED_PROXIES': '', 'TRUSTED_HOSTS': '^' + re.escape(self.state['hostname']) + '$',
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
            count = run(['mariadb', '-NBe', f"SELECT COUNT(*) FROM information_schema.schemata WHERE schema_name='{db}'"]).strip()
            users = run(['mariadb', '-NBe', f"SELECT COUNT(*) FROM mysql.user WHERE User='{db}'"]).strip()
            if count != '0' or users != '0':
                raise SetupError('The selected dedicated database name is already in use. No existing database or account was changed.')
            self.state['database_reserved'] = True
            self.save()
        sql = f"CREATE DATABASE IF NOT EXISTS `{db}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci; CREATE USER IF NOT EXISTS '{db}'@'127.0.0.1' IDENTIFIED BY '{password}'; GRANT SELECT, INSERT, UPDATE, DELETE, CREATE, ALTER, INDEX, DROP, REFERENCES, CREATE TEMPORARY TABLES, LOCK TABLES, TRIGGER ON `{db}`.* TO '{db}'@'127.0.0.1';"
        run(['mariadb', '--batch'], data=sql)
        owner = self.state['database_owner']
        run(['mariadb', '--batch'], data=f"CREATE TABLE IF NOT EXISTS `{db}`.`_emailer_provisioning` (operation_token CHAR(48) PRIMARY KEY); INSERT IGNORE INTO `{db}`.`_emailer_provisioning` VALUES ('{owner}');")

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
            os.initgroups(self.user, account.pw_gid)
            os.setgid(account.pw_gid)
            os.setuid(account.pw_uid)
        result = subprocess.run([self.php, str(self.release / 'bin/console'), *args], env=env, text=True, input=data,
                                stdout=None if interactive else subprocess.PIPE, stderr=None if interactive else subprocess.PIPE,
                                preexec_fn=drop)
        if result.returncode:
            raise SetupError('Application setup did not finish. Correct the message above and start the same setup action again; your completed steps and account are retained.')
        return result.stdout or ''

    def application(self):
        if (self.private / 'install.lock').exists():
            return
        db = self.state['database']
        # A failed baseline DDL is recoverable only before ANY administrator/input data exists.
        # Ownership marker belongs solely to this fresh-install operation, never an existing app.
        pending = run(['mariadb', '-NBe', f"SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='{db}' AND table_name='doctrine_migration_versions'"]).strip()
        migrated = False
        if pending == '1':
            migrated = run(['mariadb', '-NBe', f"SELECT COUNT(*) FROM `{db}`.doctrine_migration_versions WHERE version LIKE '%{self.meta['schema_version']}'"]).strip() == '1'
        if self.state.get('migration_started') and not migrated:
            token = run(['mariadb', '-NBe', f'SELECT operation_token FROM `{db}`._emailer_provisioning']).strip()
            if token != self.state['database_owner']:
                raise SetupError('The incomplete database ownership marker differs. No database was reset.')
            app_table = run(['mariadb', '-NBe', f"SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='{db}' AND table_name='app_user'"]).strip()
            if app_table == '1' and run(['mariadb', '-NBe', f'SELECT COUNT(*) FROM `{db}`.app_user']).strip() != '0':
                raise SetupError('An account exists in an incomplete schema. It was preserved; use the recovery reference before continuing.')
            run(['mariadb', '--batch'], data=f'DROP DATABASE `{db}`; CREATE DATABASE `{db}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;')
            self.database()
            print('Recovered this operation’s interrupted empty baseline schema. No administrator or campaign data existed.')
        self.state['migration_started'] = True
        self.save()
        self.console(['doctrine:migrations:migrate', '--no-interaction'])
        run(['mariadb', '--batch'], data=f'DROP TABLE IF EXISTS `{db}`._emailer_provisioning;')
        self.console(['emailer:setup:install', '--guided'], interactive=True)

    def service_file(self, name, content):
        durable(Path('/etc/systemd/system') / name, content, 0o644)

    def services(self):
        runtime = self.config / 'runtime.env'
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
chdir = {self.release}
catch_workers_output = no
''', 0o644)
        common = f'''User={self.user}
Group={self.user}
WorkingDirectory={self.base}/releases/current
EnvironmentFile={runtime}
NoNewPrivileges=yes
PrivateTmp=yes
ProtectSystem=strict
ReadWritePaths={self.private} {self.keys} {self.backups}
'''
        self.service_file(self.name + '-fpm.service', f'''[Unit]
Description={self.product} isolated PHP runtime
After=network.target mariadb.service
[Service]
ExecStart=/usr/sbin/php-fpm8.4 --nodaemonize --fpm-config {self.config}/fpm.conf
EnvironmentFile={runtime}
RuntimeDirectory={self.name}-fpm
Restart=on-failure
RestartSec=3
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
            example = self.release / 'deployment' / (self.name + '-' + role + '.service.example')
            command = next(line.split('bin/console ', 1)[1] for line in example.read_text().splitlines() if line.startswith('ExecStart='))
            commands[role] = command
        for role, command in commands.items():
            worker = role == 'worker'
            self.service_file(self.name + '-' + role + '.service', f'[Unit]\nDescription={self.product} {role}\nAfter=mariadb.service\n[Service]\nType=' + ('simple' if worker else 'oneshot') + '\n' + common + f'ExecStart={self.php} {self.base}/releases/current/bin/console {command}\n' + ('Restart=always\nRestartSec=5\n[Install]\nWantedBy=multi-user.target\n' if worker else ''))
            if not worker:
                interval = '60s' if role == 'scheduler' else '15min'
                self.service_file(self.name + '-' + role + '.timer', f'[Unit]\nDescription={self.product} {role} timer\n[Timer]\nOnBootSec=60s\nOnUnitActiveSec={interval}\nPersistent=true\n[Install]\nWantedBy=timers.target\n')
        run(['/usr/sbin/php-fpm8.4', '--test', '--fpm-config', str(self.config / 'fpm.conf')])
        run(['systemctl', 'daemon-reload'])
        for unit in ('fpm.service', 'worker.service', 'scheduler.timer', 'operations.timer'):
            run(['systemctl', 'enable', '--now', self.name + '-' + unit])
        # Self-contained least-privilege command launcher, no shell expansion of runtime secrets.
        launcher = (self.bundle / 'command.py').read_text().replace('@NAME@', self.name).replace('@USER@', self.user)
        durable('/usr/local/sbin/' + self.name, launcher, 0o755)

    def website(self):
        host = self.state['hostname']
        webroot = Path('/var/lib') / (self.name + '-acme')
        (webroot / '.well-known/acme-challenge').mkdir(parents=True, exist_ok=True)
        os.chmod(webroot, 0o755)
        base = f'''server {{
    listen 80;
    server_name {host};
    location ^~ /.well-known/acme-challenge/ {{ root {webroot}; }}
    access_log off;
    location / {{ return 503; }}
}}
'''
        durable(self.site, base, 0o644)
        enabled = Path('/etc/nginx/sites-enabled') / ('zzzz-' + self.site.name)
        if not enabled.exists():
            enabled.symlink_to(self.site)
        try:
            run(['nginx', '-t'])
        except SetupError:
            enabled.unlink(missing_ok=True)
            raise SetupError('The proposed website failed Nginx validation. It was not reloaded. Existing sites are unchanged; the setup can be retried.') from None
        run(['systemctl', 'enable', '--now', 'nginx'])
        run(['systemctl', 'reload', 'nginx'])
        try:
            socket.getaddrinfo(host, 443)
        except socket.gaierror:
            raise SetupError(f'DNS for {host} is not ready. Point its A/AAAA records to this VPS, then start setup again. Your account and database are saved; no application page is public yet.') from None
        result = subprocess.run(['certbot', 'certonly', '--webroot', '-w', str(webroot), '-d', host, '--non-interactive', '--agree-tos', '--register-unsafely-without-email'], stdout=subprocess.PIPE, stderr=subprocess.PIPE)
        if result.returncode:
            raise SetupError(f'Trusted HTTPS could not be issued for {host}. Check that every A/AAAA record reaches this VPS and ports 80 and 443 are reachable, then run setup again. Your progress is saved. No trusted certificate is claimed.')
        https = f'''server {{
    listen 443 ssl;
    server_name {host};
    ssl_certificate /etc/letsencrypt/live/{host}/fullchain.pem;
    ssl_certificate_key /etc/letsencrypt/live/{host}/privkey.pem;
    root {self.base}/releases/current/public;
    index index.php;
    access_log off;
    location = /index.php {{
        include fastcgi_params;
        fastcgi_param SCRIPT_FILENAME $document_root/index.php;
        fastcgi_param HTTP_PROXY "";
        fastcgi_param HTTPS on;
        fastcgi_param REQUEST_SCHEME https;
        fastcgi_pass unix:/run/{self.name}-fpm/php.sock;
    }}
    location / {{ try_files $uri /index.php$is_args$args; }}
    location ~ \\.php$ {{ return 404; }}
    location ~ (^|/)\\. {{ return 404; }}
    location ~* \\.(?:env|log|sql|dump|backup|bak|key|pem|p12|pfx)$ {{ return 404; }}
}}
'''
        durable(self.site, base.replace('return 503;', 'return 301 https://' + host + '$request_uri;') + https, 0o644)
        run(['nginx', '-t'])
        run(['systemctl', 'reload', 'nginx'])
        durable('/etc/letsencrypt/renewal-hooks/deploy/' + self.name, '#!/bin/sh\nnginx -t && systemctl reload nginx\n', 0o755)
        run(['systemctl', 'enable', '--now', 'certbot.timer'])

    def recovery(self):
        phrase_file = self.state_dir / 'recovery-password'
        if not phrase_file.exists():
            durable(phrase_file, secrets.token_urlsafe(36) + '\n')
        phrase = phrase_file.read_text().strip()
        if not (self.keys / 'recovery-key.enxkey').exists():
            self.console(['emailer:secrets:export-recovery-key', '--passphrase-stdin'], data=phrase + '\n' + phrase + '\n')
        self.console(['emailer:backup:create'])

    def health(self):
        for unit in ('fpm.service', 'worker.service', 'scheduler.timer', 'operations.timer'):
            run(['systemctl', 'is-active', '--quiet', self.name + '-' + unit])
        run(['curl', '--fail', '--silent', '--show-error', 'https://' + self.state['hostname'] + '/health/live'])
        run(['curl', '--fail', '--silent', '--show-error', 'https://' + self.state['hostname'] + '/login'])

    def execute(self):
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
        self.initialize()
        for name, action in [('Installing supported dependencies', self.dependencies), ('Verifying the production runtime', self.verify_runtime),
                             ('Preparing dedicated resources', self.resources), ('Preparing the database', self.database),
                             ('Publishing verified application files', self.publish), ('Creating your administrator account', self.application),
                             ('Installing background services', self.services), ('Creating recovery material and initial backup', self.recovery), ('Setting up trusted HTTPS', self.website),
                             ('Checking application health and initial backup', self.health)]:
            self.phase(name, action)
        print(f"\nInstallation successful. Sign in: https://{self.state['hostname']}/login\nEmail delivery: not configured. Open Mail settings when ready.\nVerified backups: {self.private}/backups\nRecovery package: {self.keys}/recovery-key.enxkey\nRecovery password file (keep separately): {self.state_dir}/recovery-password\nLocal backup destination: {self.backups}\nServer command: sudo {self.name}\nKeep this package to resume or recover the installation.")


def main():
    installer = None
    try:
        installer = Installer(sys.argv[1])
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
