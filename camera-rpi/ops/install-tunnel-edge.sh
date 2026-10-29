#!/usr/bin/env bash
# Operator root on edge only. Exact known include; no blanket server restart.
set -euo pipefail
umask 077
[[ $EUID == 0 && $# == 2 && "$1" == --apply ]] || { echo 'Aufruf: install-tunnel-edge.sh --apply ORIGIN-IP'; exit 1; }
SRC="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")/.." && pwd -P)"
(cd "$SRC" && sha256sum -c SHA256SUMS)
CONFIG=/etc/apache2/db0bi-proxy.conf
INCLUDE=/etc/apache2/camera-rpi-tunnel.inc
[[ -f "$CONFIG" && ! -L "$CONFIG" && ! -e "$INCLUDE" ]]
apachectl -M 2>/dev/null | grep -q 'proxy_http_module'
apachectl -t
SAVE="$(mktemp -d /root/camera-tunnel-edge-before.XXXXXXXX)"
cp -a "$CONFIG" "$SAVE/"
python3 "$SRC/ops/build-tunnel-apache.py" edge "$2" > "$SAVE/new.inc"
rollback() {
  local rc=$?
  trap - EXIT
  if ((rc != 0)); then
    cp -a "$SAVE/db0bi-proxy.conf" "$CONFIG"
    if [[ -f "$INCLUDE" ]]; then mv -- "$INCLUDE" "$SAVE/failed.inc"; fi
    apachectl -t && systemctl reload apache2 || true
    echo "Abgebrochen. Sicherung: $SAVE" >&2
  fi
  exit "$rc"
}
trap rollback EXIT
trap 'exit 130' INT
trap 'exit 143' TERM
install -o root -g root -m 0644 "$SAVE/new.inc" "$INCLUDE"
printf '\nInclude camera-rpi-tunnel.inc\n' >> "$CONFIG"
apachectl -t
systemctl reload apache2
systemctl is-active --quiet apache2
trap - EXIT INT TERM
echo "OK: HTTPS-WebSocket-Pfad geladen; Sicherung: $SAVE"
