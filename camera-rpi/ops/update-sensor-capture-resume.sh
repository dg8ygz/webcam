#!/usr/bin/env bash
# Sep 20 Bielefeld .16 trial, extended to Steinhagen .17 on Sep 21 by request.
# Replace/restart only the sensor helper;
# keep climate age limits, worker processes, DHT driver and settings untouched.
set -euo pipefail
umask 077
[[ $EUID == 0 && $# == 1 && ( $1 == --check || $1 == --apply ) ]] || {
  echo 'Als root mit --check oder --apply ausfuehren.' >&2; exit 1;
}
case "$(hostname -s)" in
  webcam-bfld) workers=(camera-rpi-worker@bielefeld-legacy.service
    camera-rpi-worker@bielefeld-prod.service camera-rpi-worker@bielefeld-devsvr.service) ;;
  webcam-sth) workers=(camera-rpi-worker@steinhagen-legacy.service
    camera-rpi-worker@steinhagen-prod.service) ;;
  *) echo 'Dieses Paket ist nur fuer .16/.17.' >&2; exit 1 ;;
esac
action=$1
SRC="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")/.." && pwd -P)"
cd "$SRC"
sha256sum -c SHA256SUMS
source ops/lib/update-inodes.sh
target=/usr/local/libexec/camera-rpi-sensor
unit=camera-rpi-sensor.service
baseline=e47db53fd6d8f639b96dafc569db5b0cac0afb4dbbbeb6a27998167d6b0f29bf
bash -n scripts/camera-rpi-sensor
bash scripts/camera-rpi-sensor --check-config /etc/camera-rpi/device.conf
# Serialize with real readings/captures; never stop a reader mid-transaction.
exec 9>/run/lock/camera-rpi.operation.lock
flock -w 120 9
[[ -f $target && ! -L $target ]]
read -r current _ < <(sha256sum "$target")
read -r next _ < <(sha256sum scripts/camera-rpi-sensor)
[[ $current == "$baseline" || $current == "$next" ]] || {
  echo 'Abweichender Sensorstand; nichts geaendert.' >&2; exit 1;
}
systemctl is-active --quiet "$unit"
systemctl is-active --quiet camera-rpi-climate.service
if [[ $action == --check ]]; then echo 'OK: Sensor-Capture-Resume Vorpruefung.'; exit 0; fi
if cmp -s scripts/camera-rpi-sensor "$target"; then echo 'Bereits installiert.'; exit 0; fi

SAVE='' pending='' rw_owned=0 publishing=0
replace_sensor() {
  pending="$(mktemp "${target}.capture-resume.XXXXXXXX")" || return 1
  install -o root -g root -m 0755 -- "$1" "$pending" || return 1
  cu_preserve_inode "$target" "$SAVE/inodes" || return 1
  mv -f -- "$pending" "$target" || return 1
  pending=''
}
finish() {
  local rc=$? restored=1
  trap - EXIT
  if [[ -n "$pending" ]]; then rm -f -- "$pending"; fi
  if (( rc != 0 && publishing )); then
    replace_sensor "$SAVE/camera-rpi-sensor" || restored=0
    systemctl restart "$unit" || restored=0
    echo "Update fehlgeschlagen; Rueckstellung=$restored, Sicherung: $SAVE" >&2
  fi
  if (( rw_owned )); then
    sync -f /usr/local/libexec || rc=1
    mount -o remount,ro / || { echo 'Root-Schreibschutz nicht restauriert!' >&2; rc=1; }
  fi
  exit "$rc"
}
trap finish EXIT
trap 'exit 130' INT
trap 'exit 143' TERM
trap 'exit 129' HUP
case ",$(findmnt -n -o OPTIONS -T /)," in
  *,ro,*) mount -o remount,rw /; rw_owned=1 ;;
  *,rw,*) ;;
  *) echo 'Root-Mountstatus unbekannt.' >&2; exit 1 ;;
esac
SAVE="$(mktemp -d /root/camera-rpi-sensor-resume-before.XXXXXXXX)"
install -d -m 0700 "$SAVE/inodes"
cp -a -- "$target" "$SAVE/camera-rpi-sensor"
cp -- SHA256SUMS "$SAVE/package-SHA256SUMS"
# Hash private settings without exposing contents; compare after restart.
find /etc/camera-rpi -type f -exec sha256sum {} + | sort > "$SAVE/config.before"
units=(camera-rpi-climate.service "${workers[@]}")
systemctl show "${units[@]}" -p Id -p MainPID -p ActiveState > "$SAVE/services.before"
sha256sum /usr/local/libexec/camera-rpi-climate.php /usr/local/libexec/climate-controller.php \
  /usr/local/libexec/camera-rpi-worker > "$SAVE/other-code.before"
echo "Sicherung: $SAVE"
publishing=1
replace_sensor scripts/camera-rpi-sensor
cmp -- scripts/camera-rpi-sensor "$target"
systemctl restart "$unit"
systemctl is-active --quiet "$unit"
find /etc/camera-rpi -type f -exec sha256sum {} + | sort > "$SAVE/config.after"
systemctl show "${units[@]}" -p Id -p MainPID -p ActiveState > "$SAVE/services.after"
cmp "$SAVE/config.before" "$SAVE/config.after"
cmp "$SAVE/services.before" "$SAVE/services.after"
sha256sum -c "$SAVE/other-code.before"
publishing=0
sha256sum "$target"
echo 'OK: Nur Sensor ersetzt/neugestartet; Konfiguration, Klima und Worker unveraendert.'
