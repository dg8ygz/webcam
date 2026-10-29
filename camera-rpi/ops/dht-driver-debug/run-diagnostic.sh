#!/bin/bash
# Temporary DHT module trial; no installed module, boot or GPIO config changes.
set -Eeuo pipefail
export PATH=/usr/sbin:/usr/bin:/sbin:/bin
umask 077
[[ $EUID == 0 ]] || { echo 'Bitte mit sudo bash starten.' >&2; exit 1; }
[[ -x /usr/bin/php ]] || { echo 'PHP-CLI fuer monotone Rueckstell-Frist fehlt.' >&2; exit 1; }

# Idempotent rollback shared by EXIT and the independent safety timer. Serialize
# both callers, reload the installed module and resume sampling before checking
# identity. A failed verification must not falsely create the RESTORED marker.
restore_driver() {
  local run=$1
  [[ $run == /run/camera-rpi-dht-driver.* && -d $run && ! -L $run ]] || return 1
  exec 8>"$run/restore.lock"
  flock -w 30 8 || return 1
  if [[ -f $run/RESTORED ]]; then return 0; fi
  timeout 20 systemctl stop camera-rpi-sensor.service || return 1
  if [[ -d /sys/module/dht11 ]]; then modprobe -r dht11 || return 1; fi
  modprobe dht11 || return 1
  # Always restart sampling after loading a driver, even if final identity
  # verification fails. Never leave it stopped merely due to a check failure.
  timeout 20 systemctl start camera-rpi-sensor.service || return 1
  systemctl is-active --quiet camera-rpi-sensor.service || return 1
  # sysfs advertises a synthetic 4096-byte size; cmp -s may reject it
  # without reading. Compare the small attribute contents instead.
  [[ $(cat "$run/original.srcversion") == "$(cat /sys/module/dht11/srcversion)" ]] || return 1
  [[ ! -e /sys/module/dht11/parameters/diag_seconds ]] || return 1
  touch "$run/RESTORED"
  chmod 0644 "$run/RESTORED"
  echo 'OK: Originaltreiber wieder geladen; Sensordienst aktiv.'
}

