#!/usr/bin/env bash
# First enrollment only. Arm ExecStop without restarting consumers, restoring
# an old checkpoint, changing GPIO or interrupting the experimental DHT driver.
set -euo pipefail
umask 077
[[ $EUID == 0 && $# == 1 && $1 == --apply ]]
SRC=$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")/.." && pwd -P)
cd "$SRC"
sha256sum -c SHA256SUMS
source ops/lib/update-inodes.sh
php tests/reboot-data.php
php -l scripts/camera-rpi-reboot-data.php
bash -n scripts/camera-rpi-reboot-save
[[ $(findmnt -n -o FSTYPE -T /run) == tmpfs ]]
[[ $(findmnt -n -o TARGET -T /var/lib/camera-rpi/spool) == /var/lib/camera-rpi/spool ]]
[[ $(findmnt -n -o FSTYPE -T /var/lib/camera-rpi/spool) == tmpfs ]]
[[ ! -e /etc/systemd/system/camera-rpi-reboot-save.service ]]
[[ ! -e /etc/systemd/system/camera-rpi-reboot-restore.service ]]
sources=(scripts/camera-rpi-reboot-data.php scripts/camera-rpi-reboot-save
  systemd/camera-rpi-reboot-save.service systemd/camera-rpi-reboot-restore.service
  systemd/reboot-worker.conf systemd/reboot-history.conf systemd/reboot-history.conf
  systemd/reboot-history.conf REBOOT-BACKUP.md)
targets=(/usr/local/libexec/camera-rpi-reboot-data.php /usr/local/libexec/camera-rpi-reboot-save
  /etc/systemd/system/camera-rpi-reboot-save.service /etc/systemd/system/camera-rpi-reboot-restore.service
  /etc/systemd/system/camera-rpi-worker@.service.d/reboot.conf
  /etc/systemd/system/camera-rpi-sensor.service.d/reboot.conf
  /etc/systemd/system/camera-rpi-climate.service.d/reboot.conf
  /etc/systemd/system/camera-rpi-sensor-restore.service.d/reboot.conf
  /usr/local/share/doc/camera-rpi/REBOOT-BACKUP.md)
modes=(0644 0755 0644 0644 0644 0644 0644 0644 0644)
for path in "${targets[@]}"; do
  [[ ! -e $path && ! -L $path ]] || { echo "Bestehendes Ziel nicht ueberschreiben: $path" >&2; exit 1; }
  parent=$(dirname "$path")
  while [[ $parent != / ]]; do [[ ! -L $parent ]]; parent=$(dirname "$parent"); done
done
exec 9>/run/lock/camera-rpi.operation.lock
flock -w 120 9
rw=0 changed=0 save=''
finish() {
  local rc=$? path
  trap - EXIT
  if (( rc!=0 && changed )); then
    # The no-op arming start is last, after all checks. Before that no consumer
    # has been restarted and no ExecStop action has been armed by this updater.
    if ! systemctl is-active --quiet camera-rpi-reboot-save.service; then
      systemctl disable camera-rpi-reboot-save.service camera-rpi-reboot-restore.service || true
      for path in "${targets[@]}"; do
        if [[ -f $path && ! -L $path ]]; then
          cu_preserve_inode "$path" "$save/inodes" && rm -f -- "$path" || rc=1
        fi
      done
      systemctl daemon-reload || rc=1
    else echo "Sicherung bereits scharf; keine automatische Deinstallation. $save" >&2; fi
  fi
  if (( rw )); then sync; mount -o remount,ro / || rc=1; fi
  exit "$rc"
}
trap finish EXIT
trap 'exit 143' TERM
trap 'exit 130' INT
case ",$(findmnt -n -o OPTIONS -T /)," in
  *,ro,*) mount -o remount,rw /; rw=1 ;;
  *,rw,*) ;;
  *) exit 1 ;;
esac
save=$(mktemp -d /var/backups/camera-rpi-reboot-install.XXXXXXXX)
install -d -m 0700 "$save/inodes"
printf '%s\n' "${targets[@]}" > "$save/new-files"
changed=1
for i in "${!sources[@]}"; do
  install -D -o root -g root -m "${modes[$i]}" "${sources[$i]}" "${targets[$i]}"
done
systemd-analyze verify /etc/systemd/system/camera-rpi-reboot-save.service /etc/systemd/system/camera-rpi-reboot-restore.service
systemctl daemon-reload
systemctl enable camera-rpi-reboot-save.service camera-rpi-reboot-restore.service
# After= does not pull the restore service into this start transaction.
# Starting this oneshot only executes /bin/true. Never start restore mid-boot.
systemctl start camera-rpi-reboot-save.service
systemctl is-active --quiet camera-rpi-reboot-save.service
changed=0
echo "OK: Reboot-Sicherung scharf; nur neuer No-op-Start, keine Verbraucher neu gestartet. $save"
echo 'Tagesbackup, DHT-Langzeittest, GPIO-Belegung, Keys und Kameraaufnahmen unveraendert.'
