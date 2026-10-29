#!/usr/bin/env bash
# One explicitly requested exposure on webcam-sth using the INSTALLED helper.
# Run detached from SSH so disconnects cannot interrupt the pause/restore.
# No upload, focus command, power switch, sensor or climate service changes.
set -euo pipefail
umask 077
export PATH=/usr/sbin:/usr/bin:/sbin:/bin
ST_PROJECT="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")/.." && pwd -P)"
ST_MODE="${1:---check}"
[[ $# == 1 && ( "$ST_MODE" == --check || "$ST_MODE" == --start || "$ST_MODE" == --capture ) ]]
[[ $EUID == 0 && $(hostname) == webcam-sth ]]
(cd "$ST_PROJECT" && sha256sum -c SHA256SUMS)
source "$ST_PROJECT/ops/test-legacy-capture.sh"
source "$ST_PROJECT/ops/lib/legacy-install.sh"
LI_ROOT=/
cmp "$ST_PROJECT/scripts/lib/camera-rpi-eos-legacy" /usr/local/libexec/camera-rpi-eos-legacy
li_require_quiet_new_stack

if [[ "$ST_MODE" == --start ]]; then
  unit="camera-eos-test-$(date +%Y%m%d-%H%M%S)"
  systemd-run --no-block --unit="$unit" --property=Type=oneshot \
    /bin/bash "$ST_PROJECT/ops/test-steinhagen-capture.sh" --capture
  printf 'Test laeuft unabhaengig von SSH. Log: journalctl -u %s -f\n' "$unit"
  exit 0
fi

# Require exactly the inventoried polling process; never kill capture/curl.
# SIGSTOP preserves the pending command/curl context for SIGCONT after the test.
ST_PID="$(pgrep -x webcam_wait)"
[[ "$ST_PID" =~ ^[1-9][0-9]*$ ]]
ST_START="$(awk '{print $22}' "/proc/$ST_PID/stat")"
st_no_camera_process() {
  local found rc=0
  found="$(pgrep -f '(^|[[:space:]/])webcam_(capture|check)([[:space:]]|$)|(^|[[:space:]/])gphoto2([[:space:]]|$)')" || rc=$?
  ((rc <= 1)) && [[ -z "$found" ]]
}
st_no_camera_process || { echo 'Aufnahme/Pruefer noch aktiv; spaeter erneut starten.' >&2; exit 1; }
[[ "$(findmnt -n -o FSTYPE -T /tmp)" == tmpfs ]]
[[ -f /etc/crontab && ! -L /etc/crontab ]]
if [[ "$ST_MODE" == --check ]]; then
  echo 'OK: Einmaltest vorbereitet; Alt-Poller unveraendert, keine Aufnahme.'
  exit 0
fi

exec 8>/run/lock/camera-eos-test.lock
flock -n 8
ST_BACKUP='' ST_CRON_CHANGED=0 ST_PAUSED=0 ST_RW=0
st_finish() {
  local result=$? temp
  trap - EXIT HUP INT TERM
  # A failed cron restore must not skip resuming the old poller.
  set +e
  # Only restore the cron file we published. A concurrent operator edit wins.
  if ((ST_CRON_CHANGED)); then
    if cmp -s /etc/crontab "$ST_BACKUP/crontab.paused"; then
      if temp="$(mktemp /etc/.camera-cron-restore.XXXXXXXX)"; then
        cp -a "$ST_BACKUP/crontab.before" "$temp" && mv -T "$temp" /etc/crontab || result=1
      else
        result=1
      fi
    else
      echo "Cron inzwischen geaendert; nicht ueberschrieben. Sicherung: $ST_BACKUP" >&2
      result=1
    fi
  fi
  if ((ST_PAUSED)); then
    if [[ -r /proc/$ST_PID/stat && "$(awk '{print $22}' "/proc/$ST_PID/stat")" == "$ST_START" ]]; then
      kill -CONT "$ST_PID" || result=1
    else
      echo 'Alter Poller fehlt; kein neuer Prozess automatisch gestartet.' >&2
      result=1
    fi
  fi
  if ((ST_RW)); then sync; mount -o remount,ro / || result=1; fi
  printf 'Test beendet (exit=%s); Cron/Poller-Rueckstellung durchgefuehrt. Sicherung: %s\n' "$result" "$ST_BACKUP"
  exit "$result"
}
trap st_finish EXIT
trap 'exit 143' HUP TERM
trap 'exit 130' INT
case ",$(findmnt -n -o OPTIONS -T /)," in
  *,ro,*) mount -o remount,rw /; ST_RW=1 ;;
  *,rw,*) ;;
  *) exit 1 ;;
esac
ST_BACKUP="$(mktemp -d /var/backups/camera-eos-test.XXXXXXXX)"
cp -a /etc/crontab "$ST_BACKUP/crontab.before"
awk '
  /^[[:space:]]*#/ {print; next}
  /\/usr\/local\/bin\/webcam_check([[:space:]]|$)/ {print "# camera-eos-test: " $0; n++; next}
  {print}
  END {if(n!=1) exit 1}
' "$ST_BACKUP/crontab.before" > "$ST_BACKUP/crontab.paused"
cmp /etc/crontab "$ST_BACKUP/crontab.before"
temporary="$(mktemp /etc/.camera-cron-pause.XXXXXXXX)"
cp -a "$ST_BACKUP/crontab.before" "$temporary"
cat "$ST_BACKUP/crontab.paused" > "$temporary"
ST_CRON_CHANGED=1
mv -T "$temporary" /etc/crontab
[[ "$(awk '{print $22}' "/proc/$ST_PID/stat")" == "$ST_START" ]]
ST_PAUSED=1
kill -STOP "$ST_PID"

# The common capture test normally requires no poller at all. Here exactly
# the saved, stopped poller is allowed; every physical camera user must be gone.
lc_require_idle() {
  [[ "$(pgrep -x webcam_wait)" == "$ST_PID" ]]
  [[ "$(awk '{print $3}' "/proc/$ST_PID/stat")" == T ]]
  st_no_camera_process
  ! awk '!/^[[:space:]]*#/ && /webcam_check|webcam_wait/ {found=1} END {exit !found}' /etc/crontab
  li_require_quiet_new_stack
}
lc_require_idle
printf 'Alt-Poller pausiert; genau eine neue EOS-Aufnahme ohne Upload. Sicherung: %s\n' "$ST_BACKUP"
lc_main --capture
