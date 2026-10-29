#!/usr/bin/env bash
# Reviewed first installation on .16/.17; invoke via detached systemd service.
set -euo pipefail
umask 077
[[ $EUID == 0 && ${1:-} == --apply ]] || { echo 'Usage as root: bash install-system-maintenance.sh --apply'; exit 1; }
host=$(hostname -s)
[[ $host == webcam-sth || $host == webcam-bfld ]] || { echo 'Wrong host' >&2; exit 1; }
base=$(cd -- "$(dirname -- "$0")" && pwd)
cd "$base"
sha256sum --check SHA256SUMS
helper=/usr/local/libexec/camera-rpi-system-maintenance
unit=/etc/systemd/system/camera-rpi-system-maintenance.service
timer=/etc/systemd/system/camera-rpi-system-maintenance.timer
config=/etc/default/camera-rpi-system-maintenance
for path in "$helper" "$unit" "$timer" "$config"; do
  [[ ! -e $path && ! -L $path ]] || { echo "Already exists: $path" >&2; exit 1; }
done
declare -A old_enabled old_active
for name in apt-daily apt-daily-upgrade logrotate; do
  active=$(systemctl show "$name.service" -p ActiveState --value)
  [[ $active == inactive || $active == failed ]] || { echo "Busy: $name" >&2; exit 1; }
  old_enabled[$name]=$(systemctl is-enabled "$name.timer" || true)
  old_active[$name]=$(systemctl is-active "$name.timer" || true)
  [[ ${old_enabled[$name]} == enabled || ${old_enabled[$name]} == disabled ]] || { echo "Unexpected timer state: $name" >&2; exit 1; }
done
# Preserve .16's existing RAM-based logrotate state file.
if [[ $host == webcam-bfld ]]; then
  grep -Fxq '  ExecStart=/usr/sbin/logrotate --state /var/log/logrotate.status /etc/logrotate.conf' /etc/systemd/system/logrotate.service.d/override.conf
fi
state=${CAMERA_RPI_MAINT_STATE_DIR:?Detached installer must provide recovery state directory}
mkdir -p "$state"
exec 9>/run/lock/camera-rpi.operation.lock
flock -w 120 9
[[ ,$(findmnt -n -o OPTIONS -T /), == *,ro,* ]] || { echo 'Foreign RW window' >&2; exit 1; }
backup=
complete=0
finish() {
  local result=$?
  trap - EXIT
  if (( complete == 0 )) && [[ -n $backup ]]; then
    systemctl disable --now camera-rpi-system-maintenance.timer || true
    rm -f -- "$helper" "$unit" "$timer" "$config"
    systemctl daemon-reload || true
    for name in apt-daily apt-daily-upgrade logrotate; do
      if [[ ${old_enabled[$name]} == enabled ]]; then systemctl enable "$name.timer" || true; fi
      if [[ ${old_active[$name]} == active ]]; then systemctl start "$name.timer" || true; fi
    done
    echo "Install failed; originals restored; backup: $backup" >&2
  fi
  # Keep the operation lock continuously held through RO restoration.
  if [[ -e $state/root ]]; then
    sync -f /
    if mount -o remount,ro / && [[ ,$(findmnt -n -o OPTIONS -T /), == *,ro,* ]]; then
      rm -f "$state/root"
    else
      echo 'CRITICAL: install cleanup could not restore RO; ExecStopPost will retry' >&2
      result=1
    fi
  fi
  exit "$result"
}
trap finish EXIT
trap 'exit 143' TERM HUP
trap 'exit 130' INT
printf '/\n' > "$state/root"
mount -o remount,rw /
backup=$(mktemp -d /root/camera-system-maintenance-before-20261003.XXXXXXXX)
systemctl list-unit-files apt-daily.timer apt-daily-upgrade.timer logrotate.timer > "$backup/timers.txt"
declare -p old_enabled old_active > "$backup/timer-state.sh"
cp -a /etc/fstab "$backup/fstab"
cp -a SHA256SUMS "$backup/SHA256SUMS"
install -m 0755 camera-rpi-system-maintenance "$helper"
install -m 0644 camera-rpi-system-maintenance.service "$unit"
install -m 0644 camera-rpi-system-maintenance.timer "$timer"
if [[ $host == webcam-bfld ]]; then
  printf 'CAMERA_RPI_LOGROTATE_STATE=/var/log/logrotate.status\n' > "$config"
else
  printf '# Use the existing distribution logrotate state path.\n' > "$config"
fi
chmod 0644 "$config"
systemd-analyze verify "$unit" "$timer"
systemctl daemon-reload
systemctl disable --now apt-daily.timer apt-daily-upgrade.timer logrotate.timer
systemctl enable --now camera-rpi-system-maintenance.timer
complete=1
echo "INSTALLED; backup: $backup"
# First actual maintenance is started separately, after closing this write window.
