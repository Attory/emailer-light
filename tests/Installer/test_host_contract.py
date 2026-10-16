import json
from pathlib import Path
import sys
import types
import unittest
from unittest.mock import patch

sys.path.insert(0, str(Path(__file__).resolve().parents[2] / 'installer'))
from runtime_env import parse_environment_text, RuntimeEnvironmentError
from host import HostHandoff
from provision import Installer, SetupError


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
        manager = types.SimpleNamespace(prefix='emailer-light', installer=types.SimpleNamespace(product='emailer-light'), record={}, releases=Path('/srv/emailer-light/releases'))
        handoff = HostHandoff(manager)
        challenge = 'location ^~ /.well-known/acme-challenge/ { root /var/lib/emailer-light-acme; }'
        handoff.state.update(hostname='mailer.example.test', certificate='/etc/letsencrypt/live/mailer/fullchain.pem', certificate_key='/etc/letsencrypt/live/mailer/privkey.pem', acme_locations=[challenge])
        for maintenance in (True, False):
            site = handoff.site_text(maintenance=maintenance)
            self.assertIn(challenge, site)
            self.assertIn('access_log off;', site)
            self.assertIn('fastcgi_pass unix:/run/emailer-light-light-110-fpm/php.sock;', site)
            self.assertEqual(maintenance, 'deny all;' in site)


class PackageFailureGuidance(unittest.TestCase):
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


if __name__ == '__main__':
    unittest.main()
