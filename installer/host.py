"""Isolated native-host handoff. Source credentials and shared PHP services are retained untouched."""
import base64
import grp
import hashlib
import json
import os
from pathlib import Path
import pwd
import re
import secrets
import shutil
import stat
import subprocess
import urllib.parse


class HostHandoff:
    def __init__(self, manager):
        self.m = manager
        self.host = manager.installer
        self.name = manager.prefix + '-' + manager.installer.product.removeprefix('emailer-') + '-110'
        self.configuration = Path('/etc') / self.name
        self.runtime = self.configuration / 'runtime.env'
        self.hold = self.configuration / 'installer-hold.json'
        self.state = manager.record.setdefault('host_handoff', {})

    def fail(self, message):
        raise self.m.error(message)

    def database(self, action):
        values = {'action': action, 'bootstrap': str(self.m.bootstrap), 'maintenance_url': self.m.environment['DATABASE_URL']}
        if action != 'drain':
            values.update(self.state['database'])
            values['source_url'] = self.m.source_values['DATABASE_URL']
        return self.m.call(['/usr/bin/php8.4', str(self.m.package.parent / 'host-database.php')], data=json.dumps(values))

    def site_text(self, *, maintenance):
        state = self.state
        address = state['hostname']
        restriction = 'allow 127.0.0.1; allow ::1; deny all;\n    error_page 403 =503 @installing;\n    location @installing { return 503 "Emailer setup is finishing. Please try again shortly."; }' if maintenance else ''
        return f'''server {{
    listen 80;
    server_name {address};
    access_log off;
    {''.join(state.get('acme_locations', []))}
    location / {{ return 301 https://{address}$request_uri; }}
}}
server {{
    listen 443 ssl;
    server_name {address};
    ssl_certificate {state['certificate']};
    ssl_certificate_key {state['certificate_key']};
    root {self.m.releases}/current/public;
    index index.php;
    access_log off;
    {restriction}
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

    def apply_site(self, text):
        path = Path(self.state['site'])
        previous = path.read_bytes()
        self.host.durable(path, text, 0o644)
        check = subprocess.run(['nginx', '-t'], capture_output=True)
        if check.returncode:
            self.host.durable(path, previous, 0o644)
            self.fail('The proposed Emailer website failed Nginx validation. Its previous configuration was restored and Nginx was not reloaded. Keep the saved operation and correct the website conflict before Finish.')
        self.m.call(['systemctl', 'reload', 'nginx'])

    @staticmethod
    def references(line, markers):
        return any(re.search(r'(?<![A-Za-z0-9_./-])' + re.escape(marker) + r'(?=[\s\'";|&]|$)', line) for marker in markers)

    def profile_check(self):
        m = self.m
        url = urllib.parse.urlsplit(m.source_values['DATABASE_URL'])
        if url.hostname not in ('localhost', '127.0.0.1', '::1'):
            self.fail('This hosting profile supports a local MariaDB server. The remote database was preserved and no update was started.')
        for path in [m.private, *m.private.rglob('*')]:
            metadata = path.lstat()
            if path.is_symlink() or not (stat.S_ISREG(metadata.st_mode) or stat.S_ISDIR(metadata.st_mode)):
                self.fail('An application data path contains a link or special object. No update was started.')
        backups = Path(m.source_values.get('EMAILER_BACKUP_DESTINATION_DIR') or '/var/backups/' + m.prefix)
        if backups != Path('/var/backups') / m.prefix and not backups.is_relative_to(m.private):
            self.fail('This hosting profile supports the dedicated local backup folder. The external backup folder requires an explicit service-account transfer before updating; no update was started.')
        if backups.exists() and (backups.is_symlink() or any(path.is_symlink() for path in backups.rglob('*'))):
            self.fail('The backup destination contains a symbolic link. No update was started.')
        markers = (str(m.releases / 'current/bin/console'), '/usr/local/sbin/' + m.prefix, '/usr/local/sbin/' + m.source_service_prefix)
        sources = [path.read_text() for path in Path('/etc/cron.d').iterdir() if path.is_file() and not path.is_symlink()]
        if shutil.which('crontab'):
            for user in set(['root', m.account.pw_name]):
                result = subprocess.run(['crontab', '-u', user, '-l'], capture_output=True, text=True)
                if result.returncode not in (0, 1):
                    self.fail('The current scheduler entries could not be inspected. No update was started.')
                if result.returncode == 0:
                    sources.append(result.stdout)
        for text in sources:
            for line in text.splitlines():
                if not line.lstrip().startswith('#') and self.references(line, markers) and re.search(r'&&|\|\||;|\|', line):
                    self.fail('An Emailer cron entry chains other commands. Separate that entry before updating so unrelated jobs are preserved.')

    def prepare(self):
        m = self.m
        self.profile_check()
        if not self.state:
            uri = urllib.parse.urlsplit(m.values['DEFAULT_URI'])
            if uri.scheme != 'https' or uri.port or uri.path not in ('', '/') or not uri.hostname or not re.fullmatch(r'[a-z0-9.-]+', uri.hostname):
                self.fail('This update hosting profile requires an existing HTTPS hostname without a custom port or application subfolder.')
            sites = []
            for enabled in Path('/etc/nginx/sites-enabled').iterdir():
                path = enabled.resolve()
                if not path.is_file():
                    continue
                text = path.read_text()
                if re.search(r'\broot\s+' + re.escape(str(m.releases / 'current/public')) + r'\s*;', text):
                    if path in sites:
                        continue
                    names = re.findall(r'\bserver_name\s+([^;]+);', text)
                    if not names or any(value.strip() != uri.hostname for value in names):
                        self.fail('The Emailer Nginx file also contains other website names. Split only this site into its own file before using this hosting profile; no site was changed.')
                    sites.append(path)
            if len(sites) != 1:
                self.fail('Setup could not identify exactly one dedicated Emailer HTTPS website. No web server was changed.')
            site = sites[0]
            info = site.lstat()
            if info.st_uid != 0 or info.st_mode & 0o022 or info.st_nlink != 1:
                self.fail('The existing website file is not a protected root-owned file.')
            text = site.read_text()
            certificate = re.findall(r'\bssl_certificate\s+(/[A-Za-z0-9_./-]+);', text)
            certificate_key = re.findall(r'\bssl_certificate_key\s+(/[A-Za-z0-9_./-]+);', text)
            if len(set(certificate)) != 1 or len(set(certificate_key)) != 1:
                self.fail('The dedicated website needs one existing trusted HTTPS certificate. Its certificate configuration was preserved.')
            for path in (self.configuration, Path('/usr/local/sbin') / self.name):
                if path.exists() or path.is_symlink():
                    self.fail('The isolated target service name is already in use. It was not reused.')
            try:
                pwd.getpwnam(self.name)
            except KeyError:
                pass
            else:
                self.fail('The target Unix account already exists and is not owned by this operation.')
            for suffix in ('fpm.service', 'worker.service', 'scheduler.service', 'scheduler.timer', 'operations.service', 'operations.timer'):
                status = subprocess.run(['systemctl', 'show', self.name + '-' + suffix, '--property=LoadState'], capture_output=True, text=True).stdout
                if 'LoadState=not-found' not in status:
                    self.fail('An isolated target service name is already occupied. No service was changed.')
            original = m.original_configuration
            self.state.update({'phase': 'reserved', 'hostname': uri.hostname, 'site': str(site), 'site_bytes': base64.b64encode(site.read_bytes()).decode(),
                               'acme_locations': re.findall(r'location\s+\^~\s+/\.well-known/acme-challenge/\s*\{\s*root\s+/[A-Za-z0-9_./-]+;\s*\}', text), 'certificate': certificate[0], 'certificate_key': certificate_key[0], 'source_configuration': str(original),
                               'source_configuration_sha256': hashlib.sha256(original.read_bytes()).hexdigest(),
                               'source_version': m.source_version, 'source_product': m.product,
                               'installation_uuid': json.loads((m.private / 'install.lock').read_text())['installation_uuid'],
                               'database': {'name': 'em_' + secrets.token_hex(10), 'password': secrets.token_hex(32), 'host': 'localhost' if 'unix_socket' in urllib.parse.parse_qs(urllib.parse.urlsplit(m.source_values['DATABASE_URL']).query) else '127.0.0.1'}, 'cron': {}})
            launcher = Path('/usr/local/sbin') / m.prefix
            self.state['launcher_bytes'] = base64.b64encode(launcher.read_bytes()).decode() if launcher.exists() else None
            pointer = m.root / 'active-installation.json'
            if pointer.exists() or pointer.is_symlink():
                from manage import protected
                protected(pointer)
                metadata = pointer.lstat()
                self.state['source_active_pointer'] = {'bytes': base64.b64encode(pointer.read_bytes()).decode(), 'mode': stat.S_IMODE(metadata.st_mode), 'uid': metadata.st_uid, 'gid': metadata.st_gid}
            else:
                self.state['source_active_pointer'] = None
            m.save()  # Reserve all generated identities before creating resources.
        if hashlib.sha256(m.original_configuration.read_bytes()).hexdigest() != self.state['source_configuration_sha256']:
            self.fail('The original application configuration changed after this operation began. It was preserved; Finish needs its original recovery evidence.')
        try:
            account = pwd.getpwnam(self.name)
        except KeyError:
            m.call(['useradd', '--system', '--user-group', '--home-dir', str(m.private.parent), '--shell', '/usr/sbin/nologin', self.name])
            account = pwd.getpwnam(self.name)
        if account.pw_uid == 0 or account.pw_name != self.name or grp.getgrgid(account.pw_gid).gr_name != self.name:
            self.fail('The reserved service identity has unexpected ownership.')
        self.configuration.mkdir(mode=0o750, exist_ok=True)
        metadata = self.configuration.lstat()
        if self.configuration.is_symlink() or metadata.st_uid != 0 or metadata.st_mode & 0o022:
            self.fail('The isolated target configuration folder is unsafe.')
        os.chown(self.configuration, 0, account.pw_gid)
        self.host.durable(self.hold, json.dumps({'format': 'emailer-installer-hold-v1', 'installation_uuid': self.state['installation_uuid'], 'phase': 'host-handoff'}) + '\n', 0o644)
        if not self.state.get('database_prepared'):
            self.database('create')
            try:
                self.database('unlock')
                self.database('probe')
            finally:
                self.database('lock')
            self.state['database_prepared'] = True
            m.save()
        m.environment['EMAILER_INSTALLER_RETAIN_SOURCE_FENCE'] = '1'
        m.environment['EMAILER_INSTALLER_REPLACEMENT_RUNTIME'] = str(self.runtime)
        m.environment['EMAILER_INSTALLER_HOLD_FILE'] = str(self.hold)

    def drain(self):
        print('Pausing new delivery and waiting for any message already in progress …', flush=True)
        self.apply_site(self.site_text(maintenance=True))
        self.database('drain')
        self.m.stop_services()
        if self.state.get('phase') == 'recovered' and 'runtime_metadata_before' in self.state:
            self.state.setdefault('runtime_metadata_history', []).append(self.state.pop('runtime_metadata_before'))
            self.state['phase'] = 'retrying-preparation'
            self.m.save()
        try:
            self.secure_runtime_metadata()
        except OSError:
            self.fail('A generated cache or lock path became unsafe or unavailable. Keep the saved operation and choose Recover before a deliberate retry.')

    def secure_runtime_metadata(self):
        m = self.m
        runtime = Path(m.source_values.get('EMAILER_RUNTIME_DIR') or m.private)
        allowed_runtime = m.private / ('runtime-' + getattr(m, 'source_service_prefix', m.prefix))
        if runtime not in (m.private, allowed_runtime):
            self.fail('The runtime folder is outside the dedicated generated-file layout. It was preserved; no permissions were changed.')
        roots = [runtime / 'cache', runtime / 'log']
        lock_dsn = m.source_values.get('LOCK_DSN', '')
        if lock_dsn.startswith('flock://'):
            lock_root = Path(lock_dsn.removeprefix('flock://'))
            if lock_root != m.private / 'locks':
                self.fail('The lock setting points outside the dedicated private locks folder. It was preserved; no permissions were changed.')
            roots.append(lock_root)
        flags = os.O_RDONLY | os.O_DIRECTORY | os.O_NOFOLLOW | os.O_CLOEXEC

        def directory(path):
            if not path.is_absolute() or '..' in path.parts:
                self.fail('The runtime directory is not a normalized absolute path.')
            descriptor = os.open('/', flags)
            try:
                for part in path.parts[1:]:
                    child = os.open(part, flags, dir_fd=descriptor)
                    os.close(descriptor)
                    descriptor = child
                return descriptor
            except BaseException:
                os.close(descriptor)
                raise

        def digest(descriptor):
            value = hashlib.sha256()
            offset = 0
            while True:
                data = os.pread(descriptor, 65536, offset)
                if not data:
                    return value.hexdigest()
                value.update(data)
                offset += len(data)

        def inspect(descriptor):
            info = os.fstat(descriptor)
            is_directory = stat.S_ISDIR(info.st_mode)
            if info.st_uid not in (0, m.account.pw_uid) or not (is_directory or (stat.S_ISREG(info.st_mode) and info.st_nlink == 1)):
                self.fail('A generated runtime object has unexpected ownership, links or type. No application data was converted.')
            return {'uid': info.st_uid, 'gid': info.st_gid, 'mode': stat.S_IMODE(info.st_mode), 'device': info.st_dev, 'inode': info.st_ino, 'directory': is_directory, 'sha256': None if is_directory else digest(descriptor)}

        def open_relative(parent, parts):
            descriptor = os.dup(parent)
            try:
                for index, name in enumerate(parts):
                    child = os.open(name, os.O_RDONLY | os.O_NOFOLLOW | os.O_CLOEXEC | os.O_NONBLOCK | (os.O_DIRECTORY if index < len(parts) - 1 else 0), dir_fd=descriptor)
                    os.close(descriptor)
                    descriptor = child
                return descriptor
            except BaseException:
                os.close(descriptor)
                raise

        entries = {}
        private = directory(m.private)
        try:
            anchor = os.fstat(private)
            for root in roots:
                if not root.is_relative_to(m.private) or root == m.private or '..' in root.parts:
                    self.fail('The runtime uses a shared or external cache/lock folder. Its permissions were preserved; this hosting handoff requires a dedicated private runtime.')
                parts = root.relative_to(m.private).parts
                try:
                    descriptor = open_relative(private, parts)
                except FileNotFoundError:
                    continue
                if not stat.S_ISDIR(os.fstat(descriptor).st_mode):
                    os.close(descriptor)
                    self.fail('A generated runtime root is not a directory. It was preserved.')
                def walk(current, relative):
                    metadata = inspect(current)
                    entries[str(relative)] = metadata
                    if metadata['directory']:
                        for name in os.listdir(current):
                            child = os.open(name, os.O_RDONLY | os.O_NOFOLLOW | os.O_CLOEXEC | os.O_NONBLOCK, dir_fd=current)
                            try:
                                walk(child, relative / name)
                            finally:
                                os.close(child)
                try:
                    walk(descriptor, Path(*parts))
                finally:
                    os.close(descriptor)
            if 'runtime_metadata_before' in self.state:
                retained = self.state['runtime_metadata_before']
                if set(retained) != set(entries):
                    self.fail('The saved generated runtime inventory changed. Choose Recover to reopen the unchanged source before a deliberate retry.')
                for name, original in retained.items():
                    current = entries[name]
                    identity = ('device', 'inode', 'directory', 'sha256')
                    original_mode = (original['uid'], original['gid'], original['mode'])
                    repaired_mode = (m.account.pw_uid, m.account.pw_gid, 0o700 if original['directory'] else 0o600)
                    intermediate_mode = (m.account.pw_uid, m.account.pw_gid, original['mode'])
                    if any(current[key] != original[key] for key in identity) or (current['uid'], current['gid'], current['mode']) not in (original_mode, intermediate_mode, repaired_mode):
                        self.fail('A saved runtime file drifted after interrupted permission preparation. Choose Recover; the old receipt was retained unchanged.')
            else:
                self.state['runtime_metadata_before'] = entries
            m.save()  # Durable evidence precedes metadata changes; no byte or lock inode is replaced.
            for name, recorded in entries.items():
                descriptor = open_relative(private, Path(name).parts)
                try:
                    if inspect(descriptor) != recorded:
                        self.fail('A generated runtime object changed during permission preparation. Keep the saved operation; no business data was converted.')
                    os.fchown(descriptor, m.account.pw_uid, m.account.pw_gid)
                    os.fchmod(descriptor, 0o700 if recorded['directory'] else 0o600)
                    info = os.fstat(descriptor)
                    if (info.st_dev, info.st_ino, info.st_uid, info.st_gid, stat.S_IMODE(info.st_mode)) != (recorded['device'], recorded['inode'], m.account.pw_uid, m.account.pw_gid, 0o700 if recorded['directory'] else 0o600) or (not recorded['directory'] and (info.st_nlink != 1 or digest(descriptor) != recorded['sha256'])):
                        self.fail('A generated runtime file changed while its permissions were secured. Keep the operation for recovery.')
                finally:
                    os.close(descriptor)
            for name, recorded in entries.items():
                descriptor = open_relative(private, Path(name).parts)
                try:
                    current = inspect(descriptor)
                    if any(current[key] != recorded[key] for key in ('device', 'inode', 'directory', 'sha256')) or (current['uid'], current['gid'], current['mode']) != (m.account.pw_uid, m.account.pw_gid, 0o700 if current['directory'] else 0o600):
                        self.fail('The generated runtime inventory moved or changed during preparation.')
                    if current['directory']:
                        expected_children = {Path(other).name for other in entries if Path(other).parent == Path(name)}
                        if set(os.listdir(descriptor)) != expected_children:
                            self.fail('A generated runtime directory changed during preparation. Choose Recover before retrying.')
                finally:
                    os.close(descriptor)
            check = directory(m.private)
            try:
                observed = os.fstat(check)
                if (observed.st_dev, observed.st_ino) != (anchor.st_dev, anchor.st_ino):
                    self.fail('The private runtime directory moved during preparation. Keep the saved operation; no business data was converted.')
            finally:
                os.close(check)
        finally:
            os.close(private)

    def adopt(self):
        m = self.m
        self.prepare()
        if self.state.get('phase') == 'complete':
            return
        account = pwd.getpwnam(self.name)
        self.database('verify')
        keys = self.configuration / 'keys'
        keys.mkdir(mode=0o700, exist_ok=True)
        os.chown(keys, account.pw_uid, account.pw_gid)
        values = dict(m.source_values)
        key_paths = ['EMAILER_SECRET_KEY_FILE', 'EMAILER_RECOVERY_KEY_FILE']
        # The edition layer supplies its generated recovery path without changing the old runtime file.
        for name in key_paths:
            source = Path(m.environment.get(name, values.get(name, '')))
            if not source.is_file() or source.is_symlink():
                self.fail('The verified recovery material is missing. Keep delivery held and Finish after restoring its saved file.')
            target = keys / source.name
            if target.exists() and target.read_bytes() != source.read_bytes():
                self.fail('The retained target encryption material differs. It was not replaced.')
            self.host.durable(target, source.read_bytes(), 0o600)
            os.chown(target, account.pw_uid, account.pw_gid)
            values[name] = str(target)
        pin = self.configuration / 'release-signing.pub'
        self.host.durable(pin, (m.package.parent / 'release.pub').read_bytes(), 0o644)
        original_url = urllib.parse.urlsplit(values['DATABASE_URL'])
        if original_url.hostname not in ('localhost', '127.0.0.1', '::1'):
            self.fail('This host handoff supports a local MariaDB server. The remote database configuration was preserved.')
        database = self.state['database']
        netloc = database['name'] + ':' + database['password'] + '@' + database['host'] + ':' + str(original_url.port or 3306)
        values.update({'DATABASE_URL': urllib.parse.urlunsplit((original_url.scheme, netloc, original_url.path, original_url.query, '')),
                       'EMAILER_RUNTIME_CONFIGURATION_FILE': str(self.runtime), 'EMAILER_RELEASE_PUBLIC_KEY_FILE': str(pin),
                       'EMAILER_RUNTIME_DIR': str(m.private / ('runtime-' + self.name)), 'LOCK_DSN': 'flock://' + str(m.private / 'locks'), 'EMAILER_HTTP_MODE': 'direct-fpm', 'TRUSTED_PROXIES': '',
                       'EMAILER_INSTALLER_HOLD_FILE': str(self.hold)})
        for name in ('EMAILER_UPDATE_SOURCE_VERSION', 'EMAILER_INSTALLER_REPLACEMENT_RUNTIME', 'EMAILER_INSTALLER_RETAIN_SOURCE_FENCE'):
            values.pop(name, None)
        for path in [m.private, *m.private.rglob('*')]:
            metadata = path.lstat()
            if path.is_symlink() or not (stat.S_ISREG(metadata.st_mode) or stat.S_ISDIR(metadata.st_mode)):
                self.fail('An application data path contains a link or special object. It was retained for recovery review.')
        for path in [m.private, *m.private.rglob('*')]:
            os.chown(path, account.pw_uid, account.pw_gid)
        backups = Path(values.get('EMAILER_BACKUP_DESTINATION_DIR') or '/var/backups/' + m.prefix)
        values['EMAILER_BACKUP_DESTINATION_DIR'] = str(backups)
        if backups != Path('/var/backups') / m.prefix and not backups.is_relative_to(m.private):
            self.fail('The external backup folder needs a separate explicit transfer to the new service account. Its contents were preserved.')
        if backups.exists():
            if 'backup_metadata' not in self.state:
                self.state['backup_metadata'] = {str(path): [path.stat().st_uid, path.stat().st_gid, path.stat().st_mode & 0o777] for path in [backups, *backups.rglob('*')]}
                m.save()
            for path in [backups, *backups.rglob('*')]:
                if path.is_symlink():
                    self.fail('The backup destination contains a symbolic link. It was preserved.')
                os.chown(path, account.pw_uid, account.pw_gid)
        else:
            backups.mkdir(mode=0o700, parents=True)
            os.chown(backups, account.pw_uid, account.pw_gid)
        self.host.durable(self.runtime, ''.join(name + '=' + json.dumps(value, ensure_ascii=False).replace('$', '\\$') + '\n' for name, value in sorted(values.items())), 0o640)
        os.chown(self.runtime, 0, account.pw_gid)
        self.database('unlock')
        self.state['phase'] = 'target-prepared'
        m.save()
        self.apply_site(self.site_text(maintenance=True))
        # Reuse the reviewed fresh service generator with entirely separate unit/config identities.
        host = self.host
        host.name = self.name
        host.user = self.name
        host.config = self.configuration
        host.private = m.private
        host.keys = keys
        host.backups = backups
        host.base = m.releases.parent
        host.release = (m.releases / 'current').resolve(strict=True)
        host.php = '/usr/bin/php8.4'
        host.services()
        launcher = Path('/usr/local/sbin') / m.prefix
        generated = Path('/usr/local/sbin') / self.name
        self.host.durable(launcher, generated.read_bytes(), 0o755)
        self.state['phase'] = 'services-prepared'
        m.save()
        self.live(['emailer:health:verify-runtime', '--offline-maintenance'])
        m.call(['curl', '--fail', '--silent', '--show-error', '--resolve', self.state['hostname'] + ':443:127.0.0.1', 'https://' + self.state['hostname'] + '/health/live'])
        m.call(['curl', '--fail', '--silent', '--show-error', '--resolve', self.state['hostname'] + ':443:127.0.0.1', 'https://' + self.state['hostname'] + '/login'])

    def live(self, arguments):
        return self.m.call(['/usr/local/sbin/' + self.name, *arguments])

    def complete(self):
        m = self.m
        # Called only after the core has durably verified its replacement account / acceptance.
        self.state['phase'] = 'core-accepted'
        m.save()
        for unit in m.record.get('source_units', m.record.get('active_units', [])):
            if unit.startswith(m.prefix + '-') and not unit.startswith(self.name + '-'):
                m.call(['systemctl', 'disable', unit])
        self.disable_cron()
        self.host.durable(m.root / 'active-installation.json', json.dumps({'format': 'emailer-active-installation-v1', 'runtime': str(self.runtime), 'product': self.host.product, 'version': self.host.version}) + '\n')
        # Only this root-controlled operation can lift the host hold. Delivery itself stays quiesced.
        self.hold.unlink(missing_ok=True)
        self.live(['emailer:health:verify-runtime', '--allow-operational-hold'])
        self.apply_site(self.site_text(maintenance=False))
        self.state['phase'] = 'complete'
        m.record.pop('active_units', None)
        m.save()

    def disable_cron(self):
        # Retain every unrelated entry verbatim. Never replace an entire shared crontab.
        m = self.m
        markers = (str(m.releases / 'current/bin/console'), '/usr/local/sbin/' + m.prefix, '/usr/local/sbin/' + m.source_service_prefix)
        sources = [('file', str(path)) for path in Path('/etc/cron.d').iterdir() if path.is_file() and not path.is_symlink()]
        if shutil.which('crontab'):
            sources += [('user', name) for name in set(['root', m.account.pw_name])]
        for kind, name in sources:
            key = kind + ':' + name
            if kind == 'file':
                path = Path(name)
                text = path.read_text()
            else:
                result = subprocess.run(['crontab', '-u', name, '-l'], capture_output=True, text=True)
                if result.returncode == 1:
                    continue
                if result.returncode:
                    self.fail('The previous scheduler entries could not be inspected; Finish needs access to the local crontab utility.')
                text = result.stdout
            changed = []
            for line in text.splitlines(keepends=True):
                if not line.lstrip().startswith('#') and self.references(line, markers):
                    if re.search(r'&&|\|\||;|\|', line):
                        self.fail('An Emailer cron entry also chains other commands. Separate that entry before Finish so unrelated jobs remain unchanged.')
                    changed.append('# Emailer installer retained old entry: ' + line)
                else:
                    changed.append(line)
            updated = ''.join(changed)
            if updated == text:
                continue
            if key not in self.state['cron']:
                self.state['cron'][key] = text
                m.save()
            if kind == 'file':
                self.host.durable(name, updated, Path(name).stat().st_mode & 0o777)
            else:
                m.call(['crontab', '-u', name, '-'], data=updated)

    def stop_target(self):
        if self.state.get('phase') in ('core-accepted', 'complete'):
            self.fail('This operation was accepted. Finish host setup; recovery cannot downgrade it.')
        for suffix in ('scheduler.timer', 'operations.timer', 'scheduler.service', 'operations.service', 'worker.service', 'fpm.service'):
            unit = self.name + '-' + suffix
            state = subprocess.run(['systemctl', 'show', unit, '--property=LoadState'], capture_output=True, text=True)
            if 'LoadState=not-found' in state.stdout:
                continue
            if state.returncode or 'LoadState=loaded' not in state.stdout:
                self.fail('The target service state could not be inspected. Restoration was not started; restore access to systemd before Recover.')
            self.m.call(['systemctl', 'stop', unit])
            active = subprocess.run(['systemctl', 'is-active', '--quiet', unit])
            if active.returncode not in (3, 4):
                self.fail('A target service is still active. Data restoration was not started.')
        if self.runtime.exists():
            self.database('lock')

    def recover_host(self):
        m = self.m
        if not self.state:
            return
        if self.state.get('phase') in ('core-accepted', 'complete'):
            self.fail('This operation was accepted. Finish host setup; recovery cannot downgrade a completed installation.')
        if 'database' in self.state and self.runtime.exists():
            self.database('lock')
        for suffix in ('scheduler.timer', 'operations.timer', 'scheduler.service', 'operations.service', 'worker.service', 'fpm.service'):
            subprocess.run(['systemctl', 'stop', self.name + '-' + suffix], capture_output=True)
        for name, metadata in self.state.get('backup_metadata', {}).items():
            path = Path(name)
            if path.is_symlink():
                self.fail('A retained backup path became a link. Source data was recovered, but host ownership restoration needs review.')
            if path.exists():
                os.chown(path, metadata[0], metadata[1])
                os.chmod(path, metadata[2])
        self.apply_site(base64.b64decode(self.state['site_bytes']))
        saved = self.state.get('launcher_bytes')
        if saved is not None:
            self.host.durable('/usr/local/sbin/' + m.prefix, base64.b64decode(saved), 0o755)
        # The core restores source data/key ownership before this method is called.
        self.hold.unlink(missing_ok=True)
        pointer = m.root / 'active-installation.json'
        if 'source_active_pointer' not in self.state:
            self.fail('This operation lacks its previous active-installation receipt. The source data was retained; restore the exact protected host receipt before reopening the site.')
        saved_pointer = self.state['source_active_pointer']
        if saved_pointer is None:
            pointer.unlink(missing_ok=True)
        else:
            self.host.durable(pointer, base64.b64decode(saved_pointer['bytes']), saved_pointer['mode'])
            os.chown(pointer, saved_pointer['uid'], saved_pointer['gid'])
        self.state['phase'] = 'recovered'
        m.save()
