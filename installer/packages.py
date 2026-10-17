"""Authenticated Ubuntu PHP source and non-destructive package transactions."""
from pathlib import Path
import os
import re
import stat
import base64
import fcntl
import tempfile

PHP_SOURCE = 'https://ppa.launchpadcontent.net/ondrej/php/ubuntu'
PHP_FINGERPRINT = 'B8DC7E53946656EFBCE4C1DD71DAEAAB4AD4CAB6'


def protected_content(path, content, durable, error):
    path = Path(path)
    if path.exists() or path.is_symlink():
        metadata = path.lstat()
        if not stat.S_ISREG(metadata.st_mode) or metadata.st_uid != 0 or metadata.st_nlink != 1 or metadata.st_mode & 0o022 or path.read_text() != content:
            raise error('The reserved Emailer package-source file is already occupied or changed: ' + str(path) + '. It was preserved; no packages were installed.')
    else:
        durable(path, content, 0o644)


def ubuntu_php_source(installer):
    if installer.system[0] != 'ubuntu':
        return
    codename = {'22.04': 'jammy', '24.04': 'noble'}[installer.system[1]]
    key = installer.bundle / 'ubuntu-php.asc'
    # The exact reviewed public key is bound by the signed installer inventory.
    key_text = key.read_text()
    if not key_text.startswith('-----BEGIN PGP PUBLIC KEY BLOCK-----'):
        raise installer.error('The authenticated Ubuntu PHP signing key is missing. Use the original official setup file.')
    public = '/usr/share/keyrings/emailer-php84.asc'
    protected_content(public, key_text, installer.durable, installer.error)
    preference = '''Package: *
Pin: release o=LP-PPA-ondrej-php
Pin-Priority: 1

Package: php8.4-* php-common libgd3
Pin: release o=LP-PPA-ondrej-php
Pin-Priority: 500
'''
    protected_content('/etc/apt/preferences.d/emailer-php84', preference, installer.durable, installer.error)
    protected_content('/etc/apt/sources.list.d/emailer-php84.list', f'deb [arch=amd64 signed-by={public}] {PHP_SOURCE} {codename} main\n', installer.durable, installer.error)
    print('The PHP source also supplies its required php-common metadata and ABI-compatible libgd3 image library; existing PHP handlers and versions are retained.')
    print('PHP 8.4 uses the maintained Ondřej Surý Ubuntu PPA, authenticated with its pinned signing identity. Existing PHP runtimes, Apache and database servers will not be upgraded.')


def validate_plan(output, error, compatible_packages=()):
    """APT simulation is a gate, never permission to upgrade a shared runtime."""
    for line in output.splitlines():
        if line.startswith(('Remv ', 'Purg ')):
            raise error('Installing the missing dependencies would remove existing host software. No packages were changed; use a separate supported VPS or resolve the named package conflict.')
        match = re.match(r'^Inst (\S+) \[([^]]+)\]', line)
        if match and match.group(1) not in compatible_packages and not match.group(1).startswith('php8.4-'):
            raise error('The package manager would change existing host package ' + match.group(1) + '. No packages were changed. Update that shared dependency through your normal host maintenance process, then retry setup; setup will not upgrade shared software.')
        if match and match.group(1).startswith('php8.4-'):
            # The existing pool can belong to another website; keep its patch too.
            raise error('The required PHP 8.4 extensions need a different patch than an existing PHP 8.4 installation. Setup kept that runtime unchanged; restore matching signed packages or arrange its normal host maintenance first.')


class HostLock:
    """A regular root-owned flock; children can retain the same file description."""
    def __init__(self, path, error):
        self.path, self.error, self.descriptor = Path(path), error, None

    def __enter__(self):
        descriptor=os.open(self.path,os.O_RDWR|os.O_CREAT|os.O_NOFOLLOW|os.O_CLOEXEC|os.O_NONBLOCK,0o600)
        metadata=os.fstat(descriptor)
        if not stat.S_ISREG(metadata.st_mode) or metadata.st_uid!=0 or metadata.st_nlink!=1 or metadata.st_mode&0o077:
            os.close(descriptor)
            raise self.error('The host installer lock is unsafe. No shared resource was changed.')
        try:fcntl.flock(descriptor,fcntl.LOCK_EX|fcntl.LOCK_NB)
        except BlockingIOError:
            os.close(descriptor)
            raise self.error('Another Emailer host or package task is still running. Let it finish, then start this same setup action again; existing services were preserved.') from None
        self.descriptor=descriptor
        return descriptor

    def __exit__(self,*unused):
        os.close(self.descriptor);self.descriptor=None


