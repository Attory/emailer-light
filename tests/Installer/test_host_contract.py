import base64
import json
import os
import tempfile
from pathlib import Path
import sys
import types
import unittest
from unittest.mock import patch

sys.path.insert(0, str(Path(__file__).resolve().parents[2] / 'installer'))
from runtime_env import parse_environment_text, RuntimeEnvironmentError
from host import HostHandoff
from provision import Installer, SetupError, root_directory
from manage import Manager, ManagementError


class RuntimeFileContract(unittest.TestCase):
    def test_literal_protected_values_round_trip_without_shell_expansion(self):
        values = {'APP_SECRET': 'a$PRIVATE"\\secreté', 'DATABASE_URL': 'mysql://app:encoded%27password@127.0.0.1/db'}
        text = ''.join(key + '=' + json.dumps(value, ensure_ascii=False).replace('$', '\\$') + '\n' for key, value in values.items())
        self.assertEqual(values, parse_environment_text(text))
        self.assertEqual({'APP_SECRET': '$LITERAL'}, parse_environment_text("APP_SECRET='$LITERAL'\n"))

    def test_rejects_ambiguous_or_expanding_configuration(self):
        for text in ('APP_SECRET=x\nAPP_SECRET=y', 'APP_SECRET="$HOME"', 'APP_SECRET=${HOME}', "APP_SECRET='unfinished", 'APP_SECRET=two words', 'APP_SECRET="null"\nINVALID NAME=value', 'APP_SECRET="a\\nb"', 'APP_SECRET="a\\u0000b"'):
            with self.subTest(text=text), self.assertRaises(RuntimeEnvironmentError):
                parse_environment_text(text)

    def test_rejects_shell_launchers_instead_of_evaluating_them(self):
        for text in ('export APP_SECRET=x', 'APP_SECRET=$(id)', 'APP_SECRET=`id`'):
            with self.subTest(text=text):
                if ' ' in text or text.startswith('export'):
                    with self.assertRaises(RuntimeEnvironmentError):
                        parse_environment_text(text)
                else:
                    # Backticks are inert data, never subprocess/shell input.
                    self.assertEqual(text.split('=', 1)[1], parse_environment_text(text)['APP_SECRET'])


