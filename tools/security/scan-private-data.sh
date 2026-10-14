#!/usr/bin/env bash

set -euo pipefail

project_root="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
cd "$project_root"

# Match whole address tokens. The exact updater unit is operational syntax,
# not recipient data; exclude only that known token, including Git history.
email_pattern='(?<![A-Z0-9.!#$%&*+/=?^_`{|}~-])(?!`?emailer-update@1\.0\.2\.service(?![A-Z0-9.-]))[A-Z0-9.!#$%&*+/=?^_`{|}~-]+@(?!example\.(?:test|invalid|com)\b)[A-Z0-9][A-Z0-9.-]*\.[A-Z]{2,63}'
sensitive_path_pattern='\.(csv|tsv|log|sql|sqlite|sqlite3|dump|backup|bak|p12|pfx|pem|key)$'
finding=0

scan_file() {
    local file="$1"
    local status

    # Composer lockfiles contain public upstream package-author metadata. Secret
    # scanning still covers these files; this recipient-data check does not
    # classify upstream maintainer addresses as application private data.
    case "$file" in
        composer.lock|./composer.lock|build/artifact/composer.lock|./build/artifact/composer.lock)
            return
            ;;
    esac

    if rg --quiet --text --ignore-case --pcre2 -- "$email_pattern" "$file"; then
        printf '%s\n' "$file" >&2
        finding=1
        return
    else
        status=$?
    fi

    if [ 1 -ne "$status" ]; then
        exit "$status"
    fi
}

while IFS= read -r -d '' file; do
    scan_file "$file"

    case "$file" in
        ./var/log/*)
            # Test/framework logs are expected generated output; their content
            # is scanned above and they remain excluded from Git/artifacts.
            ;;
        *)
            if printf '%s\n' "$file" | rg --quiet --ignore-case -- "$sensitive_path_pattern"; then
                printf '%s\n' "$file" >&2
                finding=1
            fi
            ;;
    esac
done < <(
    find . \
        -type d \( \
            -path './.git' -o \
            -path './.cache' -o \
            -path './.tools' -o \
            -path './assets/vendor' -o \
            -path './build/artifact/assets/vendor' -o \
            -path './build/artifact/vendor' -o \
            -path './var/cache' -o \
            -path './vendor' \
        \) -prune -o \
        -type f -print0
)

while IFS= read -r commit; do
    if git grep -I --ignore-case --name-only --perl-regexp -- "$email_pattern" "$commit" -- . ':(exclude,top)composer.lock'; then
        finding=1
    else
        status=$?
        if [ 1 -ne "$status" ]; then
            exit "$status"
        fi
    fi
done < <(git rev-list --all)

if git rev-list --objects --all | cut -d' ' -f2- | rg --ignore-case -- "$sensitive_path_pattern"; then
    finding=1
else
    status=$?
    if [ 1 -ne "$status" ]; then
        exit "$status"
    fi
fi

if [ 0 -ne "$finding" ]; then
    exit 1
fi
