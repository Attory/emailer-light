import contextlib
import copy
import io
import json
from pathlib import Path
import sys
import tempfile
import types
import unittest
from unittest.mock import Mock, patch

sys.path.insert(0, str(Path(__file__).resolve().parents[2] / 'installer'))
from host import HostHandoff
from manage import ManagementError


class UpdateDeliveryRestoration(unittest.TestCase):
    def fixture(self, *, prior=1, active=False, phase='in_progress', accepting=0, findings=None, uncertain=0):
        m = types.SimpleNamespace(prefix='emailer', product='emailer-ripe', private=Path('/var/lib/emailer/private'),
                                  directory=Path('/etc/emailer/installer/133'), record={}, error=ManagementError, save=Mock(),
                                  installer=types.SimpleNamespace(product='emailer-ripe', version='1.3.3'))
        h = HostHandoff(m)
        h.state['delivery_snapshot'] = {'format':'emailer-update-delivery-v1', 'phase':phase, 'prior_accepting_work':prior,
            'active_claims':0, 'campaigns':[{'id':'01900000-0000-7000-8000-000000000001','state':'sending','remaining':2,'sent':3}] if active else []}
        h.current_update_snapshot = Mock(side_effect=lambda: copy.deepcopy(h.state['delivery_snapshot']))
        h.live = Mock(return_value=json.dumps({'runtime_verified':True,'findings':findings or []}))
        h.database = Mock(return_value=json.dumps({'accepting_work':accepting,'active_claims':0,'processing':0,'test_processing':0,'uncertain':uncertain}))
        def mark(value, reason=''):
            h.state['delivery_snapshot'] = {**h.state['delivery_snapshot'], 'phase':value, 'reason':reason}
        h.update_delivery_phase = Mock(side_effect=mark)
        return h

    def test_idle_optional_smtp_does_not_trigger_resume_or_warning(self):
        h=self.fixture(findings=[{'code':'sender_identity','severity':'critical','sending_blocked':True,'summary':'Email not configured.','remediation':'Configure later.'}])
        with contextlib.redirect_stdout(io.StringIO()) as output:h.restore_update_delivery()
        self.assertEqual('',output.getvalue())
        self.assertEqual(['inspect-update','restore-idle-update'],[call.args[0] for call in h.database.call_args_list])
        self.assertEqual('complete',h.state['delivery_snapshot']['phase'])
        self.assertEqual(2,h.live.call_count)

    def test_preexisting_hold_remains_held_even_with_an_active_campaign(self):
        h=self.fixture(prior=0,active=True)
        h.restore_update_delivery()
        self.assertEqual(['inspect-update'],[call.args[0] for call in h.database.call_args_list])
        self.assertEqual(1,h.live.call_count)
        self.assertEqual('complete',h.state['delivery_snapshot']['phase'])

    def test_active_delivery_uses_normal_resume_and_never_queues_or_approves(self):
        h=self.fixture(active=True)
        h.restore_update_delivery()
        self.assertIn(['emailer:delivery:resume','--no-interaction'],[call.args[0] for call in h.live.call_args_list])
        self.assertEqual(['inspect-update'],[call.args[0] for call in h.database.call_args_list])
        self.assertEqual('complete',h.state['delivery_snapshot']['phase'])

    def test_failed_readiness_retains_hold_with_specific_reason(self):
        h=self.fixture(active=True,findings=[{'code':'smtp_transport_security','severity':'critical','sending_blocked':True,'summary':'SMTP TLS validation failed.','remediation':'Correct the certificate.'}])
        with contextlib.redirect_stdout(io.StringIO()) as output:h.restore_update_delivery()
        self.assertIn('Correct the certificate',output.getvalue())
        self.assertEqual('blocked',h.state['delivery_snapshot']['phase'])
        self.assertEqual('retain-update-hold',h.database.call_args.args[0])
        self.assertEqual(1,h.live.call_count)

    def test_uncertain_message_is_not_automatically_retried(self):
        h=self.fixture(active=True,uncertain=1)
        with contextlib.redirect_stdout(io.StringIO()):h.restore_update_delivery()
        self.assertEqual('blocked',h.state['delivery_snapshot']['phase'])
        self.assertIn('uncertain',h.state['delivery_snapshot']['reason'])
        self.assertEqual(1,h.live.call_count)

    def test_resume_commit_before_receipt_can_finish_without_reenabling(self):
        for active in (False,True):
            with self.subTest(active=active):
                h=self.fixture(active=active,phase='resuming',accepting=1)
                h.restore_update_delivery()
                self.assertEqual('complete',h.state['delivery_snapshot']['phase'])
                self.assertEqual(['inspect-update'],[call.args[0] for call in h.database.call_args_list])
                self.assertEqual(2,h.live.call_count)

    def test_failed_state_discovery_never_announces_paused_delivery(self):
        h=self.fixture()
        h.database=Mock(side_effect=ManagementError('Delivery state unavailable'))
        with contextlib.redirect_stdout(io.StringIO()) as output:
            with self.assertRaises(ManagementError):h.begin_update_delivery()
        self.assertNotIn('paused',output.getvalue())

    def test_only_confirmed_active_delivery_is_announced_as_paused(self):
        for prior,active in ((0,True),(1,False),(1,True)):
            with self.subTest(prior=prior,active=active):
                h=self.fixture(prior=prior,active=active)
                h.database=Mock(return_value=json.dumps(h.state['delivery_snapshot']))
                with contextlib.redirect_stdout(io.StringIO()) as output:h.begin_update_delivery()
                self.assertEqual(prior==1 and active,'Active delivery paused' in output.getvalue())

