#!/usr/bin/env bash
# Install only the sensor helper; keep camera workers and configuration intact.
set -euo pipefail
umask 077
[[ "$EUID" == 0 ]] || { printf 'Als root ausfuehren.\n' >&2; exit 1; }
SOURCE_DIR="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")/.." && pwd -P)"
TARGET=/usr/local/libexec/camera-rpi-sensor
UNIT=camera-rpi-sensor.service
test -f "$TARGET"
test ! -L "$TARGET"
test -f "$SOURCE_DIR/SHA256SUMS"
(cd "$SOURCE_DIR" && sha256sum -c SHA256SUMS)
bash -n "$SOURCE_DIR/scripts/camera-rpi-sensor"
bash "$SOURCE_DIR/scripts/camera-rpi-sensor" --check-config /etc/camera-rpi/device.conf
BACKUP="$(mktemp -d /root/camera-rpi-sensor-before.XXXXXXXX)"
cp -a -- "$TARGET" "$BACKUP/camera-rpi-sensor"
printf 'Sicherung: %s\n' "$BACKUP"
WAS_ACTIVE=0
if systemctl is-active --quiet "$UNIT"; then WAS_ACTIVE=1; fi
rollback() {
  # Restore the single helper and prior active state; do not restart camera workers.
  local result=$?
  trap - EXIT
  if (( result != 0 )); then
    printf 'Update fehlgeschlagen; Sensor-Script wird wiederhergestellt.\n' >&2
    cp -a -- "$BACKUP/camera-rpi-sensor" "$TARGET"
    if (( WAS_ACTIVE == 1 )); then systemctl restart "$UNIT"; fi
  fi
  exit "$result"
}
trap rollback EXIT
install -o root -g root -m 0755 "$SOURCE_DIR/scripts/camera-rpi-sensor" "$TARGET"
cmp -- "$SOURCE_DIR/scripts/camera-rpi-sensor" "$TARGET"
if (( WAS_ACTIVE == 1 )); then
  systemctl restart "$UNIT"
  sleep 6
  systemctl is-active --quiet "$UNIT"
fi
trap - EXIT
printf 'OK: Sensor-Update installiert. Langzeitkontrolle der Messungen steht noch aus.\n'
systemctl show "$UNIT" -p ActiveState -p SubState -p NRestarts
