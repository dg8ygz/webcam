#!/usr/bin/env bash
# Narrow controller update: hold normal PWM through brief DHT read failures.
# Restart ONLY climate under the existing camera-operation lock. Never read
# IIO, change the DHT module/test, touch settings or restart camera workers.
set -euo pipefail
umask 077
[[ $EUID == 0 && $# == 1 && $1 == --apply ]] || {
  echo 'Als root mit --apply ausfuehren.' >&2; exit 1;
}
SRC="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")/.." && pwd -P)"
cd "$SRC"
sha256sum -c SHA256SUMS
source "$SRC/ops/lib/update-inodes.sh"
php -l scripts/lib/climate-controller.php
php tests/climate-sensor-grace.php
sources=(scripts/lib/climate-controller.php CLIMATE.md)
targets=(/usr/local/libexec/climate-controller.php /usr/local/share/doc/camera-rpi/CLIMATE.md)
baseline=a5e5485d63f6bb7a8cae7e82d7e5fb861ea0d36991f7e771d910099258cfd011
unit=camera-rpi-climate.service
# A capture holding this lock must finish before the regulator is restarted.
exec 9>/run/lock/camera-rpi.operation.lock
flock -w 120 9
for target in "${targets[@]}"; do [[ -f "$target" && ! -L "$target" ]]; done
read -r current _ < <(sha256sum "${targets[0]}")
read -r next _ < <(sha256sum "${sources[0]}")
[[ $current == "$baseline" || $current == "$next" ]] || {
  echo 'Abweichender Reglerstand; nichts geaendert.' >&2; exit 1;
}
systemctl is-active --quiet "$unit"
if cmp -s "${sources[0]}" "${targets[0]}" && cmp -s "${sources[1]}" "${targets[1]}"; then
  echo 'OK: Sensor-Haltefrist bereits installiert.'; exit 0
fi
SAVE='' pending='' rw_owned=0 publishing=0

# Publish complete files via same-filesystem rename; the running PHP process
# keeps its old code until the single explicit restart after both replacements.
replace_file() {
  pending="$(mktemp "${2}.grace.XXXXXXXX")" || return 1
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
  # Preserve initial mount policy, including rollback; an initially RW root
  # deliberately stays RW. Keep the operation lock until this is complete.
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
SAVE="$(mktemp -d /root/camera-rpi-climate-grace-before.XXXXXXXX)"
install -d -m 0700 "$SAVE/inodes"
for i in "${!targets[@]}"; do cp -a -- "${targets[$i]}" "$SAVE/$i"; done
printf '%s\n' "${targets[@]}" > "$SAVE/targets.txt"
echo "Sicherung: $SAVE"
publishing=1
for i in "${!targets[@]}"; do
  replace_file "${sources[$i]}" "${targets[$i]}"
  cmp -s "${sources[$i]}" "${targets[$i]}"
done
systemctl restart "$unit"
systemctl is-active --quiet "$unit"
publishing=0
echo 'OK: Kurze Sensor-Aussetzer halten normale PWM; Heizung sofort aus.'
echo 'Nur Klima-Dienst neu gestartet; Puffer baut sich neu auf. Sensor/DHT-Test/Worker unveraendert.'
