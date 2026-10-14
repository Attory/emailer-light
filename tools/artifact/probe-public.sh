#!/usr/bin/env bash

set -euo pipefail

project_root="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
artifact_root="$project_root/build/artifact"
runtime_root="$project_root/build/probe-runtime"
listen_address='127.0.0.1:18080'
server_pid=''

mkdir -p "$runtime_root"
find "$runtime_root" -mindepth 1 -depth -delete

cleanup() {
    if [ -n "$server_pid" ] && kill -0 "$server_pid" 2>/dev/null; then
        kill "$server_pid"
        wait "$server_pid" 2>/dev/null || true
    fi
    find "$runtime_root" -mindepth 1 -depth -delete
    rmdir "$runtime_root"
}
trap cleanup EXIT

APP_ENV=prod \
APP_DEBUG=0 \
APP_SECRET=phase1-probe-only \
DATABASE_URL='mysql://emailer:local-only@database:3306/emailer_test?serverVersion=10.11.19-MariaDB&charset=utf8mb4' \
DEFAULT_URI="http://$listen_address" \
EMAILER_PRIVATE_DIR="$runtime_root" \
LOCK_DSN=flock \
MAILER_DSN='smtp://mailpit:1025' \
MESSENGER_TRANSPORT_DSN='doctrine://default' \
php -S "$listen_address" -t "$artifact_root/public" "$artifact_root/public/index.php" \
    >"$runtime_root/server.stdout" \
    2>"$runtime_root/server.stderr" &
server_pid=$!

for _ in $(seq 1 20); do
    if curl --fail --silent "http://$listen_address/health/live" >/dev/null 2>&1; then
        break
    fi
    sleep 0.25
done

body="$(curl --fail --silent --show-error "http://$listen_address/health/live")"
if [ '{"status":"ok"}' != "$body" ]; then
    printf 'Artifact liveness response was not coarse and fixed.\n' >&2
    exit 1
fi

for forbidden_path in \
    '/.env' \
    '/.git/config' \
    '/composer.json' \
    '/config/services.yaml' \
    '/docs/README.md' \
    '/tests/bootstrap.php' \
    '/var/log/prod.log' \
    '/vendor/autoload.php' \
    '/%2e%2e/config/services.yaml'; do
    status="$(
        curl \
            --silent \
            --show-error \
            --output /dev/null \
            --write-out '%{http_code}' \
            --path-as-is \
            "http://$listen_address$forbidden_path"
    )"

    if [ '404' != "$status" ]; then
        printf 'Forbidden public path did not return 404: %s\n' "$forbidden_path" >&2
        exit 1
    fi
done
