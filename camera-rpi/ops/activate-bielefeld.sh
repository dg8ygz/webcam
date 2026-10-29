#!/usr/bin/env bash
# Deliberate .16 cutover AFTER the successful EOS exposure. This is not a
# generic installer: baseline/idle checks prevent parallel legacy GPIO owners.
set -Eeuo pipefail
umask 077

ac_paths() {
  AC_MOUNT='var-lib-camera\x2drpi-spool.mount'
  AC_UNITS=(camera-rpi-worker@bielefeld-legacy.service camera-rpi-climate.service
    camera-rpi-sensor.service camera-rpi-sensor-backup.timer
    camera-rpi-sensor-backup.service camera-rpi-sensor-restore.service)
  UE_PATHS=(etc/camera-rpi/servers/bielefeld-legacy.conf etc/camera-rpi/device.conf
    etc/camera-rpi/climate.json boot/config.txt etc/rc.local
    "etc/systemd/system/$AC_MOUNT"
    etc/systemd/system/camera-rpi-worker@bielefeld-legacy.service.d/cutover.conf
    etc/systemd/system/camera-rpi-sensor.service.d/cutover.conf
    etc/systemd/system/apache2.service.d/camera-rpi-logs.conf
    etc/tmpfiles.d/camera-rpi-apache.conf)
  AC_TEMPLATES=("$AC_MOUNT" worker.conf sensor.conf camera-rpi-logs.conf camera-rpi-apache.conf)
  AC_OLD_RAM=(tmp/raw.jpg tmp/http_raw.jpg tmp/http.jpg tmp/temperature.rrd
    tmp/wx.txt tmp/wxstn.txt tmp/today.png tmp/today_temp.png tmp/week.png
    tmp/log.txt tmp/log.tmp tmp/cmd.txt)
}

# Change exactly one existing field, without sourcing the shell-like file.
# Duplicate/missing fields are rejected; keys and unrelated options stay intact.
ac_enable() {
  awk '
    /^[[:space:]]*enabled[[:space:]]*=/ {count++; print "enabled=true"; next}
    {print}
    END {if (count != 1) exit 1}
  ' "$1"
}

# Snapshot bounded, named old RAM data and the new software's private trees.
# This runs only while their writers are stopped. Rollback copies NEW pending
# images here before unmounting RAM; no picture/key/log directory is deleted.
ac_data_snapshot() {
  local dest="$1" path
  mkdir -p "$dest"
  for path in etc/camera-rpi var/lib/camera-rpi run/camera-rpi-status \
    run/camera-rpi-climate "${AC_OLD_RAM[@]}"; do
    if [[ -e "$LI_ROOT/$path" ]]; then
      li_no_symlink_parents "$path" || return 1
      (cd "$LI_ROOT" && cp -a --parents "$path" "$dest/") || return 1
    fi
  done
  (cd "$dest" && find . -type f -print0 | sort -z | xargs -0 -r sha256sum > ../data.sha256) || return 1
  (cd "$dest" && sha256sum --quiet -c ../data.sha256) || return 1
}

# A reboot may have emptied the old /tmp as well. Restore saved legacy RAM
# files only AFTER saving the current ones. Preserve owner/mode; absent-before
# files are not deleted. The RRD can then resume from its cutover snapshot.
ac_restore_old_ram() {
  local backup="$1" path temp
  for path in "${AC_OLD_RAM[@]}"; do
    [[ -f "$backup/original/data/$path" ]] || continue
    li_no_symlink_parents "$path" || return 1
    temp="$(mktemp "$LI_ROOT/tmp/.camera-legacy-restore.XXXXXXXX")" || return 1
    cp -a "$backup/original/data/$path" "$temp" && mv -T "$temp" "$LI_ROOT/$path" || return 1
  done
}

# CLI helper supplies strict config parsing and status validation in PHP.
# No new Python, direct sensor reads, shell eval or remote commands from files.
ac_php() { php -d pcre.jit=0 -d opcache.enable_cli=0 "$AC_PROJECT/ops/lib/bielefeld-cutover.php" "$@"; }

