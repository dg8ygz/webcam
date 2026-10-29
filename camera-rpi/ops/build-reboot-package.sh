#!/usr/bin/env bash
# Allowlisted source-only bundle. No keys/configs, service operations or GPIO.
set -euo pipefail
umask 077
SRC=$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")/.." && pwd -P)
stage=$(mktemp -d /tmp/camera-reboot-package.XXXXXXXX)
trap 'rm -rf -- "$stage"' EXIT
names=(scripts/camera-rpi-reboot-data.php scripts/camera-rpi-reboot-save
  systemd/camera-rpi-reboot-save.service systemd/camera-rpi-reboot-restore.service
  systemd/reboot-worker.conf systemd/reboot-history.conf ops/update-reboot-backup.sh
  ops/lib/update-inodes.sh tests/reboot-data.php REBOOT-BACKUP.md)
for name in "${names[@]}"; do install -D -m 0644 "$SRC/$name" "$stage/$name"; done
(
  cd "$stage"
  sha256sum "${names[@]}" > SHA256SUMS
  tar --sort=name --mtime=@0 --owner=0 --group=0 --numeric-owner -czf update.tar.gz "${names[@]}" SHA256SUMS
)
read -r digest _ < <(sha256sum "$stage/update.tar.gz")
target=/tmp/camera-rpi-reboot-backup-${digest:0:12}.tar.gz
if [[ -e $target ]]; then cmp "$stage/update.tar.gz" "$target"; else install -m 0644 "$stage/update.tar.gz" "$target"; fi
printf 'Paket: %s\nSHA256: %s\n' "$target" "$digest"
