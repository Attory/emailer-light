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
documentation="${EMAILER_RELEASE_DOCUMENTATION_DIR:?Render the reviewed guides outside the repository and set EMAILER_RELEASE_DOCUMENTATION_DIR.}"
source_epoch="$(git -C "$project_root" show -s --format=%ct HEAD)"
work="$(mktemp -d)"
trap 'rm -rf -- "$work"' EXIT
mkdir "$work/first" "$work/second"
candidate="$output/emailer-light-1.2.6-candidate"
test ! -e "$candidate"
bash "$project_root/tools/artifact/build.sh"
for target in "$work/first" "$work/second"; do
    php "$project_root/tools/release/build-package.php" \
        --artifact="$project_root/build/artifact" --output="$target" --version=1.2.6 \
        --source="$source_commit" --minimum-source=1.0.0 --supported-sources=1.0.0,1.1.0,1.2.0,1.2.1,1.2.2,1.2.3,1.2.4,1.2.5 \
        --minimum-schema=20261014000000 --schema=20261025000000 --rollback-schema-compatible=false \
        --signing-key="$signing_key" --source-date-epoch="$source_epoch" >/dev/null
done
archive=emailer-light-1.2.6.zip
for suffix in '' .manifest.json .sha256 .spdx.json .sig; do
    cmp "$work/first/$archive$suffix" "$work/second/$archive$suffix"
done
php "$project_root/tools/release/verify-package.php" --archive="$work/first/$archive" \
    --public-key="$public_key" --current-version=1.0.0 --expected-version=1.2.6 --expected-source="$source_commit" >/dev/null
bundle_options=()
if [ "${EMAILER_INSTALLER_TEST_ONLY:-0}" = 1 ]; then bundle_options+=(--test-only); fi
for target in "$work/first" "$work/second"; do
    php "$project_root/installer/build-bundle.php" --candidate="$target" --source="$source_commit" --version=1.2.6 \
        --public-key="$public_key" --signing-key="$signing_key" --predecessors="$predecessors" \
        --output="$target/emailer-light-1.2.6-setup.run" "${bundle_options[@]}"
done
cmp "$work/first/emailer-light-1.2.6-setup.run" "$work/second/emailer-light-1.2.6-setup.run"
git -C "$project_root" archive --format=zip --prefix=emailer-light-1.2.6-source/ --output="$work/first/emailer-light-1.2.6-source.zip" "$source_commit"
# Render outside the PHP runtime image with the pinned maintainer PDF toolchain.
# The source commit, complete input roster and every output digest are checked.
php "$project_root/tools/docs/verify-built-guides.php" "$documentation" "$source_commit"
cp "$documentation/"*.html "$documentation/"*.pdf \
    "$documentation/DOCS-MANIFEST.json" "$documentation/FONT-LICENSE.txt" "$work/first/"
php -r '$z = new ZipArchive(); if (true !== $z->open($argv[1], ZipArchive::CREATE | ZipArchive::EXCL)) { exit(1); } foreach (glob($argv[2]."/*") as $p) { if (is_file($p)) { $z->addFile($p, basename($p)); $z->setMtimeName(basename($p), (int) $argv[3]); } } if (!$z->close()) { exit(1); }' \
    "$work/first/emailer-light-1.2.6-documentation.zip" "$documentation" "$source_epoch"
printf '{"product":"emailer-light","version":"1.2.6","source_commit":"%s","source_epoch":%s,"test_only":%s}\n' \
    "$source_commit" "$source_epoch" "$(if [ "${EMAILER_INSTALLER_TEST_ONLY:-0}" = 1 ]; then printf true; else printf false; fi)" >"$work/first/BUILD-IDENTITY.json"
(cd "$work/first" && sha256sum -- * >"$work/SHA256SUMS")
cp "$work/SHA256SUMS" "$work/first/SHA256SUMS"
php "$project_root/tools/release/sign-checksums.php" "$signing_key" "$work/first/SHA256SUMS" "$work/first/SHA256SUMS.sig"
mkdir "$candidate"
cp "$work/first/"* "$candidate/"
printf '%s\n' "$candidate"
