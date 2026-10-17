"""Native, socket-only MariaDB beside an unrelated database server.

The upstream LTS binary is pinned by digest in the signed installer. No server
package, global database configuration, service, port or data directory changes.
"""
import hashlib
import json
import os
from pathlib import Path
import posixpath
import pwd
import shutil
import stat
import subprocess
import tarfile
import tempfile
import urllib.request
import secrets
import re

VERSION = '10.11.19'
ARCHIVE = 'mariadb-' + VERSION + '-linux-systemd-x86_64.tar.gz'
URL = 'https://archive.mariadb.org/mariadb-' + VERSION + '/bintar-linux-systemd-x86_64/' + ARCHIVE
SHA256 = '7efb257927f31f4422f496246b32590d0b7617f3d9dd900e575de761b302d919'
SIZE = 357390930
BASE = Path('/opt/emailer-database')
RELEASE = BASE / VERSION
ROOT = Path('/var/lib/emailer-database')
CONFIG = Path('/etc/emailer-database.cnf')
SOCKET = '/run/emailer-database/mysql.sock'
UNIT = Path('/etc/systemd/system/emailer-database.service')
USER = 'emailer-db'


class DatabaseProfile:
    def __init__(self, installer):
        self.i = installer
        self.receipt = ROOT / 'installation.json'

    def call(self, arguments, **kwargs):
        result = subprocess.run(arguments, capture_output=True, text=True, pass_fds=(self.descriptor,) if getattr(self, 'descriptor', None) is not None else (), **kwargs)
        if result.returncode:
            raise self.i.error('The isolated Emailer database could not complete ' + Path(arguments[0]).name + '. Shared databases were untouched. Keep /var/lib/emailer-database and the installer state; check journalctl -u emailer-database and rerun this same setup file.')
        return result.stdout

    def preflight(self):
        # Jammy's distribution server is 10.6. Never upgrade it in place, even
        # on a clean host: the dedicated native server is this OS's default.
        self.i.database_profile = 'isolated' if self.i.system == ('ubuntu', '22.04') else 'shared'
        if self.i.database_profile != 'isolated':
            return
        if shutil.disk_usage('/').free < 5 * 1024 ** 3:
            raise self.i.error('Ubuntu 22.04 needs at least 5 GiB free for Emailer’s isolated MariaDB and initial backups. No shared database will be upgraded.')
        if self.receipt.exists():
            self.load()
            self.configure_client()
            return
        for path in (ROOT, BASE, CONFIG, UNIT):
            if path.exists() or path.is_symlink():
                raise self.i.error('The reserved isolated database location is already occupied: ' + str(path) + '. It was preserved; no database or package was changed.')
        status = subprocess.run(['systemctl', 'show', 'emailer-database.service', '--property=LoadState'],capture_output=True,text=True)
        if status.returncode or 'LoadState=not-found' not in status.stdout:
            raise self.i.error('The isolated Emailer database service name is occupied. No service was changed.')
        try:
            pwd.getpwnam(USER)
        except KeyError:
            return
        raise self.i.error('The isolated database account name is occupied. It was not reused; no packages were changed.')

    def load(self):
        for path in (ROOT, self.receipt):
            m = path.lstat()
            if path.is_symlink() or m.st_uid != 0 or m.st_mode & 0o022 or (path == self.receipt and (not stat.S_ISREG(m.st_mode) or m.st_nlink != 1 or m.st_mode & 0o077)):
                raise self.i.error('The isolated database ownership record is unsafe. It was not used.')
        state = json.loads(self.receipt.read_text())
        if state.get('format') != 'emailer-isolated-mariadb-v1' or state.get('version') != VERSION or state.get('archive_sha256') != SHA256 or state.get('socket') != SOCKET or not isinstance(state.get('owner'),str) or not re.fullmatch(r'[a-f0-9]{32}',state['owner']) or state.get('phase') not in ('reserved','initializing','initialized-staging','initialized','ready'):
            raise self.i.error('The isolated database record belongs to a different runtime. It was preserved; use its matching supported installer before host maintenance.')
        return state

    def save(self, state):
        self.i.durable(self.receipt, json.dumps(state,indent=2)+'\n')

    def configure_client(self):
        self.i.database_cli = [str(RELEASE / 'bin/mariadb'), '--no-defaults', '--protocol=socket', '--socket=' + SOCKET, '--user=root']
        self.i.database_environment = {'PATH': '/usr/bin:/bin', 'LANG': 'C.UTF-8'}

    def archive(self):
        cache = Path('/var/cache/emailer-database')
        cache.mkdir(mode=0o700,exist_ok=True)
        info=cache.lstat()
        if cache.is_symlink() or info.st_uid != 0 or info.st_mode & 0o077:
            raise self.i.error('The isolated database download cache is unsafe; no file was used.')
        path=cache / ARCHIVE
        if path.exists() or path.is_symlink():
            info=path.lstat()
            if not stat.S_ISREG(info.st_mode) or info.st_uid != 0 or info.st_nlink != 1 or info.st_mode & 0o022:
                raise self.i.error('The cached database archive is unsafe. It was preserved.')
        else:
            print('Retrieving MariaDB ' + VERSION + ' for an isolated, socket-only Emailer service (about 360 MB). Shared database software is retained.',flush=True)
            temporary=cache / (ARCHIVE + '.part')
            if temporary.exists() or temporary.is_symlink():
                info=temporary.lstat()
                if not stat.S_ISREG(info.st_mode) or info.st_uid != 0 or info.st_nlink != 1 or info.st_mode & 0o077:
                    raise self.i.error('The partial database download is unsafe; it was preserved and not used.')
            offset=temporary.stat().st_size if temporary.exists() else 0
            try:
                # A crash after the final fsync but before rename needs no HTTP
                # request (an HTTP Range at EOF would otherwise return 416).
                if offset<SIZE:
                    request=urllib.request.Request(URL,headers={'Range': 'bytes=' + str(offset) + '-'} if offset else {})
                    with urllib.request.urlopen(request,timeout=60) as response:
                        continued=bool(offset and response.status==206)
                        if response.status not in (200,206) or (response.status==206 and (not continued or response.headers.get('Content-Range')!=f'bytes {offset}-{SIZE-1}/{SIZE}')):
                            raise self.i.error('The database download server returned an invalid resume range. Keep the partial file; retry when its official service is healthy.')
                        expected=SIZE-(offset if continued else 0)
                        length=response.headers.get('Content-Length')
                        if length is not None and (not length.isdecimal() or int(length)!=expected):
                            raise self.i.error('The official database server returned a different release size. Downloaded bytes were retained; retry when its release service is healthy.')
                        flags=os.O_WRONLY|os.O_CREAT|os.O_NOFOLLOW|(os.O_APPEND if continued else os.O_TRUNC)
                        fd=os.open(temporary,flags,0o600)
                        with os.fdopen(fd,'wb') as output:
                            total=offset if continued else 0
                            reported=total//(32*1024**2)
                            while True:
                                chunk=response.read(1024*1024)
                                if not chunk: break
                                total+=len(chunk)
                                if total>SIZE: raise self.i.error('The database download exceeded its authenticated release size limit. It was not extracted or executed.')
                                output.write(chunk)
                                if total//(32*1024**2)>reported:
                                    output.flush();os.fsync(output.fileno());reported=total//(32*1024**2)
                                    print(f'Database download: {total//(1024**2)} MiB received. Progress is saved.',flush=True)
                            output.flush();os.fsync(output.fileno())
                        if total!=SIZE:
                            raise self.i.error('The database download ended early. Downloaded bytes were retained; start this same setup file again to continue. Shared databases were untouched.')
                os.rename(temporary,path)
                descriptor=os.open(cache,os.O_RDONLY|os.O_DIRECTORY)
                try:os.fsync(descriptor)
                finally:os.close(descriptor)
            except OSError:
                raise self.i.error('The official isolated MariaDB download could not finish. Restore outbound HTTPS to archive.mariadb.org, then rerun setup; downloaded bytes and completed phases were retained and shared databases were untouched.') from None
        with path.open('rb') as stream:
            digest=hashlib.file_digest(stream,'sha256').hexdigest() if hasattr(hashlib,'file_digest') else self.digest(stream)
        if digest != SHA256:
            rejected=cache / ('rejected-' + digest + '.tar.gz')
            if rejected.exists(): rejected=cache / ('rejected-' + digest + '-' + os.urandom(8).hex() + '.tar.gz')
            os.rename(path,rejected)
            raise self.i.error('The isolated MariaDB archive failed its pinned SHA-256 check. It was retained under /var/cache/emailer-database/rejected-* and was not extracted or executed. Start the same setup action again to download a fresh official copy.')
        print('Database archive verified against the release’s pinned SHA-256.',flush=True)
        return path

    @staticmethod
    def digest(stream):
        digest=hashlib.sha256()
        while True:
            chunk=stream.read(1024*1024)
            if not chunk: return digest.hexdigest()
            digest.update(chunk)

    def extract(self, archive):
        staging=Path(tempfile.mkdtemp(prefix='.database-',dir=BASE))
        prefix='mariadb-' + VERSION + '-linux-systemd-x86_64'
        try:
            with tarfile.open(archive,'r:gz') as source:
                members=source.getmembers()
                for member in members:
                    normalized=posixpath.normpath(member.name)
                    if not (normalized==prefix or normalized.startswith(prefix+'/')) or member.name.startswith('/') or not (member.isfile() or member.isdir() or member.issym() or member.islnk()) or member.mode & 0o6000:
                        raise self.i.error('The authenticated database archive has an unexpected path or object; it was not executed.')
                    if member.issym() or member.islnk():
                        target=posixpath.normpath(posixpath.join(posixpath.dirname(member.name),member.linkname)) if member.issym() else posixpath.normpath(member.linkname)
                        if member.linkname.startswith('/') or not target.startswith(prefix+'/'):
                            raise self.i.error('The database archive contains an external link; it was not extracted.')
                    member.uid=member.gid=0
                    member.uname=member.gname='root'
                    member.mode &= ~0o022
                source.extractall(staging,members=members)
            os.rename(staging / prefix,RELEASE)
        finally:
            shutil.rmtree(staging)

    def provision(self):
        from packages import HostLock
        with HostLock('/run/lock/emailer-database.lock', self.i.error) as descriptor:
            self.descriptor=descriptor
            try:self.provision_locked()
            finally:self.descriptor=None

    def provision_locked(self):
        from provision import root_directory
        if not self.receipt.exists():
            # Publish the directory AND its ownership receipt atomically. A crash
            # cannot leave an unexplained, partially reserved global datadir.
            staging=Path(tempfile.mkdtemp(prefix='.emailer-database-',dir=ROOT.parent))
            state={'format':'emailer-isolated-mariadb-v1','version':VERSION,'archive_sha256':SHA256,'socket':SOCKET,'phase':'reserved','owner':secrets.token_hex(16)}
            try:
                self.i.durable(staging/'installation.json',json.dumps(state)+'\n')
                os.rename(staging,ROOT)
            finally:
                if staging.exists():
                    (staging/'installation.json').unlink(missing_ok=True);staging.rmdir()
        state=self.load()
        if not BASE.exists():
            staging=Path(tempfile.mkdtemp(prefix='.emailer-database-',dir=BASE.parent))
            try:
                self.i.durable(staging/'emailer-owner',state['owner']+'\n')
                os.chmod(staging,0o755);os.rename(staging,BASE)
            finally:
                if staging.exists():
                    (staging/'emailer-owner').unlink(missing_ok=True);staging.rmdir()
        root_directory(BASE,0,0o755)
        marker=BASE/'emailer-owner'
        metadata=marker.lstat()
        if not stat.S_ISREG(metadata.st_mode) or metadata.st_uid!=0 or metadata.st_nlink!=1 or metadata.st_mode&0o077 or marker.read_text()!=state['owner']+'\n':
            raise self.i.error('The isolated runtime directory is not owned by this operation. It was preserved.')
        if not RELEASE.exists(): self.extract(self.archive())
        if RELEASE.is_symlink() or RELEASE.stat().st_uid != 0 or RELEASE.stat().st_mode & 0o022:
            raise self.i.error('The isolated MariaDB runtime is unsafe; it was not executed.')
        # Verify actual loader dependencies before initialization. Never invent
        # SONAME symlinks to make another OS appear compatible.
        for name in ('mariadbd','mariadb','mariadb-admin'):
            libraries=self.call(['ldd',str(RELEASE/'bin'/name)])
            if 'not found' in libraries:
                raise self.i.error('The official MariaDB binary needs unavailable host libraries. Shared databases were untouched; this isolated runtime is not supported on this host.')
        marker='Emailer isolated database ' + state['owner']
        try: account=pwd.getpwnam(USER)
        except KeyError:
            self.call(['useradd','--system','--user-group','--home-dir',str(ROOT/'data'),'--shell','/usr/sbin/nologin','--comment',marker,USER]);account=pwd.getpwnam(USER)
        import grp
        if state.get('uid',account.pw_uid)!=account.pw_uid or account.pw_uid==0 or account.pw_gecos!=marker or account.pw_dir!=str(ROOT/'data') or account.pw_shell!='/usr/sbin/nologin' or grp.getgrgid(account.pw_gid).gr_name!=USER:
            raise self.i.error('The reserved database service identity changed or is not owned by this operation. Its data was preserved.')
        state['uid']=account.pw_uid;self.save(state)
        # Parent grants traversal only; application supplementary group gets the
        # socket, but the separate database datadir is mode0700 owned by emailer-db.
        root_directory(ROOT,0,0o711)
        data=ROOT/'data'
        config=f'''[mysqld]
basedir={RELEASE}
datadir={data}
socket={SOCKET}
pid-file=/run/emailer-database/server.pid
skip-networking
skip-name-resolve
bind-address=127.0.0.1
character-set-server=utf8mb4
collation-server=utf8mb4_unicode_ci
log-error=/var/lib/emailer-database/data/error.log
'''
        from packages import protected_content
        protected_content(CONFIG,config,self.i.durable,self.i.error)
        initial=ROOT/('initial-data-' + state['owner'])
        if state['phase'] in ('reserved','initializing'):
            if data.exists() or UNIT.exists() or subprocess.run(['systemctl','is-active','--quiet','emailer-database.service'],pass_fds=(self.descriptor,)).returncode==0:
                raise self.i.error('An isolated database already has final data or a live service while initialization is incomplete. It was preserved; no data directory was reset.')
            # Only the operation's unserved staging directory is disposable. The
            # final datadir is NEVER removed by repeat setup or recovery.
            if initial.exists():
                if initial.is_symlink() or initial.stat().st_uid!=account.pw_uid:
                    raise self.i.error('The incomplete database staging folder has unexpected ownership. It was retained.')
                shutil.rmtree(initial)
            initial.mkdir(mode=0o700);os.chown(initial,account.pw_uid,account.pw_gid)
            state['phase']='initializing';self.save(state)
            self.call([str(RELEASE/'scripts/mariadb-install-db'),'--defaults-file='+str(CONFIG),'--user='+USER,'--basedir='+str(RELEASE),'--datadir='+str(initial),'--log-error='+str(initial/'error.log'),'--auth-root-authentication-method=socket','--skip-test-db'])
            identity=initial.stat()
            state['initial_identity']=[identity.st_dev,identity.st_ino,identity.st_uid]
            state['phase']='initialized-staging';self.save(state)
        if state['phase']=='initialized-staging':
            selected=data if data.exists() else initial
            identity=selected.lstat()
            if selected.is_symlink() or [identity.st_dev,identity.st_ino,identity.st_uid]!=state['initial_identity'] or not stat.S_ISDIR(identity.st_mode):
                raise self.i.error('The initialized database directory differs from its retained inode proof. It was preserved.')
            if selected==initial:os.rename(initial,data)
            descriptor=os.open(ROOT,os.O_RDONLY|os.O_DIRECTORY)
            try:os.fsync(descriptor)
            finally:os.close(descriptor)
            state['phase']='initialized';self.save(state)
        service=f'''[Unit]
Description=Emailer isolated MariaDB {VERSION} (shared databases untouched)
After=local-fs.target
[Service]
Type=simple
User={USER}
Group={USER}
ExecStart={RELEASE}/bin/mariadbd --defaults-file={CONFIG}
ExecStartPost=/bin/sh -c 'for n in $(seq 1 60); do {RELEASE}/bin/mariadb-admin --no-defaults --socket={SOCKET} --user={USER} ping --silent >/dev/null 2>&1 && chmod 0660 {SOCKET} && exit 0; sleep 1; done; exit 1'
RuntimeDirectory=emailer-database
RuntimeDirectoryMode=0755
UMask=0007
Restart=on-failure
RestartSec=3
TimeoutStartSec=90
NoNewPrivileges=yes
PrivateTmp=yes
ProtectSystem=strict
ProtectHome=yes
ReadWritePaths={data} /run/emailer-database
[Install]
WantedBy=multi-user.target
'''
        protected_content(UNIT,service,self.i.durable,self.i.error)
        self.call(['systemctl','daemon-reload'])
        self.call(['systemctl','enable','--now','emailer-database.service'])
        self.configure_client()
        observed=self.call([*self.i.database_cli,'-NBe','SELECT VERSION(),@@socket,@@datadir'])
        if not observed.startswith(VERSION+'-MariaDB\t'+SOCKET+'\t'+str(data)):
            raise self.i.error('The isolated database endpoint identity differs from its recorded service; no application database was created.')
        state['phase']='ready';self.save(state)
