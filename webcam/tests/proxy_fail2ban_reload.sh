#!/bin/bash
# Isolierter nativer Fail2Ban-Test fuer ops/extend-proxy-fail2ban.sh.
# Argument: dessen --check-Arbeitskopie (stage/). KEINE echte Firewallaktion,
# kein Zugriff auf den System-Socket oder aktive Logs. Dummy-Aktion schreibt
# nur im privaten Testordner. Der Testserver wird auch im Fehlerfall gestoppt.
set -Eeuo pipefail
export LC_ALL=C
umask 077
STAGE="$(realpath -e -- "${1:?stage-Verzeichnis fehlt}")"
WORK="$(mktemp -d /tmp/webcam-f2b-reload-test.XXXXXXXX)"
CONFIG="$WORK/config"
mkdir -p "$CONFIG/filter.d" "$CONFIG/jail.d" "$CONFIG/action.d"
client() { fail2ban-client -c "$CONFIG" -s "$WORK/socket" -p "$WORK/pid" "$@"; }
cleanup() {
  if [ -S "$WORK/socket" ]; then client stop; fi
}
trap cleanup EXIT
trap 'exit 130' INT
trap 'exit 143' TERM
printf 'Isolierter Testordner: %s\n' "$WORK"
cp "$STAGE/fail2ban.conf" "$CONFIG/fail2ban.conf"
cp "$STAGE/action.d/dummy.conf" "$CONFIG/action.d/dummy.conf"
cp "$STAGE/filter.d/apache-wp-probe.conf" "$CONFIG/filter.d/apache-wp-probe.conf"
cp "$STAGE/jail.d/apache-wp-probe.local" "$CONFIG/jail.d/apache-wp-probe.local"
cat > "$CONFIG/fail2ban.local" <<CONFIGURATION
[Definition]
logtarget = $WORK/server.log
dbfile = None
CONFIGURATION
cat > "$CONFIG/jail.conf" <<'CONFIGURATION'
[DEFAULT]
enabled = false
backend = polling
usedns = no
CONFIGURATION
for NAME in access arx_access cmarx_access cmhab_access cmxdl_access dk0re_access fhem_access ftui_access; do
  : > "$WORK/$NAME.log"
done
# Nur die separaten Testjails lesen den Testbaum und nutzen dummy; der Inhalt
# der Produktionsaktionen wird hier bewusst NICHT als Firewalltest ausgegeben.
prepare_test_jail() {
  TEST_WORK="$WORK" perl -pi -e '
    s@/var/log/apache2/@$ENV{TEST_WORK}/@g;
    s/^backend\s*=.*/backend = polling/;
    s/^action\s*=.*/action = dummy[target=$ENV{TEST_WORK}\/dummy-%(__name__)s.log]/;
  ' "$1"
}
prepare_test_jail "$CONFIG/jail.d/apache-wp-probe.local"
client -t
client start
client set apache-wp-probe banip 192.0.2.10
wait_banned() {
  local jail="$1" ip="$2"
  # Das native Polling kann die erste Dateiaenderung erst nach rund zehn
  # Sekunden sehen. Begrenzt warten, statt einen asynchronen Ban zu ueberholen.
  for ATTEMPT in {1..150}; do
    [ "$(client get "$jail" banned "$ip")" = 1 ] && return 0
    sleep 0.1
  done
  printf 'FEHLER: Testban fehlt in %s\n' "$jail" >&2
  return 1
}
wait_banned apache-wp-probe 192.0.2.10
cp "$STAGE/filter.d/apache-wp-probe.local" "$CONFIG/filter.d/apache-wp-probe.local"
cp "$STAGE/filter.d/apache-vhost-secrets.conf" "$CONFIG/filter.d/apache-vhost-secrets.conf"
cp "$STAGE/jail.d/apache-vhost-secrets.local" "$CONFIG/jail.d/apache-vhost-secrets.local"
prepare_test_jail "$CONFIG/jail.d/apache-vhost-secrets.local"
append_secret_probe() {
  printf '198.51.100.10 - - [%s] "GET /.env HTTP/1.1" 401 123 "-" "test"\n' \
    "$(date '+%d/%b/%Y:%H:%M:%S %z')" >> "$WORK/ftui_access.log"
}
# Diese schon vorhandenen Zeilen duerfen wegen logpath tail nicht bannen.
append_secret_probe
append_secret_probe
client -t
client reload --if-exists apache-vhost-secrets
client reload apache-wp-probe
wait_banned apache-wp-probe 192.0.2.10
sleep 1
[ "$(client get apache-vhost-secrets banned 198.51.100.10)" = 0 ]
client get apache-vhost-secrets logpath
client get apache-wp-probe datepattern
append_secret_probe
append_secret_probe
wait_banned apache-vhost-secrets 198.51.100.10
for TEST_CASE in apache-wp-probe:192.0.2.10 apache-vhost-secrets:198.51.100.10; do
  for ATTEMPT in {1..40}; do
    if grep -Fxq "+${TEST_CASE#*:}" "$WORK/dummy-${TEST_CASE%%:*}.log" 2>/dev/null; then break; fi
    sleep 0.1
  done
  grep -Fxq "+${TEST_CASE#*:}" "$WORK/dummy-${TEST_CASE%%:*}.log"
done
client status apache-wp-probe
client status apache-vhost-secrets
# Auch beim Rueckweg darf der alte WP-Ban nicht verloren gehen.
client stop apache-vhost-secrets
mv "$CONFIG/filter.d/apache-wp-probe.local" "$WORK/withdrawn-wp.local"
client reload apache-wp-probe
wait_banned apache-wp-probe 192.0.2.10
if grep -Eq '\b(WARNING|ERROR|CRITICAL)\b' "$WORK/server.log"; then
  printf 'FEHLER: Aktions-/Serverfehler im isolierten Testlog.\n' >&2
  exit 1
fi
printf 'OK: Neuer Jail gezielt geladen, tail respektiert, neue Treffer gebannt, WP-Ban bei Reload und Rueckweg erhalten.\n'
