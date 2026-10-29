#!/usr/bin/env bash
# Reproducible allowlist for the .16/.17 sensor scheduling update; no settings.
set -euo pipefail
umask 077
SRC="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")/.." && pwd -P)"
STAGE="$(mktemp -d /tmp/camera-sensor-resume-package.XXXXXXXX)"
trap 'rm -rf -- "$STAGE"' EXIT
names=(scripts/camera-rpi-sensor ops/update-sensor-capture-resume.sh
  ops/lib/update-inodes.sh tests/sensor-capture-resume tests/sensor-retry-sequence)
for name in "${names[@]}"; do
  [[ -f "$SRC/$name" && ! -L "$SRC/$name" ]]
  install -D -m 0644 -- "$SRC/$name" "$STAGE/$name"
done
(
  cd "$STAGE"
  sha256sum "${names[@]}" > SHA256SUMS
  tar --sort=name --mtime=@0 --owner=0 --group=0 --numeric-owner -czf update.tar.gz "${names[@]}" SHA256SUMS
)
read -r digest _ < <(sha256sum "$STAGE/update.tar.gz")
target="/tmp/camera-rpi-sensor-resume-${digest:0:12}.tar.gz"
if [[ -e "$target" ]]; then cmp -- "$STAGE/update.tar.gz" "$target";
else install -m 0644 -- "$STAGE/update.tar.gz" "$target"; fi
printf 'Paket: %s\nSHA256: %s\n' "$target" "$digest"
