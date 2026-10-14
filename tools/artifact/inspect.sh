#!/usr/bin/env bash

set -euo pipefail

project_root="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
artifact_root="$project_root/build/artifact"
allowlist="$project_root/tools/artifact/allowlist.txt"

if [ ! -d "$artifact_root" ]; then
    printf 'Build the production artifact before inspection.\n' >&2
    exit 1
fi

actual_top_level="$(find "$artifact_root" -mindepth 1 -maxdepth 1 -printf '%f\n' | sort)"
expected_top_level="$(sort "$allowlist")"

if [ "$expected_top_level" != "$actual_top_level" ]; then
    printf 'Production artifact top-level paths differ from the allowlist.\n' >&2
    diff -u <(printf '%s\n' "$expected_top_level") <(printf '%s\n' "$actual_top_level") >&2 || true
    exit 1
fi

if [ "$(find "$artifact_root/bin" -mindepth 1 -maxdepth 1 -printf '%f\n')" != 'console' ]; then
    printf 'Only bin/console may be present in the production artifact.\n' >&2
    exit 1
fi

for prohibited in \
    .env \
    .git \
    .github \
    doc \
    prompts \
    test \
    tests \
    var; do
    if find "$artifact_root" -path "*/$prohibited" -print -quit | rg --quiet .; then
        printf 'Production artifact contains prohibited path: %s\n' "$prohibited" >&2
        exit 1
    fi
done

if find "$artifact_root" -type f \
    \( -name '*.backup' -o -name '*.csv' -o -name '*.dump' -o -name '*.key' \
       -o -name '*.log' -o -name '*.p12' -o -name '*.pem' -o -name '*.pfx' \
       -o -name '*.sql' -o -name '*.sqlite' -o -name '*.sqlite3' -o -name '*.tsv' \) \
    -print -quit | rg --quiet .; then
    printf 'Production artifact contains a prohibited operational/private file type.\n' >&2
    exit 1
fi

if find "$artifact_root/public" -type l -print -quit | rg --quiet .; then
    printf 'The public document root contains a symlink.\n' >&2
    exit 1
fi

if find "$artifact_root/public" -type f -name '*.php' ! -path "$artifact_root/public/index.php" -print -quit | rg --quiet .; then
    printf 'The public document root contains an unexpected PHP entry point.\n' >&2
    exit 1
fi

for development_package in \
    deptrac \
    friendsofphp \
    phpstan \
    phpunit; do
    if [ -e "$artifact_root/vendor/$development_package" ]; then
        printf 'Development package leaked into artifact: %s\n' "$development_package" >&2
        exit 1
    fi
done

composer --working-dir="$artifact_root" validate --strict
