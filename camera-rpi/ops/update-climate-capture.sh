#!/usr/bin/env bash
# Narrow Sep 19 fix, for the inventoried .16/.17 climate baseline only.
# Preserve settings, sensor/driver, workers, fault latch and initial root mode.
set -euo pipefail
umask 077
[[ $EUID == 0 && $# == 1 && ( $1 == --check || $1 == --apply ) ]] || {
  echo 'Als root mit --check oder --apply ausfuehren.' >&2; exit 1;
}
action=$1
SRC="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")/.." && pwd -P)"
cd "$SRC"
sha256sum -c SHA256SUMS
source "$SRC/ops/lib/update-inodes.sh"
sources=(scripts/lib/climate-controller.php scripts/camera-rpi-climate.php)
targets=(/usr/local/libexec/climate-controller.php /usr/local/libexec/camera-rpi-climate.php)
baselines=(270db1983082ae2fc45344b629f8814cdf636f3517f800216738f7610f154722 ce4ebc66deae041bf345b3f0a5e9e3ff811d06df7b8a99afeec1e4ac21b102a5)
unit=camera-rpi-climate.service
for source in "${sources[@]}"; do php -l "$source"; done
php tests/climate-sensor-grace.php

# Wait for an ongoing camera/sensor operation; never restart during exposure.
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
systemctl is-active --quiet "$unit"
if [[ $action == --check ]]; then echo 'OK: Capture-Fix Vorpruefung.'; exit 0; fi
if cmp -s "${sources[0]}" "${targets[0]}" && cmp -s "${sources[1]}" "${targets[1]}"; then
  echo 'OK: Capture-Fix bereits installiert.'; exit 0
fi

SAVE='' pending='' rw_owned=0 publishing=0
replace_file() {
  pending="$(mktemp "${2}.capture-fix.XXXXXXXX")" || return 1
  install -o root -g root -m 0644 -- "$1" "$pending" || return 1
  cu_preserve_inode "$2" "$SAVE/inodes" || return 1
  mv -f -- "$pending" "$2" || return 1
  pending=''
}
finish() {
  local rc=$? restored=1
  trap - EXIT
  if [[ -n "$pending" ]]; then rm -f -- "$pending"; fi
  if (( rc != 0 && publishing )); then
    for i in "${!targets[@]}"; do replace_file "$SAVE/$i" "${targets[$i]}" || restored=0; done
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
SAVE="$(mktemp -d /root/camera-rpi-climate-capture-before.XXXXXXXX)"
install -d -m 0700 "$SAVE/inodes"
for i in "${!targets[@]}"; do cp -a -- "${targets[$i]}" "$SAVE/$i"; done
printf '%s\n' "${targets[@]}" > "$SAVE/targets.txt"
cp -- SHA256SUMS "$SAVE/package-SHA256SUMS"
echo "Sicherung: $SAVE"
publishing=1
for i in "${!targets[@]}"; do
  replace_file "${sources[$i]}" "${targets[$i]}"
  cmp -s "${sources[$i]}" "${targets[$i]}"
done
systemctl restart "$unit"
systemctl is-active --quiet "$unit"
publishing=0
sha256sum "${targets[@]}"
echo 'OK: Capture-Fix installiert; nur Klima neu gestartet, Fehler nicht quittiert.'
