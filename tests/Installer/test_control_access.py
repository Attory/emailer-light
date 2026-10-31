"""Actual service identities/namespaces must supplement root-only installer health."""
from pathlib import Path
import sys
import unittest
from unittest.mock import patch

sys.path.insert(0, str(Path(__file__).resolve().parents[2] / 'installer'))
from provision import Installer, SetupError


class NonrootControlAccess(unittest.TestCase):
    def fixture(self):
        obj = Installer.__new__(Installer)
        obj.product = 'emailer-light'
        obj.version = '1.2.12'
        obj.name = 'emailer-repair'
        obj.user = 'emailer-repair'
        obj.php = '/usr/bin/php8.5'
        obj.bundle = Path(__file__).resolve().parents[2] / 'installer'
        obj.release = Path('/srv/emailer/releases/1.2.12')
        obj.private = Path('/var/lib/emailer/private')
        obj.delivery_control_identity = '01900000-0000-7000-8000-000000000001'
        return obj

    def test_pre_activation_probe_uses_actual_nonroot_identity_without_smtp(self):
        obj = self.fixture()
        with patch('provision.run', return_value='') as call:
            obj.verify_delivery_control_access()
        command = call.call_args.args[0]
        self.assertEqual(['env', '-i', 'PATH=/usr/sbin:/usr/bin:/sbin:/bin', 'LANG=C.UTF-8', 'runuser', '-u', obj.user, '--', obj.php, '-r'], command[:10])
        self.assertIn('held_claim_rejected', command[10])
        self.assertEqual([str(obj.release), str(obj.private), obj.delivery_control_identity], command[11:])

    def test_live_probe_enters_both_actual_service_mount_namespaces(self):
        obj = self.fixture()
        with patch('provision.run', side_effect=['321\n', '', '654\n', '']) as call:
            obj.verify_delivery_control_access(live=True)
        commands = [entry.args[0] for entry in call.call_args_list]
        self.assertEqual(['nsenter', '--target', '321', '--mount', '--', 'env', '-i', 'PATH=/usr/sbin:/usr/bin:/sbin:/bin', 'LANG=C.UTF-8', 'runuser', '-u', obj.user], commands[1][:12])
        self.assertEqual(['nsenter', '--target', '654', '--mount', '--', 'env', '-i', 'PATH=/usr/sbin:/usr/bin:/sbin:/bin', 'LANG=C.UTF-8', 'runuser', '-u', obj.user], commands[3][:12])
        self.assertIn(obj.name + '-fpm.service', commands[0])
        self.assertIn(obj.name + '-worker.service', commands[2])

    def test_missing_live_pid_cannot_pass_as_root_readiness(self):
        obj = self.fixture()
        with patch('provision.run', return_value='0\n') as call:
            with self.assertRaisesRegex(SetupError, 'delivery_control_unavailable'):
                obj.verify_delivery_control_access(live=True)
        self.assertEqual(1, call.call_count)
