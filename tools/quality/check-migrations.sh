#!/usr/bin/env bash

set -euo pipefail

project_root="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
cd "$project_root"

# Preserve the historical transactional baseline gate. MariaDB's one reviewed
# ALTER is honestly nontransactional and has its own exact inventory/DDL/ledger
# proof plus the separate root-controlled crash/inverse recovery matrix.
plan="$(php tools/quality/check-draft-schema.php plan)"
if [ "$plan" = baseline ]; then
    php bin/console doctrine:migrations:migrate 'DoctrineMigrations\Version20261014000000' \
        --env=test --no-debug --no-interaction --all-or-nothing
fi
if [ "$plan" != target ]; then
    php bin/console doctrine:migrations:execute 'DoctrineMigrations\Version20261025000000' \
        --up --env=test --no-debug --no-interaction
fi
php tools/quality/check-draft-schema.php verify
php bin/console doctrine:migrations:up-to-date --env=test --no-debug
php bin/console doctrine:schema:validate --env=test --no-debug --skip-sync
