#!/usr/bin/env bash
# Targeted .16 cutover: webcam_check respawns webcam_wait every minute.
# Comment only those exact legacy camera jobs; preserve every other cron row.
# No camera command, no reboot, no global cron-service restart.
set -euo pipefail
umask 077
[[ $EUID == 0 && "${1:-}" == --apply && $# == 1 ]]
[[ -f /etc/crontab && ! -L /etc/crontab ]]
stage="$(mktemp -d /run/camera-legacy-cron.XXXXXXXX)"
cp -a /etc/crontab "$stage/before"
# Require exactly the known single watchdog row. Zero or multiple matches need
# inspection, not a broad rewrite of the machine's unrelated cron jobs.
awk '
  /^[[:space:]]*#/ { print; next }
  /\/usr\/local\/bin\/webcam_(check|wait)([[:space:]]|$)/ {
    print "# camera-rpi cutover: " $0; changed++; next
  }
  { print }
  END { if (changed != 1) exit 1 }
' "$stage/before" > "$stage/after"
[[ "$(wc -l < "$stage/before")" == "$(wc -l < "$stage/after")" ]]
rw_owned=0
# Leave an originally writable root writable; restore only our own RO window.
finish() {
  local rc=$?
  trap - EXIT HUP INT TERM
  if ((rw_owned)); then sync; mount -o remount,ro / || rc=1; fi
  exit "$rc"
}
trap finish EXIT
trap 'exit 143' HUP INT TERM
case ",$(findmnt -n -o OPTIONS -T /)," in *,ro,*) mount -o remount,rw /; rw_owned=1 ;; *,rw,*) ;; *) exit 1 ;; esac
backup="$(mktemp -d /var/backups/camera-rpi-legacy-cron.XXXXXXXX)"
cp -a "$stage/before" "$backup/crontab.before"
# Compare immediately before publication so a concurrent operator edit wins.
cmp /etc/crontab "$stage/before"
temporary="$(mktemp /etc/.camera-crontab.XXXXXXXX)"
install -o root -g root -m 0644 "$stage/after" "$temporary"
mv -T "$temporary" /etc/crontab
sync -f /etc/crontab
printf 'OK: Alter webcam_check-Cronjob deaktiviert; andere Jobs unveraendert. Sicherung: %s\n' "$backup"
