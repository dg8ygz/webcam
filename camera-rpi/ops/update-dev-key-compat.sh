#!/usr/bin/env bash
# .16 only: four-character legacy key compatibility and one operator-supplied
# DEV key. Does not start a worker, poll, capture or change the legacy key.
set -euo pipefail
umask 077
export PATH=/usr/sbin:/usr/bin:/sbin:/bin
[[ $EUID == 0 && "${1:-}" == --apply && $# == 1 ]]
project="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd -P)"
(cd "$project" && sha256sum -c SHA256SUMS)
# Read the operator-supplied secret via stdin, not argv/environment/history.
# The fourth target is backed up but deliberately has no repository source.
IFS= read -r replacement
[[ "$replacement" =~ ^[A-Za-z0-9._~+/=-]{4,512}$ ]]
source "$project/ops/update-eos-legacy.sh"
source "$project/ops/lib/legacy-install.sh"
LI_ROOT=/
UE_SOURCES=(scripts/lib/camera-rpi-common.sh scripts/admin/camera-rpi-admin admin/public/index.php)
UE_PATHS=(usr/local/libexec/camera-rpi-common.sh usr/local/sbin/camera-rpi-admin usr/local/share/camera-rpi-admin/public/index.php etc/camera-rpi/keys/bielefeld-devsvr.key)
UE_MODES=(0644 0755 0644 0600)
baseline=(95f27441f3e047603b71bb905241fe4424e470b24c244cf1ea6205be15c6a419 3c55dfba49165588ca7284c7a32c53bdc762fd755554713471e4cac889805df0 c44cd0c1f55f609317a0b02b9b9225ec8121f1ab4d2fe05ef50cde6ed5926f2f)
for path in "${UE_PATHS[@]}" var/backups; do li_no_symlink_parents "$path"; done
[[ -z "$(systemctl list-units 'camera-rpi-worker@*.service' --state=active,activating,deactivating --no-legend --no-pager)" ]]
exec 9>/run/lock/camera-rpi.operation.lock
flock -n 9
for i in "${!UE_SOURCES[@]}"; do
  [[ "$(sha256sum "/${UE_PATHS[$i]}" | awk '{print $1}')" == "${baseline[$i]}" ]]
done
bash -n "$project/scripts/lib/camera-rpi-common.sh"
bash -n "$project/scripts/admin/camera-rpi-admin"
php -l "$project/admin/public/index.php"
backup='' rw_owned=0 publishing=0
# Clear the in-memory key even on failure. Restore this transaction only and
# close an RW window only if this invocation opened it; workers remain stopped.
finish() {
  local rc=$?
  trap - EXIT HUP INT TERM
  unset replacement
  if (( rc != 0 && publishing )); then ue_restore "$backup/before" "$(mktemp -d "$backup/failed.XXXXXXXX")" || echo 'Rueckstellung fehlgeschlagen' >&2; fi
  if (( rw_owned )); then sync; mount -o remount,ro / || rc=1; fi
  exit "$rc"
}
trap finish EXIT
trap 'exit 143' HUP TERM
trap 'exit 130' INT
case ",$(findmnt -n -o OPTIONS -T /)," in *,ro,*) mount -o remount,rw /; rw_owned=1 ;; *,rw,*) ;; *) exit 1 ;; esac
backup="$(mktemp -d /var/backups/camera-rpi-dev-key.XXXXXXXX)"
ue_snapshot "$backup/before"
sha256sum /etc/camera-rpi/keys/bielefeld-legacy.key > "$backup/legacy-key.sha256"
printf '%s\n' "$replacement" > "$backup/replacement.key"
unset replacement
sync -f "$backup"
publishing=1
for i in "${!UE_SOURCES[@]}"; do ue_publish_file "$project/${UE_SOURCES[$i]}" "${UE_PATHS[$i]}" "${UE_MODES[$i]}"; done
ue_publish_file "$backup/replacement.key" etc/camera-rpi/keys/bielefeld-devsvr.key 0600
env -i PATH="$PATH" bash /usr/local/libexec/camera-rpi-worker --check-config /etc/camera-rpi/servers/bielefeld-devsvr.conf
sha256sum -c "$backup/legacy-key.sha256"
sync -f "$backup"
publishing=0
printf 'OK: DEV-Key gesetzt; Legacy-Key unveraendert. Keine Dienst-/Kameraaktion. Sicherung: %s\n' "$backup"
