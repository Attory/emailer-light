"""Native recovery follows the authenticated source runtime, including chained updates."""
import json
from pathlib import Path
import sys
import tempfile
import types
import unittest
from unittest.mock import Mock, patch

sys.path.insert(0, str(Path(__file__).resolve().parents[2] / 'installer'))
from manage import Manager, ManagementError


class NativeOperationLocation(unittest.TestCase):
    def manager(self, root, source, managed, phase):
        manager = object.__new__(Manager)
        manager.root = root / 'emailer-light'
        configuration = root / ('emailer-light-light-123' if managed else 'emailer-light') / 'runtime.env'
        configuration.parent.mkdir(parents=True, exist_ok=True)
        manager.original_configuration = configuration
        manager.configuration = configuration
        manager.installer = types.SimpleNamespace(product='emailer-light', version='1.2.4')
        manager.package = root / 'bundle/emailer-light-1.2.4.zip'
        manager.product = 'emailer-light'
        manager.source_version = source
        manager.record = {}
        manager.environment = {}
        manager.save = Mock()
        manager.stop_services = Mock()
        manager.restore_services = Mock()
        manager.console = Mock(return_value=json.dumps({'delivery_held': True}))
        operation = configuration.parent / ('native-updates/light-' + source + '-to-1.2.4.json')
        operation.parent.mkdir()
        operation.write_text(json.dumps({'phase': phase}))
        return manager, operation

    def test_post_activation_failure_does_not_restore_only_hosting_when_core_journal_exists(self):
        for source, managed in [('1.0.0', False), ('1.2.3', True)]:
            with self.subTest(source=source), tempfile.TemporaryDirectory() as directory:
                manager, operation = self.manager(Path(directory), source, managed, 'activated')
                manager.console.side_effect = ManagementError('Synthetic post-activation readiness rejection')
                with patch('host.HostHandoff') as handoff:
                    with self.assertRaisesRegex(ManagementError, 'readiness rejection'):
                        manager.native_action('1')
                    handoff.return_value.recover_host.assert_not_called()
                    manager.restore_services.assert_not_called()
                    self.assertEqual('activated', json.loads(operation.read_text())['phase'])

    def test_failure_before_any_core_journal_restores_only_the_prepared_host(self):
        with tempfile.TemporaryDirectory() as directory:
            manager, operation = self.manager(Path(directory), '1.2.3', True, 'unused')
            operation.unlink()
            manager.console.side_effect = ManagementError('Synthetic pre-journal rejection')
            with patch('host.HostHandoff') as handoff:
                with self.assertRaisesRegex(ManagementError, 'pre-journal rejection'):
                    manager.native_action('1')
                handoff.return_value.recover_host.assert_called_once()
                manager.restore_services.assert_called_once()

    def test_recover_calls_core_before_restoring_host_and_uses_retained_source_on_reentry(self):
        with tempfile.TemporaryDirectory() as directory:
            manager, operation = self.manager(Path(directory), '1.2.3', True, 'activated')
            manager.source_version = '1.2.4'
            manager.record['native_source'] = '1.2.3'
            ordering = Mock()
            ordering.attach_mock(manager.console, 'core')
            with patch('manage.protected', side_effect=lambda p: p), patch('host.HostHandoff') as handoff:
                ordering.attach_mock(handoff.return_value.recover_host, 'host')
                manager.native_action('2')
                self.assertEqual(['core', 'host'], [c[0] for c in ordering.mock_calls])
                self.assertIn('--recover', manager.console.call_args.args[0])
                self.assertIn('emailer-light-1.2.3.zip', manager.console.call_args.args[0][2])
                handoff.return_value.stop_target.assert_called_once()

    def test_finish_finds_the_source_journal_and_finishes_the_recorded_handoff(self):
        with tempfile.TemporaryDirectory() as directory:
            manager, operation = self.manager(Path(directory), '1.2.3', True, 'activated')
            with patch('host.HostHandoff') as handoff:
                manager.native_action('3')
                handoff.return_value.adopt.assert_called_once()
                handoff.return_value.complete.assert_called_once()
                manager.console.assert_called_once()

    def test_completed_chained_update_cannot_be_recovered_as_a_downgrade(self):
        with tempfile.TemporaryDirectory() as directory:
            manager, operation = self.manager(Path(directory), '1.2.3', True, 'completed')
            with patch('manage.protected', side_effect=lambda p: p), patch('host.HostHandoff') as handoff:
                with self.assertRaisesRegex(ManagementError, 'already succeeded'):
                    manager.native_action('2')
                handoff.assert_not_called()
                manager.console.assert_not_called()