class HostingIsolationContract(unittest.TestCase):
    @unittest.skipUnless(os.geteuid() == 0, 'Real root-owned service traversal requires root')
    def test_required_traversal_survives_restrictive_umask_and_a_retry(self):
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)
            previous = os.umask(0o077)
            try:
                configuration = root / 'configuration'
                root_directory(configuration, 65534, 0o750)
                self.assertEqual((0, 65534, 0o750), (configuration.stat().st_uid, configuration.stat().st_gid, configuration.stat().st_mode & 0o777))
                configuration.chmod(0o700)  # An interrupted older run retained this root directory.
                root_directory(configuration, 65534, 0o750)
                self.assertEqual(0o750, configuration.stat().st_mode & 0o777)
                challenge = root / 'challenge'
                root_directory(challenge, 0, 0o755)
                self.assertEqual(0o755, challenge.stat().st_mode & 0o777)
                outside = root / 'outside'
                outside.mkdir(mode=0o700)
                linked = root / 'linked'
                linked.symlink_to(outside, target_is_directory=True)
                with self.assertRaises(OSError):
                    root_directory(linked, 65534, 0o750)
                self.assertEqual(0o700, outside.stat().st_mode & 0o777)
            finally:
                os.umask(previous)

    def test_only_the_actual_nginx_listener_is_accepted_when_nginx_is_installed(self):
        instance = object.__new__(Installer)
        def table(path, *args, **kwargs):
            if str(path) == '/proc/4321/comm':
                return 'nginx\n'
            if str(path) == '/proc/net/tcp':
                return 'header\n0: 00000000:0050 00000000:0000 0A 0:0 0:0 0 0 0 111\n'
            return 'header\n'
        with patch.object(Path, 'read_text', table), patch.object(Path, 'iterdir', return_value=iter([Path('/proc/4321/fd/7')])), patch('provision.os.readlink', return_value='socket:[111]'), patch('provision.subprocess.run', return_value=types.SimpleNamespace(returncode=0, stdout='4321')):
            instance.check_public_listeners()
        def foreign(path, *args, **kwargs):
            result = table(path, *args, **kwargs)
            return result + '1: 00000000:01BB 00000000:0000 0A 0:0 0:0 0 0 0 222\n' if str(path) == '/proc/net/tcp' else result
        with patch.object(Path, 'read_text', foreign), patch.object(Path, 'iterdir', return_value=iter([Path('/proc/4321/fd/7')])), patch('provision.os.readlink', return_value='socket:[111]'), patch('provision.subprocess.run', return_value=types.SimpleNamespace(returncode=0, stdout='4321')), self.assertRaisesRegex(SetupError, 'Port 443.*outside'):
            instance.check_public_listeners()

    def test_cron_matching_preserves_another_edition_and_neighbouring_path(self):
        markers = ('/usr/local/sbin/emailer', '/srv/emailer/releases/current/bin/console')
        for line in ('* * * * * /usr/local/sbin/emailer-light operations:run', '* * * * * /usr/local/sbin/emailer-other job', '* * * * * /srv/emailer/releases/current/bin/console-other'):
            self.assertFalse(HostHandoff.references(line, markers))
        for line in ('* * * * * /usr/local/sbin/emailer operations:run', '* * * * * sudo -u app "/srv/emailer/releases/current/bin/console" job'):
            self.assertTrue(HostHandoff.references(line, markers))

    def test_target_edition_has_separate_host_identity(self):
        def instance(product):
            manager = types.SimpleNamespace(prefix='emailer-light', installer=types.SimpleNamespace(product=product), record={})
            return HostHandoff(manager)
        first, second = instance('emailer-light'), instance('emailer-pro')
        self.assertNotEqual(first.name, second.name)
        self.assertNotEqual(first.runtime, second.runtime)

    def test_maintenance_and_final_sites_preserve_acme_webroot(self):
        manager = types.SimpleNamespace(prefix='emailer-light', installer=types.SimpleNamespace(product='emailer-light'), record={}, releases=Path('/srv/emailer-light/releases'), error=ManagementError)
        handoff = HostHandoff(manager)
        challenge = 'location ^~ /.well-known/acme-challenge/ { root /var/lib/emailer-light-acme; }'
        handoff.state.update(hostname='mailer.example.test', certificate='/etc/letsencrypt/live/mailer/fullchain.pem', certificate_key='/etc/letsencrypt/live/mailer/privkey.pem', acme_locations=[challenge])
        for maintenance in (True, False):
            site = handoff.site_text(maintenance=maintenance)
            self.assertIn(challenge, site)
            self.assertIn('access_log off;', site)
            self.assertIn('fastcgi_pass unix:/run/emailer-light-light-110-fpm/php.sock;', site)
            self.assertEqual(maintenance, 'deny all;' in site)


