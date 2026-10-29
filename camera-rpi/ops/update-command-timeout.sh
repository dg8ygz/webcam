#!/usr/bin/env bash
# Root on the Pi; only target test receives a 700-second command timeout.
set -euo pipefail
umask 077
[[ "$EUID" == 0 ]] || exit 1
SRC="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")/.." && pwd -P)"
(cd "$SRC" && sha256sum -c SHA256SUMS)
WORKER=/usr/local/libexec/camera-rpi-worker
COMMON=/usr/local/libexec/camera-rpi-common.sh
CONF=/etc/camera-rpi/servers/test.conf
UNIT=camera-rpi-worker@test.service
[[ ! -L "$WORKER" && ! -L "$COMMON" && ! -L "$CONF" ]]
[[ "$(sha256sum "$WORKER" | awk '{print $1}')" == 8c6c9865eabcba5018f9d7692bb515e91c188be4e7e6728c279d22c9d7c3ef57 ]]
[[ "$(sha256sum "$COMMON" | awk '{print $1}')" == 36389e5c7a5434a355880ca6f39a7608ae5f01cb3c3fc7fe460c905e10b7d866 ]]
[[ "$(grep -c '^command_timeout_seconds=' "$CONF")" == 1 ]]
[[ ",$(findmnt -n -o OPTIONS -T /)," == *,rw,* ]]
bash -n "$SRC/scripts/camera-rpi-worker" "$SRC/scripts/lib/camera-rpi-common.sh"
SAVE="$(mktemp -d /root/camera-rpi-command-before.XXXXXXXX)"
cp -a "$WORKER" "$COMMON" "$CONF" "$SAVE/"
printf 'Sicherung: %s\n' "$SAVE"
active=0
systemctl is-active --quiet "$UNIT" && active=1
rollback() {
  local rc=$?
  trap - EXIT
  if (( rc != 0 )); then
    cp -a "$SAVE/camera-rpi-worker" "$WORKER"
    cp -a "$SAVE/camera-rpi-common.sh" "$COMMON"
    cp -a "$SAVE/test.conf" "$CONF"
    (( active == 0 )) || systemctl restart "$UNIT" || true
    echo 'Update fehlgeschlagen; Ausgangsstand wiederhergestellt.' >&2
  fi
  exit "$rc"
}
trap rollback EXIT
trap 'exit 130' INT
trap 'exit 143' TERM
(( active == 0 )) || systemctl stop "$UNIT"
install -o root -g root -m 0755 "$SRC/scripts/camera-rpi-worker" "$WORKER"
install -o root -g root -m 0755 "$SRC/scripts/lib/camera-rpi-common.sh" "$COMMON"
sed -i 's/^command_timeout_seconds=.*/command_timeout_seconds=700/' "$CONF"
bash "$WORKER" --check-config "$CONF"
(( active == 0 )) || systemctl start "$UNIT"
trap - EXIT INT TERM
echo 'OK: test Command-Poll 700s; DEV-Konfiguration, Keys und Upload-Zeitlimits unveraendert.'
systemctl show "$UNIT" -p ActiveState -p SubState
