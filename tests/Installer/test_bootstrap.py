"""Execute the shell-only bootstrap with synthetic APT and real filesystem locks."""
import os
from pathlib import Path
import shutil
import signal
import subprocess
import tempfile
import unittest


@unittest.skipUnless(os.geteuid() == 0, 'Root ownership gates run in the existing root CI job')
class VerificationBootstrap(unittest.TestCase):
    def setUp(self):
        self.temporary = tempfile.TemporaryDirectory()
        self.root = Path(self.temporary.name)
        self.bin = self.root / 'bin'
        self.bin.mkdir()
        for tool in ('python3', 'apt-get', 'dpkg-query', 'dpkg-deb', 'flock', 'stat', 'sha256sum', 'sync', 'cp', 'awk', 'mktemp', 'cat', 'cut', 'rmdir', 'mv', 'rm', 'chmod', 'sh', 'sleep'):
            executable = shutil.which(tool)
            self.assertIsNotNone(executable)
            (self.bin / tool).symlink_to(executable)
        for relative in ('var/lib', 'usr/sbin', 'run/lock'):
            (self.root / relative).mkdir(parents=True)
        self.policy = self.root / 'usr/sbin/policy-rc.d'
        self.state = self.root / 'var/lib/emailer-bootstrap-installer'
        self.original = b'#!/bin/sh\n# synthetic pre-existing host policy\nexit 103\n'
        self.policy.write_bytes(self.original)
        self.policy.chmod(0o751)
        os.chown(self.policy, 0, 1234)
        os.setxattr(self.policy, 'user.bootstrap-test', b'preserved')
        source = (Path(__file__).resolve().parents[2] / 'installer/bootstrap.sh.in').read_text()
        for prefix in ('/var/lib', '/usr/sbin', '/run/lock'):
            source = source.replace(prefix, str(self.root / prefix[1:]))
        self.script = self.root / 'bootstrap.sh'
        self.script.write_text('set -eu\n' + source)
        self.mock('dpkg-deb', '''case "$3" in Package) echo openssl;; Version) echo 3.0.99;; Architecture) echo amd64;; *) exit 1;; esac''')
        self.mock('dpkg-query', '''if [ "$BOOTSTRAP_TEST_MODE" = changed ]; then printf 'install ok installed\\n3.0.0'; elif [ "$BOOTSTRAP_TEST_MODE" = partial ]; then printf 'install ok unpacked\\n3.0.99'; else exit 1; fi''')
        self.mock('apt-get', '''case " $* " in
 *' update '*) exit 0;;
 *' --simulate '*) if [ "$BOOTSTRAP_TEST_MODE" = upgrade ]; then echo 'Inst apache2 [2.4.52] (2.4.99 Ubuntu [amd64])'; else echo 'Inst openssl (3.0.99 Ubuntu [amd64])'; fi; exit 0;;
 *' install '*)
   policy_status=0; "$BOOTSTRAP_TEST_ROOT/usr/sbin/policy-rc.d" || policy_status=$?
   [ "$policy_status" = 101 ] || exit 20
   echo /cache/openssl.deb | sh "$BOOTSTRAP_TEST_ROOT/var/lib/emailer-bootstrap-installer/guard" || exit 21
   if [ "$BOOTSTRAP_TEST_MODE" = orphan ]; then sleep 30 >/dev/null 2>&1 & echo $! > "$BOOTSTRAP_TEST_ROOT/child.pid"; exit 22; fi
   touch "$BOOTSTRAP_TEST_ROOT/unpacked"
   exit 0;;
 *) exit 23;; esac''')
        (self.bin / 'touch').symlink_to(shutil.which('touch'))

    def mock(self, name, body):
        path = self.bin / name
        path.unlink()
        path.write_text('#!/bin/sh\nset -eu\n' + body + '\n')
        path.chmod(0o755)

    def run_bootstrap(self, mode):
        return subprocess.run(['/bin/sh', str(self.script)], env={**os.environ, 'PATH': str(self.bin), 'BOOTSTRAP_TEST_ROOT': str(self.root), 'BOOTSTRAP_TEST_MODE': mode}, capture_output=True, text=True, timeout=10)

    def assert_policy_restored(self):
        self.assertEqual(self.original, self.policy.read_bytes())
        self.assertEqual((0, 1234, 0o751), (self.policy.stat().st_uid, self.policy.stat().st_gid, self.policy.stat().st_mode & 0o777))
        self.assertEqual(b'preserved', os.getxattr(self.policy, 'user.bootstrap-test'))
        self.assertFalse(self.state.exists())

    def tearDown(self):
        pid = self.root / 'child.pid'
        if pid.exists():
            try:
                os.kill(int(pid.read_text()), signal.SIGTERM)
            except ProcessLookupError:
                pass
        self.temporary.cleanup()

    def test_missing_verifier_installs_under_fence_and_restores_all_host_policy_metadata(self):
        result = self.run_bootstrap('success')
        self.assertEqual(0, result.returncode, result.stderr)
        self.assertTrue((self.root / 'unpacked').exists())
        self.assert_policy_restored()

    def test_shared_dependency_upgrade_is_rejected_before_policy_or_package_mutation(self):
        result = self.run_bootstrap('upgrade')
        self.assertNotEqual(0, result.returncode)
        self.assertIn('would change existing host packages', result.stderr)
        self.assertFalse((self.root / 'unpacked').exists())
        self.assert_policy_restored()

    def test_actual_transaction_change_is_rejected_before_unpack(self):
        result = self.run_bootstrap('changed')
        self.assertNotEqual(0, result.returncode)
        self.assertIn('No package was unpacked', result.stderr)
        self.assertFalse((self.root / 'unpacked').exists())
        self.assert_policy_restored()

    def test_intervening_unpacked_package_is_not_treated_as_absent(self):
        result = self.run_bootstrap('partial')
        self.assertNotEqual(0, result.returncode)
        self.assertIn('No package was unpacked', result.stderr)
        self.assertFalse((self.root / 'unpacked').exists())
        self.assert_policy_restored()

    def test_surviving_descendant_retains_fence_until_a_new_lock_can_be_acquired(self):
        result = self.run_bootstrap('orphan')
        self.assertNotEqual(0, result.returncode)
        self.assertIn('subprocess is still running', result.stderr)
        self.assertTrue(self.state.exists())
        self.assertIn('exit 101', self.policy.read_text())
        pid = int((self.root / 'child.pid').read_text())
        os.kill(pid, signal.SIGTERM)
        # flock, rather than a timer assumption, proves the descendant is gone.
        subprocess.run(['/usr/bin/flock', '-w', '5', str(self.root / 'run/lock/emailer-package-task.lock'), '/bin/true'], check=True)
        result = self.run_bootstrap('success')
        self.assertEqual(0, result.returncode, result.stderr)
        self.assert_policy_restored()
