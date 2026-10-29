#!/usr/bin/env bash
# Add read-only board identity and header checks to an enrolled Testcam.
# No config, overlay, native helper, service or GPIO operation is changed.
set -euo pipefail
umask 077
export PATH=/usr/sbin:/usr/bin:/sbin:/bin
[[ $EUID == 0 && $# == 1 && $1 == --apply ]]
SRC="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")/.." && pwd -P)"
cd "$SRC"
sha256sum -c SHA256SUMS
source "$SRC/ops/lib/update-inodes.sh"
php tests/gpio-config.php
php tests/gpio-board.php
php tests/gpio-store.php
sources=(scripts/lib/gpio-config.php scripts/camera-rpi-gpio.php admin/src/gpio-panel.php GPIO.md)
targets=(/usr/local/libexec/gpio-config.php /usr/local/libexec/camera-rpi-gpio.php /usr/local/share/camera-rpi-admin/src/gpio-panel.php /usr/local/share/doc/camera-rpi/GPIO.md)
for file in "${sources[@]:0:3}"; do php -l "$file"; done
# Refuse an unknown installed version rather than overwriting another update.
printf '%s\n' \
 'da73f18b3b99037ceb1160019d44e040b642889eefd02c1036b8c3171e47411d  /usr/local/libexec/gpio-config.php' \
 'faf3d5c9bb5a390132f54339da7a163444d8cd3a896217a6a56b0f2a9f9b9537  /usr/local/libexec/camera-rpi-gpio.php' \
 'b62caeeacba2a5598821b6d02eb474d1fb5291a8a62cc993e8734dda6eef5529  /usr/local/share/camera-rpi-admin/src/gpio-panel.php' | sha256sum -c -
# Evaluate the new code against real DT and current files without writing them.
php -r 'require "scripts/camera-rpi-gpio.php"; $b=CameraGpioBoard::detect(); CameraGpioConfig::effective(); [$p]=(new CameraGpioApp())->load(); CameraGpioConfig::validate($p,CameraGpioBoard::allowedHeader($b)); echo $b["model"]." / ".$b["header_pins"]."-Pin-Header\n";'
SAVE='' ROOT_RW=0 CHANGED=0
finish() {
  local rc=$? i tmp
  trap - EXIT
  if (( rc != 0 && CHANGED )); then
    for i in "${!targets[@]}"; do
      tmp="$(mktemp "$(dirname "${targets[$i]}")/.gpio-model-restore.XXXXXXXX")"
      cu_preserve_inode "${targets[$i]}" "$SAVE/inodes" || { rc=1; continue; }
      cp -a "$SAVE/$i" "$tmp" && mv -f "$tmp" "${targets[$i]}" || rc=1
    done
    echo "Rueckstellung aus $SAVE; bitte Ausgabe pruefen." >&2
  fi
  if (( ROOT_RW )); then mount -o remount,ro / || rc=1; fi
  exit "$rc"
}
trap finish EXIT
trap 'exit 130' INT
trap 'exit 143' TERM
exec 9>/run/lock/camera-rpi.operation.lock
flock -w 30 9
for target in "${targets[@]}"; do
  [[ -f $target && ! -L $target ]]
  parent=$(dirname "$target")
  while [[ $parent != / ]]; do [[ ! -L $parent ]]; parent=$(dirname "$parent"); done
done
if [[ ,$(findmnt -n -o OPTIONS -T /), == *,ro,* ]]; then mount -o remount,rw /; ROOT_RW=1; fi
SAVE="$(mktemp -d /var/backups/camera-rpi-gpio-model.XXXXXXXX)"
install -d -m 0700 "$SAVE/inodes"
for i in "${!targets[@]}"; do cp -a "${targets[$i]}" "$SAVE/$i"; done
printf '%s\n' "${targets[@]}" > "$SAVE/targets"
CHANGED=1
for i in "${!targets[@]}"; do
  tmp="$(mktemp "$(dirname "${targets[$i]}")/.gpio-model-update.XXXXXXXX")"
  install -o root -g root -m 0644 "${sources[$i]}" "$tmp"
  cu_preserve_inode "${targets[$i]}" "$SAVE/inodes"
  mv -f "$tmp" "${targets[$i]}"
done
php /usr/local/libexec/camera-rpi-gpio.php get
php /usr/local/libexec/camera-rpi-gpio.php sensor
sync -f /usr/local/libexec/gpio-config.php
CHANGED=0
printf 'OK: Modell/Header-Erkennung installiert. Keine Pin-/Konfigurationsaenderung, keine Dienstneustarts.\nSicherung: %s\n' "$SAVE"
