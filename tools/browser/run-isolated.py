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
    for dependency in ('mariadb:10.11.19', 'axllent/mailpit:v1.30.5'):
        command(['docker', 'image', 'inspect', dependency])
    stage = 'new isolated services'
    command(['docker', 'network', 'create', '--internal', '--label', label + '=' + token, name])
    network_created = True
    database_password = secrets.token_hex(24)
    write(private / 'database.env', 'MARIADB_DATABASE=emailer_browser_test\nMARIADB_USER=emailer\nMARIADB_PASSWORD=' + database_password + '\nMARIADB_ROOT_PASSWORD=' + secrets.token_hex(24) + '\n')
    command(['docker', 'run', '-d', '--name', name + '-db', '--label', label + '=' + token, '--network', name, '--network-alias', 'database', '--env-file', str(private / 'database.env'), 'mariadb:10.11.19'])
    containers.append(name + '-db')
    command(['docker', 'run', '-d', '--name', name + '-mailpit', '--label', label + '=' + token, '--network', name, '--network-alias', 'mailpit', '-e', 'MP_DISABLE_VERSION_CHECK=true', '-e', 'MP_MAX_MESSAGES=1000', 'axllent/mailpit:v1.30.5'])
    containers.append(name + '-mailpit')
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
        'APP_ENV': 'test', 'APP_DEBUG': '0', 'APP_SECRET': secrets.token_hex(32),
        'DATABASE_URL': 'mysql://emailer:' + database_password + '@database:3306/emailer_browser_test?serverVersion=10.11.19-MariaDB&charset=utf8mb4',
        'DEFAULT_URI': 'https://emailer.example.test', 'EMAILER_PRIVATE_DIR': '/emailer-browser-secrets/runtime',
        'EMAILER_SECRET_KEY_FILE': key_directory + '/master.key', 'LOCK_DSN': 'flock',
        'MAILER_DSN': 'smtp://mailpit:1025', 'MESSENGER_TRANSPORT_DSN': 'doctrine://default',
        'TRUSTED_PROXIES': '127.0.0.1,::1,' + gateway,
        'TRUSTED_HOSTS': '^emailer\\.example\\.test$',
    }
    write(private / 'application.env', ''.join(key + '=' + value + '\n' for key, value in environment.items()))
    write(private / 'browser-fixture.json', json.dumps({'email': 'editor@example.test', 'password': 'Synthetic editor ' + secrets.token_urlsafe(24)}))
    if os.getuid() == 0:
        os.chown(private / 'browser-fixture.json', service_uid, service_gid)
    web = ['docker', 'run', '-d', '--name', name + '-web', '--label', label + '=' + token, '--network', name, '--user', str(service_uid) + ':' + str(service_gid), '--env-file', str(private / 'application.env'), '-v', str(private) + ':/emailer-browser-secrets', '-v', str(private / 'test-keys') + ':' + key_directory]
    if os.environ.get('EMAILER_BROWSER_SOURCE_TREE') == '1':
        web += ['-v', str(root) + ':/app']
    web += ['-w', '/app', image, 'php', '-d', 'variables_order=EGPCS', '-S', '0.0.0.0:8080', '-t', 'public', 'tools/browser/router.php']
    command(web)
    containers.append(name + '-web')
    for attempt in range(60):
        ready = subprocess.run(['docker', 'exec', name + '-db', 'healthcheck.sh', '--connect', '--innodb_initialized'], stdout=log, stderr=log)
        if ready.returncode == 0:
            break
        time.sleep(1)
    else:
        raise RuntimeError('Disposable database did not start.')
    stage = 'real synthetic installation'
    php('-r', 'if(posix_geteuid()===0){exit(1);}')
    php('bin/console', 'doctrine:migrations:migrate', '--env=test', '--no-interaction')
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
    config = {'url': 'https://emailer.example.test', 'port': port, 'spki': base64.b64encode(hashlib.sha256(der).digest()).decode(), 'edition': edition, 'credentials': str(private / 'browser-fixture.json'), 'image': str(private / 'banner.png'), 'output': str(output), 'requireExistingAssets': False}
    write(private / 'browser.json', json.dumps(config))
    stage = 'real browser regressions'
    node_environment = os.environ.copy()
    node_environment['EMAILER_BROWSER_CONFIG'] = str(private / 'browser.json')
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
    print('PASS: isolated ' + edition + ' browser, no-send and real worker SMTP regressions; safe receipts in ' + str(output))
except Exception:
    print('FAIL: editor acceptance at ' + stage + '. Private diagnostics retained; credentials and request details omitted.', file=sys.stderr)
    receipt = output / 'browser-result.json'
    if stage == 'real browser regressions' and receipt.is_file():
        try:
            failed = json.loads(receipt.read_text())
            failed_phase = failed.get('failedPhase')
            if failed.get('status') == 'FAIL' and isinstance(failed_phase, str) and re.fullmatch(r'[A-Za-z0-9 /_-]{1,160}', failed_phase):
                print('Browser regression phase: ' + failed_phase, file=sys.stderr)
                print('Browser cases completed: ' + str(len(failed.get('cases', []))), file=sys.stderr)
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
                command(['docker', 'rm', '-f', resource])
        except Exception:
            print('Task resource cleanup requires inspection: ' + resource, file=sys.stderr)
    if network_created:
        try:
            if inspect('network', name)['Labels'].get(label) == token:
                command(['docker', 'network', 'rm', name])
        except Exception:
            print('Task network cleanup requires inspection: ' + name, file=sys.stderr)
    log.close()
