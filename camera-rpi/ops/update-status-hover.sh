#!/usr/bin/env bash
# UI-only nearest-time hover update for the Version 1.0 status page.
# Two fixed public files, verified baseline, backup and atomic replacement.
# Never change climate mode, restart a service, touch GPIO or read the DHT.
set -euo pipefail
umask 077
[[ $EUID == 0 && $# == 0 ]] || { echo 'Als root ohne Argumente ausfuehren.' >&2; exit 1; }
SRC="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")/.." && pwd -P)"
cd "$SRC"
sha256sum -c SHA256SUMS
php -l status/public/index.php
sources=(status/public/index.php status/public/style.css)
targets=(/usr/local/share/camera-rpi-status/public/index.php /usr/local/share/camera-rpi-status/public/style.css)
before=(8141ebafe1739f445a3aac05be1c086d867f8321b3cd4d13fb39c29f26f71584 8d08329007c5c1cac9418914adaeb11e8799df9157c5b2de4fe215ce096d2971)
after=()
# Serialize against the existing admin/full-update write path; no mode change.
exec 9>/run/lock/camera-rpi.operation.lock
flock -w 120 9
for i in "${!targets[@]}"; do
  [[ -f "${targets[$i]}" && ! -L "${targets[$i]}" ]]
  read -r current _ < <(sha256sum "${targets[$i]}")
  read -r next _ < <(sha256sum "${sources[$i]}")
  after+=("$next")
  # .16 has the same reviewed page without the release-version header; no
  # camera-local functional changes. Accept that exact hash only for PHP.
  [[ $current == "${before[$i]}" || $current == "$next" ||
      ( $i == 0 && $current == bd8828d4de6aa6ea2fd1a9a8e47412fd57cc5b5bb626903b6001d0235976a5a4 ) ]] || {
    echo "Abweichender installierter Stand: ${targets[$i]}; nichts geaendert." >&2; exit 1;
  }
done
if cmp -s "${sources[0]}" "${targets[0]}" && cmp -s "${sources[1]}" "${targets[1]}"; then
  echo 'OK: Anzeige-Korrektur ist bereits installiert.'; exit 0
fi
SAVE='' pending='' rw_owned=0 publishing=0

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
  if (( rc != 0 && publishing )); then
    for i in "${!targets[@]}"; do replace_file "$SAVE/$i" "${targets[$i]}" || restored=0; done
    if (( restored )); then
      echo "Update fehlgeschlagen; beide Anzeigedateien aus $SAVE wiederhergestellt." >&2
    else
      echo "Update fehlgeschlagen; Rueckweg unvollstaendig, Sicherung $SAVE pruefen." >&2
    fi
  fi
  # Keep the operation lock until remount completes, including all error and
  # signal exits. An initially RW root is deliberately not switched to RO.
  if (( rw_owned )); then
    sync -f /usr/local/share/camera-rpi-status/public || rc=1
    if ! mount -o remount,ro /; then
      echo 'ACHTUNG: Root-Schreibschutz konnte nicht restauriert werden.' >&2
      rc=1
    fi
  fi
  exit "$rc"
}
trap rollback EXIT
trap 'exit 130' INT
trap 'exit 143' TERM
trap 'exit 129' HUP
# Do not toggle the filesystem during a camera operation. Baseline validation
# and publication run under its existing lock, without stopping any service.
case ",$(findmnt -n -o OPTIONS -T /)," in
  *,ro,*) mount -o remount,rw /; rw_owned=1 ;;
  *,rw,*) ;;
  *) echo 'Root-Mountstatus unbekannt.' >&2; exit 1 ;;
esac
SAVE="$(mktemp -d /root/camera-rpi-status-before.XXXXXXXX)"
for i in "${!targets[@]}"; do cp -a -- "${targets[$i]}" "$SAVE/$i"; done
printf '%s\n' "${targets[@]}" > "$SAVE/targets.txt"
echo "Sicherung: $SAVE"
publishing=1
for i in "${!targets[@]}"; do replace_file "${sources[$i]}" "${targets[$i]}"; done
for i in "${!targets[@]}"; do printf '%s  %s\n' "${after[$i]}" "${targets[$i]}"; done | sha256sum -c -
publishing=0
echo 'OK: Zeitbezogener Hover in beiden Grafiken, Touch/Pfeiltasten; Tabelle unveraendert.'
echo 'Kein Fetch/Schreiben bei Hover; unbekannte Werte und Messluecken bleiben erkennbar.'
echo 'CSS-Version aktualisiert. Keine Dienstneustarts; Automatik, DHT-Test und Verlauf unveraendert.'
