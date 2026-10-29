#!/usr/bin/env bash
# UI-only update: public webcam identifier and explicit V1.0 label. Also ship
# missing VERSION metadata on legacy installations. No consumer restarts.
set -euo pipefail
umask 077
[[ $EUID == 0 && $# == 0 ]]
SRC=$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")/.." && pwd -P)
cd "$SRC"
sha256sum -c SHA256SUMS
php -l status/public/index.php
source ops/lib/update-inodes.sh
[[ $(<VERSION) == 1.0 ]]
sources=(status/public/index.php VERSION)
targets=(/usr/local/share/camera-rpi-status/public/index.php /usr/local/share/doc/camera-rpi/VERSION)
exec 9>/run/lock/camera-rpi.operation.lock
flock -w 120 9
for path in "${targets[@]}"; do
  [[ ! -L $path ]]
  parent=$(dirname "$path")
  while [[ $parent != / ]]; do [[ -d $parent && ! -L $parent ]]; parent=$(dirname "$parent"); done
done
read -r current _ < <(sha256sum "${targets[0]}")
read -r next _ < <(sha256sum "${sources[0]}")
[[ $current == 113d6ab4e18cccadc7e0370a0a6dde1fa688d9117554fd4591e1cc17113ada10 || $current == "$next" ]] || {
  echo 'Abweichender Seitenstand; nichts geaendert.' >&2; exit 1;
}
[[ ! -e ${targets[1]} || $(<"${targets[1]}") == 1.0 ]]
if cmp -s "${sources[0]}" "${targets[0]}" && cmp -s VERSION "${targets[1]}"; then
  echo 'OK: Titel und Versionsdatei bereits aktuell.'; exit 0
fi
rw=0 publishing=0 save='' pending=''
replace() {
  pending=$(mktemp "${2}.title.XXXXXXXX") || return 1
  install -o root -g root -m 0644 "$1" "$pending" || return 1
  cu_preserve_inode "$2" "$save/inodes" || return 1
  mv -f -- "$pending" "$2" || return 1
  pending=''
}
finish() {
  local rc=$? i
  trap - EXIT
  [[ -z $pending ]] || rm -f -- "$pending"
  if (( rc != 0 && publishing )); then
    for i in "${!targets[@]}"; do
      if [[ -f $save/$i ]]; then replace "$save/$i" "${targets[$i]}" || rc=1
      elif [[ -f $save/$i.absent ]]; then
        cu_preserve_inode "${targets[$i]}" "$save/inodes" && rm -f -- "${targets[$i]}" || rc=1
      fi
    done
    echo "Update fehlgeschlagen; Rueckweg/Sicherung: $save" >&2
  fi
  if (( rw )); then sync; mount -o remount,ro / || rc=1; fi
  exit "$rc"
}
trap finish EXIT
trap 'exit 143' TERM
trap 'exit 130' INT
case ",$(findmnt -rn -o OPTIONS -T /)," in
  *,ro,*) mount -o remount,rw /; rw=1 ;;
  *,rw,*) ;;
  *) exit 1 ;;
esac
save=$(mktemp -d /var/backups/camera-rpi-status-title.XXXXXXXX)
mkdir "$save/inodes"
for i in "${!targets[@]}"; do
  if [[ -f ${targets[$i]} ]]; then cp -a "${targets[$i]}" "$save/$i"; else touch "$save/$i.absent"; fi
done
publishing=1
for i in "${!targets[@]}"; do replace "${sources[$i]}" "${targets[$i]}"; cmp -s "${sources[$i]}" "${targets[$i]}"; done
publishing=0
echo "OK: Lokaler Blick auf Webcam-Kennung, V1.0. Sicherung: $save"
echo 'Keine Dienstneustarts; DHT-Test, Kamera, GPIO und Verlaeufe unveraendert.'
