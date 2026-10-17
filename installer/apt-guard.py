"""APT pre-install gate: runs with APT/dpkg locks held, before unpacking any .deb."""
import json
from pathlib import Path
import re
import subprocess
import sys


def inspect(plan, paths, runner=subprocess.run):
    if plan.get('format')!='emailer-apt-plan-v1' or not isinstance(plan.get('packages'),dict):
        raise ValueError('plan')
    for path in paths:
        if not path:continue
        metadata=runner(['dpkg-deb','--field',path,'Package','Version','Architecture'],check=True,capture_output=True,text=True).stdout
        fields=dict(line.split(': ',1) for line in metadata.splitlines() if ': ' in line)
        name,version,architecture=fields['Package'],fields['Version'],fields['Architecture']
        if not re.fullmatch(r'[a-z0-9][a-z0-9+.-]*',name) or architecture not in ('amd64','all'):raise ValueError('package')
        approved=plan['packages'].get(name) or plan['packages'].get(name+':'+architecture)
        if not isinstance(approved,dict) or approved.get('version')!=version:raise ValueError('unplanned package')
        current=runner(['dpkg-query','-W','-f=${Status}\n${Version}',name],capture_output=True,text=True)
        installed=current.stdout.splitlines()
        existing=installed[1] if current.returncode==0 and len(installed)==2 and installed[0]=='install ok installed' else None
        if existing not in (approved.get('previous'),version):raise ValueError('installed package changed')


def main():
    try:
        inspect(json.loads(Path(sys.argv[1]).read_text()),[line.rstrip('\n') for line in sys.stdin])
    except (OSError,ValueError,KeyError,TypeError,subprocess.SubprocessError):
        print('EMAILER_APT_PLAN_CHANGED: The actual package transaction differs from the verified plan. No package was unpacked. Let other host package tasks finish, then rerun setup.',file=sys.stderr)
        return 1
    return 0


if __name__=='__main__':sys.exit(main())
