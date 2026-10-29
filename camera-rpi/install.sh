#!/usr/bin/env bash

# Installiert den hier entwickelten Stand auf einem Kamera-Pi. Das Script
# wird nicht automatisch ausgefuehrt. Ohne --core oder --web veraendert es
# gar nichts und zeigt nur die Hilfe.

set -euo pipefail
umask 077

SOURCE_DIR="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd -P)"
INSTALL_CORE=0
INSTALL_WEB=0
INSTALL_PACKAGES=0
WITH_V4L2=0
CHECK_ONLY=0
ADMIN_USER=""
ROOT_RW_OWNED=0
CLIMATE_BUILD=""

usage() {
  cat <<'EOF'
Aufruf:
  sudo ./install.sh --core
  sudo ./install.sh --core --web ADMINNAME
  sudo ./install.sh --core --web ADMINNAME --packages [--check]

--core installiert Worker, Healthcheck und systemd-Template.
--web installiert zusaetzlich Admin- und Bild-/Klima-Seite und fragt
      interaktiv nach einem neuen HTTP-Passwort fuer ADMINNAME.
--packages installiert benoetigte Debian-/Raspberry-Pi-OS-Pakete (12/13).
           Nur fuer bereits schreibbares Root/Boot; kein allgemeines Upgrade.
--check prueft nur Voraussetzungen; mit --packages nur APT-Simulation.
--with-v4l2 prueft v4l2-ctl/python3; --packages installiert v4l-utils/python3.
            gphoto2 bleibt fuer den spaeteren Wechsel ebenfalls installiert.

Kein vorhandener Zielserver und kein Key wird geloescht oder ueberschrieben.
EOF
}

while (($# > 0)); do
  case "$1" in
    --core) INSTALL_CORE=1 ;;
    --packages) INSTALL_PACKAGES=1 ;;
    --with-v4l2) WITH_V4L2=1 ;;
    --check) CHECK_ONLY=1 ;;
    --web)
      INSTALL_WEB=1
      shift
      ADMIN_USER="${1:-}"
      ;;
    -h|--help) usage; exit 0 ;;
    *) printf 'Unbekannte Option: %s\n' "$1" >&2; usage >&2; exit 2 ;;
  esac
  shift
done

(( INSTALL_CORE == 1 || INSTALL_WEB == 1 )) || { usage; exit 0; }
(( EUID == 0 )) || { printf 'Bitte mit sudo/root ausfuehren.\n' >&2; exit 1; }
if (( INSTALL_WEB == 1 )); then
  [[ "$ADMIN_USER" =~ ^[A-Za-z0-9._-]{1,64}$ ]] \
    || { printf 'ADMINNAME ist ungueltig.\n' >&2; exit 2; }
  INSTALL_CORE=1
fi

if (( INSTALL_PACKAGES == 1 )); then
  # Dieser Weg ist fuer frische Installationen mit normalem sudo-Login.
  # Bestehende RO-Pis behalten ihren separaten, kontrollierten Deploymentweg.
  . /etc/os-release
  case "${ID:-}:${VERSION_CODENAME:-}" in
    debian:bookworm|debian:trixie|raspbian:bookworm|raspbian:trixie) ;;
    *) printf 'Paketinstallation nur fuer Debian/Raspberry Pi OS Bookworm oder Trixie.\n' >&2; exit 1 ;;
  esac
  for program in apt-get findmnt; do
    command -v "$program" >/dev/null || { printf 'Programm fehlt: %s\n' "$program" >&2; exit 1; }
  done
  for mount_path in / /boot /boot/firmware; do
    [[ -e "$mount_path" ]] || continue
    options="$(findmnt -n -o OPTIONS -T "$mount_path")"
    [[ ",$options," == *,rw,* ]] || {
      printf '%s ist nicht rw. --packages ist nur fuer frische RW-Systeme; RO-Deployment verwenden.\n' "$mount_path" >&2
      exit 1
    }
  done
  packages=(bash ca-certificates coreutils curl gphoto2 grep mawk sed tar util-linux usbutils python3)
  (( INSTALL_CORE == 0 )) || packages+=(php-cli php-gd gcc libc6-dev linux-libc-dev)
  (( WITH_V4L2 == 0 )) || packages+=(v4l-utils python3)
  if (( INSTALL_WEB == 1 )); then
    packages+=(apache2 apache2-utils libapache2-mod-php php sudo)
  fi
  if (( CHECK_ONLY == 1 )); then
    apt-get --simulate install --no-install-recommends "${packages[@]}"
    printf 'Nur Paketpruefung; keine Dateien, Dienste oder Mounts geaendert.\n'
    exit 0
  fi
  apt-get update
  DEBIAN_FRONTEND=noninteractive apt-get install -y --no-install-recommends "${packages[@]}"
  apt-get check
