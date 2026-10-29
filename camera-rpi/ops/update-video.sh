#!/usr/bin/env bash
# Experimental USB video, installed inactive. No sensor/climate/kernel changes.
set -euo pipefail
umask 077
[[ $EUID == 0 && ${1:-} == --apply && $# == 1 ]]
SRC=$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")/.." && pwd -P)
cd "$SRC"
sha256sum -c SHA256SUMS
php tests/video-stream.php
for file in scripts/camera-rpi-video.php scripts/camera-rpi-video-http.php scripts/lib/camera-source.php scripts/lib/video-stream.php; do php -l "$file"; done
bash -n scripts/lib/camera-rpi-common.sh
[[ -x /usr/bin/ffmpeg && -x /usr/local/bin/chisel ]]
read -r baseline _ < <(sha256sum /usr/local/libexec/camera-rpi-common.sh)
read -r desired _ < <(sha256sum scripts/lib/camera-rpi-common.sh)
case "$baseline" in
  cb2c5b2d0e857d18158bb3221d15f63e21d37ca7b287dcf55f808880f440a131|0e2ea12d19bfc2f9610e9999017151aa2c4a46f790ddb6c993e556051805bb88|"$desired") ;;
  *) echo 'Unbekannter Common-Stand: erst vergleichen, nichts ueberschreiben.' >&2; exit 1 ;;
esac
php -r 'foreach (["curl", "pcntl", "posix"] as $e) if (!extension_loaded($e)) exit(1);'
# The new daemon must never run alongside a worker with the old capture library.
# Caller pauses camera workers only; long-running DHT/climate services stay up.
if systemctl list-units --state=active --no-legend 'camera-rpi-worker@*.service' | grep -q camera-rpi-worker; then
  echo 'Zuerst Kamera-Worker nach abgeschlossener Aufnahme stoppen; Sensor weiterlaufen lassen.' >&2; exit 1
fi
! systemctl is-active --quiet camera-rpi-video.service
case ",$(findmnt -rn -o OPTIONS /)," in *,rw,*) ;; *) echo 'Installation braucht ein kontrolliertes RW-Fenster.' >&2; exit 1;; esac
source ops/lib/update-inodes.sh
SAVE=$(mktemp -d /var/backups/camera-rpi-video.XXXXXXXX)
mkdir "$SAVE/inodes" "$SAVE/files"
sources=(scripts/camera-rpi-video.php scripts/camera-rpi-video-http.php scripts/lib/camera-source.php scripts/lib/video-stream.php scripts/lib/camera-rpi-common.sh systemd/camera-rpi-video.service)
targets=(/usr/local/libexec/camera-rpi-video.php /usr/local/libexec/camera-rpi-video-http.php /usr/local/libexec/camera-source.php /usr/local/libexec/video-stream.php /usr/local/libexec/camera-rpi-common.sh /etc/systemd/system/camera-rpi-video.service)
for i in "${!targets[@]}"; do
  path=${targets[$i]}; [[ ! -L $path ]]
  if [[ -f $path ]]; then cp -a "$path" "$SAVE/files/$i"; else touch "$SAVE/files/$i.absent"; fi
  printf '%s\n' "$path" >> "$SAVE/targets"
done
publishing=1
finish() {
  rc=$?; trap - EXIT
  if (( rc != 0 && publishing )); then
    for i in "${!targets[@]}"; do
      if [[ -f $SAVE/files/$i ]]; then cp -a "$SAVE/files/$i" "${targets[$i]}";
      elif [[ -e ${targets[$i]} ]]; then mv "${targets[$i]}" "$SAVE/new-$i"; fi
    done
    systemctl daemon-reload
    echo "Update abgebrochen, Dateien zurueckgestellt: $SAVE" >&2
  fi
  exit "$rc"
}
trap finish EXIT
for i in "${!targets[@]}"; do
  path=${targets[$i]}; cu_preserve_inode "$path" "$SAVE/inodes"
  tmp=$(mktemp "$path.video.XXXXXXXX")
  install -o root -g root -m 0644 "${sources[$i]}" "$tmp"
  mv -f "$tmp" "$path"
done
systemctl daemon-reload
publishing=0
echo "OK: Video inaktiv installiert; keine Registrierung/Freigabe angelegt. Sicherung: $SAVE"
echo 'Kamera-Worker wieder starten; Sensor, Klima und DHT-Test wurden nicht neu gestartet.'
