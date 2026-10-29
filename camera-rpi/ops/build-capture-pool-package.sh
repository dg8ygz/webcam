#!/usr/bin/env bash
# Immutable content-addressed, self-contained update. Tests use fake hardware.
set -euo pipefail
umask 077
project="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd -P)"
stage="$(mktemp -d /tmp/camera-pool-build.XXXXXXXX)"
trap 'rm -rf -- "$stage"' EXIT
mkdir "$stage/package"
cd "$project"
# These trees contain source/fixtures only, not installed credentials/configs.
cp -a scripts tests config admin "$stage/package/"
for file in CAPTURE-POOL.md ops/update-capture-pool.sh ops/update-eos-legacy.sh ops/lib/legacy-install.sh; do
  install -D -m 0644 "$file" "$stage/package/$file"
done
cd "$stage/package"
bash tests/capture-pool-update
bash tests/admin-logs
bash tests/capture-pool
php -l admin/public/index.php
# Build outside the package so the manifest cannot accidentally hash itself.
find . -type f ! -name SHA256SUMS -print0 | sort -z | xargs -0 sha256sum > "$stage/SHA256SUMS"
install -m 0644 "$stage/SHA256SUMS" SHA256SUMS
tar -czf "$stage/update.tar.gz" .
digest="$(sha256sum "$stage/update.tar.gz" | awk '{print $1}')"
output="/tmp/camera-rpi-capture-pool-${digest:0:12}.tar.gz"
# Never replace an existing content-addressed name with different bytes.
[[ ! -e "$output" ]] || cmp "$stage/update.tar.gz" "$output"
install -m 0644 "$stage/update.tar.gz" "$output"
printf 'PAKET=%s\nSHA256=%s\n' "$output" "$digest"