# is-enabled deliberately returns 1 for the REQUIRED disabled state. Consume
# that expected status before comparing; ERR is inherited by command
# substitutions and otherwise prints a false abort even when [[ ]] succeeds.
ac_unit_disabled() {
  local state rc=0
  state="$(systemctl is-enabled "$1")" || rc=$?
  [[ "$rc" == 1 && "$state" == disabled ]]
}

# Before the first IIO module is loaded, /sys/bus/iio itself can be absent.
# Absence is valid; a failed enumeration of an existing directory is NOT proof
# of absence. Never silence a real find failure and treat its stdout as empty.
ac_no_iio() {
  local dir="$1" devices
  if [[ ! -e "$dir" && ! -L "$dir" ]]; then
    [[ -d /sys/bus && -r /sys/bus && -x /sys/bus ]]
    return
  fi
  [[ -d "$dir" && -r "$dir" && -x "$dir" ]] || return 1
  devices="$(find "$dir" -maxdepth 1 -name 'iio:device*' -print)" || return 1
  [[ -z "$devices" ]]
}

ac_idle() {
  lc_require_idle
  ac_unit_disabled fancontrol.service
  ! systemctl is-active --quiet fancontrol.service
  ! systemctl is-active --quiet rrd-create.service
  local rc=0 found
  found="$(pgrep -f '(^|[[:space:]/])fan\.py([[:space:]]|$)|(^|[[:space:]/])rrd-generate[^[:space:]]*')" || rc=$?
  (( rc <= 1 )) && [[ -z "$found" ]] || { lc_fail 'Alter Regler/RRD-Prozess noch aktiv'; return 1; }
  # Deliberately pinned to the operator-paused state actually inventoried.
  [[ "$(sha256sum /etc/crontab | awk '{print $1}')" == b0136cd54f86647232a0a76f2da5aacfa621a06225ba5cbd932ff5a17be8b2ac ]]
  [[ "$(sha256sum /etc/rc.local | awk '{print $1}')" == ec92beafae14a684a9ddd24c7298a30bb81bb6573cd45973cc61561fa3b03b62 ]]
}

ac_preflight() {
  local path
  ac_idle
  [[ "$(tr -d '\0' </proc/device-tree/model)" == 'Raspberry Pi 2 Model B Rev 1.1' ]]
  [[ "$(sha256sum /boot/config.txt | awk '{print $1}')" == c9c4f3712d46bc70e0957949e98d9e67785730090707608d51ae04e6583a0d0f ]]
  [[ "$(systemctl is-enabled rrd-create.service)" == enabled ]]
  ac_no_iio /sys/bus/iio/devices
  [[ "$(dtoverlay -l)" == 'No overlays loaded' ]]
  modinfo dht11 >/dev/null
  [[ -r /boot/overlays/dht11.dtbo && -c /dev/gpiochip0 ]]
  [[ "$(lsusb -d 04a9:32e1 | wc -l)" == 1 ]]
  [[ "$(findmnt -n -o FSTYPE -T /run)" == tmpfs ]]
  [[ "$(findmnt -n -o FSTYPE -T /var/log)" == tmpfs ]]
  [[ "$(findmnt -n -o FSTYPE -T /tmp)" == tmpfs ]]
  [[ "$(findmnt -n -o FSTYPE -T /var/tmp)" == tmpfs ]]
  ! mountpoint -q /var/lib/camera-rpi/spool
  [[ -d /var/lib/camera-rpi/spool ]]
  [[ -z "$(find /var/lib/camera-rpi/spool -mindepth 1 -print -quit)" ]] \
    || { lc_fail 'SD-Spool ist nicht leer; zuerst separat verlustfrei migrieren'; return 1; }
  (( $(awk '/MemAvailable:/ {print $2}' /proc/meminfo) >= 262144 ))
  (( $(df -Pk /var/backups | awk 'NR==2 {print $4}') >= 131072 ))
  for path in "${UE_PATHS[@]}" var/backups etc/camera-rpi/keys var/lib/camera-rpi/spool; do
    li_no_symlink_parents "$path"
  done
  for path in "${UE_PATHS[@]:5}"; do [[ ! -e "/$path" ]]; done
  [[ ! -e /etc/apache2/conf-enabled/camera-rpi-admin.conf ]]
  (cd / && sha256sum --quiet -c "$AC_PROJECT/installed.sha256")
  source /usr/local/libexec/camera-rpi-common.sh
  cr_load_server_config /etc/camera-rpi/servers/bielefeld-legacy.conf
  [[ "$SERVER_ID" == bielefeld-legacy && "$CAMERA_SLUG" == bielefeld
    && "$SERVER_BASE_URL" == http://192.0.2.2/webcam && "$ENABLED" == 0
    && "$CAPTURE_BACKEND" == gphoto2 && "$GPHOTO_CAPTURE_MODE" == eos-legacy
    && "$UPLOAD_MODE" == legacy && "$SPOOL_ROOT" == /var/lib/camera-rpi/spool ]]
  cr_read_secret "$KEY_FILE"; unset CR_SECRET
  /usr/local/libexec/camera-rpi-worker --check-config /etc/camera-rpi/servers/bielefeld-legacy.conf
  /usr/local/libexec/camera-rpi-sensor --check-config /etc/camera-rpi/device.conf
  ac_php config /etc/camera-rpi/climate.json >/dev/null
  apache2ctl configtest
}

