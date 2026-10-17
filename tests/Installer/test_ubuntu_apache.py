"""Safety boundaries for the native Ubuntu/Apache profile."""
import hashlib
import json
import os
from pathlib import Path
import sys
import tempfile
import types
import unittest
from unittest.mock import patch

sys.path.insert(0, str(Path(__file__).resolve().parents[2] / 'installer'))
from hosting import WebServer
from packages import ServicePolicy, HostLock, validate_plan
from provision import SetupError, durable
from host import HostHandoff
from manage import ManagementError


class ApacheProfile(unittest.TestCase):
    def test_profiles_choose_only_explicit_tested_combinations(self):
        with patch('hosting.shutil.which',return_value=None):
            for version in ('22.04','24.04'):
                self.assertEqual('apache2',WebServer.discover(('ubuntu',version),SetupError).kind)
            self.assertEqual('nginx',WebServer.discover(('debian','13'),SetupError).kind)
            for system in [('ubuntu','20.04'),('ubuntu','26.04'),('debian','12')]:
                with self.subTest(system=system),self.assertRaises(SetupError):WebServer.discover(system,SetupError)
        with patch('hosting.shutil.which',side_effect=lambda name:'/usr/sbin/nginx' if name=='nginx' else None),self.assertRaisesRegex(SetupError,'Existing Nginx'):
            WebServer.discover(('ubuntu','22.04'),SetupError)

    def test_parsed_apache_inventory_covers_single_vhost_alias_and_default(self):
        web=WebServer('apache2',SetupError)
        inventory='''*:80 legacy.example.test (/etc/apache2/sites-enabled/000-legacy.conf:1)
*:443 is a NameVirtualHost
 default server secure.example.test (/etc/apache2/sites-enabled/000-secure.conf:1)
 port 443 namevhost secure.example.test (/etc/apache2/sites-enabled/000-secure.conf:1)
 alias www.secure.example.test
 wild alias *.reserved.example.test
'''
        names=web.names(inventory)
        for host in ('legacy.example.test','secure.example.test','www.secure.example.test','new.reserved.example.test'):
            self.assertTrue(web.conflicts(host,names),host)
        self.assertFalse(web.conflicts('new.example.test',names))

    def test_source_file_with_another_site_or_include_is_preserved(self):
        with tempfile.TemporaryDirectory() as d:
            web=WebServer('apache2',SetupError);web.directory=Path(d)
            (web.directory/'sites-enabled').mkdir()
            site=web.directory/'sites-enabled/emailer.conf'
            base='''<VirtualHost *:443>
ServerName mailer.example.test
DocumentRoot /srv/emailer-light/releases/current/public
SSLCertificateFile /etc/letsencrypt/live/mailer/fullchain.pem
SSLCertificateKeyFile /etc/letsencrypt/live/mailer/privkey.pem
</VirtualHost>
'''
            for extra in ['ServerAlias unrelated.example.test\n','Include /etc/apache2/other.conf\n','ServerName unrelated.example.test\n','<VirtualHost 127.0.0.1:8080>\nServerName mailer.example.test\nDocumentRoot /var/www/unrelated-admin\n</VirtualHost>\n']:
                text=base+extra;site.write_text(text)
                with self.assertRaises(SetupError):web.source_site(Path('/srv/emailer-light/releases'),'mailer.example.test')
                self.assertEqual(text,site.read_text())
            for directive in ('AliasMatch ^/legacy/(.*)$ /var/www/unrelated/$1', 'ScriptAliasMatch ^/legacy/(.*)$ /var/www/unrelated/$1', 'ScriptAlias /legacy/ /var/www/unrelated/'):
                text=base.replace('</VirtualHost>',directive+'\n</VirtualHost>');site.write_text(text)
                with self.subTest(directive=directive),self.assertRaises(SetupError):
                    web.source_site(Path('/srv/emailer-light/releases'),'mailer.example.test')
                self.assertEqual(text,site.read_text())
            site.write_text(base)
            self.assertEqual(site,web.source_site(Path('/srv/emailer-light/releases'),'mailer.example.test')[0])

    def test_handoff_preserves_custom_routes_and_accepts_generated_sites(self):
        releases=Path('/srv/emailer-light/releases');host='mailer.example.test'
        for kind in ('apache2','nginx'):
            with self.subTest(kind=kind),tempfile.TemporaryDirectory() as directory:
                web=WebServer(kind,SetupError);web.directory=Path(directory)
                (web.directory/'sites-enabled').mkdir()
                site=web.directory/'sites-enabled/emailer.conf'
                for maintenance in (False,True):
                    text=web.site(hostname=host,name='emailer-light',public=releases/'current/public',webroot='/var/lib/emailer-acme',certificate='/etc/tls/fullchain.pem',certificate_key='/etc/tls/key.pem',maintenance=maintenance)
                    site.write_text(text)
                    self.assertEqual(site,web.source_site(releases,host)[0])
                if kind=='apache2':
                    extras=['VirtualDocumentRoot /srv/unrelated/%0','VirtualScriptAliasIP /srv/unrelated/%0','RewriteRule ^/legacy/(.*)$ /srv/unrelated/$1 [L]','RewriteRule ^ - [H=application/x-httpd-php,L]','RewriteRule ^ index.php [P,L]', '<Location /legacy/>\nSetHandler "proxy:http://127.0.0.1:8080"\n</Location>']
                    for extra in extras:
                        changed=text.replace('</VirtualHost>',extra+'\n</VirtualHost>',1);site.write_text(changed)
                        with self.subTest(extra=extra),self.assertRaises(SetupError):web.source_site(releases,host)
                        self.assertEqual(changed,site.read_text())
                else:
                    extras=['location /legacy/ { proxy_pass http://127.0.0.1:8080; }','include /etc/nginx/other-routes.conf;','location /legacy/ { uwsgi_pass unix:/run/unrelated.sock; }','set $fragment "#"; location /legacy/ { proxy_pass http://127.0.0.1:8080; }', 'rewrite ^ /unrelated last;', 'location /legacy/ { fastcgi_pass unix:/run/legacy.sock; fastcgi_param SCRIPT_FILENAME /srv/unrelated/index.php; }']
                    for extra in extras:
                        changed=text.replace('server {','server {\n'+extra,1);site.write_text(changed)
                        with self.subTest(extra=extra),self.assertRaises(SetupError):web.source_site(releases,host)
                        self.assertEqual(changed,site.read_text())
                    # The published1.1 installer used this same standard FastCGI
                    # include and dedicated HTTP/HTTPS shape (no custom routing).
                    published=(Path(__file__).parent/'fixtures/nginx-1.1.0.conf').read_text()
                    site.write_text('# Ordinary full-line comment\n'+published.replace('include fastcgi_params;','include fastcgi_params; # Ordinary end-line comment'))
                    self.assertEqual(site,web.source_site(releases,host)[0])

    def test_target_handoff_is_versioned_and_uses_apache_without_nginx(self):
        manager=types.SimpleNamespace(prefix='emailer-light',installer=types.SimpleNamespace(product='emailer-light',version='1.2.0'),record={},error=ManagementError,releases=Path('/srv/emailer-light/releases'))
        handoff=HostHandoff(manager)
        handoff.state.update(web_server='apache2',hostname='mailer.example.test',certificate='/etc/tls/fullchain.pem',certificate_key='/etc/tls/key.pem',acme_webroot='/var/lib/emailer-light-acme')
        held=handoff.site_text(maintenance=True);ready=handoff.site_text(maintenance=False)
        self.assertIn('emailer-light-light-120-fpm/php.sock',held)
        self.assertIn('RewriteRule ^ - [R=503,L]',held)
        self.assertNotIn('RewriteRule ^ - [R=503,L]',ready)
        self.assertIn('Alias /.well-known/acme-challenge/',held)
        self.assertNotIn('nginx',held)
        self.assertIn('AllowOverride None',ready)
        self.assertIn('-MultiViews',ready)
        self.assertNotIn('enablereuse',ready)


