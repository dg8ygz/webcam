#!/usr/bin/env bash
set -euo pipefail

if [[ "${EUID:-$(id -u)}" -ne 0 ]]; then
  printf 'Bitte als root ausführen.\n' >&2
  exit 1
fi

bundle_dir="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
service_user="webcam-helper"

for required_command in curl dd flock grep od sed sha256sum stat systemctl; do
  command -v "$required_command" >/dev/null \
    || { printf 'Benötigter Befehl fehlt: %s\n' "$required_command" >&2; exit 1; }
done

if ! getent group "$service_user" >/dev/null; then
  groupadd --system "$service_user"
fi
if ! id -u "$service_user" >/dev/null 2>&1; then
  useradd --system \
    --gid "$service_user" \
    --home-dir /var/lib/webcam-legacy-helper \
    --create-home \
    --shell /usr/sbin/nologin \
    "$service_user"
fi

install -d -o root -g "$service_user" -m 0750 /etc/webcam-legacy-helper
install -d -o "$service_user" -g "$service_user" -m 0700 /var/lib/webcam-legacy-helper
# systemd erzeugt spaeter pro Instanz ein eigenes Unterverzeichnis ueber
# RuntimeDirectory. Fuer den dokumentierten --once-Test vor dem ersten
# Dienststart muss das Basisverzeichnis aber bereits existieren und fuer den
# Dienstbenutzer beschreibbar sein.
install -d -o "$service_user" -g "$service_user" -m 0700 /run/webcam-legacy-helper
install -o root -g root -m 0755 \
  "$bundle_dir/webcam-legacy-helper.sh" \
  /usr/local/libexec/webcam-legacy-helper
install -o root -g root -m 0644 \
  "$bundle_dir/webcam-legacy-helper@.service" \
  /etc/systemd/system/webcam-legacy-helper@.service

if [[ ! -e /etc/webcam-legacy-helper/camera.conf.example ]]; then
  install -o root -g "$service_user" -m 0640 \
    "$bundle_dir/camera.conf.example" \
    /etc/webcam-legacy-helper/camera.conf.example
fi

systemctl daemon-reload

cat <<'EOF'
Helper installiert, aber noch nicht gestartet.

Für jede Kamera:
  1. camera.conf.example nach <slug>.conf kopieren und Werte eintragen.
  2. Rechte auf root:webcam-helper 0640 setzen.
  3. Konfiguration prüfen:
     sudo -u webcam-helper /usr/local/libexec/webcam-legacy-helper \
       --check-config /etc/webcam-legacy-helper/<slug>.conf
  4. Einmaligen echten Probelauf ausführen:
     sudo -u webcam-helper /usr/local/libexec/webcam-legacy-helper \
       --once /etc/webcam-legacy-helper/<slug>.conf
  5. Danach aktivieren:
     systemctl enable --now webcam-legacy-helper@<slug>.service
EOF
