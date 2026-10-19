"""Live endpoint ownership, not binary presence, defines Ubuntu coexistence."""
import os
from pathlib import Path
import sys
import types
import unittest
from unittest.mock import patch
sys.path.insert(0, str(Path(__file__).resolve().parents[2] / 'installer'))
from hosting import WebServer
from provision import Installer, SetupError


class ApacheCoexistence(unittest.TestCase):
    def inspect(self, rows, *, owned=('111', '112'), comm='apache2', pid='4321'):
        instance = object.__new__(Installer)
        instance.web = WebServer('apache2', SetupError)
        def read(path, *args, **kwargs):
            name = str(path)
            if name == '/proc/4321/comm': return comm + '\n'
            if name in ('/proc/net/tcp', '/proc/net/tcp6'):
                return 'header\n' + ''.join(f'{i}: {address}:{port:04X} 0:0000 0A 0:0 0:0 0 0 0 {inode}\n' for i, (table, address, port, inode) in enumerate(rows) if name.endswith('/' + table))
            raise AssertionError('Unexpected read: ' + name)
        def descriptors(path):
            return iter(Path('/proc/4321/fd') / inode for inode in owned)
        with patch.object(Path, 'read_text', read), patch.object(Path, 'iterdir', descriptors), patch('provision.os.readlink', side_effect=lambda p:'socket:['+p.name+']'), patch('provision.subprocess.run', return_value=types.SimpleNamespace(returncode=0, stdout=pid)) as service:
            instance.check_public_listeners()
        if any(row[2] in (80,443) for row in rows):
            service.assert_called_once_with(['systemctl','show','apache2.service','--property=MainPID','--value'], capture_output=True, text=True)

    def test_apache_public_endpoints_with_nginx8081_ipv4_and_ipv6(self):
        self.inspect([('tcp','00000000',80,'111'), ('tcp6','0'*32,443,'112'), ('tcp','00000000',8081,'222'), ('tcp6','0'*32,8081,'223')])

    def test_free_public_ports_allow_installing_apache_beside_nginx(self):
        self.inspect([('tcp','00000000',8081,'222')], owned=(), pid='0')

    def test_foreign_public_endpoints_fail_even_on_another_address(self):
        for table,address,port in [('tcp','00000000',80), ('tcp','0100007F',443), ('tcp','020200C0',80), ('tcp6','0'*32,443), ('tcp6','0'*31+'1',80)]:
            with self.subTest(table=table,address=address,port=port), self.assertRaisesRegex(SetupError, f'Port {port}.*outside'):
                self.inspect([('tcp','00000000',80,'111'), (table,address,port,'999')])

    def test_service_pid_and_executable_must_both_match(self):
        for comm,pid in [('nginx','4321'), ('apache2','0'), ('apache2','invalid')]:
            with self.subTest(comm=comm,pid=pid), self.assertRaisesRegex(SetupError,'outside'):
                self.inspect([('tcp','00000000',80,'111')],comm=comm,pid=pid)

    def test_inspection_failure_is_closed(self):
        instance=object.__new__(Installer);instance.web=WebServer('apache2',SetupError)
        with patch.object(Path,'read_text',side_effect=PermissionError), self.assertRaisesRegex(SetupError,'could not safely identify'):
            instance.check_public_listeners()

    def test_apache_address_specific_vhosts_cannot_shadow_new_site(self):
        web=WebServer('apache2',SetupError)
        web.check_layout('*:80 is a NameVirtualHost\n*:443 secure.example.test (/etc/apache2/sites-enabled/site:1)\n127.0.0.1:8081 internal.example.test (/etc/apache2/sites-enabled/internal:1)\n')
        for address in ('127.0.0.1','192.0.2.2','[::]','[::1]'):
            for port in (80,443):
                with self.subTest(address=address,port=port), self.assertRaisesRegex(SetupError,'Emailer Light supports only wildcard.*requires Emailer Ripe'):
                    web.check_layout(f'{address}:{port} is a NameVirtualHost\n')

    def test_wildcard_port_vhosts_are_outside_both_profiles(self):
        web=WebServer('apache2',SetupError)
        for selector in ('*:0','192.0.2.2:*','_default_:*'):
            with self.subTest(selector=selector),self.assertRaisesRegex(SetupError,'outside both'):
                web.check_layout(selector+' is a NameVirtualHost\n')

    def test_preflight_cannot_skip_listener_proof_on_fresh_or_managed_runs(self):
        # Use real preflight flow; stop exactly at the mandatory live inspection.
        instance=object.__new__(Installer);instance.journal=Path('/synthetic/state.json')
        instance.base=Path('/synthetic/app');instance.private=Path('/synthetic/private');instance.backups=Path('/synthetic/backups');instance.config=Path('/synthetic/config');instance.user='synthetic-emailer'
        for managing in (False, True):
            with self.subTest(managing=managing), patch('provision.os.geteuid',return_value=0), patch('provision.platform.machine',return_value='x86_64'), patch.object(Path,'read_text',return_value='ID=ubuntu\nVERSION_ID="22.04"'), patch.object(Path,'is_dir',return_value=True), patch.object(Path,'exists',return_value=True), patch('provision.shutil.disk_usage',return_value=types.SimpleNamespace(free=10*1024**3)), patch('provision.shutil.which',side_effect=lambda n:'/usr/sbin/'+n if n in ('apache2','nginx','caddy') else None), patch('isolated_database.DatabaseProfile.preflight',side_effect=lambda:setattr(instance,'database_profile','isolated')), patch.object(WebServer,'configuration',return_value='*:80 is a NameVirtualHost\n*:443 is a NameVirtualHost'), patch.object(instance,'check_public_listeners',side_effect=SetupError('synthetic-live-conflict')) as inspection, self.assertRaisesRegex(SetupError,'synthetic-live-conflict'):
                instance.preflight(managing=managing)
            inspection.assert_called_once()
