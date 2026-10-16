#!/usr/bin/env bash
set -euo pipefail
project_root="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
fixture="$(mktemp -d /tmp/emailer-smtp-acceptance.XXXXXXXX)"
network="emailer-smtp-acceptance-$$"
sink="${network}-sink"
cleanup() {
    docker rm --force "$sink" >/dev/null 2>&1 || true
    docker network rm "$network" >/dev/null 2>&1 || true
    rm -rf -- "$fixture"
}
trap cleanup EXIT
mkdir "$fixture/evidence"
chmod 0755 "$fixture/evidence"
openssl req -x509 -newkey rsa:2048 -nodes -days 1 -subj /CN=Emailer-Synthetic-SMTP-CA -addext basicConstraints=critical,CA:TRUE -keyout "$fixture/ca.key" -out "$fixture/ca.crt" >/dev/null 2>&1
openssl req -newkey rsa:2048 -nodes -subj /CN=smtp-fixture.test -keyout "$fixture/server.key" -out "$fixture/server.csr" >/dev/null 2>&1
printf 'subjectAltName=DNS:smtp-fixture.test\nextendedKeyUsage=serverAuth\n' > "$fixture/extensions"
openssl x509 -req -in "$fixture/server.csr" -CA "$fixture/ca.crt" -CAkey "$fixture/ca.key" -CAcreateserial -days 1 -extfile "$fixture/extensions" -out "$fixture/server.crt" >/dev/null 2>&1
chmod 0644 "$fixture/ca.crt"
docker network create --internal "$network" >/dev/null
docker run --detach --name "$sink" --network "$network" --network-alias smtp-fixture.test --network-alias wrong-host.test -v "$fixture:/fixture:ro" -v "$fixture/evidence:/evidence" -v "$project_root/tools/installer/smtp-fixture.py:/smtp-fixture.py:ro" "${EMAILER_SMTP_FIXTURE_IMAGE:-python:3.12.14-slim-bookworm}" python /smtp-fixture.py >/dev/null
for attempt in {1..30}; do
    if [[ -f "$fixture/evidence/ready" ]]; then break; fi
    sleep 1
done
[[ -f "$fixture/evidence/ready" ]] || { printf 'The isolated synthetic SMTP fixture did not start.\n' >&2; exit 1; }
docker run --rm --user "${EMAILER_SMTP_PROBE_UID:-1000:1000}" --network "$network" -v "$project_root:/app:ro" -v "$fixture/ca.crt:/smtp-ca.crt:ro" -v "$fixture/evidence:/evidence:ro" -w /app "${EMAILER_SMTP_PROBE_IMAGE:-emailer-next-app:latest}" php -d openssl.cafile=/smtp-ca.crt tools/installer/check-smtp-probe.php
