#!/usr/bin/env bash
# Allowlisted source bundle: no live configs/keys, no deployment, no Python.
# C is compiled on the target architecture by the updater, never shipped as x86.
set -euo pipefail
umask 077
SRC="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")/.." && pwd -P)"
STAGE="$(mktemp -d /tmp/camera-climate-package.XXXXXXXX)"
cleanup() { rm -rf -- "$STAGE"; }
trap cleanup EXIT
names=(CLIMATE.md ops/update-climate.sh config/climate.example.json
  scripts/camera-rpi-climate.php scripts/lib/climate-controller.php scripts/lib/climate-gpio.php scripts/lib/gpio-config.php scripts/lib/climate-history.php
  scripts/native/camera-rpi-pwm.c scripts/lib/camera-rpi-common.sh scripts/camera-rpi-worker
  scripts/admin/camera-rpi-admin admin/public/index.php admin/public/style.css
  admin/src/climate.php admin/src/climate-panel.php status/public/index.php status/public/style.css
  scripts/camera-rpi-power.php tests/power.php
  systemd/camera-rpi-climate.service tests/climate.php tests/climate-history.php tests/climate-native tests/climate-native.php)
for name in "${names[@]}"; do
  [[ -f "$SRC/$name" && ! -L "$SRC/$name" ]] || exit 1
  install -D -m 0644 -- "$SRC/$name" "$STAGE/$name"
done
(
  cd "$STAGE"
  sha256sum "${names[@]}" > SHA256SUMS
  tar --sort=name --mtime=@0 --owner=0 --group=0 --numeric-owner -czf update.tar.gz "${names[@]}" SHA256SUMS
)
read -r digest _ < <(sha256sum "$STAGE/update.tar.gz")
target="/tmp/camera-rpi-climate-${digest:0:12}.tar.gz"
if [[ -e "$target" ]]; then cmp -- "$STAGE/update.tar.gz" "$target";
else install -m 0644 -- "$STAGE/update.tar.gz" "$target"; fi
printf 'Paket: %s\nSHA256: %s\n' "$target" "$digest"
