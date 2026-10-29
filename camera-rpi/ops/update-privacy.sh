#!/usr/bin/env bash
# Optional device-wide capture guard. No target/key/clock/GPIO edits or service
# starts. All photo/video consumers must be stopped by the operator for apply.
# Backup preserves old open inodes, allowing the original RO mount to return.
set -euo pipefail
umask 077
export PATH=/usr/sbin:/usr/bin:/sbin:/bin
SRC="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")/.." && pwd -P)"
MODE="${1:---check}"
[[ $EUID == 0 && $# == 1 && ( "$MODE" == --check || "$MODE" == --apply ) ]] \
  || { echo 'Als root: bash ops/update-privacy.sh --check|--apply' >&2; exit 1; }
cd "$SRC"
sha256sum -c SHA256SUMS
php -r 'if (!extension_loaded("gd")) {fwrite(STDERR,"Für dieses Paket bitte zuerst php-gd installieren.\n"); exit(1);}'
php tests/capture-privacy.php
bash tests/capture-privacy-worker
for file in scripts/camera-rpi-privacy.php scripts/lib/capture-privacy.php admin/src/privacy.php admin/src/privacy-panel.php admin/public/index.php; do php -l "$file"; done
bash -n scripts/camera-rpi-worker scripts/lib/camera-rpi-common.sh scripts/lib/camera-rpi-pool.sh scripts/admin/camera-rpi-admin
for file in camera-rpi-climate.php climate-controller.php climate-gpio.php climate-history.php gpio-config.php; do
  [[ -r /usr/local/libexec/$file ]] || { echo "Installierte Grundlage fehlt: $file" >&2; exit 1; }
done
php -r 'foreach (["date_sun_info","fsync","posix_geteuid"] as $f) if (!function_exists($f)) exit(1);'
sources=(scripts/camera-rpi-privacy.php scripts/lib/capture-privacy.php scripts/camera-rpi-worker
  scripts/lib/camera-rpi-common.sh scripts/lib/camera-rpi-pool.sh scripts/admin/camera-rpi-admin
  admin/public/index.php admin/src/privacy.php admin/src/privacy-panel.php CAPTURE-PRIVACY.md)
targets=(/usr/local/libexec/camera-rpi-privacy.php /usr/local/libexec/capture-privacy.php /usr/local/libexec/camera-rpi-worker
  /usr/local/libexec/camera-rpi-common.sh /usr/local/libexec/camera-rpi-pool.sh /usr/local/sbin/camera-rpi-admin
  /usr/local/share/camera-rpi-admin/public/index.php /usr/local/share/camera-rpi-admin/src/privacy.php
  /usr/local/share/camera-rpi-admin/src/privacy-panel.php /usr/local/share/doc/camera-rpi/CAPTURE-PRIVACY.md)
modes=(0644 0644 0755 0644 0644 0755 0644 0644 0644 0644)
if [[ -f /usr/local/libexec/camera-rpi-video.php ]]; then
  sources+=(scripts/camera-rpi-video.php scripts/lib/video-stream.php scripts/lib/camera-source.php scripts/camera-rpi-video-http.php)
  targets+=(/usr/local/libexec/camera-rpi-video.php /usr/local/libexec/video-stream.php /usr/local/libexec/camera-source.php /usr/local/libexec/camera-rpi-video-http.php)
  modes+=(0644 0644 0644 0644)
fi
# Only an explicitly packaged known baseline may be replaced. Each line lists
# source path + hash; repeated paths allow known pre-video and video versions.
for i in "${!targets[@]}"; do
  path=${targets[$i]}
  parent=$path
  while [[ "$parent" != / ]]; do
    [[ ! -L "$parent" ]] || { echo "Symlink im Installationspfad: $parent" >&2; exit 1; }
    parent=$(dirname "$parent")
  done
  [[ -d "$(dirname "$path")" ]] || { echo "Zielverzeichnis fehlt: $path" >&2; exit 1; }
  if [[ -e "$path" ]] && ! cmp -s "${sources[$i]}" "$path"; then
    hash=$(sha256sum "$path"); hash=${hash%% *}
    grep -Fqx "${sources[$i]} $hash" PRIVACY-BASELINES \
      || { echo "Unbekannter Ausgangsstand: $path – erst vergleichen, nichts überschrieben." >&2; exit 1; }
  fi
done
if [[ "$MODE" == --check ]]; then echo 'OK: Nur Vorprüfung. Keine Dienste, Kamera, Konfiguration oder Mounts geändert.'; exit 0; fi
busy=$(systemctl list-units 'camera-rpi-worker@*.service' camera-rpi-video.service --state=active,activating,deactivating --no-legend --no-pager)
[[ -z "$busy" ]] || { echo 'Zuerst Kamera-Worker und ggf. Videodienst stoppen; Sensor/Klima weiterlaufen lassen.' >&2; exit 1; }
exec 9>/run/lock/camera-rpi.operation.lock
flock -n 9 || { echo 'Kameraoperation aktiv.' >&2; exit 1; }
busy=$(systemctl list-units 'camera-rpi-worker@*.service' camera-rpi-video.service --state=active,activating,deactivating --no-legend --no-pager)
[[ -z "$busy" ]] || { echo 'Kamera-/Videodienst wurde zwischenzeitlich gestartet; Abbruch.' >&2; exit 1; }
source ops/lib/update-inodes.sh
rw_owned=0 published=0 backup=''
finish() {
  rc=$?; trap - EXIT
  if (( rc != 0 && published )); then
    for i in "${!targets[@]}"; do
      if [[ -f "$backup/files/$i" ]]; then cp -a "$backup/files/$i" "${targets[$i]}";
      elif [[ -e "${targets[$i]}" ]]; then mv "${targets[$i]}" "$backup/failed-new-$i"; fi
    done
    echo "Fehler: Programmdateien zurückgestellt; Sicherung $backup" >&2
  fi
  if (( rw_owned )); then sync; mount -o remount,ro / || { echo 'KRITISCH: Root blieb schreibbar.' >&2; rc=1; }; fi
  exit "$rc"
}
trap finish EXIT
trap 'exit 143' HUP TERM
trap 'exit 130' INT
case ",$(findmnt -rn -o OPTIONS /)," in *,ro,*) mount -o remount,rw /; rw_owned=1 ;; esac
backup=$(mktemp -d /var/backups/camera-rpi-privacy.XXXXXXXX)
mkdir "$backup/files" "$backup/inodes"
cp -a /etc/machine-id "$backup/machine-id"
for i in "${!targets[@]}"; do
  printf '%s\n' "${targets[$i]}" >> "$backup/targets"
  if [[ -f "${targets[$i]}" ]]; then cp -a "${targets[$i]}" "$backup/files/$i"; else touch "$backup/files/$i.absent"; fi
done
published=1
for i in "${!targets[@]}"; do
  path=${targets[$i]}; cu_preserve_inode "$path" "$backup/inodes"
  tmp=$(mktemp "$path.privacy.XXXXXXXX")
  install -o root -g root -m "${modes[$i]}" "${sources[$i]}" "$tmp"
  mv -f -- "$tmp" "$path"
  cmp -s "${sources[$i]}" "$path"
done
published=0
echo "OK: Aufnahmeschutz installiert; ohne bestehende privacy.json weiterhin AUS. Sicherung: $backup"
echo 'Keine Dienste gestartet, keine Fotos/GPIO-Aktionen, keine Ziel-/Key-/DHT-/Klimaänderung.'
echo 'Worker (und zuvor aktiven Videodienst) wieder starten. Erst nach frischem Standort-Poll im Admin konfigurieren.'
echo 'Legacy-Watchdog vor Nachtpausen separat anpassen; Rückweg siehe CAPTURE-PRIVACY.md.'