class RecoveryAndDiagnosticContract(unittest.TestCase):
    def test_chain_recovery_restores_the_previous_active_runtime_receipt(self):
        for existed in (True, False):
            with self.subTest(existed=existed), tempfile.TemporaryDirectory() as directory:
                root = Path(directory)
                pointer = root / 'active-installation.json'
                previous = b'{"runtime":"/etc/emailer-light-light-110/runtime.env"}\n'
                pointer.write_text('target pointer')
                def write(path, content, mode):
                    Path(path).write_bytes(content if isinstance(content, bytes) else content.encode())
                    Path(path).chmod(mode)
                manager = types.SimpleNamespace(prefix='emailer-light', product='emailer-light', root=root, installer=types.SimpleNamespace(product='emailer-pro', durable=write), record={}, save=lambda: None)
                handoff = HostHandoff(manager)
                handoff.state.update(phase='prepared', site_bytes=base64.b64encode(b'source site').decode(), source_active_pointer={'bytes': base64.b64encode(previous).decode(), 'mode': 0o600, 'uid': os.getuid(), 'gid': os.getgid()} if existed else None)
                handoff.hold = root / 'hold'
                handoff.apply_site = lambda text: None
                handoff.owned_target_units = lambda: []
                handoff.claims = lambda: types.SimpleNamespace(remove=lambda path: path.unlink(missing_ok=True), write=lambda path, content, mode=0o600, **kwargs: write(path, content, mode))
                with patch('host.subprocess.run'):
                    handoff.recover_host()
                if existed:
                    self.assertEqual(previous, pointer.read_bytes())
                    self.assertEqual(0o600, pointer.stat().st_mode & 0o777)
                else:
                    self.assertFalse(pointer.exists())

    def test_machine_failure_uses_bounded_guidance_and_retains_no_raw_secret(self):
        with tempfile.TemporaryDirectory() as directory:
            instance = object.__new__(Manager)
            instance.directory = Path(directory)
            saved = []
            instance.installer = types.SimpleNamespace(durable=lambda path, content: saved.append(content))
            result = types.SimpleNamespace(returncode=1, stdout='A safe note\n{"installer_error":"finish_required"}\n', stderr='synthetic-sensitive-value')
            with patch('manage.subprocess.run', return_value=result), self.assertRaisesRegex(ManagementError, 'Choose Finish'):
                instance.call(['/usr/bin/php8.4', '/console', 'emailer:release:update'])
            self.assertNotIn('synthetic-sensitive-value', ''.join(saved))
            self.assertEqual('finish_required', json.loads(saved[0])['error_code'])


