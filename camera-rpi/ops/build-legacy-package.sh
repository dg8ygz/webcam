#!/usr/bin/env bash
# Build a minimal source-only transport artifact. No keys, runtime directories,
# binaries for the build host, .git or ignored deployment files are collected.
set -euo pipefail
umask 077
PROJECT="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")/.." && pwd -P)"
[[ $# == 0 ]] || { printf 'Aufruf: bash ops/build-legacy-package.sh\n' >&2; exit 2; }
source "$PROJECT/ops/lib/legacy-install.sh"
li_load_manifest "$PROJECT/ops/legacy-install-files.tsv"
BUILD_STAGE="$(mktemp -d /tmp/camera-rpi-legacy-build.XXXXXXXX)"
trap 'rm -rf -- "$BUILD_STAGE"' EXIT
files=("${LI_SOURCES[@]}" install.sh scripts/native/camera-rpi-pwm.c
  ops/install-legacy-camera.sh ops/lib/legacy-install.sh ops/legacy-install-files.tsv
  ops/prepare-legacy-target.sh tests/legacy-target-config tests/legacy-install)
for file in "${files[@]}"; do
  [[ -f "$PROJECT/$file" && ! -L "$PROJECT/$file" ]] || exit 1
  install -D -m 0644 "$PROJECT/$file" "$BUILD_STAGE/package/$file"
done
(cd "$BUILD_STAGE/package" && find . -type f -print0 | sort -z | xargs -0 sha256sum > "$BUILD_STAGE/SHA256SUMS")
install -m 0644 "$BUILD_STAGE/SHA256SUMS" "$BUILD_STAGE/package/SHA256SUMS"
# Test the exact packaged manifest/payload, not an unrelated working-tree copy.
bash "$BUILD_STAGE/package/tests/legacy-install"
bash "$BUILD_STAGE/package/tests/legacy-target-config"
tar -czf "$BUILD_STAGE/update.tar.gz" -C "$BUILD_STAGE/package" .
PACKAGE_HASH="$(sha256sum "$BUILD_STAGE/update.tar.gz" | awk '{print $1}')"
OUTPUT="/tmp/camera-rpi-legacy-${PACKAGE_HASH:0:12}.tar.gz"
[[ ! -e "$OUTPUT" && ! -L "$OUTPUT" ]] || { printf 'Ausgabedatei existiert bereits: %s\n' "$OUTPUT" >&2; exit 1; }
install -m 0644 "$BUILD_STAGE/update.tar.gz" "$OUTPUT"
printf 'Paket: %s\nSHA256: %s\n' "$OUTPUT" "$PACKAGE_HASH"
