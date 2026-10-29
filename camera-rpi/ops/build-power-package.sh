#!/usr/bin/env bash
set -euo pipefail
umask 077
SRC="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")/.." && pwd -P)"
stage=$(mktemp -d /tmp/camera-power-package.XXXXXXXX)
trap 'rm -rf -- "$stage"' EXIT
files=(ops/update-camera-power.sh ops/power-install-files.tsv ops/lib/update-inodes.sh
  scripts/native/camera-rpi-pwm.c scripts/camera-rpi-climate.php scripts/lib/climate-controller.php
  scripts/lib/climate-gpio.php scripts/lib/climate-history.php scripts/lib/gpio-config.php
  tests/climate-native tests/climate-native.php tests/power.php)
while IFS='|' read -r source rest; do [[ -z $source || $source == \#* ]] || files+=("$source"); done < "$SRC/ops/power-install-files.tsv"
for file in "${files[@]}"; do install -D -m 0644 "$SRC/$file" "$stage/$file"; done
(cd "$stage"; sha256sum "${files[@]}" > SHA256SUMS; tar -czf /tmp/camera-rpi-power-update.tar.gz "${files[@]}" SHA256SUMS)
sha256sum /tmp/camera-rpi-power-update.tar.gz