class PackageFailureGuidance(unittest.TestCase):
    def test_registered_socket_survives_missing_run_link_and_interrupted_rerun(self):
        instance = object.__new__(Installer)
        with tempfile.TemporaryDirectory() as directory:
            instance.state_dir = Path(directory)
            output = 'Name: php-fpm.sock\nStatus: auto\nValue: /run/php/php8.3-fpm.sock\nBest: /run/php/php8.3-fpm.sock\n'
            original_lstat = Path.lstat
            def metadata(path, *args, **kwargs):
                info = original_lstat(path, *args, **kwargs)
                return types.SimpleNamespace(st_uid=0, st_mode=info.st_mode, st_nlink=info.st_nlink)
            with patch.object(Path, 'lstat', metadata), patch('provision.os.path.lexists', return_value=False):
                with patch('provision.subprocess.run', return_value=types.SimpleNamespace(returncode=0, stdout=output)), patch('provision.run') as commands:
                    record = instance.preserve_php_selection('/run/php/php-fpm.sock', ['php8.4-fpm'], name='php-fpm.sock', package='php8.4-fpm')
                    self.assertEqual('auto', record['previous_mode'])
                    commands.assert_called_once_with(['update-alternatives', '--set', 'php-fpm.sock', '/run/php/php8.3-fpm.sock'])
                receipt = (instance.state_dir / 'php-fpm-sock-selection.json').read_bytes()
                after = output.replace('Status: auto', 'Status: manual').replace('Best: /run/php/php8.3-fpm.sock', 'Best: /run/php/php8.4-fpm.sock')
                with patch('provision.subprocess.run', return_value=types.SimpleNamespace(returncode=0, stdout=after)), patch('provision.run') as commands:
                    # A killed APT task can have installed every package already.
                    retained = instance.preserve_php_selection('/run/php/php-fpm.sock', [], name='php-fpm.sock', package='php8.4-fpm')
                    self.assertEqual(record, retained)
                    self.assertEqual(receipt, (instance.state_dir / 'php-fpm-sock-selection.json').read_bytes())
                    instance.verify_php_selection('php-fpm.sock', '/run/php/php-fpm.sock', retained)
                    commands.assert_not_called()
                drift = after.replace('Value: /run/php/php8.3-fpm.sock', 'Value: /run/php/php8.4-fpm.sock')
                with patch('provision.subprocess.run', return_value=types.SimpleNamespace(returncode=0, stdout=drift)), self.assertRaisesRegex(SetupError, 'differs from the saved'):
                    instance.preserve_php_selection('/run/php/php-fpm.sock', [], name='php-fpm.sock', package='php8.4-fpm')

    @unittest.skipUnless(os.geteuid() == 0, 'Protected completion records require root')
    def test_completed_legacy_receipt_allows_later_operator_selection_and_new_transaction(self):
        instance = object.__new__(Installer)
        with tempfile.TemporaryDirectory() as directory:
            instance.state_dir = Path(directory)
            receipt = instance.state_dir / 'php-selection.json'
            legacy = {'policy': 'keep_existing_version', 'previous_value': '/usr/bin/php8.1', 'previous_mode': 'auto'}
            receipt.write_text(json.dumps(legacy)); receipt.chmod(0o600)
            journal = instance.state_dir / 'state.json'
            journal.write_text(json.dumps({'completed': ['Installing supported dependencies']})); journal.chmod(0o600)
            output = 'Name: php\nStatus: manual\nValue: /usr/bin/php8.3\nBest: /usr/bin/php8.4\n'
            with patch('provision.subprocess.run', return_value=types.SimpleNamespace(returncode=0, stdout=output)), patch('provision.os.path.lexists', return_value=False), patch('provision.run') as commands:
                # Check/update with every package installed accepts current host policy.
                self.assertIsNone(instance.preserve_php_selection('/usr/bin/php', []))
                self.assertFalse(receipt.exists())
                record = instance.preserve_php_selection('/usr/bin/php', ['php8.4-cli'])
                self.assertEqual('/usr/bin/php8.3', record['previous_value'])
                self.assertEqual('emailer-php-selection-v2', record['format'])
                # An unfinished NEW transaction must retain its binding despite
                # the old installation's completed dependency phase.
                drift = output.replace('Value: /usr/bin/php8.3', 'Value: /usr/bin/php8.4')
                with patch('provision.subprocess.run', return_value=types.SimpleNamespace(returncode=0, stdout=drift)), self.assertRaisesRegex(SetupError, 'differs from the saved'):
                    instance.preserve_php_selection('/usr/bin/php', [])
                self.assertTrue(receipt.exists())
                instance.verify_php_selection('php', '/usr/bin/php', record)
                instance.retire_php_selection('php', record)
                self.assertFalse(receipt.exists())
                commands.assert_not_called()

    def test_unregistered_existing_fpm_socket_refuses_package_installation(self):
        instance = object.__new__(Installer)
        with tempfile.TemporaryDirectory() as directory:
            instance.state_dir = Path(directory)
            with patch('provision.subprocess.run', return_value=types.SimpleNamespace(returncode=2, stdout='')), patch('provision.os.path.lexists', return_value=True), patch('provision.run') as commands, self.assertRaisesRegex(SetupError, 'outside Debian'):
                instance.preserve_php_selection('/run/php/php-fpm.sock', ['php8.4-fpm'], name='php-fpm.sock', package='php8.4-fpm')
            commands.assert_not_called()

    def test_future_catalogue_signature_reports_clock_before_generic_signing_error(self):
        instance = object.__new__(Installer)
        evidence = 'OpenPGP signature verification failed: Signature was created after the --not-after date.'
        with patch('provision.subprocess.run', return_value=types.SimpleNamespace(returncode=100, stdout='', stderr=evidence)) as calls, self.assertRaisesRegex(SetupError, 'server clock'):
            instance.install_missing_packages(['php8.4-cli'])
        self.assertEqual(1, calls.call_count)

    def test_missing_matching_php_patch_is_actionable_without_relaxing_pin(self):
        instance = object.__new__(Installer)
        calls = []
        def apt(args, **kwargs):
            calls.append(args)
            return types.SimpleNamespace(returncode=0 if args[-1] == 'update' else 100, stdout='', stderr="E: Version '8.4.1' for 'php8.4-xml' was not found")
        with patch('provision.subprocess.run', side_effect=apt), self.assertRaisesRegex(SetupError, 'kept that PHP version unchanged'):
            instance.install_missing_packages(['php8.4-xml=8.4.1'])
        self.assertEqual(2, len(calls))
        self.assertIn('php8.4-xml=8.4.1', calls[1])
        self.assertNotIn('--allow-unauthenticated', calls[1])

