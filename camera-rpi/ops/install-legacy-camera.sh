#!/usr/bin/env bash
# Additive first installation on an OLD camera. Deliberately NOT a cutover:
# cron, fan.py, camera power, DHT driver, Apache routing and legacy keys stay put.
# No APT, reboot, automatic capture or service starts. See LEGACY-MIGRATION.md.
set -euo pipefail
umask 077
export PATH=/usr/sbin:/usr/bin:/sbin:/bin
PROJECT="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")/.." && pwd -P)"
source "$PROJECT/ops/lib/legacy-install.sh"
MODE="${1:---help}"
BACKUP=""
ROOT_RW_OWNED=0
PUBLISHED=0
ADMIN_USER=cameraadmin
ADMIN_LOCKED=0
LI_ROOT=/

usage() {
  printf '%s\n' \
    'bash ops/install-legacy-camera.sh --check' \
    'sudo bash ops/install-legacy-camera.sh --install [HTTP-ADMINNAME]' \
    'sudo bash ops/install-legacy-camera.sh --install-locked [HTTP-ADMINNAME]' \
    'sudo bash /var/backups/camera-rpi-legacy.XXXXXXXX/ops/install-legacy-camera.sh --rollback /var/backups/camera-rpi-legacy.XXXXXXXX' \
    'Nur inaktive Erstinstallation; KEINE Umschaltung/kein APT. Alte Kamera bleibt aktiv.'
}
case "$MODE" in
  --help|-h) usage; exit 0 ;;
  --check) [[ $# == 1 ]] || { usage; exit 2; } ;;
  --install) [[ $# -le 2 ]] || exit 2; ADMIN_USER="${2:-cameraadmin}" ;;
  --install-locked)
    [[ $# -le 2 ]] || exit 2
    ADMIN_USER="${2:-cameraadmin}"
    ADMIN_LOCKED=1
    MODE=--install ;;
  --rollback) [[ $# == 2 ]] || exit 2; BACKUP="$2" ;;
  *) usage; exit 2 ;;
esac
[[ "$ADMIN_USER" =~ ^[A-Za-z0-9._-]{1,64}$ ]] || exit 2

# systemd unit contents can execute programs, so all package inputs must be
# operator-verified. Transport hash is checked separately; this checks file
# corruption and that a rollback does not depend on the download in /tmp.
li_load_manifest "$PROJECT/ops/legacy-install-files.tsv"

# Preserve the incoming RO state only. Boot is never remounted or modified.
# On failure after publication, quarantine partial NEW files; legacy files were
# never changed and do not require an unsafe broad /etc or webroot restoration.
finish() {
  local result=$?
  trap - EXIT HUP INT TERM
  if (( PUBLISHED && result != 0 )); then
    printf 'Installation abgebrochen; sichere Teilinstallation.\n' >&2
    if li_require_quiet_new_stack && li_quarantine "$BACKUP"; then
      systemctl daemon-reload || result=1
    else
      printf 'RUECKBAU UNVOLLSTAENDIG: %s; nichts loeschen.\n' "$BACKUP" >&2
      result=1
    fi
  fi
  if (( ROOT_RW_OWNED )); then
    sync
    mount -o remount,ro / || { printf 'ACHTUNG: Root blieb RW.\n' >&2; result=1; }
  fi
  [[ -z "$BACKUP" ]] || printf 'Sicherung/Rueckweg: %s\n' "$BACKUP"
  exit "$result"
}

if [[ "$MODE" == --rollback ]]; then
  (( EUID == 0 )) || { li_fail 'Rollback braucht root'; exit 1; }
  [[ "$BACKUP" =~ ^/var/backups/camera-rpi-legacy\.[A-Za-z0-9]+$ && ! -L "$BACKUP" \
     && "$(stat -c '%u:%a' "$BACKUP")" == 0:700 ]] || { li_fail 'Kein privater Migrationsordner'; exit 1; }
  [[ ! -L /var/backups ]] || { li_fail 'Symlink als Backupwurzel'; exit 1; }
  case "$(findmnt -n -o FSTYPE -T "$BACKUP")" in
    ext4|ext3|ext2|btrfs|xfs|f2fs) ;;
    *) li_fail 'Rueckweg braucht ein persistentes lokales Dateisystem'; exit 1 ;;
  esac
  [[ "$(cat "$BACKUP/machine-id")" == "$(cat /etc/machine-id)" ]] || { li_fail 'Sicherung gehoert zu anderem Host'; exit 1; }
  (cd "$BACKUP" && sha256sum -c rollback.sha256) || exit 1
  cmp -s "$BACKUP/ops/legacy-install-files.tsv" "$PROJECT/ops/legacy-install-files.tsv" \
    || { li_fail 'Rollback mit der im Backup enthaltenen Skriptfassung starten'; exit 1; }
  [[ -f "$BACKUP/published" ]] || { li_fail 'Keine Installation in dieser Transaktion'; exit 1; }
else
  # Pure preflight reads current hardware; never invoke gphoto2, gpio or sensors.
  [[ -r /proc/device-tree/model ]] && grep -aq 'Raspberry Pi' /proc/device-tree/model \
    || { li_fail 'Dieser Installer ist nur fuer einen Raspberry Pi'; exit 1; }
  for program in php cc install tar sha256sum findmnt flock systemctl htpasswd visudo apache2ctl; do
    command -v "$program" >/dev/null || { li_fail "Voraussetzung fehlt: $program (kein automatisches APT)"; exit 1; }
  done
  # Reuse the core's read-only check to avoid silently drifting prerequisites.
  (( EUID == 0 )) || { li_fail 'Auch --check als sudo/root, ohne Schreib-/GPIO-Aktionen'; exit 1; }
  [[ -s "$PROJECT/SHA256SUMS" ]] || { li_fail 'Geprueftes Paket aus ops/build-legacy-package.sh verwenden'; exit 1; }
  (cd "$PROJECT" && sha256sum -c SHA256SUMS)
  bash "$PROJECT/install.sh" --core --web "$ADMIN_USER" --check
  li_require_fresh
  [[ -r /etc/webcam.cfg && -x /usr/local/bin/webcam_wait ]] \
    || { li_fail 'Kein erwarteter Altbestand gefunden'; exit 1; }
  printf 'Alte Kamera bleibt aktiv. Keine EOS-/IIO-/GPIO-Abnahme durch diese Pruefung.\n'
fi
li_require_quiet_new_stack
[[ "$MODE" != --check ]] || { printf 'OK: Inaktive Erstinstallation moeglich; nichts geaendert.\n'; exit 0; }

# The existing global lock also excludes a concurrent new admin/backup command.
exec 9>/run/lock/camera-rpi.operation.lock
flock -n 9 || { li_fail 'Kameraoperation aktiv'; exit 1; }
trap finish EXIT
trap 'exit 143' TERM HUP
trap 'exit 130' INT
root_options="$(findmnt -n -o OPTIONS -T /)"
[[ ",$root_options," == *,ro,* || ",$root_options," == *,rw,* ]] || { li_fail 'Root-Mountmodus unbekannt'; exit 1; }
if [[ ",$root_options," == *,ro,* ]]; then
  mount -o remount,rw /
  ROOT_RW_OWNED=1
fi
if [[ "$MODE" == --rollback ]]; then
  li_quarantine "$BACKUP"
  systemctl daemon-reload
  printf 'OK: Neue Software zurueckgebaut. Altbestand unberuehrt; aktuelle neue Daten im Rueckweg.\n'
  exit 0
fi

# A persistent, private backup is mandatory; tmpfs would disappear on a reboot.
[[ ! -L /var/backups ]] || { li_fail 'Symlink als Backupwurzel'; exit 1; }
[[ -d /var/backups ]] || install -d -m 0755 /var/backups
case "$(findmnt -n -o FSTYPE -T /var/backups)" in
  ext4|ext3|ext2|btrfs|xfs|f2fs) ;;
  *) li_fail 'Backup braucht lokales persistentes Linux-Dateisystem'; exit 1 ;;