fi

for program in install systemctl systemd-tmpfiles findmnt mount flock curl gphoto2 timeout sha256sum awk python3; do
  command -v "$program" >/dev/null || { printf 'Programm fehlt: %s\n' "$program" >&2; exit 1; }
done
if (( INSTALL_CORE == 1 )); then
  for program in php cc; do
    command -v "$program" >/dev/null || { printf 'Programm fehlt fuer Klima: %s\n' "$program" >&2; exit 1; }
  done
  php -r 'if (PHP_VERSION_ID < 80200) exit(1); foreach (["pcntl_async_signals","posix_geteuid","proc_open","socket_sendto","fsync","imagecreatefromjpeg"] as $f) if (!function_exists($f)) exit(1);' \
    || { printf 'Klima benoetigt PHP-CLI >=8.2 mit pcntl/posix/sockets/GD.\n' >&2; exit 1; }
  cc -fsyntax-only "$SOURCE_DIR/scripts/native/camera-rpi-pwm.c"
fi

if (( WITH_V4L2 == 1 )); then
  command -v v4l2-ctl >/dev/null || { printf 'Programm fehlt: v4l2-ctl (Paket v4l-utils)\n' >&2; exit 1; }
  command -v python3 >/dev/null || { printf 'Programm fehlt: python3\n' >&2; exit 1; }
fi

if (( INSTALL_WEB == 1 )); then
  for program in apache2ctl htpasswd visudo php; do
    command -v "$program" >/dev/null || { printf 'Programm fehlt: %s\n' "$program" >&2; exit 1; }
  done
  apache_modules="$(apache2ctl -M 2>/dev/null)"
  for module in alias auth_basic; do
    grep -Eq "(^|[[:space:]])${module}_module" <<< "$apache_modules" \
      || { printf 'Apache-Modul fehlt: %s\n' "$module" >&2; exit 1; }
  done
  grep -Eq '(^|[[:space:]])(php|proxy_fcgi)_module' <<< "$apache_modules" \
    || { printf 'Kein aktiver PHP-Handler in Apache.\n' >&2; exit 1; }
fi
if (( CHECK_ONLY == 1 )); then
  printf 'Voraussetzungen vorhanden; keine Installation ausgefuehrt.\n'
  exit 0
fi

# Only restore RO when this installer opened the write window itself.
# Package installation/preflight is separate from this mount cleanup.
cleanup() {
  local result=$?
  trap - EXIT HUP INT TERM
  [[ -z "$CLIMATE_BUILD" ]] || rm -rf -- "$CLIMATE_BUILD"
  if (( ROOT_RW_OWNED == 1 )); then
    sync
    mount -o remount,ro / || result=1
  fi
  exit "$result"
}
trap cleanup EXIT HUP INT TERM

root_options="$(findmnt -n -o OPTIONS -T /)"
if [[ ",$root_options," == *,ro,* ]]; then
  mount -o remount,rw /
  ROOT_RW_OWNED=1
fi