class PrivateRuntimePermissionContract(unittest.TestCase):
    def instance(self, root):
        private = root / 'private'
        private.mkdir(mode=0o700)
        manager = types.SimpleNamespace(prefix='emailer-light', private=private, source_values={'EMAILER_RUNTIME_DIR': str(private), 'LOCK_DSN': 'flock://' + str(private / 'locks')}, account=types.SimpleNamespace(pw_uid=os.getuid(), pw_gid=os.getgid()), installer=types.SimpleNamespace(product='emailer-light'), record={}, error=ManagementError)
        handoff = HostHandoff(manager)
        return manager, handoff

    def test_generated_files_are_secured_after_receipt_without_replacing_locks_or_data(self):
        with tempfile.TemporaryDirectory() as directory:
            manager, handoff = self.instance(Path(directory))
            cache = manager.private / 'cache'
            locks = manager.private / 'locks'
            cache.mkdir(mode=0o755)
            locks.mkdir(mode=0o755)
            generated = cache / 'generated.php'
            lock = locks / 'application.lock'
            business = manager.private / 'campaign-asset'
            for path in (generated, lock, business):
                path.write_bytes(b'synthetic fixture bytes')
                path.chmod(0o644)
            inodes = {str(path): path.stat().st_ino for path in (generated, lock, business)}
            saved = []
            manager.save = lambda: saved.append((generated.stat().st_mode & 0o777, json.loads(json.dumps(handoff.state))))
            handoff.secure_runtime_metadata()
            self.assertEqual(0o644, saved[0][0])
            self.assertIn('cache/generated.php', saved[0][1]['runtime_metadata_before'])
            for path in (generated, lock):
                self.assertEqual(0o600, path.stat().st_mode & 0o777)
                self.assertEqual(inodes[str(path)], path.stat().st_ino)
                self.assertEqual(b'synthetic fixture bytes', path.read_bytes())
            self.assertEqual(0o644, business.stat().st_mode & 0o777)
            handoff.secure_runtime_metadata()
            self.assertEqual(0o644, handoff.state['runtime_metadata_before']['cache/generated.php']['mode'])

    def test_linked_or_hardlinked_runtime_cannot_change_an_outside_file(self):
        for hardlink in (False, True):
            with self.subTest(hardlink=hardlink), tempfile.TemporaryDirectory() as directory:
                root = Path(directory)
                manager, handoff = self.instance(root)
                (manager.private / 'cache').mkdir()
                outside = root / 'outside'
                outside.write_text('keep this unchanged')
                outside.chmod(0o644)
                entry = manager.private / 'cache' / 'unsafe'
                if hardlink:
                    os.link(outside, entry)
                else:
                    entry.symlink_to(outside)
                manager.save = lambda: self.fail('Unsafe inventory must fail before metadata mutation')
                with self.assertRaises((ManagementError, OSError)):
                    handoff.secure_runtime_metadata()
                self.assertEqual(0o644, outside.stat().st_mode & 0o777)
                self.assertEqual('keep this unchanged', outside.read_text())

    def test_interrupted_permission_repair_resumes_and_keeps_an_open_lock_exclusive(self):
        import fcntl
        with tempfile.TemporaryDirectory() as directory:
            manager, handoff = self.instance(Path(directory))
            locks = manager.private / 'locks'
            locks.mkdir(mode=0o755)
            lock = locks / 'held.lock'
            lock.write_bytes(b'')
            lock.chmod(0o644)
            manager.save = lambda: None
            real_chmod = os.fchmod
            calls = []
            def interrupted(descriptor, mode):
                real_chmod(descriptor, mode)
                calls.append(mode)
                raise InterruptedError('synthetic interrupted metadata repair')
            with lock.open('rb') as held:
                fcntl.flock(held, fcntl.LOCK_EX | fcntl.LOCK_NB)
                inode = os.fstat(held.fileno()).st_ino
                with patch('host.os.fchmod', side_effect=interrupted), self.assertRaises(InterruptedError):
                    handoff.secure_runtime_metadata()
                handoff.secure_runtime_metadata()
                self.assertEqual(inode, lock.stat().st_ino)
                with lock.open('rb') as contender, self.assertRaises(BlockingIOError):
                    fcntl.flock(contender, fcntl.LOCK_EX | fcntl.LOCK_NB)
            self.assertEqual(0o644, handoff.state['runtime_metadata_before']['locks/held.lock']['mode'])

    @unittest.skipUnless(os.geteuid() == 0, 'Root ownership transition is separately exercised in the isolated root test container')
    def test_root_owned_file_resumes_after_owner_change_before_mode_change(self):
        with tempfile.TemporaryDirectory() as directory:
            manager, handoff = self.instance(Path(directory))
            manager.account = types.SimpleNamespace(pw_uid=1000, pw_gid=1000)
            cache = manager.private / 'cache'
            cache.mkdir(mode=0o755)
            generated = cache / 'root-created.php'
            generated.write_text('synthetic generated bytes')
            generated.chmod(0o644)
            manager.save = lambda: None
            real_chmod = os.fchmod
            def interrupted(descriptor, mode):
                if os.fstat(descriptor).st_ino == generated.stat().st_ino:
                    raise InterruptedError('after fchown before fchmod')
                return real_chmod(descriptor, mode)
            with patch('host.os.fchmod', side_effect=interrupted), self.assertRaises(InterruptedError):
                handoff.secure_runtime_metadata()
            self.assertEqual((1000, 0o644), (generated.stat().st_uid, generated.stat().st_mode & 0o777))
            handoff.secure_runtime_metadata()
            self.assertEqual((1000, 0o600), (generated.stat().st_uid, generated.stat().st_mode & 0o777))
            self.assertEqual(0, handoff.state['runtime_metadata_before']['cache/root-created.php']['uid'])

    def test_business_lock_path_and_drifted_receipts_fail_before_a_new_repair(self):
        with tempfile.TemporaryDirectory() as directory:
            manager, handoff = self.instance(Path(directory))
            business = manager.private / 'template-assets'
            business.mkdir()
            asset = business / 'keep'
            asset.write_text('unchanged business asset')
            asset.chmod(0o644)
            manager.source_values['LOCK_DSN'] = 'flock://' + str(business)
            manager.save = lambda: None
            with self.assertRaises(ManagementError):
                handoff.secure_runtime_metadata()
            self.assertEqual(0o644, asset.stat().st_mode & 0o777)
            manager.source_values['LOCK_DSN'] = 'flock://' + str(manager.private / 'locks')
            cache = manager.private / 'cache'
            cache.mkdir()
            generated = cache / 'file'
            generated.write_text('first generated content')
            handoff.secure_runtime_metadata()
            previous = json.dumps(handoff.state, sort_keys=True)
            generated.write_text('changed after interruption')
            with self.assertRaises(ManagementError):
                handoff.secure_runtime_metadata()
            self.assertEqual(previous, json.dumps(handoff.state, sort_keys=True))

    def test_concurrent_generated_file_creation_fails_closed(self):
        with tempfile.TemporaryDirectory() as directory:
            manager, handoff = self.instance(Path(directory))
            cache = manager.private / 'cache'
            cache.mkdir()
            (cache / 'original').write_text('original bytes')
            manager.save = lambda: None
            real_chmod = os.fchmod
            def concurrent(descriptor, mode):
                real_chmod(descriptor, mode)
                (cache / 'new-unrecorded').write_text('concurrent generated file')
            with patch('host.os.fchmod', side_effect=concurrent), self.assertRaises(ManagementError):
                handoff.secure_runtime_metadata()
            self.assertNotIn('cache/new-unrecorded', handoff.state['runtime_metadata_before'])
            self.assertEqual('original bytes', (cache / 'original').read_text())


if __name__ == '__main__':
    unittest.main()
