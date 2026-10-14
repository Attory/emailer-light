#!/usr/bin/env bash

set -euo pipefail

project_root="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
cd "$project_root"

php bin/console doctrine:migrations:migrate \
    --env=test \
    --no-debug \
    --no-interaction \
    --all-or-nothing
php bin/console doctrine:migrations:up-to-date --env=test --no-debug
php bin/console doctrine:schema:validate --env=test --no-debug --skip-sync
