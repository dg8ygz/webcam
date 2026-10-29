#!/usr/bin/env bash

# Uebertraegt den geprueften Git-Stand auf einen Kamera-Pi und installiert dort
# die benoetigten Pakete und Camera-RPi-Dateien.
#
# Sicherheitsprinzipien:
# - Das Root- und optionale Admin-Passwort werden verdeckt gelesen.
# - Kein Passwort steht in argv, Git, Logausgaben oder temporaeren Dateien.
# - Der SSH-Hostkey wird gespeichert und bei Aenderung abgelehnt.
# - Nur ein sauberer, bereits committeter Git-Stand wird uebertragen.
# - Root und Boot werden nur in per Trap geschuetzten Wartungsfenstern rw.
# - Bestehende Serverkonfigurationen, Keys und Spoolbilder werden nicht geloescht.

set -euo pipefail
umask 077

PROJECT_DIR="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd -P)"
TARGET_HOST=""
TARGET_USER="root"
TARGET_PORT=22
PASSWORD_FILE=""
WEB_ADMIN_USER=""
WITH_WEB=0
SKIP_PACKAGES=0
ASSUME_YES=0
DRY_RUN=0
REMOTE_STAGE=""
DEPLOY_PASSWORD=""
ADMIN_PASSWORD=""

usage() {
  cat <<'EOF'
Aufruf:
  ./deploy-to-camera-rpi.sh
  ./deploy-to-camera-rpi.sh --host 192.0.2.10 --user root
  ./deploy-to-camera-rpi.sh --host 192.0.2.10 --with-web cameraadmin

Optionen:
  --host IP-ODER-DNS       Zieladresse; ohne Option erfolgt eine Rueckfrage
  --user NAME              SSH-Konto mit echter UID 0 (Standard: root)
  --port PORT              SSH-Port (Standard: 22)
  --password-file DATEI    private Datei mit genau einer Passwortzeile
                           (sicherer fuer unbeaufsichtigte Ausfuehrung)
  --with-web ADMINNAME     lokale Admin-Webseite samt Apache/PHP installieren
  --skip-packages          APT-Schritt ueberspringen, wenn alles vorhanden ist
  --yes                    Sicherheitsabfrage automatisch bestaetigen
  --dry-run                geplante Schritte zeigen, nichts verbinden/aendern
  -h, --help               diese Hilfe anzeigen

Ein Passwort als --password ist absichtlich nicht moeglich, weil es sonst in
Shell-History und Prozesslisten sichtbar waere.
EOF
}

need_value() {
  local option="$1"
  local value="${2:-}"
  [[ -n "$value" && "$value" != -* ]] \
    || { printf '%s benoetigt einen Wert.\n' "$option" >&2; exit 2; }
}

while (($# > 0)); do
  case "$1" in
    --host) need_value "$1" "${2:-}"; TARGET_HOST="$2"; shift ;;
    --user) need_value "$1" "${2:-}"; TARGET_USER="$2"; shift ;;
    --port) need_value "$1" "${2:-}"; TARGET_PORT="$2"; shift ;;
    --password-file) need_value "$1" "${2:-}"; PASSWORD_FILE="$2"; shift ;;
    --with-web) need_value "$1" "${2:-}"; WITH_WEB=1; WEB_ADMIN_USER="$2"; shift ;;
    --skip-packages) SKIP_PACKAGES=1 ;;
    --yes) ASSUME_YES=1 ;;
    --dry-run) DRY_RUN=1 ;;
    -h|--help) usage; exit 0 ;;
    *) printf 'Unbekannte Option: %s\n' "$1" >&2; usage >&2; exit 2 ;;
  esac
  shift
done

if [[ -z "$TARGET_HOST" ]]; then
  [[ -t 0 ]] || { printf 'Ohne Terminal ist --host erforderlich.\n' >&2; exit 2; }
  read -r -p 'IP-Adresse oder DNS-Name des Kamera-Pi: ' TARGET_HOST
fi
[[ "$TARGET_HOST" =~ ^[A-Za-z0-9.:%_-]+$ && "$TARGET_HOST" != -* ]] \
  || { printf 'Ungueltige Zieladresse.\n' >&2; exit 2; }
[[ "$TARGET_USER" =~ ^[a-z_][a-z0-9_-]{0,31}$ ]] \
  || { printf 'Ungueltiger SSH-Benutzer.\n' >&2; exit 2; }
[[ "$TARGET_PORT" =~ ^[0-9]+$ ]] && (( TARGET_PORT >= 1 && TARGET_PORT <= 65535 )) \
  || { printf 'Ungueltiger SSH-Port.\n' >&2; exit 2; }
