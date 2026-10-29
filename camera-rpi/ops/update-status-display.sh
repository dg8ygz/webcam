#!/usr/bin/env bash
# UI-only follow-up to the installed 86c22cb9bf54 climate graph package.
# Two fixed public files, verified baseline, backup and atomic replacement.
# Never change climate mode, restart a service, touch GPIO or read the DHT.
set -euo pipefail
umask 077
[[ $EUID == 0 && $# == 0 ]] || { echo 'Als root ohne Argumente ausfuehren.' >&2; exit 1; }
SRC="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")/.." && pwd -P)"
cd "$SRC"
sha256sum -c SHA256SUMS
php -l status/public/index.php
[[ ",$(findmnt -n -o OPTIONS -T /)," == *,rw,* ]] || { echo 'Root-Dateisystem ist nicht schreibbar.' >&2; exit 1; }
sources=(status/public/index.php status/public/style.css)
targets=(/usr/local/share/camera-rpi-status/public/index.php /usr/local/share/camera-rpi-status/public/style.css)
before=(7155771e8e8241637ea7e77f72e0d97125bac85ca0571df54504afb606b81865 c248e9a52c5867fed01832bfa78bfdc575c80a8e0b01ba64e1942be8034cfffb)
after=()
# Serialize against the existing admin/full-update write path; no mode change.
exec 9>/run/lock/camera-rpi.operation.lock
flock -w 30 9
for i in "${!targets[@]}"; do
  [[ -f "${targets[$i]}" && ! -L "${targets[$i]}" ]]
  read -r current _ < <(sha256sum "${targets[$i]}")
  read -r next _ < <(sha256sum "${sources[$i]}")
  after+=("$next")
  [[ $current == "${before[$i]}" || $current == "$next" ]] || {
    echo "Abweichender installierter Stand: ${targets[$i]}; nichts geaendert." >&2; exit 1;
  }
done
if cmp -s "${sources[0]}" "${targets[0]}" && cmp -s "${sources[1]}" "${targets[1]}"; then
  echo 'OK: Anzeige-Korrektur ist bereits installiert.'; exit 0
fi
SAVE="$(mktemp -d /root/camera-rpi-status-before.XXXXXXXX)"
for i in "${!targets[@]}"; do cp -a -- "${targets[$i]}" "$SAVE/$i"; done
printf '%s\n' "${targets[@]}" > "$SAVE/targets.txt"
echo "Sicherung: $SAVE"
pending=''

replace_file() {
  # Same-filesystem rename publishes complete PHP/CSS, never a partial copy.
  pending="$(mktemp "${2}.display.XXXXXXXX")" || return 1
  install -o root -g root -m 0644 -- "$1" "$pending" || return 1
  mv -f -- "$pending" "$2" || return 1
  pending=''
}

rollback() {
  local rc=$? restored=1
  trap - EXIT
  if [[ -n "$pending" ]]; then rm -f -- "$pending"; fi
  if (( rc != 0 )); then
    for i in "${!targets[@]}"; do replace_file "$SAVE/$i" "${targets[$i]}" || restored=0; done
    if (( restored )); then
      echo "Update fehlgeschlagen; beide Anzeigedateien aus $SAVE wiederhergestellt." >&2
    else
      echo "Update fehlgeschlagen; Rueckweg unvollstaendig, Sicherung $SAVE pruefen." >&2
    fi
  fi
  exit "$rc"
}
trap rollback EXIT
trap 'exit 130' INT
trap 'exit 143' TERM
for i in "${!targets[@]}"; do replace_file "${sources[$i]}" "${targets[$i]}"; done
for i in "${!targets[@]}"; do printf '%s  %s\n' "${after[$i]}" "${targets[$i]}"; done | sha256sum -c -
trap - EXIT INT TERM
echo 'OK: Drei Kacheln entfernt; Bildwerte, beide Grafiken und Tabelle bleiben.'
echo 'Legendentext/Markierung/Linie: Taupunkt blau, Abstand gruen, PWM gelb, Heizung rot.'
echo 'CSS-Version aktualisiert. Keine Dienstneustarts; Automatik, DHT-Test und Verlauf unveraendert.'
