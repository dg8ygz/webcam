#!/usr/bin/env bash
# Extend the installed low-write backup, not the live regulator/sensor. Exact
# baseline checks and backups precede replacement; no stop/restart or reboot.
set -euo pipefail
umask 077
[[ $EUID == 0 && ${1:-} == --apply && $# == 1 ]] || { echo 'Als root: bash ops/update-climate-backup.sh --apply'; exit 2; }
SRC="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")/.." && pwd -P)"
cd "$SRC"
sha256sum -c SHA256SUMS
php -r 'if (PHP_VERSION_ID<80200 || !function_exists("fsync")) exit(1);'
php tests/climate-archive.php
php tests/dht-restore-timer.php
php -l scripts/camera-rpi-climate-archive.php
bash -n scripts/camera-rpi-sensor-backup scripts/camera-rpi-history-restore
[[ ",$(findmnt -n -o OPTIONS -T /)," == *,rw,* ]] || { echo 'Update braucht ein bewusst freigegebenes RW-Wartungsfenster.' >&2; exit 1; }
[[ $(findmnt -n -o FSTYPE -T /run) == tmpfs ]]
systemctl is-active --quiet camera-rpi-sensor-backup.timer
# The unmodified installed history validator is a prerequisite, not overwritten.
cmp scripts/lib/climate-history.php /usr/local/libexec/climate-history.php
sources=(scripts/camera-rpi-climate-archive.php scripts/camera-rpi-history-restore scripts/camera-rpi-sensor-backup
  systemd/camera-rpi-sensor-restore.service systemd/camera-rpi-tmpfiles.conf CLIMATE.md)
targets=(/usr/local/libexec/camera-rpi-climate-archive.php /usr/local/libexec/camera-rpi-history-restore /usr/local/libexec/camera-rpi-sensor-backup
  /etc/systemd/system/camera-rpi-sensor-restore.service /etc/tmpfiles.d/camera-rpi.conf /usr/local/share/doc/camera-rpi/CLIMATE.md)
before=('' '' 7914cb8fb557584987449adff64e603532d04e2f3b04e6cd8c40910f819ee779
  744ce4b4a3c0113b987cd4783d1709c3bf0bcb2faefcef4dced80b4943f78b3d 510de67ec31e6f64d53da35a729b1465f39889a750cbe604031ef66b8093ca41
  8cab9c77eccffd85f24f3e170d34fc9a0305d33f7b7dd116b8b03589dcccd4e8)
modes=(0644 0755 0755 0644 0644 0644)
exec 9>/run/lock/camera-rpi.operation.lock
flock -w 120 9
for i in "${!targets[@]}"; do
  target="${targets[$i]}"
  [[ ! -L "$target" ]] || { echo "Symlink abgelehnt: $target" >&2; exit 1; }
  if [[ -e "$target" ]]; then
    [[ -f "$target" ]]
    read -r current _ < <(sha256sum "$target")
    read -r next _ < <(sha256sum "${sources[$i]}")
    [[ $current == "${before[$i]}" || $current == "$next" ]] || { echo "Abweichender Stand: $target; nichts geaendert." >&2; exit 1; }
  else
    [[ -z "${before[$i]}" ]] || { echo "Erwartete Datei fehlt: $target" >&2; exit 1; }
  fi
done
# This installed trial predates the fixed monotonic fallback. Correct it before
# any daemon-reload; refuse a different trial rather than guessing its identity.
FIX_DHT_TIMER=0
if systemctl is-active --quiet camera-rpi-dht-longtest.service; then
  php ops/fix-dht-restore-timer.php --apply 2niRB78n
  FIX_DHT_TIMER=1
fi
SAVE="$(mktemp -d /root/camera-rpi-climate-backup-before.XXXXXXXX)"
for i in "${!targets[@]}"; do
  printf '%s\t%s\n' "$i" "${targets[$i]}" >> "$SAVE/targets.tsv"
  if [[ -f "${targets[$i]}" ]]; then cp -a -- "${targets[$i]}" "$SAVE/$i"; fi
done
echo "Sicherung: $SAVE"
pending=''
replace_file() {
  # Complete same-filesystem publication; rollback preserves original metadata.
  pending="$(mktemp "${2}.backup-new.XXXXXXXX")" || return 1
  cp -a -- "$1" "$pending" || return 1
  mv -f -- "$pending" "$2" || return 1
  pending=''
}
rollback() {
  local rc=$? restored=1
  trap - EXIT
  [[ -z "$pending" ]] || rm -f -- "$pending"
  if (( rc != 0 )); then
    for i in "${!targets[@]}"; do
      if [[ -f "$SAVE/$i" ]]; then replace_file "$SAVE/$i" "${targets[$i]}" || restored=0;
      else rm -f -- "${targets[$i]}" || restored=0; fi
    done
    systemctl daemon-reload || restored=0
    if (( restored )); then echo "Update fehlgeschlagen; Dateien aus $SAVE wiederhergestellt." >&2;
    else echo "Rueckweg unvollstaendig; Sicherung $SAVE pruefen." >&2; fi
  fi
  exit "$rc"
}
trap rollback EXIT
trap 'exit 130' INT
trap 'exit 143' TERM
for i in "${!targets[@]}"; do
  pending="$(mktemp "${targets[$i]}.backup-new.XXXXXXXX")"
  install -o root -g root -m "${modes[$i]}" "${sources[$i]}" "$pending"
  mv -f -- "$pending" "${targets[$i]}"
  pending=''
done
systemd-tmpfiles --create /etc/tmpfiles.d/camera-rpi.conf
systemd-analyze verify /etc/systemd/system/camera-rpi-sensor-restore.service
systemctl daemon-reload
if (( FIX_DHT_TIMER )); then php ops/fix-dht-restore-timer.php --verify 2niRB78n; fi
for i in "${!targets[@]}"; do cmp "${sources[$i]}" "${targets[$i]}"; done
trap - EXIT INT TERM
# Initial regulator-only snapshot under the same held write lock. Never run the
# boot restore on live writers, and never rewrite today's existing sensor file.
if ! /usr/bin/php -d opcache.enable_cli=0 -d opcache.file_cache= /usr/local/libexec/camera-rpi-climate-archive.php backup; then
  echo 'Backuperweiterung installiert, erste Regler-Sicherung fehlgeschlagen. Journal/Ausgabe pruefen; kein Code-Rollback.' >&2
  exit 1
fi
echo 'OK: Reglerverlauf im bestehenden Tagesbackup um 00:05 Europe/Berlin; 30 Tagesdateien.'
echo 'Boot-Restore vorbereitet. Keine aktuellen Stellwerte restauriert, keine Dienste neu gestartet.'
echo 'Automatik, Sensor, DHT-Langzeittest, Keys und laufender RAM-Verlauf unveraendert.'
systemctl list-timers camera-rpi-sensor-backup.timer --no-pager
