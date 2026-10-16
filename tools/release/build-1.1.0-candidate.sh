#!/usr/bin/env bash
set -euo pipefail
if [ "$#" -ne 4 ]; then
    printf 'Usage: %s SIGNING_PRIVATE_KEY PINNED_PUBLIC_KEY OUTPUT_DIRECTORY PREDECESSOR_DIRECTORY\n' "$0" >&2
    exit 2
fi
project_root="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
signing_key="$(realpath "$1")"
public_key="$(realpath "$2")"
output="$(realpath "$3")"
predecessors="$(realpath "$4")"
case "$signing_key" in "$project_root"|"$project_root"/*) printf 'Keep the existing signing key outside the repository.\n' >&2; exit 2 ;; esac
test -z "$(git -C "$project_root" status --porcelain --untracked-files=normal)"
source_commit="$(git -C "$project_root" rev-parse HEAD)"
source_epoch="$(git -C "$project_root" show -s --format=%ct HEAD)"
work="$(mktemp -d)"
trap 'rm -rf -- "$work"' EXIT
mkdir "$work/first" "$work/second"
candidate="$output/emailer-light-1.1.0-candidate"
test ! -e "$candidate"
bash "$project_root/tools/artifact/build.sh"
for target in "$work/first" "$work/second"; do
    php "$project_root/tools/release/build-package.php" \
        --artifact="$project_root/build/artifact" --output="$target" --version=1.1.0 \
        --source="$source_commit" --minimum-source=1.0.0 --supported-sources=1.0.0 \
        --minimum-schema=20261014000000 --schema=20261014000000 --rollback-schema-compatible=true \
        --signing-key="$signing_key" --source-date-epoch="$source_epoch" >/dev/null
done
archive=emailer-light-1.1.0.zip
for suffix in '' .manifest.json .sha256 .spdx.json .sig; do
    cmp "$work/first/$archive$suffix" "$work/second/$archive$suffix"
done
php "$project_root/tools/release/verify-package.php" --archive="$work/first/$archive" \
    --public-key="$public_key" --current-version=1.0.0 --expected-version=1.1.0 --expected-source="$source_commit" >/dev/null
bundle_options=()
if [ "${EMAILER_INSTALLER_TEST_ONLY:-0}" = 1 ]; then bundle_options+=(--test-only); fi
for target in "$work/first" "$work/second"; do
    php "$project_root/installer/build-bundle.php" --candidate="$target" --source="$source_commit" --version=1.1.0 \
        --public-key="$public_key" --signing-key="$signing_key" --predecessors="$predecessors" \
        --output="$target/emailer-light-1.1.0-setup.run" "${bundle_options[@]}"
done
cmp "$work/first/emailer-light-1.1.0-setup.run" "$work/second/emailer-light-1.1.0-setup.run"
git -C "$project_root" archive --format=zip --prefix=emailer-light-1.1.0-source/ --output="$work/first/emailer-light-1.1.0-source.zip" "$source_commit"
printf '{"product":"emailer-light","version":"1.1.0","source_commit":"%s","source_epoch":%s,"test_only":%s}\n' \
    "$source_commit" "$source_epoch" "$(if [ "${EMAILER_INSTALLER_TEST_ONLY:-0}" = 1 ]; then printf true; else printf false; fi)" >"$work/first/BUILD-IDENTITY.json"
(cd "$work/first" && sha256sum -- * >"$work/SHA256SUMS")
cp "$work/SHA256SUMS" "$work/first/SHA256SUMS"
php "$project_root/tools/release/sign-checksums.php" "$signing_key" "$work/first/SHA256SUMS" "$work/first/SHA256SUMS.sig"
mkdir "$candidate"
cp "$work/first/"* "$candidate/"
printf '%s\n' "$candidate"
