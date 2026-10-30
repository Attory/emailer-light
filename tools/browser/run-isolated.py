#!/usr/bin/env python3
"""Run editor browser and real-mail regressions without external app egress.

The PHP image and pinned browser must already exist. This tool never downloads
images, touches a deployment, or reuses any database, mail sink or web service.
Only its uniquely named and labeled resources can be removed during cleanup.
"""
import base64
import hashlib
import json
import os
from pathlib import Path
import re
import secrets
import shutil
import subprocess
import sys
import tempfile
import time

root = Path(__file__).resolve().parents[2]
token = secrets.token_hex(6)
name = 'emailer-editor-' + token
label = 'org.emailer.browser-test'
edition = 'light'
service_uid = 1000 if os.getuid() == 0 else os.getuid()
service_gid = 1000 if os.getuid() == 0 else os.getgid()
image = os.environ['EMAILER_BROWSER_PHP_IMAGE']
output = Path(os.environ.get('EMAILER_BROWSER_OUTPUT', str(root / 'build/browser-results'))).resolve()
private = Path(tempfile.mkdtemp(prefix=name + '-'))
if private.is_relative_to(root):
    raise SystemExit('Keep synthetic credentials and keys outside the source tree.')
if os.getuid() == 0:
    os.chown(private, service_uid, service_gid)
output.mkdir(mode=0o700, parents=True, exist_ok=True)
log = (private / 'runner.log').open('wb')
containers = []
network_created = False
proxy = None
stage = 'existing image check'


def command(args, **kwargs):
    return subprocess.run(args, check=True, stdout=log, stderr=log, **kwargs)


def inspect(kind, resource):
    return json.loads(subprocess.check_output(['docker', kind, 'inspect', resource], stderr=log))[0]


def write(file, data):
    file.write_text(data)
    file.chmod(0o600)


def php(*args):
    command(['docker', 'exec', name + '-web', 'php', *args])