class AptBoundary(unittest.TestCase):
    def test_shared_runtime_upgrade_and_removal_plans_fail_before_install(self):
        for plan in ['Remv apache2 [2.4.52]','Inst apache2 [2.4.52] (2.4.63 Ubuntu)','Inst php8.1-common [8.1.2] (8.1.34 PPA)','Inst mariadb-server [10.6.28] (10.11.19 MariaDB)','Inst libc6 [2.35] (2.39 Ubuntu)','Inst php8.4-common [8.4.25] (8.4.26 PPA)']:
            with self.subTest(plan=plan),self.assertRaises(SetupError):validate_plan(plan,SetupError,('php-common','libgd3'))

    def test_new_versioned_php_and_explicit_abi_metadata_are_bounded(self):
        validate_plan('Inst php8.4-fpm (8.4.26 PPA)\nInst php-common [2:92] (2:101 PPA)\nInst libgd3 [2.3.0] (2.3.3 PPA)',SetupError,('php-common','libgd3'))
        with self.assertRaises(SetupError):validate_plan('Inst php-common [2:92] (2:101 PPA)',SetupError)


@unittest.skipUnless(os.geteuid()==0,'Ownership and crash-recovery tests run in the existing root CI gate')
class PackageServiceRecovery(unittest.TestCase):
    def setUp(self):
        self.temporary=tempfile.TemporaryDirectory();self.root=Path(self.temporary.name)
        (self.root/'format').write_text('emailer-host-installer-v1\n')
        self.installer=types.SimpleNamespace(state_dir=self.root,durable=durable,error=SetupError)
        self.policy=ServicePolicy(self.installer);self.policy.path=self.root/'policy-rc.d'
        self.policy.lock=HostLock(self.root/'transaction.lock',SetupError)

    def tearDown(self):
        if self.policy.descriptor is not None:
            self.policy.lock.__exit__();self.policy.descriptor=None
        self.temporary.cleanup()

    def parent_died(self):
        self.policy.lock.__exit__();self.policy.descriptor=None

    def test_existing_policy_bytes_and_mode_are_restored_after_task_failure(self):
        original=b'#!/bin/sh\nexit 103\n';self.policy.path.write_bytes(original);self.policy.path.chmod(0o751)
        os.setxattr(self.policy.path,'user.emailer-regression',b'preserved')
        with self.assertRaisesRegex(RuntimeError,'simulated'):
            with self.policy:
                self.assertEqual(101,__import__('subprocess').run([str(self.policy.path),'apache2','restart']).returncode)
                raise RuntimeError('simulated package failure')
        self.assertEqual(original,self.policy.path.read_bytes())
        self.assertEqual(b'preserved',os.getxattr(self.policy.path,'user.emailer-regression'))
        self.assertEqual(0o751,self.policy.path.stat().st_mode&0o777)
        self.assertFalse(self.policy.receipt.exists())

    def test_killed_task_recovery_removes_only_generated_policy(self):
        self.policy.__enter__()  # No __exit__: model a SIGKILL between APT stages.
        self.assertTrue(self.policy.receipt.exists())
        self.parent_died()
        ServicePolicyRecover=self.policy.recover
        ServicePolicyRecover()
        self.assertFalse(self.policy.path.exists());self.assertFalse(self.policy.receipt.exists())
        self.policy.recover()  # Repeat recovery is inert.

    def test_crash_before_publication_and_after_restoration_are_recoverable(self):
        original=b'#!/bin/sh\nexit 0\n';self.policy.path.write_bytes(original);self.policy.path.chmod(0o755)
        self.policy.__enter__()
        self.policy.path.write_bytes(original)  # either side of atomic publication
        self.parent_died()
        self.policy.recover()
        self.assertEqual(original,self.policy.path.read_bytes());self.assertFalse(self.policy.receipt.exists())

    def test_changed_administrator_policy_is_not_overwritten_on_recovery(self):
        self.policy.__enter__();changed=b'#!/bin/sh\nexit 102\n';self.policy.path.write_bytes(changed)
        self.parent_died()
        with self.assertRaisesRegex(SetupError,'administrator policy'):self.policy.recover()
        self.assertEqual(changed,self.policy.path.read_bytes());self.assertTrue(self.policy.receipt.exists())

    def test_absent_policy_deletion_is_committed_before_receipt_retirement(self):
        self.policy.__enter__();self.parent_died()
        sync=self.policy.sync_directory
        def power_loss(path):
            if path==self.policy.path.parent:
                self.assertFalse(self.policy.path.exists())
                self.assertTrue(self.policy.receipt.exists())
                raise RuntimeError('power loss before policy deletion commit')
            sync(path)
        with patch.object(self.policy,'sync_directory',side_effect=power_loss),self.assertRaisesRegex(RuntimeError,'power loss'):
            self.policy.recover()
        self.assertTrue(self.policy.receipt.exists())
        self.policy.recover();self.assertFalse(self.policy.receipt.exists())
        self.assertFalse(self.policy.path.exists())

    def test_shared_recovery_reservation_is_durable_before_policy_publication(self):
        installer=types.SimpleNamespace(state_dir=self.root/'new-shared-state',durable=durable,error=SetupError)
        policy=ServicePolicy(installer);policy.path=self.root/'policy-new'
        policy.lock=HostLock(self.root/'other.lock',SetupError)
        def power_loss(path):
            self.assertEqual(self.root,path)
            self.assertTrue((policy.root/'format').exists())
            self.assertFalse(policy.path.exists())
            raise RuntimeError('power loss after reservation rename')
        with patch.object(policy,'sync_directory',side_effect=power_loss),self.assertRaisesRegex(RuntimeError,'reservation rename'):
            policy.__enter__()
        self.assertFalse(policy.path.exists());self.assertFalse(policy.receipt.exists())
        with policy: self.assertTrue(policy.path.exists())
        self.assertFalse(policy.path.exists())

    def test_linked_original_is_rejected_without_following_it(self):
        outside=self.root/'outside';outside.write_bytes(b'protected')
        self.policy.path.symlink_to(outside)
        with self.assertRaisesRegex(SetupError,'protected regular file'):self.policy.__enter__()
        self.assertEqual(b'protected',outside.read_bytes())

    def test_partial_policy_restore_finishes_gid_mode_and_xattrs_after_crash(self):
        original=b'#!/bin/sh\nexit 103\n';self.policy.path.write_bytes(original);self.policy.path.chmod(0o751)
        os.setxattr(self.policy.path,'user.original',b'keep')
        os.chown(self.policy.path,0,1234)
        self.policy.__enter__()
        durable(self.policy.path,original,0o600)  # killed after bytes, before metadata
        self.parent_died();self.policy.recover()
        self.assertEqual((1234,0o751),(self.policy.path.stat().st_gid,self.policy.path.stat().st_mode&0o777))
        self.assertEqual(b'keep',os.getxattr(self.policy.path,'user.original'))
        self.assertFalse(self.policy.receipt.exists())

    def test_live_orphan_child_keeps_policy_fenced_until_it_exits(self):
        import subprocess
        self.policy.__enter__()
        child=subprocess.Popen([sys.executable,'-c','import time; time.sleep(30)'],pass_fds=(self.policy.descriptor,))
        try:
            self.parent_died()
            with self.assertRaisesRegex(SetupError,'still running'):self.policy.recover()
            self.assertTrue(self.policy.receipt.exists());self.assertTrue(self.policy.path.exists())
        finally:child.terminate();child.wait(timeout=5)
        self.policy.recover();self.assertFalse(self.policy.path.exists())

    def test_exit_during_live_inherited_child_retains_policy_and_receipt(self):
        import subprocess
        self.policy.__enter__()
        child=subprocess.Popen([sys.executable,'-c','import time; time.sleep(30)'],pass_fds=(self.policy.descriptor,))
        try:
            with self.assertRaisesRegex(SetupError,'still running'):
                self.policy.__exit__(KeyboardInterrupt,KeyboardInterrupt(),None)
            self.assertTrue(self.policy.receipt.exists())
            self.assertIn('exit 101',self.policy.path.read_text())
        finally:child.terminate();child.wait(timeout=5)
        self.policy.recover()
        self.assertFalse(self.policy.path.exists());self.assertFalse(self.policy.receipt.exists())

