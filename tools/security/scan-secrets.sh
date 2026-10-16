#!/usr/bin/env bash

set -euo pipefail

project_root="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
cd "$project_root"

# Static analysis and framework boot checks create reproducible caches from
# reviewed source and dependencies. Remove them so the directory scan evaluates
# source, dependencies, artifacts, and generated logs rather than duplicate code.
if [ -d "$project_root/var/cache" ]; then
    find "$project_root/var/cache" -mindepth 1 -depth -delete
fi

# The ignored formatter cache stores only reproducible source-file hashes.
# Scan the original source rather than treating those digests as API credentials.
rm -f -- "$project_root/.php-cs-fixer.cache"

if ! command -v gitleaks >/dev/null 2>&1; then
    printf 'gitleaks 8.30.1 is required; use the project container or install the pinned version.\n' >&2
    exit 2
fi

if [ '8.30.1' != "$(gitleaks version)" ]; then
    printf 'The gitleaks version does not match the reviewed 8.30.1 policy.\n' >&2
    exit 2
fi

mkdir -p "$project_root/.cache"
report_dir="$(mktemp -d "$project_root/.cache/gitleaks.XXXXXX")"

cleanup() {
    find "$report_dir" -mindepth 1 -depth -delete
    rmdir "$report_dir"
}
trap cleanup EXIT

run_scan() {
    local name="$1"
    shift
    local report="$report_dir/$name.json"
    local status

    if gitleaks "$@" \
        --no-banner \
        --no-color \
        --redact \
        --report-format=json \
        --report-path="$report" \
        --log-level=error; then
        return
    else
        status=$?
    fi

    if [ 1 -ne "$status" ]; then
        printf 'gitleaks failed while scanning %s.\n' "$name" >&2
        exit "$status"
    fi

    php -r '
        $findings = json_decode((string) file_get_contents($argv[1]), true, flags: JSON_THROW_ON_ERROR);
        foreach ($findings as $finding) {
            fwrite(STDERR, sprintf(
                "%s:%d: %s\n",
                $finding["File"],
                $finding["StartLine"],
                $finding["RuleID"],
            ));
        }
    ' "$report"
    exit 1
}

run_scan history git --log-opts=--all .
run_scan current dir --max-archive-depth=1 .