if (( INSTALL_CORE == 1 )); then
  # Apache muss nur zur Htpasswd-Datei gelangen; Keys/Server bleiben 0700.
  install -d -m 0710 -o root -g www-data /etc/camera-rpi
  install -d -m 0700 -o root -g root \
    /etc/camera-rpi/servers /etc/camera-rpi/keys \
    /var/lib/camera-rpi/spool /run/camera-rpi
  install -d -m 0755 -o root -g root /usr/local/libexec /usr/local/sbin
  install -m 0755 -o root -g root \
    "$SOURCE_DIR/scripts/camera-rpi-worker" /usr/local/libexec/camera-rpi-worker
  install -m 0755 -o root -g root \
    "$SOURCE_DIR/scripts/camera-rpi-sensor" /usr/local/libexec/camera-rpi-sensor
  install -m 0644 -o root -g root \
    "$SOURCE_DIR/scripts/lib/camera-rpi-common.sh" /usr/local/libexec/camera-rpi-common.sh
  install -m 0644 -o root -g root \
    "$SOURCE_DIR/scripts/lib/camera-rpi-pool.sh" /usr/local/libexec/camera-rpi-pool.sh
  install -m 0755 -o root -g root \
    "$SOURCE_DIR/scripts/lib/camera-rpi-eos-legacy" /usr/local/libexec/camera-rpi-eos-legacy
  install -m 0644 -o root -g root \
    "$SOURCE_DIR/scripts/lib/camera-rpi-jpeg.py" /usr/local/libexec/camera-rpi-jpeg.py
  install -m 0644 -o root -g root \
    "$SOURCE_DIR/scripts/lib/camera-rpi-weather.py" /usr/local/libexec/camera-rpi-weather.py
  # Climate starts OFF. PHP owns control; the fixed C actuator is compiled for
  # this Pi architecture. No Python, GPIO claim or sensor setting change here.
  install -m 0644 -o root -g root \
    "$SOURCE_DIR/scripts/camera-rpi-climate.php" \
    "$SOURCE_DIR/scripts/camera-rpi-privacy.php" \
    "$SOURCE_DIR/scripts/camera-rpi-power.php" \
    "$SOURCE_DIR/scripts/lib/camera-source.php" \
    "$SOURCE_DIR/scripts/lib/capture-privacy.php" \
    "$SOURCE_DIR/scripts/lib/climate-controller.php" \
    "$SOURCE_DIR/scripts/lib/climate-gpio.php" \
    "$SOURCE_DIR/scripts/lib/gpio-config.php" \
    "$SOURCE_DIR/scripts/lib/climate-history.php" \
    "$SOURCE_DIR/scripts/camera-rpi-climate-archive.php" /usr/local/libexec/
  CLIMATE_BUILD="$(mktemp -d /tmp/camera-climate-install.XXXXXXXX)"
  cc -O2 -Wall -Wextra -Werror -Wl,-z,relro,-z,now \
    -o "$CLIMATE_BUILD/camera-rpi-pwm" "$SOURCE_DIR/scripts/native/camera-rpi-pwm.c"
  install -m 0755 -o root -g root "$CLIMATE_BUILD/camera-rpi-pwm" /usr/local/libexec/camera-rpi-pwm
  rm -rf -- "$CLIMATE_BUILD"
  install -m 0644 -o root -g root \
    "$SOURCE_DIR/systemd/camera-rpi-climate.service" /etc/systemd/system/
  if [[ ! -e /etc/camera-rpi/climate.json ]]; then
    install -m 0600 -o root -g root "$SOURCE_DIR/config/climate.example.json" /etc/camera-rpi/climate.json
  fi
  install -m 0644 -o root -g root \
    "$SOURCE_DIR/scripts/camera-rpi-tunnel.py" /usr/local/libexec/camera-rpi-tunnel.py
  install -m 0644 -o root -g root \
    "$SOURCE_DIR/systemd/camera-rpi-tunnel@.service" /etc/systemd/system/camera-rpi-tunnel@.service
  install -m 0644 -o root -g root \
    "$SOURCE_DIR/systemd/camera-rpi-maintenance-tmpfiles.conf" /etc/tmpfiles.d/camera-rpi-maintenance.conf
  systemd-tmpfiles --create /etc/tmpfiles.d/camera-rpi-maintenance.conf
  install -m 0755 -o root -g root \
    "$SOURCE_DIR/scripts/camera-rpi-healthcheck" /usr/local/sbin/camera-rpi-healthcheck
  install -m 0755 -o root -g root \
    "$SOURCE_DIR/scripts/admin/camera-rpi-admin" /usr/local/sbin/camera-rpi-admin
  install -m 0755 -o root -g root \
    "$SOURCE_DIR/scripts/admin/camera-rpi-source" /usr/local/sbin/camera-rpi-source
  install -m 0644 -o root -g root \
    "$SOURCE_DIR/systemd/camera-rpi-worker@.service" /etc/systemd/system/camera-rpi-worker@.service
  install -m 0644 -o root -g root \
    "$SOURCE_DIR/systemd/camera-rpi-sensor.service" /etc/systemd/system/camera-rpi-sensor.service
  install -m 0644 -o root -g root \
    "$SOURCE_DIR/systemd/camera-rpi-tmpfiles.conf" /etc/tmpfiles.d/camera-rpi.conf
  systemd-tmpfiles --create /etc/tmpfiles.d/camera-rpi.conf
  if [[ ! -e /etc/camera-rpi/device.conf ]]; then
    install -m 0600 -o root -g root \
      "$SOURCE_DIR/config/device.example.conf" /etc/camera-rpi/device.conf
  fi
  /usr/local/libexec/camera-rpi-sensor --check-config /etc/camera-rpi/device.conf
  install -d -m 0755 -o root -g root /usr/local/share/doc/camera-rpi
  install -m 0644 -o root -g root \
    "$SOURCE_DIR/VERSION" "$SOURCE_DIR/CAPTURE-POOL.md" "$SOURCE_DIR/README.md" "$SOURCE_DIR/ARCHITEKTUR.md" \
    "$SOURCE_DIR/DEVELOPMENT.md" "$SOURCE_DIR/STATUSSEITE.md" "$SOURCE_DIR/TRIXIE.md" "$SOURCE_DIR/CLIMATE.md" "$SOURCE_DIR/CAPTURE-PRIVACY.md" \
    /usr/local/share/doc/camera-rpi/
  systemctl daemon-reload
  systemctl enable --now camera-rpi-sensor.service
  systemctl enable --now camera-rpi-climate.service