class SourceMaintenanceBoundaries(unittest.TestCase):
    def test_real_shared_flock_prevents_another_resume_lock(self):
        import fcntl
        import os
        import subprocess
        with tempfile.TemporaryDirectory() as d:
            private=Path(d)/'private';(private/'locks').mkdir(parents=True)
            m=types.SimpleNamespace(prefix='emailer', private=private, source_values={'LOCK_DSN':'flock://'+str(private/'locks')}, account=types.SimpleNamespace(pw_uid=os.getuid(),pw_gid=os.getgid()), record={}, error=ManagementError, installer=types.SimpleNamespace(product='emailer-ripe',version='1.3.3'))
            h=HostHandoff(m)
            with h.source_maintenance_lock():
                lock=next((private/'locks').glob('sf.emailer-maintenance-operation.*.lock'))
                command=[sys.executable,'-c','import fcntl,sys; f=open(sys.argv[1],"r+");\ntry: fcntl.flock(f,fcntl.LOCK_EX|fcntl.LOCK_NB)\nexcept BlockingIOError: sys.exit(75)',str(lock)]
                self.assertEqual(75,subprocess.run(command,capture_output=True).returncode)
            self.assertEqual(0,subprocess.run(command,capture_output=True).returncode)

    def test_owned_parent_lease_is_nested_and_only_inherited_by_privileged_console(self):
        import os
        import subprocess
        from manage import Manager
        with tempfile.TemporaryDirectory() as d:
            private = Path(d) / 'private'
            (private / 'locks').mkdir(parents=True)
            m = types.SimpleNamespace(prefix='emailer', private=private,
                source_values={'LOCK_DSN': 'flock://' + str(private / 'locks')},
                account=types.SimpleNamespace(pw_uid=os.getuid(), pw_gid=os.getgid()),
                record={}, environment={}, error=ManagementError,
                installer=types.SimpleNamespace(product='emailer-ripe', version='1.3.5', php='/usr/bin/php8.4'),
                bootstrap=Path('/immutable'), directory=Path(d))
            h = HostHandoff(m)
            h.state['installation_uuid'] = '01900000-0000-7000-8000-000000000001'
            with patch('host.pwd.getpwnam', side_effect=KeyError):
                with Manager.guarded_action(m):
                    context = h.source_maintenance_lock()
                    context.__enter__()
                    m.owned_maintenance_context = context
                    descriptor = m.maintenance_descriptor
                    self.assertEqual(str(descriptor), m.environment['EMAILER_INSTALLER_MAINTENANCE_FD'])
                    with h.source_maintenance_lock():
                        self.assertEqual(descriptor, m.maintenance_descriptor)
                    with patch('manage.subprocess.run', return_value=types.SimpleNamespace(returncode=0, stdout='', stderr='')) as run:
                        Manager.call(m, ['/usr/bin/php8.4', '/immutable/bin/console', 'emailer:health:verify-runtime'], environment=m.environment)
                        self.assertEqual((descriptor,), run.call_args.kwargs['pass_fds'])
                        Manager.call(m, ['/usr/local/sbin/emailer', 'emailer:health:verify-runtime'])
                        self.assertEqual((), run.call_args.kwargs['pass_fds'])
                        self.assertNotIn('EMAILER_INSTALLER_MAINTENANCE_FD', run.call_args.kwargs['env'])
            self.assertFalse(hasattr(m, 'maintenance_descriptor'))
            self.assertNotIn('EMAILER_INSTALLER_MAINTENANCE_FD', m.environment)
            with self.assertRaises(OSError):
                os.fstat(descriptor)

    def service_fixture(self, proof):
        from manage import Manager
        m=Manager.__new__(Manager)
        m.source_service_prefix='emailer-light-light-126'
        m.original_configuration=Path('/etc/emailer-light-light-126/runtime.env')
        m.releases=Path('/srv/emailer-light/releases')
        m.record={};m.active_units=[];m.save=Mock()
        unit=m.source_service_prefix+'-fpm.service'
        def systemctl(args,**kwargs):
            if 'is-active' in args:return types.SimpleNamespace(returncode=0,stdout='')
            return types.SimpleNamespace(returncode=0,stdout=('LoadState=loaded\nEnvironmentFiles='+str(m.original_configuration)+'\nWorkingDirectory='+str(m.releases/'current')+'\n') if args[2]==unit else 'LoadState=not-found')
        def call(args):
            if '--property=MainPID' in args:return '0\n'
            if '--property=Result,ExecMainCode,ExecMainStatus,ActiveState' in args:return proof
            return ''
        m.call=Mock(side_effect=call)
        return m,unit,systemctl

    def test_only_dedicated_fpm_is_gracefully_drained_with_clean_exit_proof(self):
        proof='Result=success\nExecMainCode=1\nExecMainStatus=0\nActiveState=inactive\n'
        m,unit,systemctl=self.service_fixture(proof)
        with patch('manage.subprocess.run',side_effect=systemctl):m.stop_services(web_only=True)
        self.assertIn(['systemctl','kill','--kill-whom=main','--signal=SIGQUIT',unit],[call.args[0] for call in m.call.call_args_list])
        self.assertEqual('SIGQUIT',m.record['source_web_drains'][unit]['signal'])
        self.assertEqual([['systemctl','stop',unit]],[call.args[0] for call in m.call.call_args_list if call.args[0][1]=='stop'])

    def test_forced_fpm_termination_is_not_claimed_as_a_completed_smtp_test(self):
        m,unit,systemctl=self.service_fixture('Result=signal\nExecMainCode=2\nExecMainStatus=15\nActiveState=failed\n')
        with patch('manage.subprocess.run',side_effect=systemctl),self.assertRaisesRegex(ManagementError,'uncertain'):
            m.stop_services(web_only=True)
        self.assertEqual('requested',m.record['source_web_drains'][unit]['state'])
        self.assertTrue(m.record['source_web_drain_uncertain'])
        self.assertFalse(any(call.args[0][1]=='stop' for call in m.call.call_args_list))

    def test_missing_dedicated_fpm_is_rejected_without_stopping_any_service(self):
        m,unit,systemctl=self.service_fixture('')
        m.source_service_units=Mock(return_value=[])
        with self.assertRaisesRegex(ManagementError,'No update hold or website change'):
            m.verify_source_web_runtime()
        m.call.assert_not_called()
        m.save.assert_not_called()

    def test_already_failed_fpm_is_sticky_uncertainty_on_retry(self):
        m,unit,systemctl=self.service_fixture('Result=signal\nExecMainCode=2\nExecMainStatus=9\nActiveState=failed\n')
        m.source_service_units=Mock(return_value=[unit])
        with patch('manage.subprocess.run',return_value=types.SimpleNamespace(returncode=3)),self.assertRaisesRegex(ManagementError,'no clean graceful-exit proof'):
            m.verify_source_web_runtime()
        self.assertTrue(m.record['source_web_drain_uncertain'])
        self.assertFalse(any(call.args[0][1]=='stop' for call in m.call.call_args_list))

    def test_root_redirect_repair_is_adopted_but_arbitrary_redirects_still_rejected(self):
        from hosting import WebServer
        with tempfile.TemporaryDirectory() as d:
            web=WebServer('apache2',ManagementError);web.directory=Path(d)
            folder=Path(d)/'sites-enabled';folder.mkdir()
            site=folder/'emailer.conf'
            content=web.site(hostname='mailer.example.test',name='emailer-light',public='/srv/emailer-light/releases/current/public',webroot='/var/lib/emailer-light-acme',certificate='/etc/letsencrypt/live/mailer.example.test/fullchain.pem',certificate_key='/etc/letsencrypt/live/mailer.example.test/privkey.pem')
            for redirect,allowed in [('    RedirectMatch 302 "^/$" "/login"\n',True),('    RedirectMatch 302 "^/.*$" "https://other.example.test"\n',False)]:
                site.write_text(content.replace('    SSLEngine on\n',redirect+'    SSLEngine on\n'))
                if allowed:self.assertEqual(site,web.source_site(Path('/srv/emailer-light/releases'),'mailer.example.test')[0])
                else:
                    with self.assertRaises(ManagementError):web.source_site(Path('/srv/emailer-light/releases'),'mailer.example.test')

