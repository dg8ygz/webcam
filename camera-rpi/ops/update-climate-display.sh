#!/usr/bin/env bash
# Narrow follow-up for the first climate installation: two verified files only.
# Retain memory hardening and remove the operator-requested public hint text.
# Never restart camera workers, Apache, the sensor or the DHT driver trial.
set -euo pipefail
umask 077
[[ $EUID == 0 && $# == 0 ]] || { echo 'Als root ohne Argumente ausfuehren.' >&2; exit 1; }
SRC="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd -P)"
PAGE=/usr/local/share/camera-rpi-status/public/index.php
UNIT=/etc/systemd/system/camera-rpi-climate.service
PAGE_OLD=b7f6cc5722c739869873c39c307c7b61a8afbba0227d7371585d9d05898c21ff
PAGE_NEW=1f35cbda14cdb1ee590759034d7377e24990352f51dcb3ad3211856105acdd80
UNIT_OLD=a4b75d632e95473f963e465c01f0e75bd8be19f9eda91455359825f06448ec92
UNIT_NEW=46403b32b6b9c5c25dd788917a759e854111be4e16ab06bac7c0bee345d7c507
printf '%s  %s\n' "$PAGE_NEW" "$SRC/index.php" "$UNIT_NEW" "$SRC/camera-rpi-climate.service" | sha256sum -c -
php -l "$SRC/index.php"
systemd-analyze verify "$SRC/camera-rpi-climate.service"
[[ ",$(findmnt -n -o OPTIONS -T /)," == *,rw,* ]] || { echo 'Root-Dateisystem ist nicht schreibbar.' >&2; exit 1; }
[[ -f "$PAGE" && ! -L "$PAGE" && -f "$UNIT" && ! -L "$UNIT" ]]
read -r page_hash _ < <(sha256sum "$PAGE")
read -r unit_hash _ < <(sha256sum "$UNIT")
[[ $page_hash == "$PAGE_OLD" || $page_hash == "$PAGE_NEW" ]] || { echo 'Infoseite weicht vom geprueften Stand ab.' >&2; exit 1; }
[[ $unit_hash == "$UNIT_OLD" || $unit_hash == "$UNIT_NEW" ]] || { echo 'Dienstdatei weicht vom geprueften Stand ab.' >&2; exit 1; }

# Share the normal write/capture lock so an admin cannot arm outputs mid-update.
exec 9>/run/lock/camera-rpi.operation.lock
flock -w 30 9
php -d pcre.jit=0 /usr/local/libexec/camera-rpi-climate.php get | php -r '$c=json_decode(stream_get_contents(STDIN),true,16,JSON_THROW_ON_ERROR); if (($c["config"]["mode"]??null)!=="off") {fwrite(STDERR,"Zuerst Klima im Admin auf Aus stellen.\n");exit(1);}'
systemctl is-active --quiet camera-rpi-climate.service
SAVE="$(mktemp -d /root/camera-rpi-climate-finish-before.XXXXXXXX)"
cp -a -- "$PAGE" "$SAVE/index.php"
cp -a -- "$UNIT" "$SAVE/camera-rpi-climate.service"
echo "Sicherung: $SAVE"

replace_file() {
  # Publish complete files atomically on their destination filesystem.
  local stage
  stage="$(mktemp "${2}.new.XXXXXXXX")"
  install -o root -g root -m 0644 -- "$1" "$stage"
  mv -f -- "$stage" "$2"
}
rollback() {
  local rc=$?
  trap - EXIT
  if (( rc != 0 )); then
    replace_file "$SAVE/index.php" "$PAGE" || true
    replace_file "$SAVE/camera-rpi-climate.service" "$UNIT" || true
    systemctl daemon-reload || true
    systemctl restart camera-rpi-climate.service || true
    echo "Update fehlgeschlagen; vorherige Dateien aus $SAVE wiederhergestellt, Dienststatus pruefen." >&2
  fi
  exit "$rc"
}
trap rollback EXIT
trap 'exit 130' INT
trap 'exit 143' TERM
replace_file "$SRC/index.php" "$PAGE"
replace_file "$SRC/camera-rpi-climate.service" "$UNIT"
systemctl daemon-reload
systemctl restart camera-rpi-climate.service
systemctl is-active --quiet camera-rpi-climate.service
printf '%s  %s\n' "$PAGE_NEW" "$PAGE" "$UNIT_NEW" "$UNIT" | sha256sum -c -
trap - EXIT INT TERM
echo 'OK: Hinweistext entfernt, PCRE-JIT explizit aus; Speicherhaertung bleibt aktiv.'
echo 'Nur Klima-Dienst neu gestartet. Kamera-Worker, Apache, Sensor und DHT-Test unveraendert.'
