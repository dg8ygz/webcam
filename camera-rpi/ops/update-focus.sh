#!/usr/bin/env bash
# Root on the Pi. Does not change keys, targets, upload modes or timeouts.
set -euo pipefail
umask 077
[[ "$EUID" == 0 ]] || { echo 'Bitte als root ausfuehren.' >&2; exit 1; }
SRC="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")/.." && pwd -P)"
(cd "$SRC" && sha256sum -c SHA256SUMS)
[[ ",$(findmnt -n -o OPTIONS -T /)," == *,rw,* ]] || { echo 'Installation braucht temporaer schreibbares Root-Dateisystem.' >&2; exit 1; }
WORKER=/usr/local/libexec/camera-rpi-worker
COMMON=/usr/local/libexec/camera-rpi-common.sh
BRIDGE=/usr/local/sbin/camera-rpi-admin
WEB=/usr/local/share/camera-rpi-admin/public
for file in "$WORKER" "$COMMON" "$BRIDGE" "$WEB/index.php" "$WEB/style.css"; do
  [[ -f "$file" && ! -L "$file" ]] || exit 1
done
# Verified read-only through the operator's SSH tunnel on 2026-09-11.
[[ "$(sha256sum "$WORKER" | awk '{print $1}')" == 210032f80f48aa6028d91f9b75974e64c13d691c912c519f92b94693f8af9324 ]]
[[ "$(sha256sum "$COMMON" | awk '{print $1}')" == 6a61a5340c0b8757980f9c211671bfe0ef78473fdd994625a39d60ff62f085dc ]]
[[ "$(sha256sum "$WEB/index.php" | awk '{print $1}')" == 405de015f322b9932a5d3052d8ec59eb8d480f6eed3cfe7984736a1f9c9c5c8f ]]
[[ "$(sha256sum "$WEB/style.css" | awk '{print $1}')" == 44587dd33a77f5fda4d227db8670258fbf45ed2778d43cd3badf115688bd866c ]]
bash -n "$SRC/scripts/camera-rpi-worker" "$SRC/scripts/admin/camera-rpi-admin" "$SRC/scripts/lib/camera-rpi-common.sh"
php -l "$SRC/admin/public/index.php"
for config in /etc/camera-rpi/servers/*.conf; do
  [[ -f "$config" ]] || continue
  CAMERA_RPI_COMMON_LIB="$SRC/scripts/lib/camera-rpi-common.sh" \
    CAMERA_RPI_JPEG_HELPER=/usr/local/libexec/camera-rpi-jpeg.py \
    bash "$SRC/scripts/camera-rpi-worker" --check-config "$config"
done
apachectl -t
SAVE="$(mktemp -d /root/camera-rpi-focus-before.XXXXXXXX)"
cp -a "$WORKER" "$COMMON" "$BRIDGE" "$WEB/index.php" "$WEB/style.css" "$SAVE/"
printf 'Sicherung: %s\n' "$SAVE"
mapfile -t active_units < <(systemctl list-units --type=service --state=active --plain --no-legend 'camera-rpi-worker@*.service' | awk '{print $1}')
installed=0
rollback() {
  # Restore only backed-up update targets; restart only previously active workers.
  local rc=$?
  trap - EXIT
  if (( rc != 0 )); then
    if (( installed == 1 )); then
      ((${#active_units[@]} == 0)) || systemctl stop "${active_units[@]}" || true
      cp -a "$SAVE/camera-rpi-worker" "$WORKER"
      cp -a "$SAVE/camera-rpi-common.sh" "$COMMON"
      cp -a "$SAVE/camera-rpi-admin" "$BRIDGE"
      cp -a "$SAVE/index.php" "$SAVE/style.css" "$WEB/"
    fi
    ((${#active_units[@]} == 0)) || systemctl start "${active_units[@]}" || true
    echo 'Update abgebrochen; Ausgangsstand wiederhergestellt. Status bitte pruefen.' >&2
  fi
  exit "$rc"
}
trap rollback EXIT
trap 'exit 130' INT
trap 'exit 143' TERM
((${#active_units[@]} == 0)) || systemctl stop "${active_units[@]}"
exec 9>/run/lock/camera-rpi.operation.lock
flock -w 30 9
installed=1
install -o root -g root -m 0755 "$SRC/scripts/camera-rpi-worker" "$WORKER"
install -o root -g root -m 0755 "$SRC/scripts/lib/camera-rpi-common.sh" "$COMMON"
install -o root -g root -m 0750 "$SRC/scripts/admin/camera-rpi-admin" "$BRIDGE"
install -o root -g root -m 0644 "$SRC/admin/public/index.php" "$SRC/admin/public/style.css" "$WEB/"
flock -u 9
exec 9>&-
((${#active_units[@]} == 0)) || systemctl start "${active_units[@]}"
if systemctl is-active --quiet apache2; then systemctl reload apache2; fi
for unit in "${active_units[@]}"; do systemctl is-active --quiet "$unit"; done
trap - EXIT INT TERM
echo 'OK: Fokus lokal/remote installiert; keine Fokusbewegung oder Testaufnahme ausgeloest.'
echo 'USB/V4L2 bleibt gesperrt. gphoto2-Buttons zentral nach naechstem Poll verfuegbar.'
