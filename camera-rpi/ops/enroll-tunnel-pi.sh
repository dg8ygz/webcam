#!/usr/bin/env bash
# Only a locally provisioned target may be controlled by a remote lease.
set -euo pipefail
umask 077
[[ $EUID == 0 && $# == 3 && "$1" == --apply && "$2" =~ ^[a-z0-9]+(-[a-z0-9]+)*$ && ${#2} -le 64 ]] \
  || { echo 'Aufruf: enroll-tunnel-pi.sh --apply SERVER-ID PRIVATE-JSON'; exit 1; }
SRC="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")/.." && pwd -P)"
(cd "$SRC" && sha256sum -c SHA256SUMS)
[[ -f "/etc/camera-rpi/servers/$2.conf" && ! -e "/etc/camera-rpi/maintenance/$2.json" ]]
[[ -r /usr/local/libexec/camera-rpi-tunnel.py && -x /usr/local/bin/chisel ]]
[[ "$(/usr/local/bin/chisel --version)" == 1.11.5 ]]
grep -Eq '^server_base_url=https://' "/etc/camera-rpi/servers/$2.conf"
python3 -B - "$SRC/scripts/camera-rpi-tunnel.py" "$3" <<'PY'
import importlib.util, pathlib, sys
spec=importlib.util.spec_from_file_location('t', sys.argv[1]); t=importlib.util.module_from_spec(spec); spec.loader.exec_module(t)
c=t.config(pathlib.Path(sys.argv[2]))
assert 'REPLACE' not in c['auth'] and c['fingerprint'] != 'A'*43+'='
PY
SAVE="$(mktemp -d /root/camera-tunnel-enroll-before.XXXXXXXX)"
cp -a "/etc/camera-rpi/servers/$2.conf" "$SAVE/"
install -d -o root -g root -m 0700 /etc/camera-rpi/maintenance
install -o root -g root -m 0600 "$3" "/etc/camera-rpi/maintenance/$2.json"
python3 /usr/local/libexec/camera-rpi-tunnel.py check "$2"
systemd-tmpfiles --create /etc/tmpfiles.d/camera-rpi-maintenance.conf
if ! systemctl enable --now "camera-rpi-tunnel@$2.service"; then
  systemctl disable --now "camera-rpi-tunnel@$2.service" || true
  mv -- "/etc/camera-rpi/maintenance/$2.json" "$SAVE/failed-enrolment.json"
  exit 1
fi
echo "OK: Wartungsdienst registriert, standardmaessig geschlossen. Sicherung: $SAVE"
echo 'Erst nach frischem Command-Poll kann der Admin die 30-Minuten-Freigabe erteilen.'
