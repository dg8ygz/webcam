#!/usr/bin/env bash
# Reproducible, explicit payload list; never package configs, keys or RAM data.
set -euo pipefail
umask 077
SRC="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")/.." && pwd -P)"
STAGE=$(mktemp -d /tmp/camera-rpi-privacy-package.XXXXXXXX)
files=(CAPTURE-PRIVACY.md PRIVACY-BASELINES ops/update-privacy.sh ops/lib/update-inodes.sh
  scripts/camera-rpi-privacy.php scripts/lib/capture-privacy.php scripts/camera-rpi-worker
  scripts/lib/camera-rpi-common.sh scripts/lib/camera-rpi-pool.sh scripts/admin/camera-rpi-admin
  scripts/camera-rpi-video.php scripts/lib/video-stream.php scripts/lib/camera-source.php scripts/camera-rpi-video-http.php
  scripts/camera-rpi-climate.php scripts/lib/climate-controller.php scripts/lib/climate-gpio.php
  scripts/lib/climate-history.php scripts/lib/gpio-config.php
  admin/public/index.php admin/src/privacy.php admin/src/privacy-panel.php
  tests/capture-privacy.php tests/capture-privacy-worker tests/fixtures/privacy-camera.php tests/fixtures/fake-curl)
for file in "${files[@]}"; do
  install -D -m 0644 "$SRC/$file" "$STAGE/$file"
done
chmod 0755 "$STAGE/tests/fixtures/privacy-camera.php" "$STAGE/tests/fixtures/fake-curl"
(cd "$STAGE" && sha256sum "${files[@]}" > SHA256SUMS)
tar -czf "$STAGE/update.tar.gz" -C "$STAGE" "${files[@]}" SHA256SUMS
printf 'Paket: %s/update.tar.gz\n' "$STAGE"
sha256sum "$STAGE/update.tar.gz"