try:
    command(['docker', 'image', 'inspect', image])
    # Require cached images locally; CI explicitly pulls these documented pins.
    for dependency in ('mariadb:10.11.19', 'axllent/mailpit:v1.30.5', 'python:3.12.14-slim-bookworm'):
        command(['docker', 'image', 'inspect', dependency])
    stage = 'new isolated services'
    network_options = ['--subnet', os.environ['EMAILER_BROWSER_NETWORK_SUBNET']] if os.environ.get('EMAILER_BROWSER_NETWORK_SUBNET') else []
    command(['docker', 'network', 'create', '--internal', '--label', label + '=' + token, *network_options, name])
    network_created = True
    database_password = secrets.token_hex(24)
    write(private / 'database.env', 'MARIADB_DATABASE=emailer_browser_test\nMARIADB_USER=emailer\nMARIADB_PASSWORD=' + database_password + '\nMARIADB_ROOT_PASSWORD=' + secrets.token_hex(24) + '\n')
    command(['docker', 'run', '-d', '--name', name + '-db', '--label', label + '=' + token, '--network', name, '--network-alias', 'database', '--env-file', str(private / 'database.env'), 'mariadb:10.11.19'])
    containers.append(name + '-db')
    command(['docker', 'run', '-d', '--name', name + '-mailpit', '--label', label + '=' + token, '--network', name, '--network-alias', 'mailpit', '-e', 'MP_DISABLE_VERSION_CHECK=true', '-e', 'MP_MAX_MESSAGES=1000', 'axllent/mailpit:v1.30.5'])
    containers.append(name + '-mailpit')
    stage = 'owned SMTP TLS fixture'
    smtp = private / 'smtp'
    smtp.mkdir(mode=0o755)
    evidence = smtp / 'evidence'
    evidence.mkdir(mode=0o755)
    if os.getuid() == 0:
        os.chown(evidence, service_uid, service_gid)
    command(['openssl', 'req', '-x509', '-newkey', 'rsa:2048', '-nodes', '-days', '1', '-subj', '/CN=Emailer-Browser-Synthetic-CA', '-addext', 'basicConstraints=critical,CA:TRUE', '-keyout', str(smtp / 'ca.key'), '-out', str(smtp / 'ca.crt')])
    command(['openssl', 'req', '-newkey', 'rsa:2048', '-nodes', '-subj', '/CN=smtp-fixture.test', '-keyout', str(smtp / 'server.key'), '-out', str(smtp / 'server.csr')])
    (smtp / 'extensions').write_text('subjectAltName=DNS:smtp-fixture.test\nextendedKeyUsage=serverAuth\n')
    command(['openssl', 'x509', '-req', '-in', str(smtp / 'server.csr'), '-CA', str(smtp / 'ca.crt'), '-CAkey', str(smtp / 'ca.key'), '-CAcreateserial', '-days', '1', '-extfile', str(smtp / 'extensions'), '-out', str(smtp / 'server.crt')])
    command(['docker', 'run', '-d', '--name', name + '-smtp', '--label', label + '=' + token, '--network', name, '--network-alias', 'smtp-fixture.test', '--network-alias', 'wrong-host.test', '-v', str(smtp) + ':/fixture:ro', '-v', str(evidence) + ':/evidence', '-v', str(root / 'tools/installer/smtp-fixture.py') + ':/smtp-fixture.py:ro', 'python:3.12.14-slim-bookworm', 'python', '/smtp-fixture.py'])
    containers.append(name + '-smtp')
    for attempt in range(30):
        if (evidence / 'ready').is_file():
            break
        time.sleep(1)
    else:
        raise RuntimeError('Owned SMTP fixture did not start.')
    network = inspect('network', name)
    if not network['Internal'] or network['Labels'].get(label) != token:
        raise RuntimeError('Network isolation mismatch.')
    gateway = network['IPAM']['Config'][0]['Gateway']
    private_key_match = re.search(r"emailer.master_key_file: '([^']+)'", (root / 'config/services_test.yaml').read_text())
    if not private_key_match or not private_key_match[1].startswith('/tmp/'):
        raise RuntimeError('Unexpected test-only key path.')
    key_directory = str(Path(private_key_match[1]).parent)
    (private / 'test-keys').mkdir(mode=0o700)
    if os.getuid() == 0:
        os.chown(private / 'test-keys', service_uid, service_gid)
    environment = {
        'EMAILER_BROWSER_SMTP_FIXTURE': '1', 'APP_ENV': 'test', 'APP_DEBUG': '0', 'APP_SECRET': secrets.token_hex(32),
        'DATABASE_URL': 'mysql://emailer:' + database_password + '@database:3306/emailer_browser_test?serverVersion=10.11.19-MariaDB&charset=utf8mb4',
        'DEFAULT_URI': 'https://emailer.example.test', 'EMAILER_PRIVATE_DIR': '/emailer-browser-runtime/private',
        'EMAILER_UPDATE_TEST_PRIVATE_DIR': '/emailer-browser-runtime/private',
        'EMAILER_SECRET_KEY_FILE': key_directory + '/master.key', 'LOCK_DSN': 'flock',
        'MAILER_DSN': 'smtp://mailpit:1025', 'MESSENGER_TRANSPORT_DSN': 'doctrine://default',
        'TRUSTED_PROXIES': '127.0.0.1,::1,' + gateway,
        'TRUSTED_HOSTS': '^emailer\\.example\\.test$',
    }
    write(private / 'application.env', ''.join(key + '=' + value + '\n' for key, value in environment.items()))
    write(private / 'browser-fixture.json', json.dumps({'email': 'editor@example.test', 'password': 'Synthetic editor ' + secrets.token_urlsafe(24)}))
    if os.getuid() == 0:
        os.chown(private / 'browser-fixture.json', service_uid, service_gid)
    web = ['docker', 'run', '-d', '--name', name + '-web', '--label', label + '=' + token, '--network', name, '--user', str(service_uid) + ':' + str(service_gid), '--env-file', str(private / 'application.env'), '-v', str(private) + ':/emailer-browser-secrets', '-v', str(private / 'test-keys') + ':' + key_directory, '-v', str(smtp / 'ca.crt') + ':/emailer-browser-smtp-ca.crt:ro']
    if os.environ.get('EMAILER_BROWSER_SOURCE_TREE') == '1':
        web += ['-v', str(root) + ':/app']
    if os.environ.get('EMAILER_BROWSER_VENDOR_DIRECTORY'):
        vendor = Path(os.environ['EMAILER_BROWSER_VENDOR_DIRECTORY']).resolve()
        if os.environ.get('EMAILER_BROWSER_SOURCE_TREE') != '1' or not (vendor / 'autoload.php').is_file():
            raise RuntimeError('A vendor override requires the explicit source-level test profile.')
        web += ['-v', str(vendor) + ':/app/vendor']
    web += ['-w', '/app', image, 'php', '-d', 'openssl.cafile=/emailer-browser-smtp-ca.crt', '-d', 'variables_order=EGPCS', '-S', '0.0.0.0:8080', '-t', 'public', 'tools/browser/router.php']
    command(web)
    containers.append(name + '-web')
    command(['docker', 'exec', '--user', '0:0', name + '-web', 'sh', '-c', 'mkdir -p /emailer-browser-runtime/private && chmod 0755 /emailer-browser-runtime && chown ' + str(service_uid) + ':' + str(service_gid) + ' /emailer-browser-runtime/private && chmod 0700 /emailer-browser-runtime/private'])
    for attempt in range(60):
        ready = subprocess.run(['docker', 'exec', name + '-db', 'healthcheck.sh', '--connect', '--innodb_initialized'], stdout=log, stderr=log)
        if ready.returncode == 0:
            break
        time.sleep(1)
    else:
        raise RuntimeError('Disposable database did not start.')
    stage = 'real synthetic installation'
    php('-r', 'if(posix_geteuid()===0){exit(1);}')
    # CI and native packages contain this directory. It must not change routing
    # for private image storage served through the application controller.
    php('-r', 'if(!is_dir("public/template-assets")&&!mkdir("public/template-assets",0775,true)){exit(1);}')
    php('bin/console', 'cache:clear', '--env=test', '--no-debug', '--no-warmup')
    command(['docker', 'exec', name + '-web', 'bash', 'tools/quality/check-migrations.sh'])
    php('tools/browser/seed-editor.php')
    php('bin/console', 'asset-map:compile', '--env=test', '--no-interaction')
    php('bin/console', 'emailer:doctor:run', '--env=test', '--no-interaction')
    php('-r', '$s=@stream_socket_client("tcp://192.0.2.1:25",$c,$m,1);if(false!==$s){fclose($s);exit(1);}')
    php('tools/browser/verify-editor-delivery.php', 'snapshot')
    stage = 'loopback TLS'
    command(['openssl', 'req', '-x509', '-newkey', 'rsa:2048', '-nodes', '-days', '2', '-keyout', str(private / 'tls.key'), '-out', str(private / 'tls.crt'), '-subj', '/CN=emailer.example.test', '-addext', 'subjectAltName=DNS:emailer.example.test'])
    (private / 'tls.key').chmod(0o600)
    public = subprocess.check_output(['openssl', 'x509', '-in', str(private / 'tls.crt'), '-pubkey', '-noout'], stderr=log)
    der = subprocess.check_output(['openssl', 'pkey', '-pubin', '-outform', 'DER'], input=public, stderr=log)
    address = inspect('container', name + '-web')['NetworkSettings']['Networks'][name]['IPAddress']
    write(private / 'proxy.json', json.dumps({'upstream': address, 'certificate': str(private / 'tls.crt'), 'key': str(private / 'tls.key')}))
    proxy = subprocess.Popen([sys.executable, str(root / 'tools/browser/tls-proxy.py'), str(private / 'proxy.json')], stdout=subprocess.PIPE, stderr=log, text=True)
    port = int(proxy.stdout.readline().strip())
    config = {'url': 'https://emailer.example.test', 'port': port, 'spki': base64.b64encode(hashlib.sha256(der).digest()).decode(), 'edition': edition, 'credentials': str(private / 'browser-fixture.json'), 'image': str(private / 'banner.png'), 'output': str(output), 'requireExistingAssets': False, 'maintenanceContainer': name + '-web'}
    write(private / 'browser.json', json.dumps(config))
    stage = 'real browser regressions'
    node_environment = os.environ.copy()
    node_environment['EMAILER_BROWSER_CONFIG'] = str(private / 'browser.json')
    focus = os.environ.get('EMAILER_BROWSER_FOCUS', '')
    if focus not in ('', 'smtp', 'actions') or (focus and os.environ.get('EMAILER_BROWSER_SOURCE_TREE') != '1'):
        raise RuntimeError('Focused suites are available only for explicit source-level verification.')
    if focus == '':
        command(['node', str(root / 'tools/browser/template-editor.cjs')], env=node_environment)
        shutil.copyfile(output / 'browser-result.json', private / 'browser-result.json')
        (private / 'browser-result.json').chmod(0o600)
        if os.getuid() == 0:
            os.chown(private / 'browser-result.json', service_uid, service_gid)
        stage = 'no-send and explicit queued SMTP acceptance'
        php('tools/browser/verify-editor-delivery.php', 'request')
        php('bin/console', 'messenger:consume', 'async', '--limit=1', '--time-limit=30', '--env=test', '--no-interaction')
        php('tools/browser/verify-editor-delivery.php', 'verify')
        for receipt in ('editor-no-send-result.json', 'editor-delivery-result.json'):
            shutil.copyfile(private / receipt, output / receipt)
        stage = 'campaign browser regressions'
        php('tools/browser/verify-campaign-workflow.php', 'snapshot')
        command(['node', str(root / 'tools/browser/campaign-editor.cjs')], env=node_environment)
        shutil.copyfile(output / 'campaign-browser-result.json', private / 'campaign-browser-result.json')
        (private / 'campaign-browser-result.json').chmod(0o600)
        if os.getuid() == 0:
            os.chown(private / 'campaign-browser-result.json', service_uid, service_gid)
        stage = 'campaign no-send verification'
        php('tools/browser/verify-campaign-workflow.php', 'verify')
        shutil.copyfile(private / 'campaign-no-send-result.json', output / 'campaign-no-send-result.json')
    if focus == 'actions':
        stage = 'compact template action browser regressions'
        command(['node', str(root / 'tools/browser/template-actions.cjs')], env=node_environment)
        php('tools/browser/verify-editor-delivery.php', 'actions')
        shutil.copyfile(private / 'actions-no-send-result.json', output / 'actions-no-send-result.json')
    else:
        stage = 'SMTP current-form browser regressions'
        command(['node', str(root / 'tools/browser/mail-settings.cjs')], env=node_environment)
    if focus == '':
        stage = 'compact template action browser regressions'
        php('tools/browser/verify-editor-delivery.php', 'snapshot')
        command(['node', str(root / 'tools/browser/template-actions.cjs')], env=node_environment)
        php('tools/browser/verify-editor-delivery.php', 'actions')
        shutil.copyfile(private / 'actions-no-send-result.json', output / 'actions-no-send-result.json')
    if (evidence / 'state.json').exists():
        raise RuntimeError('Connection form testing transmitted SMTP DATA.')
    print('PASS: isolated ' + edition + ' ' + (focus or 'complete') + ' browser suite; no unintended delivery; safe receipts in ' + str(output))
