"""A resumed installer never adopts unrelated host files created between runs."""
import errno
import json
import os
from pathlib import Path
import stat
import sys
import tempfile
import types
import unittest
from unittest.mock import patch

sys.path.insert(0, str(Path(__file__).resolve().parents[2] / 'installer'))
from provision import Installer, SetupError
from resources import ResourceClaims


@unittest.skipUnless(os.geteuid() == 0, 'ownership proofs require disposable root tests')
class ResourceOwnership(unittest.TestCase):
    def setUp(self):
        self.directory = tempfile.TemporaryDirectory()
        self.root = Path(self.directory.name)
        self.entries = {}
        self.saved = []
        self.claims = ResourceClaims(self.entries, lambda: self.saved.append(json.loads(json.dumps(self.entries))), SetupError)

    def tearDown(self):
        self.directory.cleanup()

    def test_late_foreign_regular_file_in_each_reserved_role_is_preserved(self):
        for role in ('worker.service', 'site.conf', 'launcher', 'renewal-hook', 'fpm.conf'):
            with self.subTest(role=role):
                path = self.root / role
                self.claims.reserve([path])
                path.write_text('unrelated host task\n')
                before = path.read_bytes(), path.stat().st_ino
                with self.assertRaisesRegex(SetupError, 'preserved'):
                    self.claims.write(path, 'replacement')
                self.assertEqual(before, (path.read_bytes(), path.stat().st_ino))
                path.unlink()

    def test_owned_write_and_repeat_preserve_inode_until_content_changes(self):
        path = self.root / 'unit'
        self.claims.write(path, 'first')
        inode = path.stat().st_ino
        self.claims.write(path, 'first')
        self.assertEqual(inode, path.stat().st_ino)
        self.claims.write(path, 'second')
        self.assertEqual('second', path.read_text())
        self.claims.check_all()

    def test_changed_owned_file_is_not_adopted_even_if_content_is_restored_at_new_inode(self):
        path = self.root / 'unit'
        self.claims.write(path, 'own')
        path.rename(self.root / 'original')
        path.write_text('own')
        with self.assertRaises(SetupError):
            self.claims.write(path, 'new')
        self.assertEqual('own', path.read_text())

    def test_foreign_enabled_link_including_dangling_link_is_preserved(self):
        path = self.root / 'enabled'
        self.claims.reserve([path])
        path.symlink_to(self.root / 'foreign')
        with self.assertRaises(SetupError):
            self.claims.write(path, str(self.root / 'site'), link=True)
        self.assertEqual(str(self.root / 'foreign'), os.readlink(path))

    def test_owned_enabled_link_is_repeatable(self):
        path = self.root / 'enabled'
        self.claims.write(path, str(self.root / 'site'), link=True)
        self.claims.write(path, str(self.root / 'site'), link=True)
        self.claims.check_all()

    def test_exclusive_first_publication_loses_race_without_overwrite(self):
        path = self.root / 'site'
        def race(source, destination):
            destination.write_text('foreign')
            raise OSError(errno.EEXIST, 'exists')
        with patch.object(self.claims, 'create_exclusive', side_effect=race), self.assertRaises(SetupError):
            self.claims.write(path, 'own')
        self.assertEqual('foreign', path.read_text())

    def test_interruption_before_and_after_atomic_publication_resumes(self):
        for after in (False, True):
            for existing in (False, True):
                with self.subTest(after=after, existing=existing):
                    path = self.root / f'unit-{after}-{existing}'
                    if existing:
                        self.claims.write(path, 'previous')
                    original = self.claims.finish
                    def interrupted(destination, entry):
                        if after:
                            pending = entry['pending']
                            os.replace(pending['temporary'], destination)
                        raise InterruptedError()
                    with patch.object(self.claims, 'finish', side_effect=interrupted), self.assertRaises(InterruptedError):
                        self.claims.write(path, 'next')
                    self.claims.write(path, 'next')
                    self.assertEqual('next', path.read_text())
                    self.assertNotIn('pending', self.entries[str(path)])
                    self.claims.check_all()

    def test_removal_interrupted_after_unlink_resumes_without_adopting_foreign_file(self):
        path = self.root / 'site'
        self.claims.write(path, 'own')
        original_save = self.claims.save
        def interrupted():
            original_save()
            if self.entries[str(path)].get('pending', {}).get('next', 'absent') is None:
                path.unlink()
                raise InterruptedError()
        with patch.object(self.claims, 'save', side_effect=interrupted), self.assertRaises(InterruptedError):
            self.claims.remove(path)
        self.claims.remove(path)
        self.assertFalse(path.exists())

    def test_loaded_foreign_unit_and_dropin_refuse_before_any_mutation(self):
        installer = Installer.__new__(Installer)
        installer.resource_claims = self.claims
        for status in ('LoadState=loaded\nFragmentPath=/run/systemd/system/emailer-test.service\nDropInPaths=\n',
                       'LoadState=loaded\nFragmentPath=\nDropInPaths=\n'):
            with patch('provision.run', return_value=status) as commands, self.assertRaisesRegex(SetupError, 'another loaded'):
                installer.check_unit('emailer-test.service')
            self.assertEqual(1, commands.call_count)

    def test_http_https_validation_rollback_and_retry_preserve_owned_site(self):
        installer = Installer.__new__(Installer)
        installer.resource_claims = self.claims
        installer.site = self.root / 'site'
        installer.web = types.SimpleNamespace(check_command=['web-check'], check_assignment=lambda *args: None)
        installer.state = {'hostname': 'mailer.example.test'}
        enabled = self.root / 'enabled'
        with patch('provision.run'):
            installer.validated_site('HTTP', enabled)
        with patch('provision.run', side_effect=SetupError()), self.assertRaisesRegex(SetupError, 'restored'):
            installer.validated_site('HTTPS')
        self.assertEqual('HTTP', installer.site.read_text())
        with patch('provision.run'):
            installer.validated_site('HTTPS')
        self.assertEqual('HTTPS', installer.site.read_text())
        self.claims.check_all()

    def test_initial_site_validation_failure_removes_only_own_files(self):
        installer = Installer.__new__(Installer)
        installer.resource_claims = self.claims
        installer.site = self.root / 'site'
        installer.web = types.SimpleNamespace(check_command=['web-check'], check_assignment=lambda *args: None)
        installer.state = {'hostname': 'mailer.example.test'}
        enabled = self.root / 'enabled'
        with patch('provision.run', side_effect=SetupError()), self.assertRaises(SetupError):
            installer.validated_site('invalid', enabled)
        self.assertFalse(os.path.lexists(enabled))
        self.assertFalse(installer.site.exists())
        with patch('provision.run'):
            installer.validated_site('HTTP', enabled)
        self.claims.check_all()

    def test_directory_created_after_reservation_is_not_adopted(self):
        path = self.root / 'configuration'
        self.claims.reserve([path])
        path.mkdir()
        (path / 'foreign').write_text('untouched')
        with self.assertRaises(SetupError):
            self.claims.directory(path, 0, 0, 0o750)
        self.assertEqual('untouched', (path / 'foreign').read_text())

    def test_owned_directory_children_may_change_but_inode_and_permissions_must_match(self):
        path = self.root / 'configuration'
        self.claims.directory(path, 0, 0, 0o750)
        (path / 'settings').write_text('application settings')
        self.claims.directory(path, 0, 0, 0o750)
        path.chmod(0o755)
        with self.assertRaises(SetupError):
            self.claims.directory(path, 0, 0, 0o750)

    def test_published_resume_fsyncs_parent_before_committing_receipt(self):
        path = self.root / 'unit'
        original_save = self.claims.save
        def interrupted(destination, entry):
            os.replace(entry['pending']['temporary'], destination)
            raise InterruptedError()
        with patch.object(self.claims, 'finish', side_effect=interrupted), self.assertRaises(InterruptedError):
            self.claims.write(path, 'owned')
        events = []
        with patch.object(self.claims, 'sync', side_effect=lambda parent: events.append('sync')), patch.object(self.claims, 'save', side_effect=lambda: events.append('save')):
            self.claims.write(path, 'owned')
        self.assertEqual(['sync', 'save'], events)

    def test_parsed_inventory_ignores_only_proven_own_site_and_rechecks_new_alias(self):
        from hosting import WebServer
        own = self.root / 'own.conf'
        own.write_text('own site')
        for kind, inventory in (
            ('apache2', f' port 443 namevhost mailer.example.test ({own}:1)\n alias own-alias.example.test\n port 443 namevhost foreign.example.test (/etc/apache2/sites-enabled/foreign.conf:1)\n alias mailer.example.test\n'),
            ('nginx', f'# configuration file {own}:\nserver_name mailer.example.test;\n# configuration file /etc/nginx/sites-enabled/foreign.conf:\nserver_name other.example.test mailer.example.test;\n')):
            web = WebServer(kind, SetupError)
            with patch.object(web, 'configuration', return_value=inventory), self.assertRaisesRegex(SetupError, 'another website'):
                web.check_assignment('mailer.example.test', own)
            own_only = inventory.split(' port 443 namevhost foreign')[0] if kind == 'apache2' else inventory.split('# configuration file /etc/nginx')[0]
            with patch.object(web, 'configuration', return_value=own_only):
                web.check_assignment('mailer.example.test', own)

    def database_installer(self, replies):
        installer = Installer.__new__(Installer)
        installer.state = {'database': 'em_test', 'database_owner': 'a' * 48}
        installer.environment = lambda: {'DATABASE_URL': 'mysql://em_test:' + 'b' * 64 + '@127.0.0.1/em_test'}
        installer.save = lambda: None
        calls = []
        def execute(arguments, data=None):
            calls.append((arguments, data))
            if data is not None:
                return ''
            if 'SELECT SCHEMA_COMMENT' in arguments[-1]:
                return 'emailer-operation-' + 'a' * 48
            return replies(arguments[-1])
        installer.database_run = execute
        return installer, calls

    def test_reserved_foreign_database_without_marker_is_never_adopted(self):
        installer, calls = self.database_installer(lambda query: '0' if 'information_schema.tables' in query else '1')
        previous = installer.database_run
        installer.database_run = lambda arguments, data=None: 'foreign' if 'SELECT SCHEMA_COMMENT' in arguments[-1] else previous(arguments, data)
        installer.state['database_reserved'] = True
        with self.assertRaisesRegex(SetupError, 'another operation'):
            installer.database()
        self.assertFalse(any(data is not None for _, data in calls))

    def test_wrong_database_marker_is_never_adopted(self):
        installer, calls = self.database_installer(lambda query: 'foreign' if 'SELECT operation_token' in query else '1')
        with self.assertRaisesRegex(SetupError, 'ownership marker'):
            installer.database()
        self.assertFalse(any(data is not None for _, data in calls))

    def test_existing_database_account_must_match_operation_secret_and_scope(self):
        def reply(query):
            if 'operation_token' in query:
                return 'a' * 48
            if 'authentication_string' in query:
                return '0'
            return '1'
        installer, calls = self.database_installer(reply)
        with self.assertRaisesRegex(SetupError, 'does not match'):
            installer.database()
        self.assertFalse(any(data is not None for _, data in calls))

    def test_existing_account_with_unrelated_privilege_is_never_granted_or_changed(self):
        def reply(query):
            if 'operation_token' in query:
                return 'a' * 48
            if 'SHOW GRANTS' in query:
                return "GRANT ALL PRIVILEGES ON *.* TO `em_test`@`127.0.0.1`"
            return '1'
        installer, calls = self.database_installer(reply)
        with self.assertRaisesRegex(SetupError, 'unrelated privileges'):
            installer.database()
        self.assertFalse(any(data is not None for _, data in calls))

    def test_operation_owned_database_and_account_resume_with_only_scoped_grant(self):
        def reply(query):
            if 'operation_token' in query:
                return 'a' * 48
            if 'SHOW GRANTS' in query:
                return "GRANT USAGE ON *.* TO `em_test`@`127.0.0.1`"
            return '1'
        installer, calls = self.database_installer(reply)
        installer.database()
        mutations = [data for _, data in calls if data is not None]
        self.assertEqual(1, len(mutations))
        self.assertTrue(mutations[0].startswith('GRANT SELECT, INSERT'))
        self.assertNotIn('IF NOT EXISTS', mutations[0])

    def test_launcher_binding_preserves_unrelated_and_accepts_only_proven_transition_variants(self):
        from manage import Manager, ManagementError
        from provision import generated_launcher
        root = self.root
        canonical = root / 'sbin' / 'emailer-light'
        canonical.parent.mkdir()
        key = root / 'master.key'
        key.write_text('synthetic')
        bundle = Path(__file__).resolve().parents[2] / 'installer'
        manager = Manager.__new__(Manager)
        manager.prefix = 'emailer-light'
        manager.configuration = Path('/etc/emailer-light/runtime.env')
        manager.original_configuration = manager.configuration
        manager.releases = Path('/srv/emailer-light/releases')
        manager.installer = types.SimpleNamespace(bundle=bundle, product='emailer-pro', version='1.2.1')
        manager.record = {}
        manager.save = lambda: None
        values = {'EMAILER_SECRET_KEY_FILE': str(key)}
        source = generated_launcher(bundle, 'emailer-light', 'root', manager.configuration, manager.releases / 'current')
        target = generated_launcher(bundle, 'emailer-light-pro-121', 'emailer-light-pro-121', Path('/etc/emailer-light-pro-121/runtime.env'), manager.releases / 'current')
        real_path = Path
        def selected_path(value):
            return canonical.parent if value == '/usr/local/sbin' else real_path(value)
        with patch('manage.Path', side_effect=selected_path), patch('manage.read_environment', return_value=values), patch('manage.protected', side_effect=lambda path, **kwargs: path):
            manager.validate_launcher()  # Original100: no generated launcher required.
            canonical.write_text('unrelated command')
            with self.assertRaisesRegex(ManagementError, 'unrelated'):
                manager.validate_launcher()
            self.assertEqual('unrelated command', canonical.read_text())
            canonical.write_text(source)
            manager.validate_launcher()
            # Target launcher is legitimate before a separate Accept switches the pointer.
            canonical.write_text(target)
            with self.assertRaises(ManagementError):
                manager.validate_launcher()
            claims = ResourceClaims({}, manager.save, ManagementError)
            claims.adopt(canonical)
            manager.record['host_handoff'] = {'source_user': 'root', 'resource_claims': claims.entries}
            manager.validate_launcher()
            # An administrator changing even this operation-owned launcher is preserved/refused.
            canonical.write_text('foreign replacement')
            with self.assertRaises(ManagementError):
                manager.validate_launcher()
            self.assertEqual('foreign replacement', canonical.read_text())
            canonical.write_text(source)
            # Recovery restores source launcher before restoring the original active pointer.
            manager.configuration = Path('/etc/emailer-light-pro-121/runtime.env')
            manager.record['host_handoff']['source_user'] = 'root'
            claims.entries[str(canonical)] = {'current': claims.proof(canonical)}
            manager.validate_launcher()

    def test_runtime_publication_is_fsynced_before_phase_retires_mutable_claim(self):
        installer = Installer.__new__(Installer)
        installer.config = self.root
        installer.resource_claims = self.claims
        installer.state = {'completed': [], 'resource_claims': self.entries}
        installer.save = self.claims.save
        runtime = self.root / 'runtime.env'
        def interrupted(destination, entry):
            os.replace(entry['pending']['temporary'], destination)
            raise InterruptedError()
        with patch.object(self.claims, 'finish', side_effect=interrupted), self.assertRaises(InterruptedError):
            self.claims.write(runtime, 'synthetic configuration', 0o640)
        events = []
        # Actual resources() retry skips a runtime file already present on disk.
        with patch.object(self.claims, 'sync', side_effect=lambda parent: events.append('sync')), patch.object(self.claims, 'save', side_effect=lambda: events.append('save')):
            installer.phase('Preparing dedicated resources', lambda: None)
        self.assertEqual('sync', events[0])
        self.assertNotIn(str(runtime), self.entries)
        self.assertIn('Preparing dedicated resources', installer.state['completed'])

    def test_interrupted_verified_empty_database_reset_recreates_before_any_schema_query(self):
        installer = Installer.__new__(Installer)
        installer.state = {'database': 'em_test', 'empty_baseline_reset': True, 'database_created': False}
        installer.application_completed = lambda: False
        events = []
        installer.database = lambda: events.append('resume-owned-database')
        installer.save = lambda: events.append('save')
        def query(*args, **kwargs):
            events.append('schema-query')
            raise InterruptedError()
        installer.database_run = query
        with self.assertRaises(InterruptedError):
            installer.application()
        self.assertEqual(['resume-owned-database', 'save', 'schema-query'], events)
        self.assertNotIn('empty_baseline_reset', installer.state)

    def test_unnecessary_apt_upgrade_is_constrained_without_changing_host_preferences(self):
        from packages import retained_preferences
        original = self.root / 'host.preferences'
        original.write_text('Package: unrelated\nPin: release o=Ubuntu\nPin-Priority: 500\n')
        original_bytes = original.read_bytes()
        real_path = Path
        def selected_path(value):
            return original if str(value) == '/etc/apt/preferences' else real_path(value)
        plan = 'Inst zstd [1.5.5+dfsg2-2build1] (1.5.5+dfsg2-2build1.1 Ubuntu)\nInst php-common [2:92] (2:101 PPA)\n'
        with patch('packages.Path', side_effect=selected_path):
            with retained_preferences(plan, ('php-common', 'libgd3'), self.root, SetupError) as options:
                preferences = Path(options[1].split('=', 1)[1])
                text = preferences.read_text()
                self.assertTrue(text.startswith(original_bytes.decode()))
                self.assertIn('Package: zstd\nPin: version 1.5.5+dfsg2-2build1', text)
                self.assertNotIn('Package: php-common', text)
                self.assertEqual(0o600, stat.S_IMODE(preferences.stat().st_mode))
                self.assertEqual(original_bytes, original.read_bytes())
            self.assertFalse(preferences.exists())
            with self.assertRaises(InterruptedError):
                with retained_preferences(plan, (), self.root, SetupError) as options:
                    interrupted = Path(options[1].split('=', 1)[1])
                    raise InterruptedError()
            self.assertFalse(interrupted.exists())
        self.assertEqual(original_bytes, original.read_bytes())