fi

if (( INSTALL_WEB == 1 )); then
  install -d -m 0755 -o root -g root \
    /usr/local/share/camera-rpi-admin/public /usr/local/share/camera-rpi-admin/src
  install -m 0644 -o root -g root "$SOURCE_DIR/admin/public/index.php" \
    "$SOURCE_DIR/admin/public/power.js" "$SOURCE_DIR/admin/public/power-status.php" \
    "$SOURCE_DIR/admin/public/source.js" "$SOURCE_DIR/admin/public/style.css" /usr/local/share/camera-rpi-admin/public/
  install -m 0644 -o root -g root "$SOURCE_DIR/admin/src/source-panel.php" "$SOURCE_DIR/admin/src/bootstrap.php" \
    "$SOURCE_DIR/admin/src/climate.php" "$SOURCE_DIR/admin/src/climate-panel.php" \
    "$SOURCE_DIR/admin/src/privacy.php" "$SOURCE_DIR/admin/src/privacy-panel.php" \
    "$SOURCE_DIR/admin/src/power.php" "$SOURCE_DIR/admin/src/power-panel.php" \
    /usr/local/share/camera-rpi-admin/src/
  install -d -m 0755 -o root -g root /usr/local/share/camera-rpi-status/public
  install -m 0644 -o root -g root \
    "$SOURCE_DIR/status/public/index.php" "$SOURCE_DIR/status/public/style.css" \
    /usr/local/share/camera-rpi-status/public/
  install -m 0644 -o root -g root "$SOURCE_DIR/apache/camera-rpi-admin.conf" \
    /etc/apache2/conf-available/camera-rpi-admin.conf
  install -m 0440 -o root -g root "$SOURCE_DIR/sudoers/camera-rpi-admin" \
    /etc/sudoers.d/camera-rpi-admin
  visudo -cf /etc/sudoers.d/camera-rpi-admin
  printf '\nHTTP-Passwort fuer %s festlegen:\n' "$ADMIN_USER"
  if [[ "${CAMERA_RPI_ADMIN_PASSWORD_STDIN:-0}" == "1" ]]; then
    # Der Deployment-Helfer reicht das zuvor verdeckt abgefragte Passwort ueber
    # stdin ein. Es erscheint weder in ssh- noch in htpasswd-Argumenten.
    IFS= read -r ADMIN_PASSWORD
    [[ ${#ADMIN_PASSWORD} -ge 12 && "$ADMIN_PASSWORD" != *$'\r'* ]] \
      || { unset ADMIN_PASSWORD; printf 'Admin-Passwort muss mindestens 12 Zeichen lang sein.\n' >&2; exit 1; }
    if [[ -f /etc/camera-rpi/admin.htpasswd ]]; then
      printf '%s\n' "$ADMIN_PASSWORD" | htpasswd -i /etc/camera-rpi/admin.htpasswd "$ADMIN_USER"
    else
      printf '%s\n' "$ADMIN_PASSWORD" | htpasswd -i -c /etc/camera-rpi/admin.htpasswd "$ADMIN_USER"
    fi
    unset ADMIN_PASSWORD
  elif [[ -f /etc/camera-rpi/admin.htpasswd ]]; then
    htpasswd /etc/camera-rpi/admin.htpasswd "$ADMIN_USER"
  else
    htpasswd -c /etc/camera-rpi/admin.htpasswd "$ADMIN_USER"
  fi
  chown root:www-data /etc/camera-rpi/admin.htpasswd
  chmod 0640 /etc/camera-rpi/admin.htpasswd
  a2enconf camera-rpi-admin
  apache2ctl configtest
  systemctl reload apache2
fi

sync
if (( ROOT_RW_OWNED == 1 )); then
  mount -o remount,ro /
  ROOT_RW_OWNED=0
fi
trap - EXIT HUP INT TERM

printf 'Installation abgeschlossen. Es wurde kein Zielserver automatisch angelegt.\n'
printf 'Infoseite: http://PI-IP/ (ohne Anmeldung); Verwaltung: /camera-admin/ (mit Passwort).\n'
printf 'Fuer verschluesselte Verwaltung HTTPS oder optional SSH-Tunnel verwenden.\n'
