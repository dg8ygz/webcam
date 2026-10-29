#!/usr/bin/env bash
# First installation on relay host; existing installations intentionally refused.
set -euo pipefail
umask 077
[[ $EUID == 0 && $# == 3 && "$1" == --apply ]] || { echo 'Aufruf: install-tunnel-server.sh --apply PRIVATE-REGISTRATION EDGE-IP'; exit 1; }
SRC="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")/.." && pwd -P)"
# Verify the bundle and refuse existing installations: rollback may move only
# files created by this first install, never someone else's live credentials.
(cd "$SRC" && sha256sum -c SHA256SUMS)
for item in /etc/camera-rpi-tunnel-server /etc/systemd/system/camera-rpi-tunnel-server.service /etc/apache2/conf-available/camera-rpi-tunnel-origin.conf /usr/local/share/camera-rpi-tunnel-landing; do
  [[ ! -e "$item" ]] || { echo "Existiert bereits: $item" >&2; exit 1; }
done
[[ -f "$2/key" && -f "$2/users.json" && -x /usr/local/bin/chisel ]]
[[ "$(/usr/local/bin/chisel --version)" == 1.11.5 ]]
python3 - "$2/users.json" <<'PY'
# Accept one unique loopback reverse port per credential, no general forwarding.
import json, re, sys
data=json.load(open(sys.argv[1]))
assert data and isinstance(data,dict)
ports=set()
for auth, rules in data.items():
    assert re.fullmatch(r'[a-z0-9-]{1,64}:[A-Za-z0-9_-]{32,128}', auth)
    assert isinstance(rules,list) and len(rules)==1
    allowed = {'^R:127\\.0\\.0\\.1:'+str(port)+'$':port for port in range(22000,23000)}
    assert rules[0] in allowed and allowed[rules[0]] not in ports
    ports.add(allowed[rules[0]])
PY
apachectl -M 2>/dev/null | grep -q 'proxy_http_module'
apachectl -M 2>/dev/null | grep -q 'alias_module'
apachectl -M 2>/dev/null | grep -q 'setenvif_module'
apachectl -t
SAVE="$(mktemp -d /root/camera-tunnel-server-before.XXXXXXXX)"
cp -a /etc/apache2 "$SAVE/apache2"
python3 "$SRC/ops/build-tunnel-apache.py" origin "$3" > "$SAVE/origin.conf"
rollback() {
  # Restore availability after partial installation; retain removed files in
  # the private backup rather than deleting credentials or diagnostic evidence.
  local rc=$?
  trap - EXIT
  if ((rc != 0)); then
    systemctl disable --now camera-rpi-tunnel-server.service 2>/dev/null || true
    a2disconf camera-rpi-tunnel-origin 2>/dev/null || true
    for item in /etc/camera-rpi-tunnel-server /etc/systemd/system/camera-rpi-tunnel-server.service /etc/apache2/conf-available/camera-rpi-tunnel-origin.conf /usr/local/share/camera-rpi-tunnel-landing; do
      [[ ! -e "$item" ]] || mv -- "$item" "$SAVE/"
    done
    systemctl daemon-reload
    apachectl -t && systemctl reload apache2 || true
    echo "Abgebrochen; neue Dateien zurueckgenommen unter $SAVE" >&2
  fi
  exit "$rc"
}
trap rollback EXIT
trap 'exit 130' INT
trap 'exit 143' TERM
install -d -o root -g root -m 0700 /etc/camera-rpi-tunnel-server
# Static browser content is public-readable; relay credentials remain private.
install -d -o root -g root -m 0755 /usr/local/share/camera-rpi-tunnel-landing
install -o root -g root -m 0644 "$SRC/apache/tunnel-landing.html" /usr/local/share/camera-rpi-tunnel-landing/index.html
install -o root -g root -m 0600 "$2/key" "$2/users.json" /etc/camera-rpi-tunnel-server/
install -o root -g root -m 0644 "$SRC/systemd/camera-rpi-tunnel-server.service" /etc/systemd/system/
install -o root -g root -m 0644 "$SAVE/origin.conf" /etc/apache2/conf-available/camera-rpi-tunnel-origin.conf
a2enconf camera-rpi-tunnel-origin
# Validate before activation. Graceful Apache reload preserves existing traffic.
apachectl -t
systemctl daemon-reload
systemctl enable --now camera-rpi-tunnel-server.service
systemctl reload apache2
systemctl is-active --quiet camera-rpi-tunnel-server.service
systemctl is-active --quiet apache2
trap - EXIT INT TERM
echo "OK: Relay nur 127.0.0.1:9086, Apache nur vom angegebenen Edge; Sicherung $SAVE"
