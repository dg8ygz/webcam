#!/usr/bin/env bash
# Narrow .17 update, extended to .16 on explicit request Sep 27. Each host's
# package is patched from its inventoried live baseline (pre-privacy), not a
# blanket replacement with the newer repository worker/admin implementation.
set -euo pipefail
umask 077
[[ $EUID == 0 && $# == 1 && ( $1 == --check || $1 == --apply ) ]]
action=$1
SRC="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")/.." && pwd -P)"
cd "$SRC"
sha256sum -c SHA256SUMS
source ops/lib/update-inodes.sh
sources=(scripts/lib/camera-rpi-pool.sh scripts/lib/climate-controller.php scripts/camera-rpi-climate.php admin/public/index.php admin/src/climate-panel.php status/public/index.php)
targets=(/usr/local/libexec/camera-rpi-pool.sh /usr/local/libexec/climate-controller.php /usr/local/libexec/camera-rpi-climate.php /usr/local/share/camera-rpi-admin/public/index.php /usr/local/share/camera-rpi-admin/src/climate-panel.php /usr/local/share/camera-rpi-status/public/index.php)
baselines=(99461b417742544f9affdd296ba8ec57c9687470afb84fe40504cd146b98ca06 6f56c7e698c5f5d23f8dc7b395da3e3d43c4dea34bb17fef4918b6529bd26cb2 940728886d06516d58a7dcedae501e9156e224858fceb0559a65f65ddf19afbe 58b5e33a2668f62389a3ae004a44c16de56b33896b549d355643030dcbb9c075 c6e30f2f1fa58099ffcdf6628c820c22b76a64a7a689d2a4c3a0b46185253d25 24ca6398d7a57a806394fb6b23b9a12e6fe352df681b8b4643c47955c1336ba7)
workers=(camera-rpi-worker@steinhagen-prod.service camera-rpi-worker@steinhagen-legacy.service)
worker_hash=ed4414f9d9dd82b95f677dbb77a3b39d50eafa00528146c1bc6f604f867814cb
case $(hostname) in
  webcam-sth.webcam.example.invalid) host=.17 ;;
  webcam-bfld.webcam.example.invalid)
    host=.16
    workers=(camera-rpi-worker@bielefeld-prod.service camera-rpi-worker@bielefeld-legacy.service camera-rpi-worker@bielefeld-devsvr.service)
    worker_hash=afc6c518c2442b2a88ce4926821f591c0125237e93faa4f910b594842cd7e634
    baselines[0]=bb9c7265b28c3ea97a1115941db58dec163c4179075c20a8aed9a651e8d7e601
    baselines[3]=86bd8642e2c15cfe25edb61ab7f903e628d9218bd1db5e3ccf6126bcd67028ee
    ;;
  *) echo 'Nur fuer inventarisierte Pis .16/.17.' >&2; exit 1 ;;
esac
# Packages have host-specific admin/pool baselines; reject cross-installation.
[[ $(cat HOST) == "$host" ]] || { echo 'Paket fuer anderen Pi.' >&2; exit 1; }
climate=camera-rpi-climate.service
bash -n "${sources[0]}"
for source in "${sources[@]:1}"; do php -l "$source"; done
php tests/climate-sensor-grace.php
exec 9>/run/lock/camera-rpi.operation.lock
flock -w 120 9
for i in "${!targets[@]}"; do
  [[ -f ${targets[$i]} && ! -L ${targets[$i]} ]]
  read -r current _ < <(sha256sum "${targets[$i]}")
  read -r next _ < <(sha256sum "${sources[$i]}")
  [[ $current == "${baselines[$i]}" || $current == "$next" ]] || {
    echo "Abweichender Ausgangsstand: ${targets[$i]}; nichts geaendert." >&2; exit 1;
  }
done
printf '%s\n' \
 "$worker_hash  /usr/local/libexec/camera-rpi-worker" \
 '0e2ea12d19bfc2f9610e9999017151aa2c4a46f790ddb6c993e556051805bb88  /usr/local/libexec/camera-rpi-common.sh' | sha256sum -c -
for unit in "${workers[@]}" "$climate" camera-rpi-sensor.service camera-rpi-camera-power.service; do systemctl is-active --quiet "$unit"; done
if [[ $action == --check ]]; then echo "OK: $host Klima-/Retry-Vorpruefung."; exit 0; fi

SAVE='' pending='' rw_owned=0 publishing=0 workers_stopped=0
replace_file() {
  local mode
  mode=$(stat -c %a -- "$2") || return 1
  pending=$(mktemp "${2}.retry-fix.XXXXXXXX") || return 1
  install -o root -g root -m "$mode" -- "$1" "$pending" || return 1
  cu_preserve_inode "$2" "$SAVE/inodes" || return 1
  mv -f -- "$pending" "$2" || return 1
  pending=''
}
finish() {
  local rc=$? restored=1
  trap - EXIT
  if [[ -n $pending ]]; then rm -f -- "$pending"; fi
  if (( rc != 0 && publishing )); then
    for i in "${!targets[@]}"; do replace_file "$SAVE/$i" "${targets[$i]}" || restored=0; done
    systemctl restart "$climate" || restored=0
    echo "Update fehlgeschlagen; Rueckstellung=$restored, Sicherung: $SAVE" >&2
  fi
  if (( rw_owned )); then
    sync -f /usr/local/libexec || rc=1
    mount -o remount,ro / || { echo 'Root-Schreibschutz nicht restauriert!' >&2; rc=1; }
  fi
  # New worker shells load the new pool library; no capture while fd 9 held.
  if (( workers_stopped )); then
    systemctl start "${workers[@]}" || rc=1
  fi
  exit "$rc"
}
trap finish EXIT
trap 'exit 130' INT
trap 'exit 143' TERM
trap 'exit 129' HUP
workers_stopped=1
systemctl stop "${workers[@]}"
case ",$(findmnt -n -o OPTIONS -T /)," in
  *,ro,*) mount -o remount,rw /; rw_owned=1 ;;
  *,rw,*) ;;
  *) echo 'Root-Mountstatus unbekannt.' >&2; exit 1 ;;
esac
SAVE=$(mktemp -d /root/camera-rpi-climate-retry-before.XXXXXXXX)
install -d -m 0700 "$SAVE/inodes"
for i in "${!targets[@]}"; do cp -a -- "${targets[$i]}" "$SAVE/$i"; done
printf '%s\n' "${targets[@]}" > "$SAVE/targets.txt"
sha256sum /etc/camera-rpi/climate.json /etc/camera-rpi/servers/*.conf > "$SAVE/config-SHA256SUMS"
cp -- SHA256SUMS "$SAVE/package-SHA256SUMS"
echo "Sicherung: $SAVE"
publishing=1
for i in "${!targets[@]}"; do replace_file "${sources[$i]}" "${targets[$i]}"; cmp -s "${sources[$i]}" "${targets[$i]}"; done
systemctl restart "$climate"
systemctl is-active --quiet "$climate"
sha256sum -c "$SAVE/config-SHA256SUMS"
sha256sum "${targets[@]}"
publishing=0
echo "OK: $host Klima-/Retry-Fix installiert; Einstellungen und Auftraege erhalten."
