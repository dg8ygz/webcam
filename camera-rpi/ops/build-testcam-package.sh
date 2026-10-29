#!/usr/bin/env bash
# Package only repository source. No live configuration/keys, no installation.
# The baseline-locked updater is for the inventoried V4L2 test Pi, not .16.
set -euo pipefail
umask 077
project="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd -P)"
stage="$(mktemp -d /tmp/camera-testcam-build.XXXXXXXX)"
trap 'rm -rf -- "$stage"' EXIT
mkdir "$stage/package"
cd "$project"
cp -a scripts admin status apache config "$stage/package/"
for file in VERSION README.md ARCHITEKTUR.md CAPTURE-POOL.md DEVELOPMENT.md FOCUS.md STATUSSEITE.md SOURCE-COMMENTS.md ops/UPDATE-PAKETE.md ops/update-testcam-1.0.sh ops/update-eos-legacy.sh ops/lib/legacy-install.sh; do
  install -D -m 0644 "$file" "$stage/package/$file"
done
cd "$stage/package"
bash -n ops/update-testcam-1.0.sh
php -l admin/public/index.php
php -l status/public/index.php
# Manifest lives outside the payload until complete, preventing self-inclusion.
find . -type f -print0 | sort -z | xargs -0 sha256sum > "$stage/SHA256SUMS"
install -m 0644 "$stage/SHA256SUMS" SHA256SUMS
tar -czf "$stage/update.tar.gz" .
digest="$(sha256sum "$stage/update.tar.gz" | awk '{print $1}')"
output="/tmp/camera-rpi-testcam-1.0-${digest:0:12}.tar.gz"
[[ ! -e "$output" ]] || cmp "$stage/update.tar.gz" "$output"
install -m 0644 "$stage/update.tar.gz" "$output"
printf 'PAKET=%s\nSHA256=%s\n' "$output" "$digest"