class ServicePolicy:
    """Serialized crash-recoverable service fence, held by the actual APT child.

    All package-script service starts/restarts are denied only during this task.
    The exact existing host policy is restored afterward. No persistent restart
    policy or host security-update setting is changed.
    """
    def __init__(self, installer):
        self.i=installer
        self.path=Path('/usr/sbin/policy-rc.d')
        self.root=getattr(installer,'package_policy_directory',installer.state_dir)
        self.receipt=self.root/'package-service-policy.json'
        self.saved=self.root/'package-service-policy-original'
        self.lock=HostLock('/run/lock/emailer-package-task.lock',installer.error)
        self.descriptor=None

    def directory(self):
        marker='emailer-host-installer-v1\n'
        if not self.root.exists():
            temporary=Path(tempfile.mkdtemp(prefix='.emailer-host-',dir=self.root.parent))
            try:
                self.i.durable(temporary/'format',marker)
                os.rename(temporary,self.root)
            finally:
                if temporary.exists():
                    (temporary/'format').unlink(missing_ok=True);temporary.rmdir()
        metadata=self.root.lstat()
        if self.root.is_symlink() or metadata.st_uid!=0 or metadata.st_mode&0o077 or (self.root/'format').read_text()!=marker:
            raise self.i.error('The shared host installer directory is unsafe. No package policy was changed.')

    @staticmethod
    def attributes(path):
        return {name:base64.b64encode(os.getxattr(path,name)).decode() for name in os.listxattr(path)}

    def recover(self):
        if not self.receipt.exists():return
        with self.lock:
            self.directory()
            self.restore()

    def restore(self):
        if not self.receipt.exists():return
        import json,hashlib
        record=json.loads(self.receipt.read_text())
        metadata=self.path.lstat() if self.path.exists() else None
        current=hashlib.sha256(self.path.read_bytes()).hexdigest() if metadata and stat.S_ISREG(metadata.st_mode) and not self.path.is_symlink() else None
        complete=record['existed'] and current==record['original_sha256'] and (metadata.st_uid,metadata.st_gid,stat.S_IMODE(metadata.st_mode))==(0,record['gid'],record['mode']) and self.attributes(self.path)==record.get('xattrs',{})
        if complete or (not record['existed'] and not self.path.exists()):
            self.receipt.unlink();self.saved.unlink(missing_ok=True);return
        if current not in (record['temporary_sha256'],record['original_sha256'] if record['existed'] else None) or current is None:
            raise self.i.error('The temporary package service policy changed after an interrupted task. Retain its protected recovery record; setup will not replace an administrator policy.')
        if record['existed']:
            if hashlib.sha256(self.saved.read_bytes()).hexdigest()!=record['original_sha256']:
                raise self.i.error('The retained package service policy differs. No host policy was changed.')
            self.i.durable(self.path,self.saved.read_bytes(),record['mode'])
            os.chown(self.path,0,record['gid'])
            for name in os.listxattr(self.path):
                if name not in record.get('xattrs',{}):os.removexattr(self.path,name)
            for name,value in record.get('xattrs',{}).items():os.setxattr(self.path,name,base64.b64decode(value))
            with self.path.open('rb') as stream:os.fsync(stream.fileno())
            final=self.path.stat()
            if (final.st_uid,final.st_gid,stat.S_IMODE(final.st_mode))!=(0,record['gid'],record['mode']) or self.attributes(self.path)!=record.get('xattrs',{}):
                raise self.i.error('The original host service policy metadata could not be restored completely. Its recovery record was retained.')
        else:self.path.unlink()
        self.receipt.unlink();self.saved.unlink(missing_ok=True)

    def __enter__(self):
        import json,hashlib
        self.descriptor=self.lock.__enter__()
        try:
            self.directory();self.restore()
            existed=self.path.exists() or self.path.is_symlink()
            metadata=self.path.lstat() if existed else None
            if existed and (not stat.S_ISREG(metadata.st_mode) or metadata.st_uid!=0 or metadata.st_nlink!=1 or metadata.st_mode&0o022):
                raise self.i.error('The host package service policy is not a protected regular file. No packages were installed.')
            original=self.path.read_bytes() if existed else b''
            if existed:self.i.durable(self.saved,original,stat.S_IMODE(metadata.st_mode))
            policy='#!/bin/sh\n# Temporary Emailer package task; retained host policy is restored afterward.\nexit 101\n'
            record={'existed':existed,'mode':stat.S_IMODE(metadata.st_mode) if existed else None,'gid':metadata.st_gid if existed else 0,'xattrs':self.attributes(self.path) if existed else {},'original_sha256':hashlib.sha256(original).hexdigest(),'temporary_sha256':hashlib.sha256(policy.encode()).hexdigest()}
            self.i.durable(self.receipt,json.dumps(record)+'\n');self.i.durable(self.path,policy,0o755)
            return self
        except BaseException:
            self.lock.__exit__();self.descriptor=None;raise

    def __exit__(self,*unused):
        try:self.restore()
        finally:self.lock.__exit__();self.descriptor=None