if (( WITH_WEB == 1 )); then
  [[ "$WEB_ADMIN_USER" =~ ^[A-Za-z0-9._-]{1,64}$ ]] \
    || { printf 'Ungueltiger Admin-Benutzername.\n' >&2; exit 2; }
fi

for local_program in git ssh sshpass tar; do
  command -v "$local_program" >/dev/null \
    || { printf 'Lokales Programm fehlt: %s\n' "$local_program" >&2; \
         printf 'Auf Debian: apt-get install openssh-client sshpass git tar\n' >&2; exit 1; }
done

git -C "$PROJECT_DIR" rev-parse --is-inside-work-tree >/dev/null \
  || { printf 'Camera-RPi ist kein Git-Repository.\n' >&2; exit 1; }
[[ -z "$(git -C "$PROJECT_DIR" status --porcelain=v1)" ]] \
  || { printf 'Das Repository enthaelt nicht committete Aenderungen. Erst pruefen und committen.\n' >&2; exit 1; }
DEPLOY_COMMIT="$(git -C "$PROJECT_DIR" rev-parse HEAD)"

printf 'Geplanter Zielhost : %s@%s:%s\n' "$TARGET_USER" "$TARGET_HOST" "$TARGET_PORT"
printf 'Git-Stand          : %s\n' "$DEPLOY_COMMIT"
printf 'Zielpakete         : %s\n' "$([[ $SKIP_PACKAGES == 1 ]] && printf 'ueberspringen' || printf 'installieren/pruefen')"
printf 'Admin-Webseite     : %s\n' "$([[ $WITH_WEB == 1 ]] && printf 'ja, Benutzer %s' "$WEB_ADMIN_USER" || printf 'nein')"
printf 'Produktive Worker  : werden nicht automatisch angelegt oder aktiviert\n'

if (( DRY_RUN == 1 )); then
  printf 'Dry-run beendet: keine Verbindung und keine Aenderung.\n'
  exit 0
fi

if (( ASSUME_YES == 0 )); then
  [[ -t 0 ]] || { printf 'Ohne Terminal ist --yes erforderlich.\n' >&2; exit 2; }
  read -r -p 'Diesen Stand auf den Kamera-Pi uebertragen? Tippe JA: ' confirmation
  [[ "$confirmation" == "JA" ]] || { printf 'Abgebrochen.\n'; exit 0; }
fi