class HonestCompletion(unittest.TestCase):
    def test_success_requires_a_completed_authoritative_receipt(self):
        from manage import Manager
        m=Manager.__new__(Manager)
        m.values={'DEFAULT_URI':'https://emailer.example.test'}
        for snapshot,success in [({'phase':'complete'},True),({'phase':'blocked','reason':'SMTP certificate requires correction.'},False),({'phase':'failed','reason':'Runtime verification did not pass.'},False),(None,False)]:
            with self.subTest(snapshot=snapshot):
                with patch('host.HostHandoff') as handoff,contextlib.redirect_stdout(io.StringIO()) as output:
                    handoff.return_value.current_update_snapshot.return_value=snapshot
                    m.report_update_completion()
                self.assertEqual(success,'Update successful.' in output.getvalue())
                self.assertIn('Sign in: https://emailer.example.test/login',output.getvalue())
                if snapshot and not success:self.assertIn(snapshot['reason'],output.getvalue())

class ScopedMaintenanceMarker(unittest.TestCase):
    def test_only_the_scoped_maintenance_site_emits_the_update_header(self):
        from hosting import WebServer
        for kind in ('apache2','nginx'):
            web=WebServer(kind,ManagementError)
            values=dict(hostname='mailer.example.test',name='emailer-light',public='/srv/emailer-light/releases/current/public',certificate='/etc/letsencrypt/live/mailer.example.test/fullchain.pem',certificate_key='/etc/letsencrypt/live/mailer.example.test/privkey.pem')
            self.assertIn('X-Emailer-Update-State "in-progress"',web.site(**values,maintenance=True))
            self.assertNotIn('X-Emailer-Update-State',web.site(**values,maintenance=False))

