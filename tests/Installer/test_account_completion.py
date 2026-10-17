"""A successful subprocess exit alone cannot publish an incomplete account setup."""
import json
import os
from pathlib import Path
import sys
import tempfile
import types
import unittest
from unittest.mock import Mock, patch

sys.path.insert(0, str(Path(__file__).resolve().parents[2] / 'installer'))
from provision import Installer, SetupError


class AccountCompletion(unittest.TestCase):
    def instance(self, directory):
        i=object.__new__(Installer)
        i.private=Path(directory)
        i.user='synthetic-account'
        i.product='emailer-light'
        i.version='1.2.0'
        i.state={'database':'emailer_synthetic', 'completed':[]}
        i.database_run=Mock(return_value='0')
        i.console=Mock(return_value='')
        i.save=Mock()
        return i

    def test_interrupted_cli_with_zero_exit_does_not_complete_account_phase(self):
        with tempfile.TemporaryDirectory() as directory:
            i=self.instance(directory)
            with self.assertRaisesRegex(SetupError,'interrupted before completion'):
                i.phase('Creating your administrator account',i.application)
            self.assertNotIn('Creating your administrator account',i.state['completed'])
            i.console.assert_any_call(['emailer:setup:install','--guided'],interactive=True)

    @unittest.skipUnless(os.geteuid()==0,'Completion record custody is checked by the root CI gate')
    def test_owned_lock_and_database_completion_must_agree_before_publication(self):
        with tempfile.TemporaryDirectory() as directory:
            i=self.instance(directory)
            path=i.private/'install.lock'
            path.write_text(json.dumps({'product_id':i.product,'product_version':i.version,'installation_uuid':'01990606-0000-7000-8000-000000000001'}))
            path.chmod(0o600);os.chown(path,1234,1234)
            with patch('provision.pwd.getpwnam',return_value=types.SimpleNamespace(pw_uid=1234)):
                with self.assertRaisesRegex(SetupError,'not complete'):i.application_completed()
                i.database_run.return_value='1'
                self.assertTrue(i.application_completed())
                path.chmod(0o644)
                with self.assertRaisesRegex(SetupError,'unsafe ownership or permissions'):i.application_completed()
