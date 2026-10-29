#!/usr/bin/env bash
# Opt-in deployment for the tested Trixie Pi; never reboots automatically.
set -euo pipefail
umask 077
[[ "$EUID" == 0 ]] || { echo 'Als root ausfuehren.' >&2; exit 1; }
SOURCE_DIR="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")/.." && pwd -P)"
[[ "${1:-}" == --apply || "${1:-}" == --check ]] || {
  echo 'Aufruf: bash ops/enable-low-write.sh --check|--apply'; exit 2;
}
[[ -f "$SOURCE_DIR/SHA256SUMS" ]] || { echo 'SHA256SUMS fehlt.' >&2; exit 1; }
(cd "$SOURCE_DIR" && sha256sum -c SHA256SUMS)
for tool in python3 findmnt mount systemctl systemd-tmpfiles sha256sum flock cp; do
  command -v "$tool" >/dev/null
done
[[ "$(findmnt -n -o FSTYPE -T /run)" == tmpfs ]]
[[ "$(findmnt -n -o FSTYPE -T /tmp)" == tmpfs ]]
[[ ",$(findmnt -n -o OPTIONS -T /)," == *,rw,* ]] || {
  echo 'Installation braucht ein bereits RW eingehangenes Root (Wartungsfenster).' >&2; exit 1;
}
[[ -f /etc/rpi/swap.conf && -f /usr/local/libexec/camera-rpi-worker ]]
[[ ! -L /usr/local/libexec/camera-rpi-worker ]]
# This deployment was compared to the live Pi, including its legacy fixes.
# Never overwrite an unknown newer or locally modified worker.
[[ "$(sha256sum /usr/local/libexec/camera-rpi-worker | awk '{print $1}')" \
   == 7d7403e7a9cb1e34bdfe8af344f0893d5e33a6aa1b67bcb5affcb15c141c84b4 ]] || {
  echo 'Abbruch: installierter Worker weicht vom geprueften Ausgangsstand ab.' >&2; exit 1;
}
if awk 'NR>1 && $1 !~ /^\/dev\/zram[0-9]+$/ {disk=1} END {exit !disk}' /proc/swaps; then
  echo 'Abbruch: separater Disk-Swap aktiv; zuerst dessen Konfiguration klaeren.' >&2; exit 1
fi
[[ ! -L /var/lib/camera-rpi/spool && -d /var/lib/camera-rpi/spool ]]
[[ ! -L /etc/camera-rpi/device.conf ]]
[[ "$(awk '/MemAvailable:/ {print $2}' /proc/meminfo)" -ge 786432 ]] || {
  echo 'Abbruch: weniger als 768 MiB RAM verfuegbar.' >&2; exit 1;
}
SPOOL_UNIT='var-lib-camera\x2drpi-spool.mount'
units=(var-log.mount var-tmp.mount "$SPOOL_UNIT" camera-rpi-sensor-backup.service
       camera-rpi-sensor-backup.timer camera-rpi-sensor-restore.service)
sources=(scripts/camera-rpi-history-restore scripts/camera-rpi-sensor-archive.py scripts/camera-rpi-sensor-backup
         scripts/camera-rpi-worker systemd/low-write/camera-rpi-worker.conf
         systemd/low-write/sensor-restore.conf systemd/low-write/journald.conf
         systemd/low-write/swap.conf systemd/low-write/tmpfiles.conf
         systemd/low-write/logrotate.conf systemd/low-write/apache.conf)
targets=(/usr/local/libexec/camera-rpi-history-restore /usr/local/libexec/camera-rpi-sensor-archive.py /usr/local/libexec/camera-rpi-sensor-backup
         /usr/local/libexec/camera-rpi-worker /etc/systemd/system/camera-rpi-worker@.service.d/low-write.conf
         /etc/systemd/system/camera-rpi-sensor.service.d/restore.conf
         /etc/systemd/journald.conf.d/90-camera-rpi-volatile.conf
         /etc/rpi/swap.conf.d/90-camera-rpi-zram.conf
         /etc/tmpfiles.d/camera-rpi-low-write.conf
         /etc/systemd/system/logrotate.service.d/low-write.conf
         /etc/systemd/system/apache2.service.d/low-write.conf)
for unit in "${units[@]}"; do
  if [[ -f "$SOURCE_DIR/systemd/low-write/$unit" ]]; then
    sources+=("systemd/low-write/$unit")
  else
    sources+=("systemd/$unit")
  fi
  targets+=("/etc/systemd/system/$unit")
done
for target in "${targets[@]}"; do
  [[ "$target" == /usr/local/libexec/camera-rpi-worker ]] && continue
  [[ ! -e "$target" && ! -L "$target" ]] || {
    echo "Abbruch: Profilziel existiert bereits: $target" >&2; exit 1;
  }
done
for path in /var/log /var/tmp /var/lib/camera-rpi/spool; do
  if findmnt -rn -M "$path" >/dev/null || awk -v p="$path" '$1 !~ /^#/ && $2==p {found=1} END {exit !found}' /etc/fstab; then
    echo "Abbruch: bestehende Mount-Konfiguration fuer $path zuerst pruefen." >&2; exit 1
  fi