class SafeBlockerDiagnostics(unittest.TestCase):
    def test_progress_before_the_final_typed_report_does_not_hide_actual_blockers(self):
        from manage import Manager
        for prefix in ('','Preparing verified recovery material...\n'):
            m=Manager.__new__(Manager)
            m.installer=types.SimpleNamespace(durable=Mock());m.directory=Path('/tmp/owned-operation');m.record={}
            report={'installer_error':'verification_failed','runtime_blockers':['backup_external_recency']}
            with patch('manage.subprocess.run',return_value=types.SimpleNamespace(returncode=1,stdout=prefix+json.dumps(report)+'\n')):
                with self.assertRaisesRegex(ManagementError,'backup_external_recency.*choose Recover'):
                    m.call(['/usr/bin/php8.5','/verified/bin/console','emailer:release:update-native'])
            saved=json.loads(m.installer.durable.call_args.args[1])
            self.assertEqual(['backup_external_recency'],saved['runtime_blockers'])

    def test_unbounded_or_unsafe_blocker_text_is_never_echoed(self):
        from manage import Manager
        for blockers in (['Synthetic private diagnostic detail'],['backup_destination']*65):
            m=Manager.__new__(Manager)
            m.installer=types.SimpleNamespace(durable=Mock());m.directory=Path('/tmp/owned-operation');m.record={}
            report={'installer_error':'verification_failed','runtime_blockers':blockers}
            with patch('manage.subprocess.run',return_value=types.SimpleNamespace(returncode=1,stdout='Progress\n'+json.dumps(report))):
                with self.assertRaises(ManagementError) as raised:m.call(['/usr/bin/php8.5','/verified/bin/console','emailer:release:update-native'])
            self.assertNotIn('Synthetic private',str(raised.exception))
            self.assertNotIn('runtime_blockers',json.loads(m.installer.durable.call_args.args[1]))

    def test_non_object_json_is_a_safe_failure_without_a_traceback(self):
        from manage import Manager
        for value in ([],None,'text',1,True):
            m=Manager.__new__(Manager)
            m.installer=types.SimpleNamespace(durable=Mock());m.directory=Path('/tmp/owned-operation');m.record={}
            with patch('manage.subprocess.run',return_value=types.SimpleNamespace(returncode=1,stdout=json.dumps(value))):
                with self.assertRaises(ManagementError):m.call(['/usr/bin/php8.5','/verified/bin/console','emailer:release:update-native'])
            saved=json.loads(m.installer.durable.call_args.args[1])
            self.assertEqual('verification_failed',saved['error_code'])
