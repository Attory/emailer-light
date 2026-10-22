#!/usr/bin/env python3
"""Load one protected environment and execute Emailer as its service identity."""
import os
from pathlib import Path
import pwd
import sys

if os.geteuid() != 0:
    sys.exit('Use sudo @NAME@ for protected server commands.')
configuration = Path('@RUNTIME_FILE@')
metadata = configuration.lstat()
if not configuration.is_file() or configuration.is_symlink() or metadata.st_uid != 0 or metadata.st_mode & 0o027 or metadata.st_nlink != 1:
    sys.exit('The protected Emailer runtime configuration is unsafe.')
for parent in configuration.parents:
    metadata = parent.lstat()
    if parent.is_symlink() or metadata.st_uid != 0 or metadata.st_mode & 0o022:
        sys.exit('The protected Emailer runtime folder is unsafe.')
environment = {'PATH': '/usr/bin:/bin', 'HOME': '/var/lib/@NAME@', 'LANG': 'C.UTF-8'}
try:
    environment.update(parse_environment_text(configuration.read_text()))
except RuntimeEnvironmentError as error:
    sys.exit(str(error))
account = pwd.getpwnam('@USER@')
os.umask(0o077)
os.initgroups(account.pw_name, account.pw_gid)
os.setgid(account.pw_gid)
os.setuid(account.pw_uid)
os.chdir('@CURRENT_RELEASE@')
os.execve('@PHP@', ['@PHP@', 'bin/console', *sys.argv[1:]], environment)
