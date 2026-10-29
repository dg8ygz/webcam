#!/usr/bin/env bash
# Replace only the local admin bridge on .16; never save a target or read keys.
# Verified baseline, private persistent backup, atomic publication and RO return.
set -euo pipefail
umask 077
export PATH=/usr/sbin:/usr/bin:/sbin:/bin
[[ $EUID == 0 && $# == 1 && "$1" == --apply ]] \
  || { echo 'Als root: bash ops/update-key-reuse.sh --apply' >&2; exit 1; }
SRC="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")/.." && pwd -P)"
TARGET=/usr/local/sbin/camera-rpi-admin
SOURCE="$SRC/scripts/admin/camera-rpi-admin"
BASELINE=ddbf178e60ae3b3a704776871f4edf34aabcc6aecff766a9360d7d5996a31929
(cd "$SRC" && sha256sum -c SHA256SUMS)
bash -n "$SOURCE"
bash "$SRC/tests/admin-key-reuse"
for path in /usr /usr/local /usr/local/sbin "$TARGET" /var /var/backups; do
  [[ ! -L "$path" ]] || { echo "Symlink im Updatepfad: $path" >&2; exit 1; }
done
[[ -f "$TARGET" && "$(stat -c '%u:%g' "$TARGET")" == 0:0 ]]
MODE="$(stat -c %a "$TARGET")"
[[ "$MODE" == 755 || "$MODE" == 750 ]]
exec 9>/run/lock/camera-rpi.operation.lock
flock -w 30 9 || { echo 'Kameraoperation aktiv; spaeter erneut versuchen.' >&2; exit 1; }
if cmp -s "$SOURCE" "$TARGET"; then
  echo 'OK: Key-Wiederverwendung bereits freigegeben.'; exit 0
fi
[[ "$(sha256sum "$TARGET" | awk '{print $1}')" == "$BASELINE" ]] \
  || { echo 'Abweichender Admin-Stand; nichts ueberschrieben.' >&2; exit 1; }
case "$(findmnt -n -o FSTYPE -T /var/backups)" in
  ext4|ext3|ext2|btrfs|xfs|f2fs) ;; *) echo 'Persistenter Backup-Speicher erforderlich.' >&2; exit 1 ;;
esac
OPTIONS="$(findmnt -n -o OPTIONS -T /)"
RW_OWNED=0
SAVE=''
PENDING=''
PUBLISHED=0

# Same-filesystem rename publishes one complete script, never a partial file.
replace_bridge() {
  PENDING="$(mktemp /usr/local/sbin/.camera-rpi-admin-key.XXXXXXXX)" || return 1
  install -o root -g root -m "$MODE" -- "$1" "$PENDING" || return 1
  mv -f -- "$PENDING" "$TARGET" || return 1
  PENDING=''
}

# Restore the old bridge on a failed publication, then close only our RW window.
finish() {
  local rc=$?
  trap - EXIT HUP INT TERM
  if (( rc != 0 && PUBLISHED )); then
    if replace_bridge "$SAVE/camera-rpi-admin"; then
      echo 'Update fehlgeschlagen; vorheriger Admin wiederhergestellt.' >&2
    else
      echo "Rueckstellung fehlgeschlagen; Sicherung: $SAVE" >&2
    fi
  fi
  [[ -z "$PENDING" ]] || rm -f -- "$PENDING"
  if (( RW_OWNED )); then
    if ! mount -o remount,ro /; then
      echo 'ACHTUNG: Root-Dateisystem konnte nicht wieder RO gesetzt werden.' >&2
      rc=1
    fi
  fi
  exit "$rc"
}
trap finish EXIT
trap 'exit 129' HUP
trap 'exit 130' INT
trap 'exit 143' TERM
if [[ ",$OPTIONS," == *,ro,* ]]; then
  mount -o remount,rw /
  RW_OWNED=1
elif [[ ",$OPTIONS," != *,rw,* ]]; then
  echo 'Unbekannter Root-Mountzustand.' >&2; exit 1
fi
SAVE="$(mktemp -d /var/backups/camera-rpi-key-reuse.XXXXXXXX)"
cp -a -- "$TARGET" "$SAVE/camera-rpi-admin"
printf 'Sicherung: %s\n' "$SAVE"
PUBLISHED=1
replace_bridge "$SOURCE"
cmp -s "$SOURCE" "$TARGET"
sync -f "$TARGET"
echo 'OK: Gleiche Keys erlaubt; getrennte Dateien und Beibehalten bei leerem Feld unveraendert.'
echo 'Keine Keys/Konfigurationen/Hosts geaendert, keine Dienstneustarts oder Kamera-/GPIO-Aktion.'