esac
(( $(df -PB1 /var/backups | awk 'NR==2 {print $4}') >= 268435456 )) \
  || { li_fail 'Weniger als 256 MiB frei fuer Sicherung'; exit 1; }
BACKUP="$(mktemp -d /var/backups/camera-rpi-legacy.XXXXXXXX)"
cp -a /etc/machine-id "$BACKUP/machine-id"
install -D -m 0700 "$PROJECT/ops/install-legacy-camera.sh" "$BACKUP/ops/install-legacy-camera.sh"
install -D -m 0600 "$PROJECT/ops/lib/legacy-install.sh" "$BACKUP/ops/lib/legacy-install.sh"
install -m 0600 "$PROJECT/ops/legacy-install-files.tsv" "$BACKUP/ops/"
(cd "$BACKUP" && sha256sum machine-id ops/install-legacy-camera.sh ops/lib/legacy-install.sh ops/legacy-install-files.tsv > rollback.sha256)

# Copy static old configuration for documentation/recovery only, not a live
# filesystem image. No raw /etc/webcam.cfg content or key is printed or sourced.
legacy_files=(etc/webcam.cfg etc/crontab etc/rc.local)
shopt -s nullglob
for file in /usr/local/bin/webcam* /usr/local/bin/fan.py /usr/local/bin/rrd-*.sh \
  /etc/systemd/system/fancontrol.service /etc/systemd/system/rrd-create.service; do
  [[ -f "$file" && ! -L "$file" ]] || continue
  legacy_files+=("${file#/}")