# Stop writers BEFORE preserving/unmounting RAM. A failed stop or snapshot
# aborts the rollback, leaving the RAM mount intact for manual recovery.
ac_restore() {
  local backup="$1" recovery path
  recovery="$(mktemp -d "$backup/recovery.XXXXXXXX")" || return 1
  systemctl stop "${AC_UNITS[@]}" || return 1
  for path in "${AC_UNITS[@]}"; do
    ! systemctl is-active --quiet "$path" || return 1
  done
  ac_data_snapshot "$recovery/data" || return 1
  journalctl --no-pager -u camera-rpi-worker@bielefeld-legacy.service \
    -u camera-rpi-climate.service -u camera-rpi-sensor.service > "$recovery/journal.txt" || return 1
  sync -f "$backup" || return 1
  systemctl disable "${AC_UNITS[@]}" || return 1
  if [[ -f "/etc/systemd/system/$AC_MOUNT" ]]; then
    systemctl disable "$AC_MOUNT" || return 1
  fi
  if mountpoint -q /var/lib/camera-rpi/spool; then
    [[ "$(findmnt -n -o FSTYPE -M /var/lib/camera-rpi/spool)" == tmpfs ]] || return 1
    systemctl stop "$AC_MOUNT" || return 1
    ! mountpoint -q /var/lib/camera-rpi/spool || return 1
  fi
  # Only our DHT overlay is eligible for removal. After reboot the boot overlay
  # is not in dtoverlay's runtime list; unbind this exact platform device then.
  if [[ -f "$backup/dht-owned" ]]; then
    if dtoverlay -l | grep -Eq '^[[:space:]]*[0-9]+:[[:space:]]+dht11([[:space:]]|$)'; then
      dtoverlay -r dht11 || return 1
    elif [[ -L /sys/bus/platform/drivers/dht11/dht11@4 ]]; then
      printf '%s' dht11@4 > /sys/bus/platform/drivers/dht11/unbind || return 1
    fi
    [[ ! -L /sys/bus/platform/drivers/dht11/dht11@4 ]] || return 1
  fi
  a2disconf camera-rpi-admin >/dev/null || return 1
  ue_restore "$backup/before" "$recovery/files" || return 1
  ac_restore_old_ram "$backup" || return 1
  systemctl daemon-reload || return 1
  systemctl enable rrd-create.service || return 1
  apache2ctl configtest && systemctl reload apache2 || return 1
  printf 'Rueckgestellt: vorheriger ANGEHALTENER Altzustand. Neue Daten: %s\n' "$recovery"
  printf 'Alt-Cron/fancontrol bleiben absichtlich aus; alte Bilder, RRD und Keys bleiben erhalten.\n'
}

