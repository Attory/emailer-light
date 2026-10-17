"""Crash-safe claims for files created by one root-controlled installation operation."""
import ctypes
import errno
import hashlib
import os
from pathlib import Path
import stat
import tempfile


class ResourceClaims:
    def __init__(self, entries, save, error):
        self.entries, self.save, self.error = entries, save, error

    def fail(self, path):
        raise self.error(f"The reserved host resource {path} is occupied or changed outside this setup operation. It was preserved. Keep the saved operation; move the conflicting resource to a different name, or restore this operation’s original file, then retry the same setup file.")

    @staticmethod
    def sync(path):
        descriptor = os.open(path, os.O_RDONLY | os.O_DIRECTORY | os.O_NOFOLLOW)
        try:
            os.fsync(descriptor)
        finally:
            os.close(descriptor)

    def proof(self, path):
        path = Path(path)
        try:
            info = path.lstat()
        except FileNotFoundError:
            return None
        if (not stat.S_ISDIR(info.st_mode) and info.st_uid != 0) or (not stat.S_ISLNK(info.st_mode) and not stat.S_ISDIR(info.st_mode) and (not stat.S_ISREG(info.st_mode) or info.st_nlink != 1 or info.st_mode & 0o022)):
            self.fail(path)
        result = dict(device=info.st_dev, inode=info.st_ino, uid=info.st_uid, gid=info.st_gid, mode=info.st_mode)
        if stat.S_ISLNK(info.st_mode):
            result['target'] = os.readlink(path)
        elif stat.S_ISREG(info.st_mode):
            descriptor = os.open(path, os.O_RDONLY | os.O_NOFOLLOW)
            with os.fdopen(descriptor, 'rb') as stream:
                if self.metadata(os.fstat(stream.fileno())) != self.metadata(info):
                    self.fail(path)
                result['sha256'] = hashlib.file_digest(stream, 'sha256').hexdigest() if hasattr(hashlib, 'file_digest') else hashlib.sha256(stream.read()).hexdigest()
            if self.metadata(path.lstat()) != self.metadata(info):
                self.fail(path)
        return result

    @staticmethod
    def metadata(info):
        return (info.st_dev, info.st_ino, info.st_uid, info.st_gid, info.st_mode, info.st_nlink, info.st_size, info.st_mtime_ns, info.st_ctime_ns)

    def reserve(self, paths):
        # Check every destination before saving any new claim.
        for path in paths:
            if str(path) not in self.entries and self.proof(path) is not None:
                self.fail(path)
        for path in paths:
            self.entries.setdefault(str(path), {'current': None})
        self.save()
        self.check_all()

    def adopt(self, path):
        # Only callers that have validated an existing application resource may adopt it.
        if str(path) not in self.entries:
            self.entries[str(path)] = {'current': self.proof(path)}
            self.save()
        self.check(path)

    def check(self, path):
        entry = self.entries.get(str(path))
        actual = self.proof(path)
        if entry is None:
            if actual is not None:
                self.fail(path)
            return None
        permitted = [entry['current']]
        if 'pending' in entry:
            permitted.append(entry['pending']['next'])
        if actual not in permitted:
            self.fail(path)
        return actual

    def check_all(self):
        for path in self.entries:
            self.check(path)

    @staticmethod
    def create_exclusive(source, destination):
        # Both supported distributions provide Linux renameat2. No exists→replace gap.
        library = ctypes.CDLL(None, use_errno=True)
        if library.renameat2(-100, os.fsencode(source), -100, os.fsencode(destination), 1) != 0:
            number = ctypes.get_errno()
            raise OSError(number, os.strerror(number))

    def finish(self, path, entry):
        pending = entry['pending']
        actual = self.check(path)
        temporary = Path(pending['temporary']) if pending['temporary'] else None
        if actual != pending['next']:
            if temporary is None:
                path.unlink()
            else:
                if self.proof(temporary) != pending['next']:
                    self.fail(temporary)
                # Recheck just before mutation. External root configuration must not be adopted.
                self.check(path)
                if entry['current'] is None:
                    try:
                        self.create_exclusive(temporary, path)
                    except OSError as error:
                        if error.errno == errno.EEXIST:
                            self.fail(path)
                        raise
                else:
                    os.replace(temporary, path)
            self.sync(path.parent)
        if self.proof(path) != pending['next']:
            self.fail(path)
        # Also required when a previous process published/unlinked but died before fsync.
        self.sync(path.parent)
        entry['current'] = pending['next']
        del entry['pending']
        self.save()
        # A crash before first publication leaves an owned temporary file, never a foreign destination.
        if temporary and os.path.lexists(temporary):
            if self.proof(temporary) != entry['current']:
                self.fail(temporary)
            temporary.unlink()
            self.sync(temporary.parent)

    def write(self, path, content, mode=0o644, *, link=False, gid=0):
        path = Path(path)
        if str(path) not in self.entries:
            self.reserve([path])
        entry = self.entries[str(path)]
        self.check(path)
        if 'pending' in entry:
            self.finish(path, entry)
        if link:
            if entry['current'] is not None:
                if entry['current'].get('target') != str(content):
                    self.fail(path)
                return
        elif entry['current'] is not None and entry['current'].get('sha256') == hashlib.sha256(content if isinstance(content, bytes) else content.encode()).hexdigest() and stat.S_IMODE(entry['current']['mode']) == mode and entry['current']['gid'] == gid:
            return
        path.parent.mkdir(parents=True, exist_ok=True)
        for parent in (path.parent, *path.parent.parents):
            info = parent.lstat()
            if not stat.S_ISDIR(info.st_mode) or info.st_uid != 0 or (info.st_mode & 0o022 and not info.st_mode & stat.S_ISVTX):
                self.fail(parent)
        descriptor, name = tempfile.mkstemp(prefix='.' + path.name + '-emailer-', dir=path.parent)
        temporary = Path(name)
        if link:
            os.close(descriptor)
            temporary.unlink()
            temporary.symlink_to(content)
        else:
            os.fchown(descriptor, 0, gid)
            os.fchmod(descriptor, mode)
            with os.fdopen(descriptor, 'wb') as stream:
                stream.write(content if isinstance(content, bytes) else content.encode())
                stream.flush()
                os.fsync(stream.fileno())
        self.sync(path.parent)
        entry['pending'] = {'next': self.proof(temporary), 'temporary': str(temporary)}
        self.save()  # Publication intent contains the exact temporary inode, type and bytes.
        self.finish(path, entry)

    def settle(self, path):
        path = Path(path)
        self.check(path)
        entry = self.entries.get(str(path))
        if entry is not None and 'pending' in entry:
            self.finish(path, entry)

    def remove(self, path):
        path = Path(path)
        self.check(path)
        entry = self.entries[str(path)]
        if 'pending' in entry:
            self.finish(path, entry)
        if entry['current'] is None:
            return
        entry['pending'] = {'next': None, 'temporary': None}
        self.save()
        self.finish(path, entry)

    def directory(self, path, uid, gid, mode):
        path = Path(path)
        if str(path) not in self.entries:
            self.reserve([path])
        entry = self.entries[str(path)]
        self.check(path)
        if 'pending' in entry:
            self.finish(path, entry)
        if entry['current'] is not None:
            proof = entry['current']
            if not stat.S_ISDIR(proof['mode']) or (proof['uid'], proof['gid'], stat.S_IMODE(proof['mode'])) != (uid, gid, mode):
                self.fail(path)
            return
        temporary = Path(tempfile.mkdtemp(prefix='.' + path.name + '-emailer-', dir=path.parent))
        os.chown(temporary, uid, gid)
        os.chmod(temporary, mode)
        self.sync(temporary)
        self.sync(path.parent)
        entry['pending'] = {'next': self.proof(temporary), 'temporary': str(temporary)}
        self.save()
        self.finish(path, entry)