done
tar --acls --xattrs --numeric-owner -cpf "$BACKUP/legacy-config.tar" -C / "${legacy_files[@]}"
tar --compare -f "$BACKUP/legacy-config.tar" -C /
(cd "$BACKUP" && sha256sum legacy-config.tar > legacy-config.sha256)
systemctl show fancontrol.service rrd-create.service apache2.service cron.service \
  -p Id -p ActiveState -p UnitFileState > "$BACKUP/legacy-services.before"

PAYLOAD="$BACKUP/payload"
li_prepare_payload "$PROJECT" "$PAYLOAD"
cc -O2 -Wall -Wextra -Werror -Wl,-z,relro,-z,now \
  -o "$PAYLOAD/usr/local/libexec/camera-rpi-pwm" "$PROJECT/scripts/native/camera-rpi-pwm.c"
chmod 0755 "$PAYLOAD/usr/local/libexec/camera-rpi-pwm"
# Check only; neither generated program nor daemon accesses a physical GPIO.
"$PAYLOAD/usr/local/libexec/camera-rpi-pwm" --version
CAMERA_RPI_CLIMATE_CONFIG="$PAYLOAD/etc/camera-rpi/climate.json" \
  php "$PROJECT/scripts/camera-rpi-climate.php" check
php -r '$c=json_decode(file_get_contents($argv[1]),true,16,JSON_THROW_ON_ERROR); exit($c["mode"]==="off"?0:1);' "$PAYLOAD/etc/camera-rpi/climate.json"
grep -qx 'enabled=false' "$PAYLOAD/etc/camera-rpi/device.conf"
visudo -cf "$PAYLOAD/etc/sudoers.d/camera-rpi-admin"
install -d -m 0700 "$PAYLOAD/etc/camera-rpi/servers" "$PAYLOAD/etc/camera-rpi/keys" "$PAYLOAD/var/lib/camera-rpi/spool"
chmod 0700 "$PAYLOAD/var/lib/camera-rpi"
chown root:www-data "$PAYLOAD/etc/camera-rpi"
chmod 0710 "$PAYLOAD/etc/camera-rpi"
# Unattended staging must not invent/reuse a login password. The explicit
# locked mode writes an unusable hash; the operator sets a real password later.
if (( ADMIN_LOCKED )); then
  li_write_locked_admin "$PAYLOAD/etc/camera-rpi/admin.htpasswd" "$ADMIN_USER"
else
  printf '\nHTTP-Passwort fuer %s (nicht das SSH-Passwort):\n' "$ADMIN_USER"
  htpasswd -B -c "$PAYLOAD/etc/camera-rpi/admin.htpasswd" "$ADMIN_USER"
fi
chown root:www-data "$PAYLOAD/etc/camera-rpi/admin.htpasswd"
chmod 0640 "$PAYLOAD/etc/camera-rpi/admin.htpasswd"
# Keep the complete source version and payload hashes privately with the rollback.
(cd "$PAYLOAD" && find . -type f -print0 | sort -z | xargs -0 sha256sum > "$BACKUP/payload.sha256")
(cd "$PAYLOAD" && sha256sum -c "$BACKUP/payload.sha256")
sync -f "$BACKUP"
li_require_fresh
: > "$BACKUP/published"
sync -f "$BACKUP/published"
li_publish "$PAYLOAD"
(cd / && sha256sum -c "$BACKUP/payload.sha256")
systemctl daemon-reload
li_require_quiet_new_stack
PUBLISHED=0
printf 'OK: Software inaktiv installiert. KEIN Dienststart, kein Apache-Reload, kein GPIO, kein APT.\n'
printf 'Alt-Poller, fan.py, Webseiten, Keys, Bilder und RRD laufen unveraendert weiter.\n'
if (( ADMIN_LOCKED )); then
  printf 'HTTP-Admin gesperrt; vor spaeterer Aktivierung Passwort setzen: sudo htpasswd -B /etc/camera-rpi/admin.htpasswd %s\n' "$ADMIN_USER"
fi
printf 'Umschaltung noch NICHT freigegeben: EOS-Aufnahme, IIO und Cron/Boot-Konflikte zuerst abnehmen.\n'
printf 'Rollback: sudo bash %s/ops/install-legacy-camera.sh --rollback %s\n' "$BACKUP" "$BACKUP"
