"""Exercise the actual launcher preamble with and without a controlling terminal."""
import os
from pathlib import Path
import pty
import subprocess
import tempfile
import unittest


@unittest.skipUnless(os.geteuid() == 0, 'Launcher host authorization requires root')
class SetupTerminal(unittest.TestCase):
    def setUp(self):
        self.temporary = tempfile.TemporaryDirectory()
        root = Path(self.temporary.name)
        release = root / 'os-release'
        release.write_text('ID=ubuntu\nVERSION_ID="22.04"\n')
        systemd = root / 'systemd'
        systemd.mkdir()
        text = (Path(__file__).resolve().parents[2] / 'installer/setup.in').read_text().split('@BOOTSTRAP@', 1)[0]
        text = text.replace('/etc/os-release', str(release)).replace('/run/systemd/system', str(systemd))
        self.script = root / 'setup.sh'
        self.script.write_text(text + "printf 'BOOTSTRAP_REACHED\\n'\n")

    def tearDown(self):
        self.temporary.cleanup()

    def test_noninteractive_launch_stops_before_bootstrap_with_actionable_diagnostic(self):
        result = subprocess.run(['sh', str(self.script)], capture_output=True, text=True, start_new_session=True)
        self.assertEqual(1, result.returncode)
        self.assertIn('interactive terminal', result.stderr)
        self.assertIn('ssh -t', result.stderr)
        self.assertNotIn('BOOTSTRAP_REACHED', result.stdout)
        self.assertNotIn('verification failed', result.stderr)

    def test_controlling_terminal_reaches_bootstrap(self):
        child, descriptor = pty.fork()
        if child == 0:
            os.execl('/bin/sh', 'sh', str(self.script))
        output = b''
        try:
            while True:
                try:
                    chunk = os.read(descriptor, 4096)
                except OSError:
                    break
                if not chunk:
                    break
                output += chunk
        finally:
            os.close(descriptor)
            _, status = os.waitpid(child, 0)
        self.assertEqual(0, os.waitstatus_to_exitcode(status))
        self.assertIn(b'BOOTSTRAP_REACHED', output)
