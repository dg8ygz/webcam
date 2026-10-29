#!/usr/bin/env bash
# Explicit key-free transport set. Test the exact package; never reuse mutable
# old artifact names, and preserve executable mode for the gphoto download hook.
set -euo pipefail
umask 077
PROJECT="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")/.." && pwd -P)"
STAGE="$(mktemp -d /tmp/camera-eos-package.XXXXXXXX)"
trap 'rm -rf -- "$STAGE"' EXIT
files=(EOS-LEGACY.md LEGACY-MIGRATION.md config/server.example.conf
  scripts/lib/camera-rpi-common.sh scripts/lib/camera-rpi-eos-legacy
  scripts/admin/camera-rpi-admin admin/public/index.php
  ops/update-eos-legacy.sh ops/test-legacy-capture.sh ops/lib/legacy-install.sh
  tests/eos-legacy tests/eos-update tests/fixtures/fake-eos-gphoto)
for file in "${files[@]}"; do
  [[ -f "$PROJECT/$file" && ! -L "$PROJECT/$file" ]]
  mode=0644
  case "$file" in scripts/lib/camera-rpi-eos-legacy|tests/fixtures/fake-eos-gphoto) mode=0755 ;; esac
  install -D -m "$mode" "$PROJECT/$file" "$STAGE/package/$file"
done
bash "$STAGE/package/tests/eos-legacy"
bash "$STAGE/package/tests/eos-update"
(cd "$STAGE/package" && find . -type f -print0 | sort -z | xargs -0 sha256sum > "$STAGE/SHA256SUMS")
install -m 0644 "$STAGE/SHA256SUMS" "$STAGE/package/SHA256SUMS"
tar -czf "$STAGE/update.tar.gz" -C "$STAGE/package" .
digest="$(sha256sum "$STAGE/update.tar.gz" | awk '{print $1}')"
output="/tmp/camera-rpi-eos-legacy-${digest:0:12}.tar.gz"
[[ ! -e "$output" && ! -L "$output" ]]
install -m 0644 "$STAGE/update.tar.gz" "$output"
printf 'Paket: %s\nSHA256: %s\n' "$output" "$digest"
