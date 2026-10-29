#!/usr/bin/env bash
# One physical exposure on the legacy .16 camera; NOT a worker/rollout test.
# Private RAM output only, no server/key access, no upload, GPIO or services.
set -euo pipefail
umask 077
export PATH=/usr/sbin:/usr/bin:/sbin:/bin

lc_fail() { printf 'FEHLER: %s\n' "$*" >&2; return 1; }

# Both the cron watchdog and its already running children must be gone.
# Checking process arguments returns only PIDs, never expanded curl secrets.
lc_require_idle() {
  if awk '!/^[[:space:]]*#/ && /webcam_check|webcam_wait/ {found=1} END {exit !found}' /etc/crontab; then
    lc_fail 'Alter Kamera-Cron ist noch aktiv'; return 1
  fi
  local busy rc=0
  busy="$(pgrep -f '(^|[[:space:]/])webcam_(wait|check|capture)([[:space:]]|$)|(^|[[:space:]/])gphoto2([[:space:]]|$)')" || rc=$?
  (( rc <= 1 )) || { lc_fail 'Prozessliste nicht lesbar'; return 1; }
  [[ -z "$busy" ]] || { lc_fail "Alte Kameraoperation noch aktiv, PID(s): $busy"; return 1; }
  li_require_quiet_new_stack
}

# Read image metadata and framing without decompressing millions of pixels.
# This is a format/dimension check, not proof that every pixel decodes correctly.
lc_check_jpeg() {
  php -r '
    $p=$argv[1]; $size=filesize($p);
    if ($size<1024 || $size>52428800) { exit(1); }
    $f=fopen($p,"rb"); $start=fread($f,2); fseek($f,-2,SEEK_END);
    $end=fread($f,2); fclose($f);
    $info=@getimagesize($p);
    if ($start!=="\xff\xd8" || $end!=="\xff\xd9" || !$info
        || $info[2]!==IMAGETYPE_JPEG || $info[0]<16 || $info[1]<16) { exit(1); }
    printf("JPEG OK: %dx%d Pixel, %d Bytes, %s\n",$info[0],$info[1],$size,basename($p));
  ' "$1"
}

# A reboot clears /tmp; absence is a valid starting point, not a test failure.
# Record that state as well so creation/replacement by a competing job fails.
lc_snapshot_old_image() {
  if [[ -e "$1" || -L "$1" ]]; then
    sha256sum "$1" > "$2/old-image.before.sha256"
  else
    : > "$2/old-image.absent"
  fi
}

lc_verify_old_image() {
  if [[ -f "$2/old-image.absent" ]]; then
    [[ ! -e "$1" && ! -L "$1" ]]
  else
    sha256sum -c "$2/old-image.before.sha256"
  fi
}