except Exception:
    print('FAIL: editor acceptance at ' + stage + '. Private diagnostics retained; credentials and request details omitted.', file=sys.stderr)
    receipt = output / ('campaign-browser-result.json' if stage.startswith('campaign') else 'browser-result.json')
    if stage in ('real browser regressions', 'campaign browser regressions') and receipt.is_file():
        try:
            failed = json.loads(receipt.read_text())
            failed_phase = failed.get('failedPhase')
            if failed.get('status') == 'FAIL' and isinstance(failed_phase, str) and re.fullmatch(r'[A-Za-z0-9 /_-]{1,160}', failed_phase):
                print('Browser regression phase: ' + failed_phase, file=sys.stderr)
                print('Browser cases completed: ' + str(len(failed.get('cases', []))), file=sys.stderr)
                status = failed.get('publicImageStatus')
                if isinstance(status, int) and 100 <= status <= 599:
                    print('Public image HTTP status: ' + str(status), file=sys.stderr)
                if failed.get('publicImageContentType') in ('image/png', 'text/html; charset=UTF-8', 'application/json'):
                    print('Public image content type: ' + failed['publicImageContentType'], file=sys.stderr)
                for error in failed.get('runtimeErrors', [])[:3]:
                    if isinstance(error, dict) and error.get('type') in ('Error', 'TypeError', 'ReferenceError', 'SyntaxError', 'RangeError', 'OtherError') and re.fullmatch(r'(?:/assets/[A-Za-z0-9._/-]+\.js:\d+:\d+|No asset frame)', error.get('frame', '')):
                        print('Browser runtime category: ' + error['type'] + ' at ' + error['frame'], file=sys.stderr)
        except Exception:
            pass
    sys.exit(1)
finally:
    if proxy:
        proxy.terminate()
        proxy.wait(timeout=10)
    for resource in reversed(containers):
        try:
            if inspect('container', resource)['Config']['Labels'].get(label) == token:
                command(['docker', 'rm', '-f', '-v', resource])
        except Exception:
            print('Task resource cleanup requires inspection: ' + resource, file=sys.stderr)
    if network_created:
        try:
            if inspect('network', name)['Labels'].get(label) == token:
                command(['docker', 'network', 'rm', name])
        except Exception:
            print('Task network cleanup requires inspection: ' + name, file=sys.stderr)
    log.close()