if [[ ${1:-} == --restore && $# == 2 ]]; then
  restore_driver "$2"
  exit
fi
# Short mode captures edges; detached 24h mode exercises the same fix silently.
TEST_SECONDS=180
DEBUG_SECONDS=180
if [[ $# == 1 && $1 == --long-worker ]]; then
  TEST_SECONDS=86400
  DEBUG_SECONDS=0
else
  [[ $# == 0 ]] || { echo 'Ungueltiger Aufruf.' >&2; exit 1; }
fi
# Preflight refuses a mismatched kernel, a previous trial or non-RAM staging.
# No module/service changes occur until the rollback watchdog is armed below.
SOURCE=$(cd -- "$(dirname -- "$0")" && pwd)
[[ $(findmnt -n -o FSTYPE -T /run) == tmpfs ]] || exit 1
[[ -f "$SOURCE/dht11.ko" && -f "$SOURCE/SHA256SUMS" ]] || exit 1
(cd "$SOURCE" && sha256sum -c SHA256SUMS)
[[ $(modinfo -F vermagic "$SOURCE/dht11.ko") == "$(modinfo -F vermagic dht11)" ]] || exit 1
[[ $(modinfo -F intree dht11) == Y ]] || exit 1
[[ -d /sys/module/dht11 && ! -e /sys/module/dht11/parameters/diag_seconds ]] || exit 1
[[ $(cat /sys/module/dht11/srcversion) == "$(modinfo -F srcversion dht11)" ]] || exit 1
systemctl is-active --quiet camera-rpi-sensor.service || exit 1
[[ -r /run/camera-rpi-status/current.status ]] || exit 1
# Testcam uses admin; legacy cameras use the existing adm diagnostic group.
# Do not create users/groups or fail after stopping a production sensor merely
# because the camera has a different login name. Resolve before any switching.
RESULT_GROUP=admin
if ! getent group "$RESULT_GROUP" >/dev/null; then RESULT_GROUP=adm; fi
getent group "$RESULT_GROUP" >/dev/null || exit 1
exec 9>/run/camera-rpi-dht-driver-diagnostic.lock
flock -n 9 || { echo 'Eine Diagnose laeuft bereits.' >&2; exit 1; }
RUN=$(mktemp -d /run/camera-rpi-dht-driver.XXXXXXXX)
chown "root:$RESULT_GROUP" "$RUN"
chmod 0750 "$RUN"
install -m 0700 "$SOURCE/run-diagnostic.sh" "$RUN/run-diagnostic.sh"
[[ ! -f "$SOURCE/observe-counters.php" ]] || install -m 0600 "$SOURCE/observe-counters.php" "$RUN/observe-counters.php"
install -m 0600 "$SOURCE/dht11.ko" "$RUN/dht11.ko"
cp /sys/module/dht11/srcversion "$RUN/original.srcversion"
# Preserve a copy, but never replace the installed distribution module.
cp "$(modinfo -F filename dht11)" "$RUN/original-module.ko.xz"
UNIT=camera-rpi-dht-driver-restore-${RUN##*.}
ARMED=0
JOURNAL_PID=
# Stop our observer, then roll back on normal exit and caught signals. Leave
# the independent timer armed if rollback fails so it can retry without SSH.
cleanup() {
  local result=$?
  trap - EXIT
  trap '' INT TERM HUP
  if [[ -n $JOURNAL_PID ]]; then
    kill "$JOURNAL_PID" 2>/dev/null || true
    wait "$JOURNAL_PID" 2>/dev/null || true
  fi
  if (( ARMED )); then
    if restore_driver "$RUN"; then
      systemctl stop "$UNIT.timer" || result=1
    else
      echo "ACHTUNG: Rueckstellung fehlgeschlagen; $UNIT.timer bleibt als Sicherung aktiv." >&2
      result=1
    fi
  fi
  echo "RAM-Ergebnisse: $RUN"
  exit "$result"
}
trap cleanup EXIT
trap 'exit 130' INT
trap 'exit 143' TERM
trap 'exit 129' HUP
# Anchor the fallback to CLOCK_MONOTONIC since this boot. OnActiveSec in the
# Pi's transient timer was postponed by daemon-reload; a fixed OnBootSec is
# independent of later reloads and timezone/wallclock corrections. PHP hrtime
# uses that same monotonic clock on Linux; no new Python dependency or IIO read.
# Keep seconds/nanoseconds separate: hrtime(true) becomes float on 32-bit PHP,
# and multiplying uptime by a million would overflow its native integer range.
RESTORE_DEADLINE=$(/usr/bin/php -r '
  $clock = hrtime();
  printf("%d.%06ds", $clock[0] + (int)$argv[1], intdiv($clock[1], 1000));
' "$((TEST_SECONDS + 60))")
# The independent systemd restoration also survives SIGKILL / SSH loss.
systemd-run --quiet --unit="$UNIT" --on-boot="$RESTORE_DEADLINE" --timer-property=AccuracySec=1s \
  --property=Restart=on-failure --property=RestartSec=10s \
  /bin/bash "$RUN/run-diagnostic.sh" --restore "$RUN"
ARMED=1
echo "Diagnose: $RUN"
timeout 20 systemctl stop camera-rpi-sensor.service
modprobe -r dht11
insmod "$RUN/dht11.ko" diag_seconds="$DEBUG_SECONDS"
[[ -e /sys/module/dht11/parameters/diag_seconds ]]
install -o root -g "$RESULT_GROUP" -m 0640 /dev/null "$RUN/kernel.jsonl"
install -o root -g "$RESULT_GROUP" -m 0640 /dev/null "$RUN/samples.jsonl"
journalctl -k -f -n 0 -o json --no-pager --grep=dht11 >"$RUN/kernel.jsonl" &
JOURNAL_PID=$!
timeout 20 systemctl start camera-rpi-sensor.service
echo "$TEST_SECONDS Sekunden: regulaere Sensorabfragen; Debugdauer $DEBUG_SECONDS Sekunden; keine Zusatzmessung."
# Sample the existing status file, never the IIO sensor. Deduplicate unchanged
# snapshots and bound journal growth; exceptions trigger the shell rollback.
if [[ -f "$RUN/observe-counters.php" ]]; then
  /usr/bin/php "$RUN/observe-counters.php" "$RUN" "$TEST_SECONDS"
else
# Compatibility for previously packaged trials without the PHP observer.
python3 - "$RUN" "$TEST_SECONDS" <<'PY'
import datetime, json, pathlib, sys, time
root = pathlib.Path(sys.argv[1])
keys = {'updated_epoch','sensor_state','sensor_message','temperature_c','humidity_percent'}
previous = None
end = time.monotonic() + int(sys.argv[2])
with (root/'samples.jsonl').open('a') as out:
    while time.monotonic() < end:
        if (root/'kernel.jsonl').stat().st_size > 8*1024*1024:
            raise RuntimeError('8 MiB capture budget exceeded')
        current = dict(line.split('=',1) for line in pathlib.Path('/run/camera-rpi-status/current.status').read_text().splitlines()
                       if '=' in line and line.split('=',1)[0] in keys)
        if current != previous:
            out.write(json.dumps({'observed_at':datetime.datetime.now().astimezone().isoformat(),'status':current})+'\n')
            out.flush()
            previous = current
        time.sleep(0.5)
PY
fi
echo 'Diagnosefenster abgeschlossen; Originaltreiber wird wiederhergestellt.'