lc_main() (
  local mode="${1:---help}" project stage='' rc=0 started bus device extra
  if [[ "$mode" == --help ]]; then
    printf 'sudo bash %s --check|--capture\nEinmaliger EOS-Test ohne Upload; Altbetrieb vorher bewusst anhalten.\n' "$0"
    return 0
  fi
  [[ $# == 1 && ( "$mode" == --check || "$mode" == --capture ) ]] || return 2
  (( EUID == 0 )) || { lc_fail 'USB-Kamera und Operationslock erfordern root'; return 1; }
  [[ -f /etc/webcam.cfg && -r /etc/crontab ]] || { lc_fail 'Erwarteter Altbestand fehlt'; return 1; }
  project="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")/.." && pwd -P)"
  (cd "$project" && sha256sum -c SHA256SUMS)
  source "$project/ops/lib/legacy-install.sh"
  LI_ROOT=/
  lc_require_idle
  [[ "$(findmnt -n -o FSTYPE -T /tmp)" == tmpfs ]] \
    || { lc_fail '/tmp ist kein RAM-Dateisystem'; return 1; }
  (( $(df -PB1 /tmp | awk 'NR==2 {print $4}') >= 134217728 )) \
    || { lc_fail 'Weniger als 128 MiB RAM-Dateispeicher frei'; return 1; }
  for binary in gphoto2 timeout php flock lsusb; do
    command -v "$binary" >/dev/null || { lc_fail "Programm fehlt: $binary"; return 1; }
  done

  # Match only the inventoried Canon USB ID; do not accidentally pick another
  # connected camera. Port numbers are rediscovered after every reconnect.
  local -a cameras=()
  mapfile -t cameras < <(lsusb -d 04a9:32e1 | awk '{gsub(":","",$4); print $2, $4}')
  ((${#cameras[@]} == 1)) || { lc_fail 'Nicht genau eine erwartete Canon gefunden'; return 1; }
  read -r bus device extra <<< "${cameras[0]}"
  [[ "$bus" =~ ^[0-9]{3}$ && "$device" =~ ^[0-9]{3}$ && -z "$extra" ]] || return 1
  printf 'Canon 04a9:32e1 an usb:%s,%s; alter/neuer Kamera-Poller inaktiv.\n' "$bus" "$device"
  [[ "$mode" != --check ]] || { printf 'OK: Vorabpruefung, noch keine Aufnahme.\n'; return 0; }

  exec 9>/run/lock/camera-rpi.operation.lock
  flock -n 9 || { lc_fail 'Gemeinsame Kameraoperation aktiv'; return 1; }
  lc_require_idle
  stage="$(mktemp -d /tmp/camera-rpi-eos-result.XXXXXXXX)"
  # Only these fresh, key-free test results may be read by analyse for review.
  # Existing /tmp images, key directories and USB permissions stay unchanged.
  lc_finish() {
    local result=$?
    trap - EXIT
    if getent group analyse >/dev/null; then
      chgrp analyse "$stage" && chmod 0750 "$stage" || true
      find "$stage" -maxdepth 1 -type f -exec chgrp analyse {} + -exec chmod 0640 {} + || true
    fi
    printf 'RAM-Ergebnisse: %s (vor Reboot sichern, falls benoetigt)\n' "$stage"
    exit "$result"
  }
  trap lc_finish EXIT
  lc_snapshot_old_image /tmp/raw.jpg "$stage"
  printf 'EOS-Legacy-Aufnahme, maximal 120s plus 15s Rueckstellfrist; keine Wiederholung.\n'
  started=$SECONDS
  # Exercise exactly the new legacy capture helper, without polling/uploading
  # or bypassing the worker's climate gate. This is capture-only acceptance.
  (
    cd "$stage"
    ulimit -f 51200
    env -i PATH="$PATH" LC_ALL=C /usr/bin/timeout --signal=TERM --kill-after=15 120s \
      "$project/scripts/lib/camera-rpi-eos-legacy" --capture \
      "$stage/capture-001.jpg" 52428800 "usb:$bus,$device"
  ) > "$stage/gphoto2.log" 2>&1 || rc=$?
  printf 'gphoto2_exit=%s, Dauer=%ss\n' "$rc" "$((SECONDS-started))"
  cat "$stage/gphoto2.log"
  lc_verify_old_image /tmp/raw.jpg "$stage" \
    || { lc_fail 'Altes raw.jpg hat sich waehrend des Tests geaendert'; return 1; }
  (( rc == 0 )) || { lc_fail 'Einzelaufnahme fehlgeschlagen; Ergebnisse bleiben erhalten'; return "$rc"; }
  local image jpeg_count=0
  shopt -s nullglob nocaseglob
  for image in "$stage"/capture-*.jpg "$stage"/capture-*.jpeg; do
    lc_check_jpeg "$image" || { lc_fail 'JPEG-Formatpruefung fehlgeschlagen'; return 1; }
    ((jpeg_count += 1))
  done
  (( jpeg_count == 1 )) || { lc_fail 'Nicht genau ein JPEG entstanden; Format/Dateien pruefen'; return 1; }
  printf 'ABNAHME OK: einzelner gphoto2-Capture/Download und JPEG-Header/Dimensionen.\n'
  printf 'Kein Upload, Workerstart oder GPIO-Eingriff; keine Altbilder geloescht. Sensor/Regler und Dauerbetrieb noch nicht abgenommen.\n'
)

if [[ "${BASH_SOURCE[0]}" == "$0" ]]; then
  lc_main "$@"
fi
