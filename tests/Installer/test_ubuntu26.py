"""Native Ubuntu26 runtime selection must not change shared runtimes or sources."""
import os
import json
import subprocess
from pathlib import Path
import sys
import tempfile
import types
import unittest
from unittest.mock import Mock, patch

sys.path.insert(0, str(Path(__file__).resolve().parents[2] / 'installer'))
from packages import ubuntu_php_source, validate_plan, retained_preferences
from provision import Installer, SetupError, generated_launcher
from manage import Manager, ManagementError


class Ubuntu26Runtime(unittest.TestCase):
    def test_os_selects_native85_before_any_mutation_and_older_profiles_stay84(self):
        for system, expected in [(('ubuntu', '26.04'), '8.5'), (('ubuntu', '24.04'), '8.4'), (('ubuntu', '22.04'), '8.4'), (('debian', '13'), '8.4')]:
            with self.subTest(system=system):
                instance = object.__new__(Installer)
                text = 'ID=' + system[0] + '\nVERSION_ID=' + system[1] + '\n'
                with patch('provision.os.geteuid', return_value=0), patch.object(Path, 'read_text', return_value=text), patch.object(Path, 'is_dir', return_value=True), patch('hosting.shutil.which', return_value=None), patch('provision.platform.machine', return_value='x86_64'), patch('provision.shutil.disk_usage', return_value=types.SimpleNamespace(free=0)):
                    with self.assertRaisesRegex(SetupError, '2 GiB'):
                        instance.preflight()
                self.assertEqual('/usr/bin/php' + expected, instance.php)
                self.assertEqual('/usr/sbin/php-fpm' + expected, instance.php_fpm)

    def test_constructor_reenters_exact_managed_release_paths_and_rejects_other_targets(self):
        class SelectedConfiguration(Exception):
            pass
        selected = [('emailer-light', '/etc/emailer-light-light-124/runtime.env'), ('emailer-light', '/etc/emailer-light-light-125/runtime.env')]
        selected.append(('emailer-light', '/etc/emailer-light-light-127/runtime.env'))
        selected.append(('emailer-light', '/etc/emailer-light-light-128/runtime.env'))
        for product, runtime in selected:
            pointer = {'format': 'emailer-active-installation-v1', 'product': product, 'runtime': runtime}
            with self.subTest(runtime=runtime), patch.object(Path, 'exists', return_value=True), patch('manage.protected', return_value=types.SimpleNamespace(read_text=lambda: json.dumps(pointer))), patch('manage.read_environment', side_effect=SelectedConfiguration) as reader:
                with self.assertRaises(SelectedConfiguration):
                    Manager(types.SimpleNamespace(), '/etc/emailer-light/runtime.env')
                reader.assert_called_once_with(Path(runtime))
        for runtime in ['/etc/emailer-light-light-129/runtime.env', '/etc/emailer-light-ripe-132/runtime.env', '/tmp/emailer-light-light-124/runtime.env', '/etc/emailer-light-light-124/../runtime.env']:
            pointer = {'format': 'emailer-active-installation-v1', 'product': 'emailer-light', 'runtime': runtime}
            with self.subTest(rejected=runtime), patch.object(Path, 'exists', return_value=True), patch('manage.protected', return_value=types.SimpleNamespace(read_text=lambda: json.dumps(pointer))), patch('manage.read_environment') as reader:
                with self.assertRaisesRegex(ManagementError, 'active installation pointer is invalid'):
                    Manager(types.SimpleNamespace(), '/etc/emailer-light/runtime.env')
                reader.assert_not_called()

    def test_real_outer_os_gate_accepts26_and_rejects_unsupported_before_bootstrap(self):
        source = (Path(__file__).resolve().parents[2] / 'installer/setup.in').read_text()
        gate = source[source.index('case "${ID:-}:${VERSION_ID:-}"'):source.index('if [ "$(uname -m)"')]
        for version, accepted in [('22.04', True), ('24.04', True), ('26.04', True), ('25.10', False), ('26.10', False)]:
            with self.subTest(version=version):
                result = subprocess.run(['/bin/sh', '-c', gate + '\nprintf accepted'], env={**os.environ, 'ID': 'ubuntu', 'VERSION_ID': version}, capture_output=True, text=True)
                self.assertEqual(accepted, result.returncode == 0)
                self.assertEqual(accepted, result.stdout == 'accepted')

    def test_native_profile_never_reads_a_ppa_key_or_writes_repository_files(self):
        installer = types.SimpleNamespace(system=('ubuntu', '26.04'), bundle=Path('/absent'), error=SetupError, durable=Mock(side_effect=AssertionError('No PPA mutation permitted')))
        with patch.object(Path, 'read_text', side_effect=AssertionError('No PPA key needed')):
            ubuntu_php_source(installer)
        installer.durable.assert_not_called()

    def test_missing85_extensions_match_existing_patch_and_both_global_selections_are_retained(self):
        instance = object.__new__(Installer)
        instance.php = '/usr/bin/php8.5'
        instance.system = ('ubuntu', '26.04')
        instance.preserve_php_selection = Mock(return_value=None)
        instance.install_missing_packages = Mock()
        installed = {'ca-certificates', 'curl', 'unzip', 'openssl', 'php8.5-cli', 'php8.5-fpm'}
        def query(args, **kwargs):
            self.assertEqual('dpkg-query', args[0])
            if args[-1] == 'php8.5-common':
                return types.SimpleNamespace(returncode=0, stdout='install ok installed 8.5.4-0ubuntu1.5')
            return types.SimpleNamespace(returncode=0 if args[-1] in installed else 1, stdout='install ok installed' if args[-1] in installed else '')
        with patch('provision.subprocess.run', side_effect=query), patch('provision.run') as host_run:
            instance.dependencies(runtime_only=True)
        missing = instance.install_missing_packages.call_args.args[0]
        self.assertEqual({'php8.5-' + suffix + '=8.5.4-0ubuntu1.5' for suffix in ('curl', 'gd', 'intl', 'mbstring', 'mysql', 'xml', 'zip')}, set(missing))
        self.assertEqual(['php8.5-cli', 'php8.5-fpm'], [c.kwargs['package'] for c in instance.preserve_php_selection.call_args_list])
        self.assertEqual('/usr/bin/php8.5', host_run.call_args.args[0][0])

    def test_services_and_protected_launcher_use_same85_runtime(self):
        instance = object.__new__(Installer)
        instance.php = '/usr/bin/php8.5'
        instance.name = instance.user = instance.product = 'emailer-light'
        instance.database_profile = 'shared'
        instance.release = Path(__file__).resolve().parents[2]
        instance.bundle = instance.release / 'installer'
        instance.base = Path('/srv/emailer-light')
        instance.config = Path('/etc/emailer-light')
        instance.private = Path('/var/lib/emailer-light/private')
        instance.keys = Path('/var/lib/emailer-light/keys')
        instance.backups = Path('/var/backups/emailer-light')
        files, units = {}, {}
        claims = types.SimpleNamespace(check_all=lambda: None, write=lambda path, text, mode: files.update({str(path): text}))
        instance.claims = lambda: claims
        instance.check_unit = Mock()
        instance.service_file = lambda name, text: units.update({name: text})
        with patch('provision.run') as host_run:
            instance.services()
        self.assertIn('ExecStart=/usr/sbin/php-fpm8.5 ', units['emailer-light-fpm.service'])
        for role in ('worker', 'scheduler', 'operations'):
            self.assertIn('ExecStart=/usr/bin/php8.5 ', units['emailer-light-' + role + '.service'])
        launcher = files['/usr/local/sbin/emailer-light']
        self.assertIn("os.execve('/usr/bin/php8.5', ['/usr/bin/php8.5',", launcher)
        self.assertNotIn('php8.4', launcher)
        compile(launcher, 'generated-launcher', 'exec')
        self.assertIn(['/usr/sbin/php-fpm8.5', '--test', '--fpm-config', '/etc/emailer-light/fpm.conf'], [c.args[0] for c in host_run.call_args_list])

    def test_manager_uses_the_selected_target_runtime(self):
        manager = object.__new__(Manager)
        manager.installer = types.SimpleNamespace(php='/usr/bin/php8.5')
        manager.bootstrap = Path('/private/verified-runtime')
        manager.environment = {}
        manager.call = Mock(return_value='healthy')
        self.assertEqual('healthy', manager.console(['emailer:health:verify-runtime']))
        self.assertEqual('/usr/bin/php8.5', manager.call.call_args.args[0][0])

    def test_runtime_is_bounded_and_original_launcher_remains_the_default(self):
        bundle = Path(__file__).resolve().parents[2] / 'installer'
        args = (bundle, 'emailer-light', 'emailer-light', '/etc/emailer-light/runtime.env', '/srv/emailer-light/releases/current')
        self.assertEqual(generated_launcher(*args), generated_launcher(*args, php='/usr/bin/php8.4'))
        for php in ('/usr/bin/php', '/usr/bin/php8.6', '/tmp/php8.5', '/usr/bin/php8.5;id'):
            with self.subTest(php=php), self.assertRaises(SetupError):
                generated_launcher(*args, php=php)

    def test_existing_native_php_and_shared_metadata_cannot_be_upgraded(self):
        for output in ('Inst php8.5-common [8.5.4-0ubuntu1.3] (8.5.4-0ubuntu1.5 Ubuntu)', 'Inst php8.5-fpm [8.5.4-0ubuntu1.3] (8.5.4-0ubuntu1.5 Ubuntu)', 'Inst php-common [2:99] (2:100 Ubuntu)', 'Inst libgd3 [2.3.3] (2.3.4 Ubuntu)', 'Remv webmin [2.0]'):
            with self.subTest(output=output), self.assertRaises(SetupError):
                validate_plan(output, SetupError)
        validate_plan('Inst php8.5-zip (8.5.4-0ubuntu1.5 Ubuntu)', SetupError)

    def test_other_php_family_is_retained_but_selected_family_is_never_upgraded(self):
        plan = 'Inst php8.4-common [8.4.26] (8.4.27 PPA)\nInst php8.5-common [8.5.4-0ubuntu1.3] (8.5.4-0ubuntu1.5 Ubuntu)'
        for target, retained, selected in [('php8.5-', 'php8.4-common', 'php8.5-common'), ('php8.4-', 'php8.5-common', 'php8.4-common')]:
            with self.subTest(target=target), tempfile.TemporaryDirectory() as directory:
                with retained_preferences(plan, (), directory, SetupError, php_prefix=target) as options:
                    text = Path(options[-1].split('=', 1)[1]).read_text()
                    self.assertIn('Package: ' + retained + '\n', text)
                    self.assertNotIn('Package: ' + selected + '\n', text)
                with self.assertRaises(SetupError):
                    validate_plan(next(line for line in plan.splitlines() if line.startswith('Inst ' + selected + ' ')), SetupError)
