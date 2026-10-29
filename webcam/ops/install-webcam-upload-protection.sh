#!/bin/bash

# Installiert die getrennten Request- und Verbindungslimits auf dem
# oeffentlichen Reverse Proxy. Das Skript veraendert keine Apache-Datei:
# Fail2Ban liest lediglich das bestehende Access-Log, waehrend eine eigene
# nftables-Tabelle fruehe TCP-Grenzen setzt.

set -euo pipefail

if [ "${EUID:-$(id -u)}" -ne 0 ]; then
  echo 'ABBRUCH: Dieses Installationsskript muss als root laufen.' >&2
  exit 1
fi

if [ "$#" -ne 1 ] || [ ! -d "$1" ]; then
  echo "Verwendung: $0 <gepruefter-Staging-Ordner>" >&2
  exit 1
fi

SOURCE_DIR="$(readlink -f -- "$1")"
INSTALL_OWNS_TARGETS=0
INSTALL_COMPLETE=0

FILTER_LEGACY='/etc/fail2ban/filter.d/webcam-upload-legacy-rate.conf'
FILTER_CHUNK='/etc/fail2ban/filter.d/webcam-upload-chunk-rate.conf'
JAIL_CONFIG='/etc/fail2ban/jail.d/webcam-upload-rate.local'
NFT_CONFIG='/etc/nftables.d/webcam-proxy-connection-guard.nft'
SERVICE_CONFIG='/etc/systemd/system/webcam-proxy-connection-guard.service'

TARGETS=(
  "$FILTER_LEGACY"
  "$FILTER_CHUNK"
  "$JAIL_CONFIG"
  "$NFT_CONFIG"
  "$SERVICE_CONFIG"
)

# Bei einem Fehler nach der ersten Installation werden nur die hier neu
# angelegten Namen entfernt. Vorhandene Dateien werden weiter unten bewusst
# abgelehnt und koennen deshalb niemals durch diesen Rueckweg verloren gehen.
rollback_incomplete_install() {
  if [ "$INSTALL_OWNS_TARGETS" -eq 1 ] && [ "$INSTALL_COMPLETE" -eq 0 ]; then
    echo 'FEHLER: unvollstaendige Installation wird zurueckgerollt.' >&2
    systemctl disable --now \
      webcam-proxy-connection-guard.service >/dev/null 2>&1 || true
    /usr/sbin/nft delete table inet webcam_proxy_guard >/dev/null 2>&1 || true
    rm -f -- "${TARGETS[@]}"
    systemctl daemon-reload || true
    fail2ban-client reload >/dev/null 2>&1 || true
  fi
}
trap rollback_incomplete_install EXIT

# Ohne diese Werkzeuge koennte nur ein Teil der Schutzkette installiert
# werden. Deshalb endet der Lauf vor jeder Aenderung, sobald etwas fehlt.
for REQUIRED_COMMAND in \
  fail2ban-client fail2ban-regex install nft readlink sha256sum \
  systemctl systemd-analyze
do
  command -v "$REQUIRED_COMMAND" >/dev/null || {
    echo "ABBRUCH: benoetigtes Programm fehlt: $REQUIRED_COMMAND" >&2
    exit 1
  }
done

systemctl is-active --quiet fail2ban || {
  echo 'ABBRUCH: fail2ban ist nicht aktiv.' >&2
  exit 1
}

# Die Hashliste bindet den Installer an genau die gemeinsam getesteten
# Vorlagen. Abweichende oder unvollstaendig uebertragene Dateien werden nicht
# mit Root-Rechten in die Systemkonfiguration kopiert.
(
  cd "$SOURCE_DIR"
  printf '%s  %s\n' \
    '1db5766059b8efff69d571edcdd51015c36aaf19211b88a9747dba77d8c1416d' \
    'fail2ban-webcam-upload-legacy-rate.conf.example' \
    '4e66f8a89ee374899346b7398fc02d7a64a1122d9a936a794afb9c33b3383a65' \
    'fail2ban-webcam-upload-chunk-rate.conf.example' \
    '6a4183588ae57390f4266826ab9e6da14e29e3b075f6e5226c5dd22bbeb5aeec' \
    'fail2ban-webcam-upload-rate.local.example' \
    'fea8fa14fcae184717825e597408bf69afed2f3406504e1efb4ab9832aa9c642' \
    'nftables-webcam-proxy-connection-guard.nft.example' \
    '53f0fe068fd652299d88223ddc21705e0fc53ce216f8be9f5242959ae86d1795' \
    'webcam-proxy-connection-guard.service' |
  sha256sum -c -
)