class ActualAptTransaction(unittest.TestCase):
    def guard(self):
        import importlib.util
        path=Path(__file__).resolve().parents[2]/'installer/apt-guard.py'
        spec=importlib.util.spec_from_file_location('emailer_apt_guard',path)
        module=importlib.util.module_from_spec(spec);spec.loader.exec_module(module)
        return module

    def test_actual_dependency_not_in_simulation_is_rejected_before_unpack(self):
        plan={'format':'emailer-apt-plan-v1','packages':{'php8.4-cli':{'version':'8.4.26','previous':None}}}
        def metadata(args,**kwargs):return types.SimpleNamespace(stdout='Package: apache2\nVersion: 2.4.99\nArchitecture: amd64\n',returncode=0)
        with self.assertRaisesRegex(ValueError,'unplanned'):self.guard().inspect(plan,['/cache/apache2.deb'],metadata)

    def test_shared_package_changed_between_simulation_and_install_is_rejected(self):
        plan={'format':'emailer-apt-plan-v1','packages':{'php-common':{'version':'2:101','previous':'2:92'}}}
        def metadata(args,**kwargs):
            if args[0]=='dpkg-deb':return types.SimpleNamespace(stdout='Package: php-common\nVersion: 2:101\nArchitecture: all\n',returncode=0)
            return types.SimpleNamespace(stdout='install ok installed\n2:100',returncode=0)
        with self.assertRaisesRegex(ValueError,'installed package changed'):self.guard().inspect(plan,['/cache/common.deb'],metadata)

    def test_exact_approved_transaction_accepts_old_or_already_completed_version(self):
        plan={'format':'emailer-apt-plan-v1','packages':{'php-common':{'version':'2:101','previous':'2:92'}}}
        for version in ('2:92','2:101'):
            def metadata(args,**kwargs):
                if args[0]=='dpkg-deb':return types.SimpleNamespace(stdout='Package: php-common\nVersion: 2:101\nArchitecture: all\n',returncode=0)
                return types.SimpleNamespace(stdout='install ok installed\n'+version,returncode=0)
            self.guard().inspect(plan,['/cache/common.deb'],metadata)

    def test_unpacked_and_half_configured_versions_are_not_treated_as_absent(self):
        plan={'format':'emailer-apt-plan-v1','packages':{'php8.4-cli':{'version':'8.4.26','previous':None}}}
        for state in ('install ok unpacked','install ok half-configured','install reinstreq half-installed','install ok triggers-awaited'):
            def metadata(args,**kwargs):
                if args[0]=='dpkg-deb':return types.SimpleNamespace(stdout='Package: php8.4-cli\nVersion: 8.4.26\nArchitecture: amd64\n',returncode=0)
                return types.SimpleNamespace(stdout=state+'\n8.4.26',returncode=0)
            with self.subTest(state=state),self.assertRaisesRegex(ValueError,'nonterminal'):
                self.guard().inspect(plan,['/cache/php.deb'],metadata)
