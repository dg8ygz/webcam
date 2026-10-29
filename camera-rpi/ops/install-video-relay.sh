#!/usr/bin/env bash
# DEV first install only. Existing maintenance relay/credentials stay untouched.
set -euo pipefail
umask 077
[[ $EUID == 0 && $# == 2 && $1 == --apply ]]
SRC=$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")/.." && pwd -P)
PRIVATE=$(realpath "$2")
[[ ! -e /etc/camera-rpi-video-relay ]]
[[ ! -e /etc/systemd/system/camera-rpi-video-relay.service && ! -e /etc/systemd/system/camera-rpi-video-state.service ]]
[[ ! -e /etc/apache2/conf-available/camera-rpi-video-origin.conf ]]
[[ -f $PRIVATE/key && -f $PRIVATE/users.json && -f $PRIVATE/registration.json ]]
[[ -x /usr/local/bin/chisel ]]
# Refuse permissive forwarding rules or collisions before publishing anything.
! ss -H -lnt 'sport = :9087' | grep -q .
php -r '
  $u = json_decode(file_get_contents($argv[1]), true, 16, JSON_THROW_ON_ERROR);
  $r = json_decode(file_get_contents($argv[2]), true, 16, JSON_THROW_ON_ERROR);
  if (!is_array($u) || count($u) !== 1 || !preg_match("/^[a-z0-9-]+:[a-zA-Z0-9_-]{32,128}$/D", (string) array_key_first($u))
    || array_values($u)[0] !== ["^" . str_replace(".", chr(92) . ".", "R:127.0.0.1:23001") . "$" ]
    || !is_array($r) || count($r) !== 2 || ($r["port"] ?? null) !== 23001
    || !is_string($r["token"] ?? null) || !preg_match("/^[a-f0-9]{64}$/D", $r["token"])) exit(1);
' "$PRIVATE/users.json" "$PRIVATE/registration.json"
apachectl -t
SAVE=$(mktemp -d /root/camera-video-relay-before.XXXXXXXX)
cp -a /etc/apache2 "$SAVE/apache2"
rollback() {
  rc=$?; trap - EXIT
  if ((rc != 0)); then
    systemctl disable --now camera-rpi-video-relay.service camera-rpi-video-state.service 2>/dev/null || true
    a2disconf camera-rpi-video-origin 2>/dev/null || true
    for p in /etc/camera-rpi-video-relay /etc/systemd/system/camera-rpi-video-relay.service /etc/systemd/system/camera-rpi-video-state.service /etc/apache2/conf-available/camera-rpi-video-origin.conf; do
      [[ ! -e $p ]] || mv "$p" "$SAVE/"
    done
    systemctl daemon-reload
    apachectl -t && systemctl reload apache2 || true
    echo "Video-Neuinstallation zurueckgenommen: $SAVE" >&2
  fi
  exit "$rc"
}
trap rollback EXIT
install -d -o root -g root -m 0700 /etc/camera-rpi-video-relay
install -o root -g root -m 0600 "$PRIVATE/key" "$PRIVATE/users.json" "$PRIVATE/registration.json" /etc/camera-rpi-video-relay/
install -o root -g root -m 0644 "$SRC/systemd/camera-rpi-video-relay.service" "$SRC/systemd/camera-rpi-video-state.service" /etc/systemd/system/
install -o root -g root -m 0644 "$SRC/apache/camera-rpi-video-origin.conf" /etc/apache2/conf-available/
a2enconf camera-rpi-video-origin
apachectl -t
systemctl daemon-reload
systemctl enable --now camera-rpi-video-state.service camera-rpi-video-relay.service
systemctl reload apache2
systemctl is-active --quiet camera-rpi-video-relay.service
trap - EXIT
echo "OK: Separater DEV-Videorelay und RAM-Cache. Sicherung: $SAVE"
echo 'Kein Pi gestartet, keine Live-DB geaendert. Wartungs-SSH bleibt unveraendert.'
