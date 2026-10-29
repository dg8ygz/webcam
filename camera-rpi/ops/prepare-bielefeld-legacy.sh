#!/usr/bin/env bash
# Prepare only the existing .16 camera's inactive legacy target. Never source
# webcam.cfg: it is old shell configuration, not executable migration input.
# No command request, capture, service activation, GPIO or package changes.
set +x
set -euo pipefail

# Accept exactly one literal token, optionally quoted and with a shell comment.
# Ambiguous assignments/expansions fail closed. The secret stays in a shell
# variable and private files; never print it or pass it as an external argument.
bl_read_key() {
  local source_file="$1" line value count=0
  local assignment='^[[:space:]]*(export[[:space:]]+)?wc_key[[:space:]]*=(.*)$'
  local double='^"([A-Za-z0-9._~+/=-]{8,512})"([[:space:]]+#.*|[[:space:]]*)$'
  local single="^'([A-Za-z0-9._~+/=-]{8,512})'([[:space:]]+#.*|[[:space:]]*)$"
  local plain='^([A-Za-z0-9._~+/=-]{8,512})([[:space:]]+#.*|[[:space:]]*)$'
  unset BL_KEY
  while IFS= read -r line || [[ -n "$line" ]]; do
    line="${line%$'\r'}"
    [[ "$line" =~ $assignment ]] || continue
    ((count += 1))
    value="${BASH_REMATCH[2]}"
    # Shell assignments do not allow whitespace between '=' and their value.
    if [[ "$value" =~ $double || "$value" =~ $single || "$value" =~ $plain ]]; then
      BL_KEY="${BASH_REMATCH[1]}"
    else
      unset BL_KEY
      printf 'FEHLER: wc_key ist kein erlaubter literaler Token; nichts ausgefuehrt.\n' >&2
      return 1
    fi
  done < "$source_file"
  if (( count != 1 )); then
    unset BL_KEY
    printf 'FEHLER: Genau eine aktive wc_key-Zuweisung erforderlich.\n' >&2
    return 1
  fi
}

# The worker appends /include/{command,upload}.php itself. Keep the target
# disabled until EOS/IIO/cron/boot ownership has been accepted separately.
bl_render_config() {
  printf '%s\n' \
    '# Vorbereitete Bestandskamera .16; noch KEINE Umschaltung.' \
    'server_id=bielefeld-legacy' \
    'server_base_url=http://192.0.2.2/webcam' \
    'camera_slug=bielefeld' \
    "key_file=$1" \
    'enabled=false' \
    'upload_mode=legacy' \
    'capture_backend=gphoto2' \
    'gphoto_capture_mode=eos-legacy' \
    'poll_seconds=5' \
    'error_backoff_seconds=20' \
    'connect_timeout_seconds=10' \
    'command_timeout_seconds=700' \
    'upload_timeout_seconds=90' \
    'gphoto_timeout_seconds=120' \
    'manage_root_mount=true' \
    'spool_root=/var/lib/camera-rpi/spool' \
    'runtime_root=/run/camera-rpi'
}