for TARGET in "${TARGETS[@]}"; do
  if [ -e "$TARGET" ]; then
    echo "ABBRUCH: Ziel ist bereits vorhanden: $TARGET" >&2
    exit 1
  fi
done

if /usr/sbin/nft list table inet webcam_proxy_guard >/dev/null 2>&1; then
  echo 'ABBRUCH: nftables-Tabelle webcam_proxy_guard ist bereits vorhanden.' >&2
  exit 1
fi

# Die beiden Beispielzeilen pruefen nicht nur Regex-Syntax, sondern auch das
# besondere Fail2Ban-Verhalten: Der erkannte Apache-Zeitstempel wird vor dem
# eigentlichen Filterabgleich entfernt. Soll sind je ein Treffer, kein Miss.
LEGACY_REGEX_RESULT="$(
  fail2ban-regex \
    '192.0.2.10 - - [05/Sep/2026:22:00:00 +0200] "POST /webcam/include/upload.php HTTP/1.1" 403 12 "-" "camera"' \
    "$SOURCE_DIR/fail2ban-webcam-upload-legacy-rate.conf.example"
)"
printf '%s\n' "$LEGACY_REGEX_RESULT"
grep -Fq '1 matched, 0 missed' <<< "$LEGACY_REGEX_RESULT" || {
  echo 'ABBRUCH: Legacy-Filter bestand seinen Beispieltest nicht.' >&2
  exit 1
}

CHUNK_REGEX_RESULT="$(
  fail2ban-regex \
    '192.0.2.10 - - [05/Sep/2026:22:00:00 +0200] "POST /webcam2/include/upload-chunk.php?action=chunk HTTP/1.1" 200 42 "-" "camera"' \
    "$SOURCE_DIR/fail2ban-webcam-upload-chunk-rate.conf.example"
)"
printf '%s\n' "$CHUNK_REGEX_RESULT"
grep -Fq '1 matched, 0 missed' <<< "$CHUNK_REGEX_RESULT" || {
  echo 'ABBRUCH: Chunk-Filter bestand seinen Beispieltest nicht.' >&2
  exit 1
}

/usr/sbin/nft -c -f \
  "$SOURCE_DIR/nftables-webcam-proxy-connection-guard.nft.example"
systemd-analyze verify \
  "$SOURCE_DIR/webcam-proxy-connection-guard.service"

# Erst nach allen read-only Vorpruefungen werden die neuen Zielnamen
# angelegt. Der EXIT-Trap besitzt sie ab diesem Punkt und entfernt sie bei
# jedem nachfolgenden Fehler automatisch wieder.
INSTALL_OWNS_TARGETS=1

install -o root -g root -m 0644 \
  "$SOURCE_DIR/fail2ban-webcam-upload-legacy-rate.conf.example" \
  "$FILTER_LEGACY"

install -o root -g root -m 0644 \
  "$SOURCE_DIR/fail2ban-webcam-upload-chunk-rate.conf.example" \
  "$FILTER_CHUNK"

install -o root -g root -m 0644 \
  "$SOURCE_DIR/fail2ban-webcam-upload-rate.local.example" \
  "$JAIL_CONFIG"

install -d -o root -g root -m 0755 /etc/nftables.d
install -o root -g root -m 0644 \
  "$SOURCE_DIR/nftables-webcam-proxy-connection-guard.nft.example" \
  "$NFT_CONFIG"

install -o root -g root -m 0644 \
  "$SOURCE_DIR/webcam-proxy-connection-guard.service" \
  "$SERVICE_CONFIG"

# fail2ban-client -t liest jetzt die komplette reale Konfiguration inklusive
# der neuen Jails. Erst danach werden Tabelle, Boot-Aktivierung und Jails
# laufend geschaltet; Apache selbst bleibt dabei unangetastet.
fail2ban-client -t
systemctl daemon-reload
systemctl enable --now webcam-proxy-connection-guard.service
fail2ban-client reload

fail2ban-client status webcam-upload-legacy-rate >/dev/null
fail2ban-client status webcam-upload-chunk-rate >/dev/null
systemctl is-active --quiet webcam-proxy-connection-guard.service
/usr/sbin/nft list table inet webcam_proxy_guard >/dev/null

INSTALL_COMPLETE=1
trap - EXIT

echo 'OK: Rate- und Verbindungsschutz ist aktiv.'
echo 'Es wurde kein Apache-Neustart ausgefuehrt.'
