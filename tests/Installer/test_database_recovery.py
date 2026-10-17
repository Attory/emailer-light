"""Real filesystem commit-point tests; never execute a host database command."""
import json
import os
from pathlib import Path
import sys
import tempfile
import types
import unittest
from unittest.mock import patch

sys.path.insert(0,str(Path(__file__).resolve().parents[2]/'installer'))
import isolated_database as database
from provision import SetupError,durable


@unittest.skipUnless(os.geteuid()==0,'Requires the existing isolated root installer gate')
class DatabaseCommitRecovery(unittest.TestCase):
    def setUp(self):
        self.temp=tempfile.TemporaryDirectory();self.base=Path(self.temp.name)
        self.root=self.base/'state';self.runtime=self.base/'runtime';self.release=self.runtime/database.VERSION
        self.config=self.base/'database.cnf';self.unit=self.base/'database.service';self.bootstraps=0
        self.owner='a'*32
        self.account=types.SimpleNamespace(pw_uid=1234,pw_gid=1234,pw_gecos='Emailer isolated database '+self.owner,pw_dir=str(self.root/'data'),pw_shell='/usr/sbin/nologin')
        self.patches=[patch.object(database,'ROOT',self.root),patch.object(database,'BASE',self.runtime),patch.object(database,'RELEASE',self.release),patch.object(database,'CONFIG',self.config),patch.object(database,'UNIT',self.unit),patch.object(database.pwd,'getpwnam',return_value=self.account),patch('grp.getgrgid',return_value=types.SimpleNamespace(gr_name=database.USER)),patch.object(database.subprocess,'run',return_value=types.SimpleNamespace(returncode=3))]
        for item in self.patches:item.start()
        self.i=types.SimpleNamespace(error=SetupError,durable=durable)
        self.profile=database.DatabaseProfile(self.i);self.profile.descriptor=0
        self.root.mkdir(mode=0o700);self.runtime.mkdir(mode=0o755);self.release.mkdir(mode=0o755)
        durable(self.runtime/'emailer-owner',self.owner+'\n')
        self.state={'format':'emailer-isolated-mariadb-v1','version':database.VERSION,'archive_sha256':database.SHA256,'socket':database.SOCKET,'phase':'reserved','owner':self.owner}
        self.profile.save(self.state);self.profile.call=self.command

    def tearDown(self):
        for item in reversed(self.patches):item.stop()
        self.temp.cleanup()

    def command(self,args,**kwargs):
        if args[0]=='ldd':return 'all libraries resolved'
        if Path(args[0]).name=='mariadb-install-db':
            self.bootstraps+=1
            initial=next(self.root.glob('initial-data-*'))
            (initial/'synthetic-row').write_text('preserved database content')
        if '-NBe' in args:return database.VERSION+'-MariaDB\t'+database.SOCKET+'\t'+str(self.root/'data')+'/'
        return ''

    def test_crash_after_datadir_rename_resumes_without_second_bootstrap(self):
        original=self.profile.save
        def crash(state):
            if state['phase']=='initialized':raise RuntimeError('crash after rename before receipt commit')
            original(state)
        with patch.object(self.profile,'save',side_effect=crash),self.assertRaisesRegex(RuntimeError,'after rename'):
            self.profile.provision_locked()
        self.assertEqual('initialized-staging',self.profile.load()['phase'])
        data=self.root/'data';identity=data.stat().st_ino
        self.assertFalse(self.unit.exists())
        self.profile.provision_locked()
        self.assertEqual(1,self.bootstraps)
        self.assertEqual(identity,data.stat().st_ino)
        self.assertEqual('preserved database content',(data/'synthetic-row').read_text())
        self.assertEqual('ready',self.profile.load()['phase'])
        self.profile.provision_locked()
        self.assertEqual(1,self.bootstraps)

    def test_crash_before_rename_uses_verified_staging_inode(self):
        original=database.os.rename
        def crash(source,target):
            if Path(target)==self.root/'data':raise RuntimeError('crash before rename')
            return original(source,target)
        with patch.object(database.os,'rename',side_effect=crash),self.assertRaisesRegex(RuntimeError,'before rename'):
            self.profile.provision_locked()
        initial=self.root/('initial-data-'+self.owner);identity=initial.stat().st_ino
        self.profile.provision_locked()
        self.assertEqual(identity,(self.root/'data').stat().st_ino)
        self.assertEqual(1,self.bootstraps)

    def test_incomplete_receipt_never_resets_existing_final_data(self):
        data=self.root/'data';data.mkdir(mode=0o700);(data/'important').write_text('retained')
        self.state['phase']='initializing';self.profile.save(self.state)
        with self.assertRaisesRegex(SetupError,'already has final data'):
            self.profile.provision_locked()
        self.assertEqual('retained',(data/'important').read_text());self.assertEqual(0,self.bootstraps)
        self.assertFalse(self.unit.exists())

    def test_replaced_initialized_inode_is_refused_without_removal(self):
        initial=self.root/('initial-data-'+self.owner);initial.mkdir(mode=0o700);os.chown(initial,1234,1234)
        identity=initial.stat();self.state.update(phase='initialized-staging',initial_identity=[identity.st_dev,identity.st_ino+1,identity.st_uid]);self.profile.save(self.state)
        (initial/'retained').write_text('retain')
        with self.assertRaisesRegex(SetupError,'inode proof'):self.profile.provision_locked()
        self.assertEqual('retain',(initial/'retained').read_text());self.assertFalse((self.root/'data').exists())

    def test_failed_unserved_bootstrap_can_retry_without_touching_final_data(self):
        command=self.command
        def fail(args,**kwargs):
            result=command(args,**kwargs)
            if Path(args[0]).name=='mariadb-install-db':raise SetupError('bootstrap interrupted')
            return result
        with patch.object(self.profile,'call',side_effect=fail),self.assertRaisesRegex(SetupError,'interrupted'):
            self.profile.provision_locked()
        self.assertEqual('initializing',self.profile.load()['phase']);self.assertFalse((self.root/'data').exists())
        self.profile.provision_locked()
        self.assertEqual(2,self.bootstraps);self.assertEqual('ready',self.profile.load()['phase'])

    def test_interrupted_ownership_preparation_does_not_publish_a_root_owned_initial(self):
        original=database.os.chown
        def crash(path,uid,gid):
            if Path(path).name.startswith('.initial-data-'):
                raise RuntimeError('crash before staging ownership assignment')
            return original(path,uid,gid)
        with patch.object(database.os,'chown',side_effect=crash),self.assertRaisesRegex(RuntimeError,'ownership assignment'):
            self.profile.provision_locked()
        self.assertFalse((self.root/('initial-data-'+self.owner)).exists())
        self.profile.provision_locked()
        self.assertEqual('ready',self.profile.load()['phase']);self.assertEqual(1,self.bootstraps)
