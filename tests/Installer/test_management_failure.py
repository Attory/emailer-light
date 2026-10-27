import contextlib
import copy
import errno
import hashlib
import io
import json
from pathlib import Path
import sys
import tempfile
import types
import unittest
from unittest.mock import Mock, patch

sys.path.insert(0, str(Path(__file__).resolve().parents[2] / 'installer'))
from manage import Manager, ManagementError
from host import HostHandoff


class HostError(Exception):
    pass


class ManagedFailureBoundary(unittest.TestCase):
    def fixture(self, phase='in_progress'):
        m = Manager.__new__(Manager)
        m.installer = types.SimpleNamespace(error=HostError, product='emailer-light', version='1.2.7')
        m.error = ManagementError
        m.product = 'emailer-light'
        m.source_version = '1.2.7'
        m.prefix = 'emailer-light'
        m.private = Path('/var/lib/emailer-light/private')
        m.directory = Path('/tmp/owned-operation')
        m.values = {'DEFAULT_URI': 'https://emailer.example.test'}
        m.record = {'host_handoff': {'phase': 'complete', 'delivery_snapshot': {'phase': 'in_progress'}}}
        m.save = Mock()
        h = HostHandoff(m)
        snapshot = {'format': 'emailer-update-delivery-v1', 'operation': str(m.directory), 'phase': phase}
        h.current_update_snapshot = Mock(side_effect=lambda: copy.deepcopy(snapshot))
        h.database = Mock(return_value='{}')
        h.update_delivery_phase = Mock(side_effect=lambda phase, reason='': snapshot.update(phase=phase, reason=reason))
        return m, h, snapshot

    def test_service_failure_records_failed_with_finish_guidance_and_no_raw_details(self):
        m, h, state = self.fixture()
        m.failure_action = 'finish'; m.failure_stage = 'services'
        m.run_menu = Mock(side_effect=HostError('synthetic private credentials'))
        with patch('host.HostHandoff', return_value=h):
            with self.assertRaisesRegex(ManagementError, 'dedicated Emailer services.*choose Finish') as error: m.run()
        self.assertEqual('failed', state['phase'])
        self.assertNotIn('synthetic private', str(error.exception))
        self.assertNotIn('synthetic private', state['reason'])
        h.database.assert_called_once_with('retain-update-hold')

    def test_filesystem_failure_is_actionable_without_echoing_its_filename_or_message(self):
        m, h, state = self.fixture()
        m.run_menu = Mock(side_effect=OSError(errno.ENOSPC, 'synthetic private details', '/private/secret'))
        with patch('host.HostHandoff', return_value=h):
            with self.assertRaisesRegex(ManagementError, 'ran out of disk space'): m.run()
        self.assertEqual('failed', state['phase'])
        self.assertNotIn('/private', state['reason'])
        self.assertNotIn('synthetic', state['reason'])

    def test_same_version_early_finish_path_is_inside_the_boundary(self):
        m, h, state = self.fixture()
        m.stage = Mock(); m.maintenance_environment = Mock(); m.report_update_completion = Mock()
        h.restore_update_delivery = Mock(side_effect=OSError(errno.EACCES, 'private details'))
        with patch('host.HostHandoff', return_value=h), patch('builtins.input', return_value='1'), contextlib.redirect_stdout(io.StringIO()):
            with self.assertRaises(ManagementError): m.run()
        self.assertEqual('failed', state['phase'])
        h.restore_update_delivery.assert_called_once()
        m.report_update_completion.assert_not_called()

    def test_database_hold_failure_cannot_prevent_failure_recording(self):
        m, h, state = self.fixture()
        m.run_menu = Mock(side_effect=HostError('private details'))
        h.database.side_effect = ManagementError('synthetic private database details')
        with patch('host.HostHandoff', return_value=h):
            with self.assertRaises(ManagementError): m.run()
        self.assertEqual('failed', state['phase'])
        self.assertIn('database hold could not be confirmed', state['reason'])
        self.assertNotIn('synthetic private', state['reason'])

    def test_authoritative_completion_is_never_overwritten_or_reheld(self):
        m, h, state = self.fixture(phase='complete')
        m.run_menu = Mock(side_effect=OSError(errno.ENOSPC, 'private details'))
        before = copy.deepcopy(state)
        with patch('host.HostHandoff', return_value=h):
            with self.assertRaisesRegex(ManagementError, 'already completed.*Choose Check') as error: m.run()
        self.assertNotIn('choose Recover', str(error.exception))
        self.assertEqual(before, state)
        h.database.assert_not_called(); h.update_delivery_phase.assert_not_called()

    def test_failure_record_storage_error_is_reported_safely_and_exits_nonzero(self):
        m, h, state = self.fixture()
        m.run_menu = Mock(side_effect=HostError('private details'))
        h.update_delivery_phase.side_effect = OSError(errno.ENOSPC, 'synthetic private details')
        with patch('host.HostHandoff', return_value=h), contextlib.redirect_stderr(io.StringIO()) as output:
            with self.assertRaises(ManagementError): m.run()
        self.assertIn('failure record could not be fully saved', output.getvalue())
        self.assertNotIn('synthetic', output.getvalue())

    def test_malformed_saved_state_is_safe_and_interruption_still_reraises(self):
        for error in (KeyError('synthetic private details'), KeyboardInterrupt()):
            with self.subTest(error=type(error).__name__):
                m, h, state = self.fixture(); m.run_menu = Mock(side_effect=error)
                with patch('host.HostHandoff', return_value=h):
                    with self.assertRaises(KeyboardInterrupt if isinstance(error, KeyboardInterrupt) else ManagementError): m.run()
                self.assertEqual('failed', state['phase'])
                self.assertNotIn('synthetic private', state['reason'])

    def test_completed_journal_is_terminal_even_if_called_directly(self):
        with tempfile.TemporaryDirectory() as directory:
            m, h, _ = self.fixture()
            m.private = Path(directory) / 'private'
            h.update_delivery_phase = HostHandoff.update_delivery_phase.__get__(h, HostHandoff)
            path = h.update_state_file
            original = json.dumps({'format': 'emailer-update-delivery-v1', 'operation': str(m.directory), 'phase': 'complete'})
            path.write_text(original)
            with patch('manage.protected', side_effect=lambda path, **kwargs: path):
                with self.assertRaisesRegex(ManagementError, 'already completed'): h.update_delivery_phase('failed', 'late error')
            self.assertEqual(original, path.read_text())
            m.save.assert_not_called()

    def test_blocked_readiness_is_recorded_even_if_database_retention_fails(self):
        m, h, state = self.fixture()
        h.database.side_effect = ManagementError('synthetic private details')
        reason = h.block_update('SMTP TLS validation failed. Repair the certificate.')
        self.assertEqual('blocked', state['phase'])
        self.assertIn('Repair the certificate', reason)
        self.assertIn('database hold could not be confirmed', reason)
        self.assertNotIn('synthetic private', reason)

    def test_host_failure_refreshes_only_an_existing_scoped_maintenance_notice(self):
        with tempfile.TemporaryDirectory() as directory:
            m, h, state = self.fixture()
            h.state['phase'] = 'target-prepared'
            h.hold = Path(directory) / 'installer-hold.json'; h.hold.write_text('{}')
            h.site_text = Mock(return_value='scoped failed notice'); h.apply_site = Mock()
            h.block_update('Dedicated services failed.', phase='failed', public_reason='host_services')
            self.assertEqual('failed', state['phase'])
            self.assertEqual('failed', h.state['maintenance_status'])
            self.assertEqual('host_services', h.state['maintenance_reason'])
            h.apply_site.assert_called_once_with('scoped failed notice')
            h.state['phase'] = 'complete'; h.apply_site.reset_mock()
            h.block_update('Readiness failed after application verification.')
            h.apply_site.assert_not_called()

    def test_public_failed_headers_are_bounded_and_never_present_on_a_live_site(self):
        from hosting import WebServer
        values = dict(hostname='emailer.example.test', name='emailer-test', public='/srv/emailer/releases/current/public', certificate='/test/cert.pem', certificate_key='/test/key.pem')
        for server in ['apache2', 'nginx']:
            with self.subTest(server=server):
                web = WebServer(server, ManagementError)
                failed = web.site(**values, maintenance=True, maintenance_status='failed', maintenance_reason='host_services')
                self.assertIn('X-Emailer-Update-State "failed"', failed)
                self.assertIn('X-Emailer-Update-Reason "host_services"', failed)
                self.assertIn('Emailer setup stopped', failed)
                self.assertNotIn('X-Emailer-Update-', web.site(**values))
                with self.assertRaises(ManagementError): web.site(**values, maintenance=True, maintenance_reason='unsafe\nheader')

    def test_health_failure_after_hold_removal_updates_only_an_owned_maintenance_site(self):
        for live_site in (False, True):
            with self.subTest(live_site=live_site), tempfile.TemporaryDirectory() as directory:
                m, h, state = self.fixture()
                m.root = Path(directory); m.releases = Path('/srv/emailer/releases')
                h.hold = m.root / 'installer-hold.json'; h.hold.write_text('{}')
                h.state.update(phase='services-prepared', web_server='apache2', hostname='emailer.example.test', certificate='/test/cert.pem', certificate_key='/test/key.pem', site=str(m.root / 'site.conf'))
                text = h.site_text(maintenance=not live_site)
                Path(h.state['site']).write_text(text)
                claims = Mock(); claims.check.return_value = {'sha256': hashlib.sha256(text.encode()).hexdigest()}
                claims.remove.side_effect = lambda path: path.unlink(missing_ok=True)
                h.claims = Mock(return_value=claims); h.disable_cron = Mock(); h.apply_site = Mock()
                h.live = Mock(side_effect=ManagementError('Synthetic health verification failed; repair Doctor findings.'))
                m.run_menu = h.complete
                with patch('host.HostHandoff', return_value=h):
                    with self.assertRaises(ManagementError): m.run()
                self.assertFalse(h.hold.exists())
                self.assertEqual('failed', state['phase'])
                if live_site:
                    h.apply_site.assert_not_called()
                else:
                    h.apply_site.assert_called_once()
                    self.assertIn('X-Emailer-Update-State "failed"', h.apply_site.call_args.args[0])
                    self.assertIn('Emailer setup stopped', h.apply_site.call_args.args[0])

    def test_host_handoff_passes_failed_state_to_the_actual_web_generator(self):
        m, h, _ = self.fixture()
        m.releases = Path('/srv/emailer/releases')
        h.state.update(hostname='emailer.example.test', certificate='/test/cert.pem', certificate_key='/test/key.pem', maintenance_status='failed', maintenance_reason='host_storage')
        for server in ['apache2', 'nginx']:
            with self.subTest(server=server):
                h.state['web_server'] = server
                text = h.site_text(maintenance=True)
                self.assertIn('X-Emailer-Update-State "failed"', text)
                self.assertIn('X-Emailer-Update-Reason "host_storage"', text)
                self.assertNotIn('X-Emailer-Update-', h.site_text(maintenance=False))

    def test_successful_action_does_not_touch_a_delivery_hold(self):
        m, h, _ = self.fixture(); m.run_menu = Mock(return_value='done')
        with patch('host.HostHandoff', return_value=h): self.assertEqual('done', m.run())
        h.current_update_snapshot.assert_not_called(); h.database.assert_not_called()


if __name__ == '__main__': unittest.main()
