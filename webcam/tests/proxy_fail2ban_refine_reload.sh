#!/bin/bash
# Vorher/nachher-Filterschichten, gezielter Reload und Rueckweg isoliert testen.
# Keine echten Logs, System-Sockets, nftables-Aktionen oder HTTP-Probes.
set -Eeuo pipefail
export LC_ALL=C
umask 077
BEFORE="$(realpath -e -- "${1:?before-Verzeichnis fehlt}")"
STAGE="$(realpath -e -- "${2:?stage-Verzeichnis fehlt}")"
WORK="$(mktemp -d /tmp/webcam-f2b-refine-reload.XXXXXXXX)"
CONFIG="$WORK/config"
mkdir -p "$CONFIG/filter.d" "$CONFIG/action.d" "$WORK/withdrawn"
client() { fail2ban-client -c "$CONFIG" -s "$WORK/socket" -p "$WORK/pid" "$@"; }
cleanup() { if [ -S "$WORK/socket" ]; then client stop; fi; }
trap cleanup EXIT
trap 'exit 130' INT
trap 'exit 143' TERM
printf 'Isolierter Testordner: %s\n' "$WORK"
cp "$BEFORE/fail2ban.conf" "$CONFIG/fail2ban.conf"
cp "$BEFORE/action.d/dummy.conf" "$CONFIG/action.d/dummy.conf"
JAILS=(apache-backup-dumps apache-wp-probe apache-env apache-vhost-secrets)
FILTERS=(apache-backup-dumps apache-wp-probe apache-env-only apache-vhost-secrets)
for FILTER in "${FILTERS[@]}"; do
  cp "$BEFORE/filter.d/$FILTER.conf" "$CONFIG/filter.d/$FILTER.conf"
done
cp "$BEFORE/filter.d/apache-wp-probe.local" "$CONFIG/filter.d/apache-wp-probe.local"
cat > "$CONFIG/fail2ban.local" <<CONFIGURATION
[Definition]
logtarget = $WORK/server.log
dbfile = None
CONFIGURATION
cat > "$CONFIG/jail.conf" <<CONFIGURATION
[DEFAULT]
enabled = true
backend = polling
usedns = no
ignoreip =
findtime = 600
maxretry = 2
bantime = 3600
action = dummy[target=$WORK/dummy-%(__name__)s.log]
CONFIGURATION
for INDEX in "${!JAILS[@]}"; do
  JAIL="${JAILS[$INDEX]}"
  : > "$WORK/$JAIL.log"
  printf '\n[%s]\nfilter = %s\nlogpath = %s/%s.log\n' \
    "$JAIL" "${FILTERS[$INDEX]}" "$WORK" "$JAIL" >> "$CONFIG/jail.conf"
done
client -t
client start
wait_banned() {
  local jail="$1" ip="$2"
  for ATTEMPT in {1..150}; do
    [ "$(client get "$jail" banned "$ip")" = 1 ] && return 0
    sleep 0.1
  done
  printf 'FEHLER: Ban fehlt in %s: %s\n' "$jail" "$ip" >&2
  return 1
}
for JAIL in "${JAILS[@]}"; do
  client set "$JAIL" banip 192.0.2.10
  wait_banned "$JAIL" 192.0.2.10
done
# Uebernahme aller neuen Filter, anschliessend ausschliesslich Reload.
for FILTER in "${FILTERS[@]}"; do
  cp "$STAGE/filter.d/$FILTER.local" "$CONFIG/filter.d/$FILTER.local"
done
client -t
for JAIL in "${JAILS[@]}"; do
  client reload "$JAIL"
  wait_banned "$JAIL" 192.0.2.10
done
append_line() {
  printf '%s - - [%s] "GET %s HTTP/1.1" %s 123 "-" "test"\n' \
    "$2" "$(date '+%d/%b/%Y:%H:%M:%S %z')" "$3" "$4" >> "$WORK/$1.log"
}
PATHS=(/backup.zip /wp-content/plugins/plugins/cache.php /%2eENV /nested/%252ehtpasswd)
for INDEX in "${!JAILS[@]}"; do
  JAIL="${JAILS[$INDEX]}"
  # Harmlose Joomla-Zeilen duerfen trotz Fehlerstatus keinen Ban ausloesen.
  for ROUND in 1 2 3; do append_line "$JAIL" 203.0.113.20 /joomla/index.php/blogg/blog 403; done
  for ROUND in 1 2; do append_line "$JAIL" 198.51.100.20 "${PATHS[$INDEX]}" 403; done
done
for JAIL in "${JAILS[@]}"; do
  wait_banned "$JAIL" 198.51.100.20
  [ "$(client get "$JAIL" banned 203.0.113.20)" = 0 ]
  for ATTEMPT in {1..40}; do
    if grep -Fxq '+198.51.100.20' "$WORK/dummy-$JAIL.log"; then break; fi
    sleep 0.1
  done
  grep -Fxq '+198.51.100.20' "$WORK/dummy-$JAIL.log"
  grep -Fxq '+192.0.2.10' "$WORK/dummy-$JAIL.log"
done
# Rueckweg: nur neue Overlays zuruecknehmen, WP-Overlay wiederherstellen.
for FILTER in "${FILTERS[@]}"; do
  mv "$CONFIG/filter.d/$FILTER.local" "$WORK/withdrawn/$FILTER.local"
done
cp "$BEFORE/filter.d/apache-wp-probe.local" "$CONFIG/filter.d/apache-wp-probe.local"
client -t
for JAIL in "${JAILS[@]}"; do
  client reload "$JAIL"
  wait_banned "$JAIL" 192.0.2.10
  wait_banned "$JAIL" 198.51.100.20
  [ "$(client get "$JAIL" banned 203.0.113.20)" = 0 ]
  # Reload/Rueckweg duerfen weder alte noch gerade hinzugefuegte Bans loesen.
  if grep -Eq '^-(192\.0\.2\.10|198\.51\.100\.20)$' "$WORK/dummy-$JAIL.log"; then exit 1; fi
done
if grep -Eq '\b(WARNING|ERROR|CRITICAL)\b' "$WORK/server.log"; then
  printf 'FEHLER: Warnung/Fehler im isolierten Serverlog.\n' >&2
  exit 1
fi
printf 'OK: Vier Filter live nachgeladen, neue Probes gebannt, Joomla unberuehrt; Bans bei Reload und Rueckweg erhalten.\n'