done
# Refuse custom spool paths instead of silently leaving an SD-writing target.
shopt -s nullglob
configs=(/etc/camera-rpi/servers/*.conf)
for config in "${configs[@]}"; do
  (
    source /usr/local/libexec/camera-rpi-common.sh
    cr_load_server_config "$config"
    [[ "$SPOOL_ROOT" == /var/lib/camera-rpi/spool && "$RUNTIME_ROOT" == /run/camera-rpi ]]
  ) || { echo "Abbruch: Konfiguration/Pfade pruefen: $config" >&2; exit 1; }
done
bash -n "$SOURCE_DIR/scripts/camera-rpi-worker"
bash -n "$SOURCE_DIR/scripts/camera-rpi-sensor-backup"
bash /usr/local/libexec/camera-rpi-sensor --check-config /etc/camera-rpi/device.conf
if [[ "$1" == --check ]]; then
  echo 'OK: Vorpruefung bestanden; nichts veraendert.'
  exit 0
fi
BACKUP="$(mktemp -d /root/camera-rpi-low-write-before.XXXXXXXX)"
cp -a /etc/camera-rpi/device.conf "$BACKUP/device.conf"
cp -a /usr/local/libexec/camera-rpi-worker "$BACKUP/camera-rpi-worker"
cp -a /etc/fstab "$BACKUP/fstab"
cp -a /etc/rpi "$BACKUP/rpi"
printf 'Sicherung: %s\n' "$BACKUP"
active_workers=()
sensor_active=0
systemctl is-active --quiet camera-rpi-sensor.service && sensor_active=1
mounted=0
installed=0
# Undo this profile's owned files/mount and restore the saved worker/config.
# Keep the original disk spool: a failed migration must not delete pending images.
rollback() {
  local result=$?
  trap - EXIT
  if (( result != 0 )); then
    echo "Fehler; Profil wird zurueckgenommen. Sicherung: $BACKUP" >&2
    systemctl stop camera-rpi-sensor-backup.timer || true
    for unit in "${active_workers[@]}"; do systemctl stop "$unit" || true; done
    for unit in var-log.mount var-tmp.mount "$SPOOL_UNIT" camera-rpi-sensor-backup.timer; do
      systemctl disable "$unit" 2>/dev/null || true
    done
    if (( mounted )); then
      # Original disk spool is still underneath; never discard the only copy.
      systemctl stop "$SPOOL_UNIT" || { echo 'RAM-Spool konnte nicht ausgehaengt werden; manuell pruefen.' >&2; exit 1; }
    fi
    if (( installed )); then
      for target in "${targets[@]}"; do
        [[ "$target" == /usr/local/libexec/camera-rpi-worker ]] || rm -f -- "$target"
      done
    fi
    cp -a "$BACKUP/camera-rpi-worker" /usr/local/libexec/camera-rpi-worker
    cp -a "$BACKUP/device.conf" /etc/camera-rpi/device.conf
    systemctl daemon-reload
    (( sensor_active == 0 )) || systemctl restart camera-rpi-sensor.service || true
    for unit in "${active_workers[@]}"; do systemctl start "$unit" || true; done
  fi
  exit "$result"
}
trap rollback EXIT
trap 'exit 130' INT
trap 'exit 143' TERM
for config in "${configs[@]}"; do
  id="$(basename "$config" .conf)"
  [[ "$id" =~ ^[a-z0-9]+([a-z0-9-]*[a-z0-9])?$ ]]
  unit="camera-rpi-worker@$id.service"
  if systemctl is-active --quiet "$unit"; then
    active_workers+=("$unit")
    systemctl stop "$unit"
  fi
done
# Hold the same operation lock as manual captures/admin and daily backup.
exec 9>/run/lock/camera-rpi.operation.lock
flock -w 120 9
[[ "$(du -sk /var/lib/camera-rpi/spool | awk '{print $1}')" -lt 196608 ]] || {
  echo 'Spool groesser als 192 MiB; zuerst Upload-Rueckstau abbauen.' >&2; exit 1;
}
cp -a /var/lib/camera-rpi/spool "$BACKUP/spool"
cp -a /run/camera-rpi-status "$BACKUP/sensor-runtime"
installed=1
for i in "${!targets[@]}"; do
  install -d -m 0755 "$(dirname "${targets[$i]}")"
  mode=0644
  [[ "${targets[$i]}" != /usr/local/libexec/* ]] || mode=0755
  install -o root -g root -m "$mode" "$SOURCE_DIR/${sources[$i]}" "${targets[$i]}"
done
if grep -q '^history_hours=' /etc/camera-rpi/device.conf; then
  sed -i 's/^history_hours=.*/history_hours=48/' /etc/camera-rpi/device.conf
else
  printf '\nhistory_hours=48\n' >> /etc/camera-rpi/device.conf
fi
bash /usr/local/libexec/camera-rpi-sensor --check-config /etc/camera-rpi/device.conf
systemctl daemon-reload
systemctl start "$SPOOL_UNIT"
mounted=1
cp -a "$BACKUP/spool/." /var/lib/camera-rpi/spool/
diff -qr "$BACKUP/spool" /var/lib/camera-rpi/spool
systemctl enable var-log.mount var-tmp.mount "$SPOOL_UNIT" camera-rpi-sensor-backup.timer
# Restore will run at the next boot. Avoid overwriting the live sensor history.
flock -u 9
exec 9>&-
systemctl start camera-rpi-sensor-backup.service
systemctl start camera-rpi-sensor-backup.timer
# Sensor restart would pull in the boot restore while a live history exists;
# new 48h setting takes effect at reboot. Existing history is backed up above.
for unit in "${active_workers[@]}"; do systemctl start "$unit"; done
trap - EXIT INT TERM
echo 'OK: RAM-Spool uebernommen, erste Sensor-Sicherung und Tagestimer aktiv.'
echo 'NEUSTART NOETIG: /var/log, /var/tmp, reines ZRAM und 48h Sensorverlauf.'
echo 'Kein automatischer Reboot. Alte SD-Spooldaten bleiben verdeckt erhalten; Sicherung nicht loeschen.'
echo 'Ungesicherte Messwerte/Logs/wartende RAM-Bilder gehen bei Stromausfall verloren.'
systemctl list-timers camera-rpi-sensor-backup.timer --no-pager
