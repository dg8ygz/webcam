#!/usr/bin/env bash
# Import literal legacy identity/endpoint/key into an INACTIVE first target.
# No source/eval of webcam.cfg, no network, service starts or hardware access.
set +x
set -euo pipefail

# Strict literal assignments only; reject duplicates, expansion and trailing
# shell commands without printing potentially secret input. Comments are OK.
lt_read_literal() {
  local file="$1" name="$2" line value count=0
  local assignment="^[[:space:]]*(export[[:space:]]+)?${name}[[:space:]]*=(.*)$"
  local double='^"([A-Za-z0-9:/._~+=-]+)"([[:space:]]+#.*|[[:space:]]*)$'
  local single="^'([A-Za-z0-9:/._~+=-]+)'([[:space:]]+#.*|[[:space:]]*)$"
  local plain='^([A-Za-z0-9:/._~+=-]+)([[:space:]]+#.*|[[:space:]]*)$'
  unset LT_VALUE
  while IFS= read -r line || [[ -n "$line" ]]; do
    line="${line%$'\r'}"
    [[ "$line" =~ $assignment ]] || continue
    ((count += 1))
    value="${BASH_REMATCH[2]}"
    if [[ "$value" =~ $double || "$value" =~ $single || "$value" =~ $plain ]]; then
      LT_VALUE="${BASH_REMATCH[1]}"
    else
      unset LT_VALUE
      return 1
    fi
  done < "$file"
  if ((count != 1)); then unset LT_VALUE; return 1; fi
}

lt_render() {
  printf '%s\n' '# Inaktiver Import aus /etc/webcam.cfg; keine Hardwareabnahme.' \
    "server_id=$1" "camera_slug=$2" "server_base_url=$3" "key_file=$4" \
    'enabled=false' 'upload_mode=legacy' 'capture_backend=gphoto2' \
    'gphoto_capture_mode=eos-legacy' 'poll_seconds=5' 'error_backoff_seconds=20' \
    'connect_timeout_seconds=10' 'command_timeout_seconds=700' \
    'upload_timeout_seconds=90' 'gphoto_timeout_seconds=120' \
    'manage_root_mount=true' 'spool_root=/var/lib/camera-rpi/spool' \
    'runtime_root=/run/camera-rpi'
}

