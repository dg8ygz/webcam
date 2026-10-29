#!/usr/bin/env bash
# .16 overview at the exact root URL, keeping legacy/admin aliases intact.
# Back up and syntax-check before Apache reload; do not touch camera services.
set -euo pipefail
umask 077
[[ $EUID == 0 && "${1:-}" == --apply && $# == 1 ]]
project="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd -P)"
(cd "$project" && sha256sum -c SHA256SUMS)
source "$project/ops/update-eos-legacy.sh"
source "$project/ops/lib/legacy-install.sh"
LI_ROOT=/
UE_SOURCES=(apache/camera-rpi-admin.conf status/public/index.php)
UE_PATHS=(etc/apache2/conf-available/camera-rpi-admin.conf usr/local/share/camera-rpi-status/public/index.php)
UE_MODES=(0644 0644)
baseline=(3f8c8ed2ad5fd6d1e80ddac239cf484d9af67573fd59c2b7a22334ff4e0e4434 01a6eb1515fbc829d0b296921653f8b8c98cc45001ca541927b6a98cdb87e524)
for i in "${!UE_PATHS[@]}"; do
  li_no_symlink_parents "${UE_PATHS[$i]}"
  [[ "$(sha256sum "/${UE_PATHS[$i]}" | awk '{print $1}')" == "${baseline[$i]}" ]]
done
php -l "$project/status/public/index.php"
backup='' rw_owned=0 publishing=0
# A failed publication/configtest/reload restores both Apache and page files.
# Existing camera/sensor/climate units never participate in this transaction.
finish() {
  local rc=$?
  trap - EXIT HUP INT TERM
  if ((rc != 0 && publishing)); then
    ue_restore "$backup/before" "$(mktemp -d "$backup/failed.XXXXXXXX")" && apache2ctl configtest && systemctl reload apache2 || echo 'Apache-Rueckstellung pruefen' >&2
  fi
  if ((rw_owned)); then sync; mount -o remount,ro / || rc=1; fi
  exit "$rc"
}
trap finish EXIT
trap 'exit 143' HUP INT TERM
case ",$(findmnt -n -o OPTIONS -T /)," in *,ro,*) mount -o remount,rw /; rw_owned=1 ;; *,rw,*) ;; *) exit 1 ;; esac
backup="$(mktemp -d /var/backups/camera-rpi-root-page.XXXXXXXX)"
ue_snapshot "$backup/before"
publishing=1
for i in "${!UE_PATHS[@]}"; do ue_publish_file "$project/${UE_SOURCES[$i]}" "${UE_PATHS[$i]}" "${UE_MODES[$i]}"; done
apache2ctl configtest
systemctl reload apache2
sync -f "$backup"
publishing=0
echo "OK: Uebersicht direkt auf /; /camera-status/ und /camera-admin/ bleiben. Sicherung: $backup"
