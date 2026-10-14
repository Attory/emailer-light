#!/usr/bin/env bash

set -euo pipefail

project_root="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
artifact_root="$project_root/build/artifact"
runtime_root="$project_root/build/artifact-runtime"

if [ ! -f "$project_root/vendor/autoload.php" ]; then
    printf 'Install locked dependencies before building the artifact.\n' >&2
    exit 1
fi

mkdir -p "$artifact_root" "$runtime_root"
find "$artifact_root" "$runtime_root" -mindepth 1 -depth -delete

copy_directory() {
    local source="$1"
    local target="$2"

    mkdir -p "$target"
    cp -a "$source"/. "$target"/
}

copy_directory "$project_root/assets" "$artifact_root/assets"
mkdir -p "$artifact_root/assets/controllers"
copy_directory "$project_root/docs" "$artifact_root/docs"
copy_directory "$project_root/config" "$artifact_root/config"
copy_directory "$project_root/deployment" "$artifact_root/deployment"
copy_directory "$project_root/migrations" "$artifact_root/migrations"
copy_directory "$project_root/release" "$artifact_root/release"
copy_directory "$project_root/public" "$artifact_root/public"
copy_directory "$project_root/src" "$artifact_root/src"
copy_directory "$project_root/templates" "$artifact_root/templates"
copy_directory "$project_root/vendor" "$artifact_root/vendor"

mkdir -p "$artifact_root/bin"
cp "$project_root/bin/console" "$artifact_root/bin/console"
chmod 0755 "$artifact_root/bin/console"
cp \
    "$project_root/LICENSE" \
    "$project_root/README.md" \
    "$project_root/THIRD_PARTY_NOTICES.md" \
    "$project_root/composer.json" \
    "$project_root/composer.lock" \
    "$project_root/importmap.php" \
    "$project_root/symfony.lock" \
    "$artifact_root/"

composer --working-dir="$artifact_root" install \
    --no-dev \
    --classmap-authoritative \
    --no-interaction \
    --no-progress \
    --no-scripts

# Removing the development-only Flex plugin also removes its recipe ledger.
# Restore the reviewed ledger as immutable build provenance after that operation.
cp "$project_root/symfony.lock" "$artifact_root/symfony.lock"

while IFS= read -r -d '' directory; do
    find "$directory" -mindepth 1 -depth -delete
    rmdir "$directory"
done < <(
    find "$artifact_root/vendor" -type d \
        \( -name '.github' -o -name 'doc' -o -name 'docs' -o -name 'test' -o -name 'tests' \) \
        -prune -print0
)

run_artifact_console() {
    APP_ENV=prod \
    APP_DEBUG=0 \
    APP_SECRET=phase1-artifact-only \
    LOCK_DSN=flock \
    MAILER_DSN='smtp://mailpit:1025' \
    MESSENGER_TRANSPORT_DSN='in-memory://' \
    DATABASE_URL='mysql://emailer:local-only@database:3306/emailer_test?serverVersion=10.11.19-MariaDB&charset=utf8mb4' \
    DEFAULT_URI='https://emailer.example.test' \
    EMAILER_PRIVATE_DIR="$runtime_root" \
    php "$artifact_root/bin/console" "$@"
}

run_artifact_console importmap:install --no-debug --env=prod --no-interaction
run_artifact_console asset-map:compile --no-debug --env=prod

composer --working-dir="$artifact_root" check-platform-reqs --no-dev
