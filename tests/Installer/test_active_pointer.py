"""Follow only exact supported handoff paths after the newest native/edition update."""
import json
from pathlib import Path
import sys
import tempfile
import types
import unittest
from unittest.mock import patch
sys.path.insert(0, str(Path(__file__).resolve().parents[2] / 'installer'))
from manage import Manager, ManagementError

class ConfigurationReached(Exception):
    pass

class ActivePointer(unittest.TestCase):
    def test_completed_new_handoff_can_be_opened_again(self):
        for prefix, product, suffix in [('emailer-light', 'emailer-light', '126')]:
            with self.subTest(prefix=prefix, product=product), tempfile.TemporaryDirectory() as directory:
                root = Path(directory) / prefix
                root.mkdir()
                runtime = Path('/etc') / (prefix + '-' + product.removeprefix('emailer-') + '-' + suffix) / 'runtime.env'
                (root / 'active-installation.json').write_text(json.dumps({'format': 'emailer-active-installation-v1', 'product': product, 'runtime': str(runtime)}))
                with patch('manage.protected', side_effect=lambda p: p), patch('manage.read_environment', side_effect=ConfigurationReached) as read:
                    with self.assertRaises(ConfigurationReached):
                        Manager(types.SimpleNamespace(), root / 'runtime.env')
                    read.assert_called_once_with(runtime)

    def test_unlisted_or_ambiguous_paths_stop_before_runtime_read(self):
        for runtime in ['/etc/emailer-light-light-999/runtime.env', '/etc/emailer-light-light-126-other/runtime.env', '/etc/unrelated-light-126/runtime.env', '/etc/emailer-light-light-126/../runtime.env']:
            with self.subTest(runtime=runtime), tempfile.TemporaryDirectory() as directory:
                root = Path(directory) / 'emailer-light'
                root.mkdir()
                (root / 'active-installation.json').write_text(json.dumps({'format': 'emailer-active-installation-v1', 'product': 'emailer-light', 'runtime': runtime}))
                with patch('manage.protected', side_effect=lambda p: p), patch('manage.read_environment') as read:
                    with self.assertRaisesRegex(ManagementError, 'pointer is invalid'):
                        Manager(types.SimpleNamespace(), root / 'runtime.env')
                    read.assert_not_called()
