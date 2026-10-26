"""Isolated native-host handoff. Source credentials and shared PHP services are retained untouched."""
import base64
import contextlib
import fcntl
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
        self.name = manager.prefix + '-' + manager.installer.product.removeprefix('emailer-') + '-' + getattr(manager.installer, 'version', '1.1.0').replace('.', '')
        self.configuration = Path('/etc') / self.name
        self.runtime = self.configuration / 'runtime.env'
        self.hold = self.configuration / 'installer-hold.json'
        self.state = manager.record.setdefault('host_handoff', {})

    def claims(self):
        from resources import ResourceClaims
        return ResourceClaims(self.state.setdefault('resource_claims', {}), self.m.save, self.m.error)

    def fail(self, message):
        raise self.m.error(message)

    def database(self, action):
        values = {'action': action, 'bootstrap': str(self.m.bootstrap), 'maintenance_url': self.m.environment['DATABASE_URL'], 'source_url': self.m.source_values['DATABASE_URL']}
        if action not in ('drain', 'begin-update', 'inspect-update', 'restore-idle-update', 'retain-update-hold'):
            values.update(self.state['database'])
            values['source_url'] = self.m.source_values['DATABASE_URL']
        if action in ('begin-update', 'inspect-update', 'restore-idle-update', 'retain-update-hold'):
            values.update(state_file=str(self.update_state_file), operation=str(self.m.directory))
        return self.m.call([self.host.php, str(self.m.package.parent / 'host-database.php')], data=json.dumps(values))

    def web(self):
        from hosting import WebServer
        return WebServer(self.state.get('web_server', getattr(getattr(self.host, 'web', None), 'kind', 'nginx')), self.m.error)

    def site_text(self, *, maintenance):
        state = self.state
        acme = state.get('acme_webroot')
        if not acme:
            roots = re.findall(r'root\s+(/[A-Za-z0-9_./-]+);', ' '.join(state.get('acme_locations', [])))
            acme = roots[0] if roots else None
        return self.web().site(hostname=state['hostname'], name=self.name, public=str(self.m.releases / 'current/public'), webroot=acme,
                               certificate=state['certificate'], certificate_key=state['certificate_key'], maintenance=maintenance)

    def apply_site(self, text):
        path = Path(self.state['site'])
        self.claims().check_all()
        self.web().check_assignment(self.state['hostname'], path)
        previous = path.read_bytes()
        self.claims().write(path, text, 0o644)
        check = subprocess.run(self.web().check_command, capture_output=True)
        if check.returncode:
            self.claims().write(path, previous, 0o644)
            self.fail('The proposed Emailer website failed web-server validation. Its previous configuration was restored and the web server was not reloaded. Keep the saved operation and correct the website conflict before Finish.')
        self.m.call(['systemctl', 'reload', self.web().service])

    @staticmethod
    def references(line, markers):
        return any(re.search(r'(?<![A-Za-z0-9_./-])' + re.escape(marker) + r'(?=[\s\'";|&]|$)', line) for marker in markers)

    def profile_check(self):
        m = self.m
        m.verify_source_web_runtime()
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
        m.validate_launcher()
        if not self.state:
            uri = urllib.parse.urlsplit(m.values['DEFAULT_URI'])
            if uri.scheme != 'https' or uri.port or uri.path not in ('', '/') or not uri.hostname or not re.fullmatch(r'[a-z0-9.-]+', uri.hostname):
                self.fail('This update hosting profile requires an existing HTTPS hostname without a custom port or application subfolder.')
            web = self.host.web
            site, certificate, certificate_key, acme_webroot = web.source_site(m.releases, uri.hostname)
            info = site.lstat()
            if info.st_uid != 0 or info.st_mode & 0o022 or info.st_nlink != 1:
                self.fail('The existing website file is not a protected root-owned file.')
            text = site.read_text()
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
                               'web_server': web.kind, 'acme_webroot': acme_webroot, 'certificate': certificate, 'certificate_key': certificate_key, 'source_configuration': str(original),
                               'source_configuration_sha256': hashlib.sha256(original.read_bytes()).hexdigest(),
                               'source_version': m.source_version, 'source_product': m.product, 'source_user': m.account.pw_name,
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
            # Adoption is bounded to the just-validated source site and launcher.
            claims = self.claims()
            enabled_links = [link for link in (web.directory / 'sites-enabled').iterdir() if link.is_symlink() and link.resolve() == site]
            for adopted in (site, launcher, pointer, *enabled_links):
                self.state['resource_claims'][str(adopted)] = {'current': claims.proof(adopted)}
            m.save()  # Source proofs and identities are durable before any host mutation.
        self.claims().check_all()
        reserved = [self.configuration, self.configuration / 'fpm.conf', self.configuration / 'release-signing.pub', self.configuration / 'keys', self.hold, Path('/usr/local/sbin') / self.name]
        if self.state.get('phase') != 'complete':
            reserved.append(self.runtime)
        reserved += [Path('/etc/systemd/system') / (self.name + '-' + suffix) for suffix in ('fpm.service', 'worker.service', 'scheduler.service', 'scheduler.timer', 'operations.service', 'operations.timer')]
        self.claims().reserve(reserved)
        self.host.resource_claims = self.claims()
        for suffix in ('fpm.service', 'worker.service', 'scheduler.service', 'scheduler.timer', 'operations.service', 'operations.timer'):
            self.host.check_unit(self.name + '-' + suffix)
        if hashlib.sha256(m.original_configuration.read_bytes()).hexdigest() != self.state['source_configuration_sha256']:
            self.fail('The original application configuration changed after this operation began. It was preserved; Finish needs its original recovery evidence.')
        marker = 'emailer-update-' + self.state['installation_uuid'] + '-' + self.name
        try:
            account = pwd.getpwnam(self.name)
        except KeyError:
            m.call(['useradd', '--system', '--user-group', '--comment', marker, '--home-dir', str(m.private.parent), '--shell', '/usr/sbin/nologin', self.name])
            account = pwd.getpwnam(self.name)
        if account.pw_gecos != marker or account.pw_dir != str(m.private.parent) or account.pw_shell != '/usr/sbin/nologin':
            self.fail('The reserved target service account was created or changed outside this operation. It was preserved; restore the operation-owned account or move the conflicting account before retrying.')
        source_query = urllib.parse.parse_qs(urllib.parse.urlsplit(m.source_values['DATABASE_URL']).query)
        if source_query.get('unix_socket') == ['/run/emailer-database/mysql.sock']:
            m.call(['usermod', '--append', '--groups', 'emailer-db', self.name])
        if account.pw_uid == 0 or account.pw_name != self.name or grp.getgrgid(account.pw_gid).gr_name != self.name:
            self.fail('The reserved service identity has unexpected ownership.')
        from provision import root_directory
        self.claims().directory(self.configuration, 0, account.pw_gid, 0o750)
        root_directory(self.configuration, account.pw_gid, 0o750)
        self.claims().write(self.hold, json.dumps({'format': 'emailer-installer-hold-v1', 'installation_uuid': self.state['installation_uuid'], 'phase': 'host-handoff'}) + '\n', 0o644)
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

    @property
    def update_state_file(self):
        return self.m.private.parent / 'update-maintenance.json'

    def begin_update_delivery(self):
        snapshot = json.loads(self.database('begin-update'))
        if snapshot.get('format') != 'emailer-update-delivery-v1' or snapshot.get('phase') not in ('in_progress', 'blocked', 'failed', 'resuming'):
            self.fail('Delivery state could not be verified. Sending remains blocked; keep the operation and choose Recover.')
        self.state['delivery_snapshot'] = snapshot
        self.m.save()
        active = [item for item in snapshot['campaigns'] if snapshot['prior_accepting_work'] == 1 and item['state'] == 'sending' and item['remaining'] > 0]
        if active:
            print('Active delivery paused for this update.', flush=True)
            for campaign in active:
                print(f"Campaign {campaign['id']}: {campaign['sent']} SMTP accepted; {campaign['remaining']} remaining (waiting, retrying or in flight).", flush=True)
        if snapshot['active_claims']:
            print(f"Waiting for {snapshot['active_claims']} in-flight campaign message(s). Uncertain outcomes will not be retried automatically.", flush=True)
        tests = [item for item in snapshot.get('test_work', []) if item['state'] == 'processing']
        if tests:
            print(f'Waiting for {len(tests)} explicitly requested test message(s) already in progress.', flush=True)

    def update_delivery_phase(self, phase, reason='', *, explicit=False):
        from manage import protected
        snapshot = json.loads(protected(self.update_state_file, public=True).read_text())
        if snapshot.get('operation') != str(self.m.directory) or snapshot.get('format') != 'emailer-update-delivery-v1':
            self.fail('A different operation owns the update hold. Its evidence was preserved.')
        if phase == 'recovered':
            snapshot['recovered_prior_accepting_work'] = snapshot['prior_accepting_work']
            snapshot['prior_accepting_work'] = 0
        snapshot['phase'] = phase
        if explicit:
            snapshot['explicit_review'] = True
        snapshot['reason'] = reason[:1200]
        from provision import durable
        durable(self.update_state_file, json.dumps(snapshot, indent=2) + '\n', mode=0o644)
        self.state['delivery_snapshot'] = snapshot
        self.m.save()

    def current_update_snapshot(self):
        from manage import protected
        if not self.update_state_file.exists() and not self.update_state_file.is_symlink():
            return None
        snapshot = json.loads(protected(self.update_state_file, public=True).read_text())
        if snapshot.get('format') != 'emailer-update-delivery-v1':
            self.fail('The saved update delivery evidence is invalid. Keep its recovery material.')
        if snapshot.get('operation') != str(self.m.directory):
            if snapshot.get('phase') == 'complete':
                return None  # A previous completed update does not own this operation.
            self.fail('Another update owns the delivery state. Keep its evidence and finish or recover that update first.')
        return snapshot

    def restore_update_delivery(self):
        # Edition transitions retain their separate, explicit acceptance contract.
        if self.m.product != self.host.product:
            return
        snapshot = self.current_update_snapshot()
        if not snapshot:
            print('The earlier setup did not record the prior delivery state. Its hold was retained; review readiness before using Resume delivery.')
            return
        if snapshot.get('phase') == 'complete':
            return
        try:
            report = json.loads(self.live(['emailer:health:verify-runtime', '--allow-operational-hold']))
            if not report.get('runtime_verified'):
                raise self.m.error('Runtime verification did not pass. Open Doctor, repair its findings, then choose Finish.')
            # This read-only check also rejects uncertain ledger outcomes and any
            # remaining campaign/test transport activity before lifting a hold.
            inspection = json.loads(self.database('inspect-update'))
            if inspection['active_claims'] or inspection['processing'] or inspection['test_processing'] or inspection['uncertain']:
                raise self.m.error('Delivery has in-flight or uncertain messages. Sending remains blocked. Review delivery history; uncertain messages will not be retried. Use Resume delivery only after this review.')
            if snapshot['prior_accepting_work'] == 0:
                self.update_delivery_phase('complete')
                return  # Preserve an existing operational hold without another prompt.
            if self.m.record.get('source_web_drain_uncertain') and not snapshot.get('explicit_review'):
                raise self.m.error('An earlier web request did not have a clean exit. Review any interrupted SMTP test in delivery history, then use Resume delivery explicitly. No uncertain message will be retried automatically.')
            active = snapshot['active_claims'] > 0 or any(item['state'] == 'sending' and item['remaining'] > 0 for item in snapshot['campaigns'])
            if active:
                findings = [item for item in report.get('findings', []) if item.get('sending_blocked') and item.get('severity') == 'critical' and item.get('code') != 'delivery_quiesced']
                if findings:
                    reasons = '; '.join(item['summary'] + ' ' + item['remediation'] for item in findings)
                    raise self.m.error(reasons)
            self.update_delivery_phase('resuming')
            if snapshot.get('phase') == 'resuming' and inspection['accepting_work'] == 1:
                self.live(['emailer:health:verify-runtime', '--allow-operational-hold', '--record'])
                self.update_delivery_phase('complete')
                return  # Exact operation already committed resume before a crash.
            if active:
                # Retain the normal maintenance lock, edition hold and readiness
                # checks. Never approve, queue, or change any campaign state.
                self.live(['emailer:delivery:resume', '--no-interaction'])
            else:
                # Idle installations may restore accepting_work even without
                # optional SMTP. Every future send still uses normal readiness.
                self.database('restore-idle-update')
            self.live(['emailer:health:verify-runtime', '--allow-operational-hold', '--record'])
            self.update_delivery_phase('complete')
        except self.m.error as error:
            self.database('retain-update-hold')
            self.update_delivery_phase('blocked', str(error) + ' Start the same setup package and choose Finish after repairing this issue.')
            print('Sending remains blocked: ' + str(error), flush=True)

    @contextlib.contextmanager
    def source_maintenance_lock(self):
        # Use the published application's actual Symfony flock, including old
        # predecessors whose Resume command predates update-state awareness.
        setting = self.m.source_values.get('LOCK_DSN', '')
        expected = self.m.private / 'locks'
        if setting != 'flock://' + str(expected):
            self.fail('The source does not use its dedicated shared maintenance-lock directory. No delivery state was changed. Use the supported native profile before updating; a process-private temporary lock cannot safely coordinate web and background work.')
        key = 'emailer-maintenance-operation'
        suffix = base64.b64encode(hashlib.sha256(key.encode()).digest()).decode()[:7].replace('/', '_')
        parent = os.open(expected, os.O_RDONLY | os.O_DIRECTORY | os.O_NOFOLLOW | os.O_CLOEXEC)
        descriptor = None
        name = 'sf.' + key + '.' + suffix + '.lock'
        try:
            try:
                descriptor = os.open(name, os.O_RDWR | os.O_NOFOLLOW | os.O_CLOEXEC, dir_fd=parent)
            except FileNotFoundError:
                descriptor = os.open(name, os.O_RDWR | os.O_CREAT | os.O_EXCL | os.O_NOFOLLOW | os.O_CLOEXEC, 0o600, dir_fd=parent)
                os.fchown(descriptor, self.m.account.pw_uid, self.m.account.pw_gid)
                os.fsync(descriptor)
                os.fsync(parent)
            metadata = os.fstat(descriptor)
            if not stat.S_ISREG(metadata.st_mode) or metadata.st_nlink != 1 or metadata.st_uid not in (0, self.m.account.pw_uid):
                self.fail('The shared maintenance lock is unsafe. No update was started.')
            try:
                fcntl.flock(descriptor, fcntl.LOCK_EX | fcntl.LOCK_NB)
            except BlockingIOError:
                self.fail('Another maintenance action is in progress. Wait for it to finish, then run this setup file again. No delivery was paused.')
            yield
        finally:
            if descriptor is not None:
                os.close(descriptor)
            os.close(parent)

    def drain(self):
        with self.source_maintenance_lock():
            self.m.verify_source_web_runtime()
            if self.m.product == self.host.product:
                self.begin_update_delivery()
            self.apply_site(self.site_text(maintenance=True))
            # Drain legacy synchronous mail tests before backup or schema work.
            # This never stops a shared or untracked PHP runtime.
            self.m.stop_services(web_only=True)
            self.database('drain')
            self.m.stop_services()
            # Legacy test handlers did not serialize claims on delivery_runtime.
            # Recheck after all source processes stop; never clear uncertain work.
            self.database('drain')
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
        self.claims().directory(keys, account.pw_uid, account.pw_gid, 0o700)
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
        self.claims().write(pin, (m.package.parent / 'release.pub').read_bytes(), 0o644)
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
        self.claims().write(self.runtime, ''.join(name + '=' + json.dumps(value, ensure_ascii=False).replace('$', '\\$') + '\n' for name, value in sorted(values.items())), 0o640, gid=account.pw_gid)
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
        host.database_profile = 'isolated' if urllib.parse.parse_qs(original_url.query).get('unix_socket') == ['/run/emailer-database/mysql.sock'] else 'shared'
        host.resource_claims = self.claims()
        host.services()
        launcher = Path('/usr/local/sbin') / m.prefix
        generated = Path('/usr/local/sbin') / self.name
        self.claims().write(launcher, generated.read_bytes(), 0o755)
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
        self.claims().write(m.root / 'active-installation.json', json.dumps({'format': 'emailer-active-installation-v1', 'runtime': str(self.runtime), 'product': self.host.product, 'version': self.host.version}) + '\n', 0o600)
        # Only this root-controlled operation can lift the host hold. Delivery itself stays quiesced.
        self.claims().remove(self.hold)
        self.live(['emailer:health:verify-runtime', '--allow-operational-hold', '--record'])
        self.apply_site(self.site_text(maintenance=False))
        self.state['phase'] = 'complete'
        self.state.get('resource_claims', {}).pop(str(self.runtime), None)
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
        for unit in self.owned_target_units():
            self.m.call(['systemctl', 'stop', unit])
            active = subprocess.run(['systemctl', 'is-active', '--quiet', unit])
            if active.returncode not in (3, 4):
                self.fail('A target service is still active. Data restoration was not started.')
        if self.runtime.exists():
            self.database('lock')

    def owned_target_units(self):
        self.claims().check_all()
        self.host.resource_claims = self.claims()
        units = []
        # Validate every name before stopping even one service.
        for suffix in ('scheduler.timer', 'operations.timer', 'scheduler.service', 'operations.service', 'worker.service', 'fpm.service'):
            unit = self.name + '-' + suffix
            self.host.check_unit(unit)
            if self.claims().proof(Path('/etc/systemd/system') / unit) is not None:
                units.append(unit)
        return units

    def recover_host(self):
        m = self.m
        if not self.state:
            return
        if self.state.get('phase') in ('core-accepted', 'complete'):
            self.fail('This operation was accepted. Finish host setup; recovery cannot downgrade a completed installation.')
        if 'database' in self.state and self.runtime.exists():
            self.database('lock')
        for unit in self.owned_target_units():
            subprocess.run(['systemctl', 'stop', unit], capture_output=True)
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
            self.claims().write('/usr/local/sbin/' + m.prefix, base64.b64decode(saved), 0o755)
        # The core restores source data/key ownership before this method is called.
        self.claims().remove(self.hold)
        pointer = m.root / 'active-installation.json'
        if 'source_active_pointer' not in self.state:
            self.fail('This operation lacks its previous active-installation receipt. The source data was retained; restore the exact protected host receipt before reopening the site.')
        saved_pointer = self.state['source_active_pointer']
        if saved_pointer is None:
            self.claims().remove(pointer)
        else:
            self.claims().write(pointer, base64.b64decode(saved_pointer['bytes']), saved_pointer['mode'], gid=saved_pointer['gid'])
            os.chown(pointer, saved_pointer['uid'], saved_pointer['gid'])
        self.state['phase'] = 'recovered'
        if self.m.product == self.host.product and self.update_state_file.exists():
            self.database('retain-update-hold')
            self.update_delivery_phase('recovered', 'The source installation was recovered. Its delivery hold is retained. Retry the update, or review readiness before deliberately resuming delivery.')
        m.save()