bl_main() (
  umask 077
  export PATH=/usr/sbin:/usr/bin:/sbin:/bin
  local mode="${1:---help}" project backup='' key_stage='' config_stage='' rw_owned=0
  local key_path=/etc/camera-rpi/keys/bielefeld-legacy.key
  local config_path=/etc/camera-rpi/servers/bielefeld-legacy.conf
  if [[ "$mode" == --help ]]; then
    printf 'sudo bash %s --check|--apply\n' "$0"
    return 0
  fi
  [[ $# == 1 && ( "$mode" == --check || "$mode" == --apply ) ]] || return 2
  (( EUID == 0 )) || { printf 'Bitte als root ausfuehren; analyse darf die Zielkonfiguration nicht schreiben.\n' >&2; return 1; }
  project="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")/.." && pwd -P)"
  (cd "$project" && sha256sum -c SHA256SUMS) || return 1
  source "$project/ops/lib/legacy-install.sh"
  LI_ROOT=/
  [[ -f /etc/webcam.cfg && ! -L /etc/webcam.cfg && "$(stat -c '%u' /etc/webcam.cfg)" == 0 ]] \
    || { li_fail 'Erwartete root-eigene /etc/webcam.cfg fehlt'; return 1; }
  for path in etc/camera-rpi/servers etc/camera-rpi/keys; do
    li_no_symlink_parents "$path" || return 1
    [[ "$(stat -c '%u:%a' "/$path")" == 0:700 ]] \
      || { li_fail 'Private Zielordner der inaktiven Installation fehlen'; return 1; }
  done
  for path in "$key_path" "$config_path"; do
    [[ ! -e "$path" && ! -L "$path" ]] || { li_fail 'Ziel schon vorhanden; kein Ueberschreiben'; return 1; }
  done
  li_require_quiet_new_stack || return 1
  bl_read_key /etc/webcam.cfg || return 1
  unset BL_KEY
  if [[ "$mode" == --check ]]; then
    printf 'OK: Inaktiver Bielefeld-Legacy-Import moeglich; Key nicht ausgegeben, nichts geschrieben.\n'
    return 0
  fi

  # Serialize with new admin/capture/backup operations. Legacy readers are not
  # stopped: neither their source config nor their key will be changed.
  exec 9>/run/lock/camera-rpi.operation.lock
  flock -n 9 || { li_fail 'Kameraoperation aktiv'; return 1; }
  bl_finish() {
    local result=$?
    trap - EXIT HUP INT TERM
    unset BL_KEY
    [[ -z "$key_stage" ]] || rm -f -- "$key_stage"
    [[ -z "$config_stage" ]] || rm -f -- "$config_stage"
    if (( rw_owned )); then
      sync
      mount -o remount,ro / || { printf 'ACHTUNG: Root blieb RW.\n' >&2; result=1; }
    fi
    [[ -z "$backup" ]] || printf 'Sicherung: %s (privat; nicht veroeffentlichen)\n' "$backup"
    if (( result != 0 )); then
      printf 'Abbruch: kein Dienst gestartet. Eventuelle neue Teildateien bleiben zur Pruefung erhalten.\n' >&2
    fi
    exit "$result"
  }
  trap bl_finish EXIT
  trap 'exit 143' HUP TERM
  trap 'exit 130' INT
  local options
  options="$(findmnt -n -o OPTIONS -T /)"
  case ",$options," in
    *,ro,*) mount -o remount,rw /; rw_owned=1 ;;
    *,rw,*) ;;
    *) li_fail 'Root-Mountmodus unbekannt'; return 1 ;;
  esac

  # Save all existing new-stack configuration AND the old source privately.
  # Parse the verified copy, not a potentially changing live shell file.
  li_no_symlink_parents var/backups
  case "$(findmnt -n -o FSTYPE -T /var/backups)" in
    ext4|ext3|ext2|btrfs|xfs|f2fs) ;;
    *) li_fail 'Backup braucht lokalen persistenten Speicher'; return 1 ;;
  esac
  backup="$(mktemp -d /var/backups/camera-rpi-target.XXXXXXXX)"
  cp -a /etc/webcam.cfg "$backup/webcam.cfg"
  chmod 0600 "$backup/webcam.cfg"
  cmp -s /etc/webcam.cfg "$backup/webcam.cfg"
  tar --acls --xattrs --numeric-owner -cpf "$backup/camera-rpi-before.tar" -C / etc/camera-rpi
  tar --compare -f "$backup/camera-rpi-before.tar" -C /
  bl_read_key "$backup/webcam.cfg"
  printf '%s\n' "$BL_KEY" > "$backup/bielefeld-legacy.key"
  unset BL_KEY
  bl_render_config "$backup/bielefeld-legacy.key" > "$backup/check.conf"
  # check-config exits before runtime files, polling or any hardware access.
  env -i PATH="$PATH" bash /usr/local/libexec/camera-rpi-worker --check-config "$backup/check.conf"
  bl_render_config "$key_path" > "$backup/bielefeld-legacy.conf"
  (cd "$backup" && sha256sum webcam.cfg camera-rpi-before.tar bielefeld-legacy.key bielefeld-legacy.conf > SHA256SUMS)
  sync -f "$backup"

  # Same-directory staging + hard links publish complete files without ever
  # overwriting an existing target (even if it appeared after the preflight).
  li_require_quiet_new_stack
  cmp -s /etc/webcam.cfg "$backup/webcam.cfg"
  key_stage="$(mktemp /etc/camera-rpi/keys/.bielefeld-legacy.XXXXXXXX)"
  config_stage="$(mktemp /etc/camera-rpi/servers/.bielefeld-legacy.XXXXXXXX)"
  install -o root -g root -m 0600 "$backup/bielefeld-legacy.key" "$key_stage"
  install -o root -g root -m 0600 "$backup/bielefeld-legacy.conf" "$config_stage"
  ln -T -- "$key_stage" "$key_path"
  ln -T -- "$config_stage" "$config_path"
  sync -f "$config_path"
  env -i PATH="$PATH" bash /usr/local/libexec/camera-rpi-worker --check-config "$config_path"
  cmp -s "$key_path" "$backup/bielefeld-legacy.key"
  cmp -s /etc/webcam.cfg "$backup/webcam.cfg"
  li_require_quiet_new_stack
  printf 'OK: bielefeld-legacy vorbereitet; Kamera bielefeld, http://192.0.2.2/webcam, Legacy, Poll 700s, enabled=false.\n'
  printf 'Key unveraendert uebernommen (root:root/0600). Altbetrieb unveraendert; kein Dienststart, Poll, Upload oder GPIO.\n'
)

# Tests source only the pure token reader/renderer with fake credentials.
if [[ "${BASH_SOURCE[0]}" == "$0" ]]; then
  bl_main "$@"
fi