read_password_file() {
  local file="$1"
  local mode
  local owner_uid
  local -a password_lines=()
  [[ -f "$file" && -r "$file" ]] || { printf 'Passwortdatei nicht lesbar.\n' >&2; exit 1; }
  mode="$(stat -c '%a' "$file")"
  owner_uid="$(stat -c '%u' "$file")"
  [[ "$mode" == "600" || "$mode" == "400" ]] \
    || { printf 'Passwortdatei muss Modus 0600 oder 0400 besitzen.\n' >&2; exit 1; }
  (( owner_uid == EUID )) \
    || { printf 'Passwortdatei muss dem ausfuehrenden Benutzer gehoeren.\n' >&2; exit 1; }
  mapfile -t password_lines < "$file"
  ((${#password_lines[@]} == 1)) \
    || { printf 'Passwortdatei muss genau eine Zeile enthalten.\n' >&2; exit 1; }
  DEPLOY_PASSWORD="${password_lines[0]}"
  unset password_lines
}

if [[ -n "$PASSWORD_FILE" ]]; then
  read_password_file "$PASSWORD_FILE"
else
  [[ -t 0 ]] || { printf 'Ohne Terminal ist --password-file erforderlich.\n' >&2; exit 2; }
  read -r -s -p "SSH-Passwort fuer $TARGET_USER@$TARGET_HOST: " DEPLOY_PASSWORD
  printf '\n'
fi
[[ -n "$DEPLOY_PASSWORD" && "$DEPLOY_PASSWORD" != *$'\n'* && "$DEPLOY_PASSWORD" != *$'\r'* ]] \
  || { unset DEPLOY_PASSWORD; printf 'Leeres oder mehrzeiliges Passwort ist nicht erlaubt.\n' >&2; exit 1; }

if (( WITH_WEB == 1 )); then
  [[ -t 0 ]] || { unset DEPLOY_PASSWORD; printf 'Admin-Passwort benoetigt ein Terminal.\n' >&2; exit 2; }
  read -r -s -p "Neues HTTP-Passwort fuer $WEB_ADMIN_USER: " ADMIN_PASSWORD
  printf '\n'
  read -r -s -p 'HTTP-Passwort wiederholen: ' admin_password_repeat
  printf '\n'
  [[ "$ADMIN_PASSWORD" == "$admin_password_repeat" ]] \
    || { unset DEPLOY_PASSWORD ADMIN_PASSWORD admin_password_repeat; printf 'HTTP-Passwoerter stimmen nicht ueberein.\n' >&2; exit 1; }
  unset admin_password_repeat
  [[ ${#ADMIN_PASSWORD} -ge 12 && "$ADMIN_PASSWORD" != *$'\n'* && "$ADMIN_PASSWORD" != *$'\r'* ]] \
    || { unset DEPLOY_PASSWORD ADMIN_PASSWORD; printf 'HTTP-Passwort muss mindestens 12 Zeichen lang sein.\n' >&2; exit 1; }
fi

SSH_OPTIONS=(
  -p "$TARGET_PORT"
  -o BatchMode=no
  -o ConnectTimeout=15
  -o ConnectionAttempts=1
  -o NumberOfPasswordPrompts=1
  -o PreferredAuthentications=password,keyboard-interactive
  -o PubkeyAuthentication=no
  -o StrictHostKeyChecking=accept-new
  -o LogLevel=ERROR
)
REMOTE_NAME="$TARGET_USER@$TARGET_HOST"

remote_ssh() {
  SSHPASS="$DEPLOY_PASSWORD" sshpass -e ssh "${SSH_OPTIONS[@]}" "$REMOTE_NAME" "$@"
}

cleanup() {
  local result=$?
  trap - EXIT HUP INT TERM
  if [[ -n "$REMOTE_STAGE" && "$REMOTE_STAGE" =~ ^/tmp/camera-rpi-deploy\.[A-Za-z0-9]+$ \
      && -n "${DEPLOY_PASSWORD:-}" ]]; then
    remote_ssh "rm -rf -- '$REMOTE_STAGE'" >/dev/null 2>&1 || true
  fi
  unset DEPLOY_PASSWORD ADMIN_PASSWORD SSHPASS
  exit "$result"
}
trap cleanup EXIT HUP INT TERM

printf 'Pruefe Root-Login und read-only-Ausgangszustand ...\n'
preflight="$(remote_ssh bash -s <<'REMOTE_PREFLIGHT'
set -euo pipefail
printf 'REMOTE_UID=%s\n' "$(id -u)"
printf 'REMOTE_MODEL='
tr -d '\0' </proc/device-tree/model 2>/dev/null || uname -m
printf '\n'
printf 'REMOTE_ROOT_OPTIONS=%s\n' "$(findmnt -n -o OPTIONS -T /)"
REMOTE_PREFLIGHT
)"
printf '%s\n' "$preflight"
grep -qx 'REMOTE_UID=0' <<< "$preflight" \
  || { printf 'Das SSH-Konto besitzt keine echte UID 0; es wurde nichts veraendert.\n' >&2; exit 1; }
root_options="$(sed -n 's/^REMOTE_ROOT_OPTIONS=//p' <<< "$preflight")"
[[ ",$root_options," == *,ro,* ]] \
  || { printf 'Root ist bereits rw. Erst fremdes Wartungsfenster beenden.\n' >&2; exit 1; }

if (( SKIP_PACKAGES == 0 )); then
  printf 'Installiere beziehungsweise pruefe Zielpakete ...\n'
  remote_ssh bash -s -- "$WITH_WEB" <<'REMOTE_PACKAGES'
set -euo pipefail
with_web="$1"
root_rw_owned=0
boot_rw_owned=0
boot_target=""

restore_mounts() {
  local result=$?
  trap - EXIT HUP INT TERM
  sync
  if (( boot_rw_owned == 1 )); then mount -o remount,ro "$boot_target" || result=1; fi
  if (( root_rw_owned == 1 )); then mount -o remount,ro / || result=1; fi
  exit "$result"
}
trap restore_mounts EXIT HUP INT TERM

root_options="$(findmnt -n -o OPTIONS -T /)"
if [[ ",$root_options," == *,ro,* ]]; then
  mount -o remount,rw /
  root_rw_owned=1
fi
if [[ -e /boot/firmware ]]; then
  boot_target="$(findmnt -n -o TARGET -T /boot/firmware)"
  boot_options="$(findmnt -n -o OPTIONS -T /boot/firmware)"
  if [[ ",$boot_options," == *,ro,* ]]; then
    mount -o remount,rw "$boot_target"
    boot_rw_owned=1
  fi
fi

export DEBIAN_FRONTEND=noninteractive
apt-get update
packages=(bash ca-certificates coreutils curl gphoto2 grep mawk sed tar util-linux)
if (( with_web == 1 )); then
  packages+=(apache2 apache2-utils libapache2-mod-php php sudo)
fi
apt-get install -y --no-install-recommends "${packages[@]}"
audit="$(dpkg --audit)"
[[ -z "$audit" ]] || { printf '%s\n' "$audit" >&2; exit 1; }
apt-get check
REMOTE_PACKAGES
fi

printf 'Erzeuge temporaeren Zielordner und uebertrage Commit %s ...\n' "${DEPLOY_COMMIT:0:12}"
stage_output="$(remote_ssh mktemp -d /tmp/camera-rpi-deploy.XXXXXX)"
REMOTE_STAGE="$(sed -n '/^\/tmp\/camera-rpi-deploy\.[A-Za-z0-9]*$/p' <<< "$stage_output" | tail -n 1)"
[[ "$REMOTE_STAGE" =~ ^/tmp/camera-rpi-deploy\.[A-Za-z0-9]+$ ]] \
  || { printf 'Sicherer temporaerer Zielordner konnte nicht bestimmt werden.\n' >&2; exit 1; }
git -C "$PROJECT_DIR" archive --format=tar "$DEPLOY_COMMIT" \
  | remote_ssh "tar -xf - -C '$REMOTE_STAGE'"

printf 'Kopiere Projektdateien und installiere den Core ...\n'
remote_ssh bash -s -- "$REMOTE_STAGE" "$DEPLOY_COMMIT" <<'REMOTE_INSTALL'
set -euo pipefail
stage="$1"
commit="$2"
destination=/var/www/html/camera-rpi
[[ "$stage" =~ ^/tmp/camera-rpi-deploy\.[A-Za-z0-9]+$ && -d "$stage" ]]
[[ "$commit" =~ ^[0-9a-f]{40}$ ]]
root_rw_owned=0

restore_root() {
  local result=$?
  trap - EXIT HUP INT TERM
  sync
  if (( root_rw_owned == 1 )); then mount -o remount,ro / || result=1; fi
  exit "$result"
}
trap restore_root EXIT HUP INT TERM

root_options="$(findmnt -n -o OPTIONS -T /)"
if [[ ",$root_options," == *,ro,* ]]; then
  mount -o remount,rw /
  root_rw_owned=1
fi
install -d -m 0750 -o root -g root "$destination"
cp -a -- "$stage/." "$destination/"
printf '%s\n' "$commit" > "$destination/.deployed-commit"
chmod 0640 "$destination/.deployed-commit"
bash "$destination/install.sh" --core
REMOTE_INSTALL

if (( WITH_WEB == 1 )); then
  printf 'Installiere die geschuetzte Admin-Webseite ...\n'
  printf '%s\n' "$ADMIN_PASSWORD" \
    | remote_ssh "CAMERA_RPI_ADMIN_PASSWORD_STDIN=1 bash /var/www/html/camera-rpi/install.sh --web '$WEB_ADMIN_USER'"
  unset ADMIN_PASSWORD
fi

remote_ssh "rm -rf -- '$REMOTE_STAGE'"
REMOTE_STAGE=""

printf 'Fuehre Abschlusskontrolle auf dem Kamera-Pi aus ...\n'
remote_ssh bash -s <<'REMOTE_VERIFY'
set -euo pipefail
printf '\nInstallierter Commit:\n'
cat /var/www/html/camera-rpi/.deployed-commit
printf '\nMounts:\n'
for path in / /boot /boot/firmware; do
  [[ -e "$path" ]] && findmnt -T "$path" -o TARGET,SOURCE,FSTYPE,OPTIONS
done
root_options="$(findmnt -n -o OPTIONS -T /)"
[[ ",$root_options," == *,ro,* ]] || { printf 'FEHLER: Root ist nicht ro.\n' >&2; exit 1; }
printf '\nPaketstatus:\n'
dpkg --audit
apt-get check
printf '\nKameraerkennung:\n'
gphoto2 --auto-detect
printf '\nFehlgeschlagene Units:\n'
systemctl --failed --no-pager || true
REMOTE_VERIFY

unset DEPLOY_PASSWORD ADMIN_PASSWORD
trap - EXIT HUP INT TERM
printf '\nDeployment von %s erfolgreich abgeschlossen.\n' "$DEPLOY_COMMIT"
printf 'Es wurde bewusst noch keine Serverkonfiguration und kein Kamera-Worker aktiviert.\n'
