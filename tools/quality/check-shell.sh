#!/usr/bin/env bash

set -euo pipefail

project_root="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
cd "$project_root"

while IFS= read -r -d '' script; do
    bash -n "$script"
done < <(
    find bin tools \
        -type f \
        \( -name '*.sh' -o -path 'bin/bootstrap' \) \
        -print0
)