ac_main() (
  export PATH=/usr/sbin:/usr/bin:/sbin:/bin
  trap 'printf "Umschaltung abgebrochen (Pruefung/Schritt bei Zeile %s).\n" "$LINENO" >&2' ERR
  local mode="${1:---help}" backup='' changing=0 root_rw=0 boot_rw=0 path dest n
  [[ "$mode" != --help ]] || { printf 'bash %s --check|--apply|--rollback BACKUP\n' "$0"; return; }
  [[ ( $# == 1 && ( "$mode" == --check || "$mode" == --apply ) ) \
    || ( $# == 2 && "$mode" == --rollback ) ]] || return 2
  (( EUID == 0 )) || { printf 'Bitte als root ausfuehren.\n' >&2; return 1; }
  AC_PROJECT="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")/.." && pwd -P)"
  (cd "$AC_PROJECT" && sha256sum --quiet -c SHA256SUMS)
  source "$AC_PROJECT/ops/lib/legacy-install.sh"
  source "$AC_PROJECT/ops/update-eos-legacy.sh"
  source "$AC_PROJECT/ops/test-legacy-capture.sh"
  LI_ROOT=/; ac_paths
  # Prevent two cutover/rollback operators without blocking worker/backup locks.
  exec 8>/run/lock/camera-rpi.cutover.lock
  flock -n 8
  if [[ "$mode" == --rollback ]]; then
    backup="$2"
    [[ "$backup" =~ ^/var/backups/camera-rpi-cutover\.[A-Za-z0-9]+$
      && ! -L "$backup" && "$(stat -c '%u:%a' "$backup")" == 0:700 ]]
    cmp -s /etc/machine-id "$backup/machine-id"
    (cd "$backup" && sha256sum --quiet -c before.sha256)
    (cd "$backup/original/data" && sha256sum --quiet -c ../data.sha256)
  else
    ac_preflight
    [[ "$mode" != --check ]] || { printf 'OK: Umschaltung vorbereitet; keine Aenderung, kein GPIO, kein Poll.\n'; return; }
  fi

  ac_finish() {
    local result=$?
    trap - EXIT HUP INT TERM
    if (( changing && result != 0 )); then
      if ! ac_restore "$backup"; then
        printf 'ACHTUNG: Rueckstellung unvollstaendig. RAM/Backup erhalten; Altbetrieb NICHT starten: %s\n' "$backup" >&2
      fi
    fi
    sync
    if (( boot_rw )); then mount -o remount,ro /boot || result=1; fi
    if (( root_rw )); then mount -o remount,ro / || result=1; fi
    [[ -z "$backup" ]] || printf 'Sicherung/Rueckweg: %s\n' "$backup"
    exit "$result"
  }
  trap ac_finish EXIT
  trap 'exit 143' HUP TERM
  trap 'exit 130' INT
  if [[ ",$(findmnt -n -o OPTIONS -M /)," == *,ro,* ]]; then mount -o remount,rw /; root_rw=1; fi
  if [[ ",$(findmnt -n -o OPTIONS -M /boot)," == *,ro,* ]]; then mount -o remount,rw /boot; boot_rw=1; fi
  if [[ "$mode" == --rollback ]]; then ac_restore "$backup"; return; fi

  backup="$(mktemp -d /var/backups/camera-rpi-cutover.XXXXXXXX)"
  cp -a "$AC_PROJECT" "$backup/package"
  cp /etc/machine-id "$backup/machine-id"
  ue_snapshot "$backup/before"
  ac_data_snapshot "$backup/original/data"
  (cd "$backup" && find before -type f -print0 | sort -z | xargs -0 sha256sum > before.sha256)
  mkdir "$backup/rendered"
  ac_enable /etc/camera-rpi/device.conf > "$backup/rendered/device.conf"
  ac_enable /etc/camera-rpi/servers/bielefeld-legacy.conf > "$backup/rendered/server.conf"
  ac_php config /etc/camera-rpi/climate.json > "$backup/rendered/climate.json"
  /usr/local/libexec/camera-rpi-sensor --check-config "$backup/rendered/device.conf"
  /usr/local/libexec/camera-rpi-worker --check-config "$backup/rendered/server.conf"
  ac_idle
  (cd "$backup" && sha256sum --quiet -c before.sha256)
  for path in "${UE_PATHS[@]:0:5}"; do cmp -s "/$path" "$backup/before/$path"; done
  sync -f "$backup"
  changing=1
  systemctl disable --now rrd-create.service
  for n in "${!AC_TEMPLATES[@]}"; do
    path="${UE_PATHS[$((n+5))]}"
    dest="$(dirname "/$path")"; install -d -m 0755 "$dest"
    ue_publish_file "$AC_PROJECT/ops/cutover-files/${AC_TEMPLATES[$n]}" "$path" 0644
  done
  # Preserve the legacy webroot; Apache itself now creates its RAM log directory.
  sed '\|^/home/pi/startapache2\.sh$|s|^|# camera-rpi cutover: |' /etc/rc.local > "$backup/rendered/rc.local"
  ue_publish_file "$backup/rendered/rc.local" etc/rc.local 0755
  { cat /boot/config.txt; printf '\n# camera-rpi: sole reader for the existing BCM4 DHT22\n[all]\ndtoverlay=dht11,gpiopin=4\n'; } > "$backup/rendered/config.txt"
  ue_publish_file "$backup/rendered/config.txt" boot/config.txt 0644
  ue_publish_file "$backup/rendered/device.conf" etc/camera-rpi/device.conf 0600
  systemctl daemon-reload
  systemd-tmpfiles --create /etc/tmpfiles.d/camera-rpi.conf /etc/tmpfiles.d/camera-rpi-apache.conf
  systemctl enable --now "$AC_MOUNT"
  [[ "$(findmnt -n -o FSTYPE -M /var/lib/camera-rpi/spool)" == tmpfs ]]
  # Mark ownership BEFORE load so a partly successful overlay also rolls back.
  touch "$backup/dht-owned"
  dtoverlay dht11 gpiopin=4
  systemctl start camera-rpi-sensor-restore.service
  systemctl start camera-rpi-sensor.service
  printf 'Sensor gestartet: bis zu 120s auf ein frisches gueltiges Messpaar warten.\n'
  for ((n=0; n<60; n++)); do
    if ac_php sensor; then break; fi
    sleep 2
  done
  ac_php sensor || { lc_fail 'Keine gueltigen DHT-Werte; keine Regler-/Workeraktivierung'; return 1; }
  ue_publish_file "$backup/rendered/climate.json" etc/camera-rpi/climate.json 0600
  systemctl start camera-rpi-climate.service
  for ((n=0; n<15; n++)); do
    if ac_php climate; then break; fi
    sleep 2
  done
  ac_php climate || { lc_fail 'Regler/GPIO meldet keinen gesunden Start'; return 1; }
  ue_publish_file "$backup/rendered/server.conf" etc/camera-rpi/servers/bielefeld-legacy.conf 0600
  a2enconf camera-rpi-admin
  apache2ctl configtest
  systemctl reload apache2
  systemctl enable camera-rpi-sensor.service camera-rpi-climate.service \
    camera-rpi-sensor-restore.service camera-rpi-worker@bielefeld-legacy.service
  systemctl enable --now camera-rpi-sensor-backup.timer
  systemctl start camera-rpi-worker@bielefeld-legacy.service
  systemctl is-active --quiet camera-rpi-worker@bielefeld-legacy.service
  sleep 2
  systemctl is-active --quiet camera-rpi-worker@bielefeld-legacy.service
  ac_php climate
  sync -f "$backup"
  changing=0
  printf 'OK: Sensor, Automatik, Legacy-Worker und Tagesbackup aktiviert.\n'
  printf 'Reglerpuffer braucht mehrere Messungen (~3 Minuten); erster Serverauftrag/Upload noch zu pruefen.\n'
  printf 'Web: http://192.0.2.16/ und /camera-admin/ (cameraadmin).\n'
  printf 'Rollback: bash %s/package/ops/activate-bielefeld.sh --rollback %s\n' "$backup" "$backup"
  printf 'RAM-Puffer 128 MiB. Stromausfall verliert ungesicherte neue Bilder/Logs/Messungen.\n'
)

if [[ "${BASH_SOURCE[0]}" == "$0" ]]; then ac_main "$@"; fi
