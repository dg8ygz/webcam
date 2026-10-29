#!/usr/bin/env bash
# Root on .2; config test + graceful reload, never an Apache restart.
set -euo pipefail
umask 077
[[ "$EUID" == 0 ]] || exit 1
SRC="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")/.." && pwd -P)"
(cd "$SRC" && sha256sum -c SHA256SUMS)
CONF=/etc/apache2/db0bi-proxy.conf
[[ ! -L "$CONF" ]]
[[ "$(sha256sum "$CONF" | awk '{print $1}')" == b5143ad568bbf2aee2958bcd753a795698da5557802bc97ed2e7b1488377f264 ]] || {
  echo 'Abbruch: Proxy-Konfiguration wurde seit Pruefung veraendert.' >&2; exit 1;
}
systemctl is-active --quiet apache2
SAVE="$(mktemp -d /root/proxy-command-before.XXXXXXXX)"
cp -a "$CONF" "$SAVE/db0bi-proxy.conf"
python3 -B "$SRC/ops/build-command-proxy.py" "$CONF" "$SAVE/proposed.conf"
printf 'Sicherung: %s\n' "$SAVE"
rollback() {
  local rc=$?
  trap - EXIT
  if (( rc != 0 )); then
    cp -a "$SAVE/db0bi-proxy.conf" "$CONF"
    apachectl configtest && systemctl reload apache2 || true
    echo 'Update fehlgeschlagen; Proxy-Konfiguration zurueckgenommen.' >&2
  fi
  exit "$rc"
}
trap rollback EXIT
trap 'exit 130' INT
trap 'exit 143' TERM
# Preserve original file owner/mode; do not write through an unknown symlink.
cp "$SAVE/proposed.conf" "$CONF"
apachectl configtest
systemctl reload apache2
systemctl is-active --quiet apache2
trap - EXIT INT TERM
echo 'OK: /webcam/include/command.php mit 710s; allgemeines Timeout und Upload-Regeln unveraendert.'