lt_main() (
  umask 077
  export PATH=/usr/sbin:/usr/bin:/sbin:/bin
  [[ $# == 2 && "$1" == --apply && "$2" =~ ^[a-z0-9][a-z0-9-]{0,63}$ ]] || {
    printf 'Aufruf: sudo bash ops/prepare-legacy-target.sh --apply TARGET-ID\n' >&2
    return 2
  }
  ((EUID == 0)) || return 1
  local id="$2" project backup='' key config slug url options rw_owned=0
  local key_stage='' config_stage=''
  project="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")/.." && pwd -P)"
  (cd "$project" && sha256sum -c SHA256SUMS)
  source "$project/ops/lib/legacy-install.sh"
  LI_ROOT=/
  li_require_quiet_new_stack
  [[ -f /etc/webcam.cfg && ! -L /etc/webcam.cfg && $(stat -c %u /etc/webcam.cfg) == 0 ]]
  for path in etc/camera-rpi/keys etc/camera-rpi/servers; do
    li_no_symlink_parents "$path"
    [[ $(stat -c '%u:%a' "/$path") == 0:700 ]]
  done
  key="/etc/camera-rpi/keys/$id.key"
  config="/etc/camera-rpi/servers/$id.conf"
  [[ ! -e "$key" && ! -L "$key" && ! -e "$config" && ! -L "$config" ]]
  exec 9>/run/lock/camera-rpi.operation.lock
  flock -n 9
  # Restore only a write window opened by this import; retain any partial
  # published configuration for inspection on failure (it remains disabled).
  lt_finish() {
    local result=$?
    trap - EXIT HUP INT TERM
    unset LT_VALUE
    [[ -z "$key_stage" ]] || rm -f -- "$key_stage"
    [[ -z "$config_stage" ]] || rm -f -- "$config_stage"
    if ((rw_owned)); then sync; mount -o remount,ro / || result=1; fi
    [[ -z "$backup" ]] || printf 'Sicherung: %s (privat)\n' "$backup"
    exit "$result"
  }
  trap lt_finish EXIT
  trap 'exit 143' HUP TERM
  trap 'exit 130' INT
  options="$(findmnt -n -o OPTIONS -T /)"
  case ",$options," in
    *,ro,*) mount -o remount,rw /; rw_owned=1 ;;
    *,rw,*) ;;
    *) return 1 ;;
  esac
  li_no_symlink_parents var/backups
  case "$(findmnt -n -o FSTYPE -T /var/backups)" in
    ext4|ext3|ext2|btrfs|xfs|f2fs) ;; *) return 1 ;;
  esac
  backup="$(mktemp -d /var/backups/camera-rpi-target.XXXXXXXX)"
  install -m 0600 /etc/webcam.cfg "$backup/webcam.cfg"
  cmp -s /etc/webcam.cfg "$backup/webcam.cfg"
  tar --acls --xattrs --numeric-owner -cpf "$backup/camera-rpi-before.tar" -C / etc/camera-rpi
  tar --compare -f "$backup/camera-rpi-before.tar" -C /
  lt_read_literal "$backup/webcam.cfg" wc_name
  slug="$LT_VALUE"
  [[ "$slug" =~ ^[A-Za-z0-9][A-Za-z0-9_-]{0,63}$ ]]
  lt_read_literal "$backup/webcam.cfg" wc_adr
  url="${LT_VALUE%/}"
  [[ "$url" == http://*/include || "$url" == https://*/include ]]
  url="${url%/include}"
  lt_read_literal "$backup/webcam.cfg" wc_key
  [[ "$LT_VALUE" =~ ^[A-Za-z0-9._~+/=-]{4,512}$ ]]
  printf '%s\n' "$LT_VALUE" > "$backup/$id.key"
  unset LT_VALUE
  lt_render "$id" "$slug" "$url" "$backup/$id.key" > "$backup/check.conf"
  env -i PATH="$PATH" bash /usr/local/libexec/camera-rpi-worker --check-config "$backup/check.conf"
  lt_render "$id" "$slug" "$url" "$key" > "$backup/$id.conf"
  (cd "$backup" && sha256sum webcam.cfg camera-rpi-before.tar "$id.key" "$id.conf" > SHA256SUMS)
  sync -f "$backup"
  li_require_quiet_new_stack
  cmp -s /etc/webcam.cfg "$backup/webcam.cfg"
  # Publish complete files by exclusive hard link: never overwrite an existing
  # target, including one created concurrently after the initial checks.
  key_stage="$(mktemp "/etc/camera-rpi/keys/.$id.XXXXXXXX")"
  config_stage="$(mktemp "/etc/camera-rpi/servers/.$id.XXXXXXXX")"
  install -o root -g root -m 0600 "$backup/$id.key" "$key_stage"
  install -o root -g root -m 0600 "$backup/$id.conf" "$config_stage"
  ln -T -- "$key_stage" "$key"
  ln -T -- "$config_stage" "$config"
  sync -f "$config"
  env -i PATH="$PATH" bash /usr/local/libexec/camera-rpi-worker --check-config "$config"
  cmp -s "$key" "$backup/$id.key"
  cmp -s /etc/webcam.cfg "$backup/webcam.cfg"
  li_require_quiet_new_stack
  printf 'OK: %s; Kamera %s, Basis %s, Legacy, enabled=false.\n' "$id" "$slug" "$url"
  printf 'Key unveraendert privat uebernommen; kein Poll, Dienststart oder GPIO.\n'
)

if [[ "${BASH_SOURCE[0]}" == "$0" ]]; then lt_main "$@"; fi
