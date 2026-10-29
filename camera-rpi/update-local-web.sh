#!/usr/bin/env bash
# Narrow update for an already installed Pi. No packages, keys or target edits.
set -euo pipefail
umask 077
(( EUID == 0 )) || { echo 'Als root auf dem Pi ausfuehren.' >&2; exit 1; }
[[ $# == 1 && "$1" =~ ^[a-z0-9]+(-[a-z0-9]+)*$ && ${#1} -le 48 ]] \
  || { echo 'Aufruf: bash update-local-web.sh SERVER-ID' >&2; exit 2; }
UPDATE_SOURCE="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd -P)"
UPDATE_UNIT="camera-rpi-worker@$1.service"
test -f "/etc/camera-rpi/servers/$1.conf"
test -f /etc/camera-rpi/admin.htpasswd
# Current admin templates depend on the separately installed climate module.
# Refuse the historical four-file updater instead of shipping an incomplete UI.
for support in /usr/local/share/camera-rpi-admin/src/climate.php /usr/local/share/camera-rpi-admin/src/climate-panel.php; do
  [[ -f "$support" ]] || { echo 'Zuerst das vollstaendige Klima-Update installieren (ops/update-climate.sh).' >&2; exit 1; }
done
# The source editor requires matching runtime helpers. This UI-only updater
# must not install a form whose source actions the existing runtime cannot use.
for support in /usr/local/share/camera-rpi-admin/src/source-panel.php /usr/local/share/camera-rpi-admin/public/source.js; do
  [[ -f "$support" ]] || { echo 'Zuerst das vollstaendige Aufnahmequellen-Update installieren.' >&2; exit 1; }
done
grep -q 'CR_SOURCE_GPHOTO_MODE' /usr/local/libexec/camera-rpi-common.sh \
  && grep -q 'gphoto_capture_mode' /usr/local/libexec/camera-source.php \
  || { echo 'Aufnahmequellen-Laufzeit ist zu alt fuer diese Admin-Version.' >&2; exit 1; }
cd "$UPDATE_SOURCE"
sha256sum --check PACKAGE.sha256
bash -n scripts/admin/camera-rpi-admin scripts/camera-rpi-worker
php -l admin/public/index.php
apache2ctl configtest

sources=(scripts/admin/camera-rpi-admin scripts/camera-rpi-worker apache/camera-rpi-admin.conf admin/public/index.php admin/public/source.js admin/src/source-panel.php)
targets=(/usr/local/sbin/camera-rpi-admin /usr/local/libexec/camera-rpi-worker /etc/apache2/conf-available/camera-rpi-admin.conf /usr/local/share/camera-rpi-admin/public/index.php /usr/local/share/camera-rpi-admin/public/source.js /usr/local/share/camera-rpi-admin/src/source-panel.php)
modes=(0750 0750 0644 0644 0644 0644)
UPDATE_BACKUP="$(mktemp -d /root/camera-rpi-web-before.XXXXXXXX)"
for i in "${!targets[@]}"; do
  test -f "${targets[$i]}"
  cp -a -- "${targets[$i]}" "$UPDATE_BACKUP/$i"
done
printf 'Sicherung: %s\n' "$UPDATE_BACKUP"

# Wait briefly for ongoing camera/file work instead of interrupting an exposure.
exec 9>/run/lock/camera-rpi.operation.lock
flock -w 10 9 || { echo 'Kamera beschaeftigt. Bitte Update spaeter erneut ausfuehren.' >&2; exit 1; }
was_active=0
systemctl is-active --quiet "$UPDATE_UNIT" && was_active=1
# Restore the saved targets on failure; only restart a previously active worker.
# This is a scoped updater, not the first-install path and not a key migration.
restore_on_error() {
  local result=$?
  trap - EXIT
  if (( result != 0 )); then
    for i in "${!targets[@]}"; do cp -a -- "$UPDATE_BACKUP/$i" "${targets[$i]}"; done
    apache2ctl configtest && systemctl reload apache2 || true
    (( was_active == 0 )) || systemctl restart "$UPDATE_UNIT" || true
    echo 'Update fehlgeschlagen; bisherige Dateien wiederhergestellt.' >&2
  fi
  exit "$result"
}
trap restore_on_error EXIT
if (( was_active == 1 )); then systemctl stop "$UPDATE_UNIT"; fi
for i in "${!targets[@]}"; do
  install -o root -g root -m "${modes[$i]}" -- "${sources[$i]}" "${targets[$i]}.web-update"
  mv -f -- "${targets[$i]}.web-update" "${targets[$i]}"
done
apache2ctl configtest
systemctl reload apache2
if (( was_active == 1 )); then systemctl start "$UPDATE_UNIT"; fi
flock -u 9
trap - EXIT
echo 'OK: Infoseite unter /, Admin unter /camera-admin/. Keys und Zielkonfiguration unveraendert.'
