#!/usr/bin/env python3
"""Load one protected environment and execute Emailer as its service identity."""
import os
from pathlib import Path
import pwd
import sys

if os.geteuid() != 0:
    sys.exit('Use sudo @NAME@ for protected server commands.')
configuration = Path('/etc/@NAME@/runtime.env')
metadata = configuration.lstat()
if not configuration.is_file() or configuration.is_symlink() or metadata.st_uid != 0 or metadata.st_mode & 0o027 or metadata.st_nlink != 1:
    sys.exit('The protected Emailer runtime configuration is unsafe.')
environment = {'PATH': '/usr/bin:/bin', 'HOME': '/var/lib/@NAME@', 'LANG': 'C.UTF-8'}
for line in configuration.read_text().splitlines():
    key, value = line.split('=', 1)
    if not key.replace('_', '').isalnum() or not value.startswith("'") or not value.endswith("'"):
        sys.exit('The protected Emailer runtime configuration has an unsupported format.')
    environment[key] = value[1:-1]
account = pwd.getpwnam('@USER@')
os.initgroups(account.pw_name, account.pw_gid)
os.setgid(account.pw_gid)
os.setuid(account.pw_uid)
os.chdir('/srv/@NAME@/releases/current')
os.execve('/usr/bin/php8.4', ['/usr/bin/php8.4', 'bin/console', *sys.argv[1:]], environment)
