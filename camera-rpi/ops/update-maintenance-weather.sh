#!/usr/bin/env bash
# Install software only. No enrolment, no tunnel, no secret changes.
set -euo pipefail
umask 077
[[ $EUID == 0 && $# == 1 && "$1" == --apply ]] || { echo 'Aufruf: update-maintenance-weather.sh --apply'; exit 1; }
SRC="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")/.." && pwd -P)"
(cd "$SRC" && sha256sum -c SHA256SUMS)
[[ ",$(findmnt -n -o OPTIONS -T /)," == *,rw,* ]] || { echo 'Root muss fuer Installation rw sein.' >&2; exit 1; }
WORKER=/usr/local/libexec/camera-rpi-worker
[[ "$(sha256sum "$WORKER" | awk '{print $1}')" == 5d2d86ad6d76d19efc0b1beabfd3bb182197d1260a1e48e7d81d78afdfc4ae10 ]]
[[ "$(sha256sum /usr/local/libexec/camera-rpi-common.sh | awk '{print $1}')" == c1a957c6f6b98be33438f628118ee111f67c195f7537b4cfa5dfa2a62800bff6 ]]
new_paths=(/usr/local/libexec/camera-rpi-weather.py /usr/local/libexec/camera-rpi-tunnel.py /etc/systemd/system/camera-rpi-tunnel@.service /etc/tmpfiles.d/camera-rpi-maintenance.conf)
for path in "${new_paths[@]}"; do [[ ! -e "$path" ]]; done
bash -n "$SRC/scripts/camera-rpi-worker"
python3 -B "$SRC/tests/test-tunnel.py"
python3 -B "$SRC/tests/test-local-weather.py"
for cfg in /etc/camera-rpi/servers/*.conf; do
  [[ -f "$cfg" ]] || continue
  bash "$SRC/scripts/camera-rpi-worker" --check-config "$cfg"
done
SAVE="$(mktemp -d /root/camera-rpi-maintenance-before.XXXXXXXX)"
cp -a "$WORKER" "$SAVE/"
mapfile -t active < <(systemctl list-units --type=service --state=active --plain --no-legend 'camera-rpi-worker@*.service' | awk '{print $1}')
changed=0
rollback() {
  # Keep enrolment separate: undo new software paths, never alter camera keys/config.
  local rc=$?
  trap - EXIT
  if ((rc != 0)); then
    if ((changed)); then
      ((${#active[@]}==0)) || systemctl stop "${active[@]}" || true
      cp -a "$SAVE/camera-rpi-worker" "$WORKER"
      for path in "${new_paths[@]}"; do [[ ! -e "$path" ]] || mv -- "$path" "$SAVE/"; done
      systemctl daemon-reload
    fi
    ((${#active[@]}==0)) || systemctl start "${active[@]}" || true
    echo "Abgebrochen, Sicherung: $SAVE" >&2
  fi
  exit "$rc"
}
trap rollback EXIT
trap 'exit 130' INT
trap 'exit 143' TERM
((${#active[@]}==0)) || systemctl stop "${active[@]}"
changed=1
install -o root -g root -m 0755 "$SRC/scripts/camera-rpi-worker" "$WORKER"
install -o root -g root -m 0644 "$SRC/scripts/lib/camera-rpi-weather.py" /usr/local/libexec/
install -o root -g root -m 0644 "$SRC/scripts/camera-rpi-tunnel.py" /usr/local/libexec/
install -o root -g root -m 0644 "$SRC/systemd/camera-rpi-tunnel@.service" /etc/systemd/system/
install -o root -g root -m 0644 "$SRC/systemd/camera-rpi-maintenance-tmpfiles.conf" /etc/tmpfiles.d/camera-rpi-maintenance.conf
systemd-tmpfiles --create /etc/tmpfiles.d/camera-rpi-maintenance.conf
systemctl daemon-reload
((${#active[@]}==0)) || systemctl start "${active[@]}"
trap - EXIT INT TERM
echo "OK: Worker/Wetter/Wartungssoftware installiert; Sicherung $SAVE"
echo 'Tunnel noch deaktiviert. Chisel + private Registrierung separat installieren; Keys und Zielkonfigurationen unveraendert.'
