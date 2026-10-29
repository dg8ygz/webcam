#!/usr/bin/env bash
# Restore the exact first-enrollment backup; no live output reconfiguration.
# After reboot or later admin edits, require a reviewed rollback instead.
set -euo pipefail
umask 077
[[ $EUID == 0 && $# == 1 ]]
SAVE=$1
[[ $SAVE =~ ^/var/backups/camera-rpi-gpio\.[a-zA-Z0-9]+$ && -d $SAVE && ! -L $SAVE ]]
source "$SAVE/update-inodes.sh"
[[ $(cat "$SAVE/boot-id") == "$(cat /proc/sys/kernel/random/boot_id)" ]] || { echo 'Anderer Boot: Rueckstellung separat planen.' >&2; exit 1; }
if [[ -f /etc/camera-rpi/gpio.json ]]; then cmp -s /etc/camera-rpi/gpio.json "$SAVE/initial.json"; fi
systemctl is-active --quiet camera-rpi-gpio.service && { echo 'Bootdienst bereits gestartet; Rueckstellung separat planen.' >&2; exit 1; }
ROOT_RW=0 BOOT_RW=0
BOOT=/boot/firmware/config.txt BOOT_MOUNT=/boot/firmware
if [[ -f $SAVE/boot-path && -f $SAVE/boot-mount ]]; then
  read -r BOOT < "$SAVE/boot-path"
  read -r BOOT_MOUNT < "$SAVE/boot-mount"
fi
case "$BOOT:$BOOT_MOUNT" in /boot/config.txt:/boot|/boot/firmware/config.txt:/boot/firmware) ;; *) exit 1;; esac
finish() { local rc=$?; trap - EXIT; if (( BOOT_RW )); then mount -o remount,ro "$BOOT_MOUNT" || rc=1; fi; if (( ROOT_RW )); then mount -o remount,ro / || rc=1; fi; exit "$rc"; }
trap finish EXIT
if [[ ,$(findmnt -n -o OPTIONS -T /), == *,ro,* ]]; then mount -o remount,rw /; ROOT_RW=1; fi
if [[ ,$(findmnt -n -o OPTIONS -T "$BOOT"), == *,ro,* ]]; then mount -o remount,rw "$BOOT_MOUNT"; BOOT_RW=1; fi
install -d -m 0700 "$SAVE/rollback-inodes"
systemctl disable camera-rpi-gpio.service camera-rpi-camera-power.service || true
while IFS= read -r target; do
  case "$target" in /usr/local/*|/etc/camera-rpi/gpio.json|/etc/systemd/system/*|/boot/firmware/config.txt|/boot/config.txt|/etc/rc.local|/run/camera-rpi-gpio/active.json) ;; *) exit 1;; esac
  [[ ! -L $target ]]
  # A reader may already have opened the new version. Preserve that inode too,
  # including newly installed files which this rollback will remove.
  if [[ $target != "$BOOT" ]]; then cu_preserve_inode "$target" "$SAVE/rollback-inodes"; fi
  if [[ -f $SAVE/original$target ]]; then
    if [[ $target == "$BOOT" ]]; then cat "$SAVE/original$target" > "$target";
    else
      tmp="$(mktemp "$(dirname "$target")/.gpio-restore.XXXXXXXX")"
      cp -a "$SAVE/original$target" "$tmp"
      mv -f "$tmp" "$target"
    fi
  else
    # Only specific newly installed files from the recorded manifest are removed.
    rm -f -- "$target"
  fi
done < "$SAVE/targets"
systemctl daemon-reload
echo 'Vorherige Dateien restauriert; neue GPIO-Dateien entfernt (Sicherung erhalten). Keine Dienstneustarts.'
