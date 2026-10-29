#!/usr/bin/env bash
# Inactive .16 migration update only. Existing webcam_wait/fan.py keep running;
# no APT, Apache reload, service start/stop, polling, capture or GPIO operation.
set -euo pipefail
umask 077

# Fixed, reviewable scope. Config is copied separately after strict validation;
# the existing key is read for validation but never copied, printed or changed.
ue_paths() {
  UE_SOURCES=(scripts/lib/camera-rpi-common.sh scripts/lib/camera-rpi-eos-legacy
    scripts/admin/camera-rpi-admin admin/public/index.php EOS-LEGACY.md)
  UE_PATHS=(usr/local/libexec/camera-rpi-common.sh usr/local/libexec/camera-rpi-eos-legacy
    usr/local/sbin/camera-rpi-admin usr/local/share/camera-rpi-admin/public/index.php
    usr/local/share/doc/camera-rpi/EOS-LEGACY.md etc/camera-rpi/servers/bielefeld-legacy.conf)
  UE_MODES=(0644 0755 0755 0644 0644 0600)
}

# Same-directory publication is atomic per file; services must remain inactive
# throughout. A failed multi-file update restores the complete before snapshot.
ue_publish_file() {
  local source="$1" relative="$2" mode="$3" temp
  li_no_symlink_parents "$relative" || return 1
  temp="$(mktemp "$LI_ROOT/$(dirname -- "$relative")/.camera-eos.XXXXXXXX")" || return 1
  if ! install -m "$mode" -- "$source" "$temp" || ! mv -T -- "$temp" "$LI_ROOT/$relative"; then
    rm -f -- "$temp"
    return 1
  fi
}

ue_snapshot() {
  local dest="$1" relative
  mkdir -p "$dest"
  for relative in "${UE_PATHS[@]}"; do
    li_no_symlink_parents "$relative" || return 1
    if [[ -e "$LI_ROOT/$relative" ]]; then
      [[ -f "$LI_ROOT/$relative" ]] || return 1
      (cd "$LI_ROOT" && cp -a --parents -- "$relative" "$dest/") || return 1
      cmp -s "$LI_ROOT/$relative" "$dest/$relative" || return 1
    fi
  done
}

# Preserve the replaced version as well. Newly added code is moved aside, not
# deleted; key/config/image/sensor directories are never recursive targets.
ue_restore() {
  local before="$1" after="$2" relative temp
  ue_snapshot "$after" || return 1
  for relative in "${UE_PATHS[@]}"; do
    if [[ -f "$before/$relative" ]]; then
      temp="$(mktemp "$LI_ROOT/$(dirname -- "$relative")/.camera-eos.XXXXXXXX")" || return 1
      cp -a -- "$before/$relative" "$temp" && mv -T -- "$temp" "$LI_ROOT/$relative" || return 1
    elif [[ -e "$LI_ROOT/$relative" ]]; then
      mkdir -p "$after/removed/$(dirname -- "$relative")" || return 1
      mv -T -- "$LI_ROOT/$relative" "$after/removed/$relative" || return 1
    fi
  done
}

# Preserve every other field (enabled=false, key_file, URL, timing, RO policy).
# Input was validated by cr_load_server_config; no shell config evaluation.
ue_render_config() {
  local line found=0
  while IFS= read -r line || [[ -n "$line" ]]; do
    if [[ "$line" =~ ^[[:space:]]*gphoto_capture_mode[[:space:]]*= ]]; then
      printf 'gphoto_capture_mode=eos-legacy\n'; found=1
    else
      printf '%s\n' "$line"
    fi
  done < "$1"
  (( found )) || printf 'gphoto_capture_mode=eos-legacy\n'
}

ue_main() (
  export PATH=/usr/sbin:/usr/bin:/sbin:/bin
  local mode="${1:---help}" project backup='' rw_owned=0 publishing=0 relative i opts
  local config=/etc/camera-rpi/servers/bielefeld-legacy.conf
  if [[ "$mode" == --help ]]; then
    printf 'bash ops/update-eos-legacy.sh --check|--apply|--rollback BACKUP\nNur inaktive Vorbereitung, Altbetrieb laeuft weiter.\n'
    return 0
  fi
  [[ ( $# == 1 && ( "$mode" == --check || "$mode" == --apply ) ) \
    || ( $# == 2 && "$mode" == --rollback ) ]] || return 2
  (( EUID == 0 )) || { printf 'Bitte als root ausfuehren.\n' >&2; return 1; }
  project="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")/.." && pwd -P)"
  (cd "$project" && sha256sum -c SHA256SUMS)
  source "$project/ops/lib/legacy-install.sh"
  LI_ROOT=/
  ue_paths
  [[ -f /etc/webcam.cfg && -x /usr/local/libexec/camera-rpi-worker \
    && -f /etc/machine-id ]] || { li_fail 'Erwartete inaktive Bestandskamera fehlt'; return 1; }
  for relative in "${UE_PATHS[@]}" var/backups etc/camera-rpi/keys; do
    li_no_symlink_parents "$relative"
  done
  li_require_quiet_new_stack
  exec 9>/run/lock/camera-rpi.operation.lock
  flock -n 9 || { li_fail 'Neue Kameraoperation aktiv'; return 1; }
  li_require_quiet_new_stack
  if [[ "$mode" == --rollback ]]; then
    backup="$2"
    [[ "$backup" =~ ^/var/backups/camera-rpi-eos-before\.[A-Za-z0-9]+$ \
      && ! -L "$backup" && "$(stat -c '%u:%a' "$backup")" == 0:700 ]] || return 1
    cmp -s /etc/machine-id "$backup/machine-id" || return 1
    (cd "$backup" && sha256sum -c before.sha256)
    # Never silently overwrite edits made after this specific update.
    (cd / && sha256sum -c "$backup/installed.sha256")
  else
    # Read-only SSH inventory of .16: do not overwrite unknown later hotfixes.
    # An already identical packaged version is allowed for a repeat update.
    local -a baseline=(
      7649a61f15417bcb6af192031cbe5742381095a2f37b2ee2b1a86b771bd1577c
      2d90771d432cf26041c2441c92593b3962e2a706e473ebe5cc2e3bc2ca9a5259
      b1f945bd62d294f4a5223547b1cd9debe8cc3983779c8f57fedd1a3d77b0c2c2)
    local n=0
    for i in 0 2 3; do
      if ! cmp -s "/${UE_PATHS[$i]}" "$project/${UE_SOURCES[$i]}"; then
        [[ "$(sha256sum "/${UE_PATHS[$i]}" | awk '{print $1}')" == "${baseline[$n]}" ]] \
          || { li_fail "Unbekannter Ausgangsstand: /${UE_PATHS[$i]}"; return 1; }
      fi
      ((n+=1))
    done
    source "$project/scripts/lib/camera-rpi-common.sh"
    cr_load_server_config "$config"
    [[ "$SERVER_ID" == bielefeld-legacy && "$CAMERA_SLUG" == bielefeld \
      && "$SERVER_BASE_URL" == http://192.0.2.2/webcam && "$ENABLED" == 0 \
      && "$CAPTURE_BACKEND" == gphoto2 && "$UPLOAD_MODE" == legacy ]] \
      || { li_fail 'Bielefeld-Ziel fehlt/ist aktiv oder weicht vom vorbereiteten Ziel ab'; return 1; }
    cr_read_secret "$KEY_FILE"; unset CR_SECRET
    for relative in scripts/lib/camera-rpi-common.sh scripts/lib/camera-rpi-eos-legacy scripts/admin/camera-rpi-admin; do
      bash -n "$project/$relative"
    done
    php -l "$project/admin/public/index.php"
    [[ -x "$project/scripts/lib/camera-rpi-eos-legacy" ]]
    [[ "$mode" != --check ]] || { printf 'OK: Inaktives EOS-Update moeglich; nichts geaendert.\n'; return 0; }
  fi
  case "$(findmnt -n -o FSTYPE -T /var/backups)" in
    ext4|ext3|ext2|btrfs|xfs|f2fs) ;; *) li_fail 'Persistenter lokaler Backup-Speicher erforderlich'; return 1 ;;
  esac
  ue_finish() {
    local result=$?
    trap - EXIT HUP INT TERM
    if (( publishing && result != 0 )); then
      if ue_restore "$backup/before" "$(mktemp -d "$backup/failed.XXXXXXXX")"; then
        printf 'Fehler: Ausgangsdateien wiederhergestellt; keine Dienste gestartet.\n' >&2
      else
        printf 'ACHTUNG: Rueckstellung unvollstaendig, Sicherung %s pruefen.\n' "$backup" >&2
      fi
    fi
    if (( rw_owned )); then
      sync
      mount -o remount,ro / || { printf 'ACHTUNG: Root blieb RW.\n' >&2; result=1; }
    fi
    [[ -z "$backup" ]] || printf 'Sicherung: %s\n' "$backup"
    exit "$result"
  }
  trap ue_finish EXIT
  trap 'exit 143' HUP TERM
  trap 'exit 130' INT
  opts="$(findmnt -n -o OPTIONS -T /)"
  case ",$opts," in
    *,ro,*) mount -o remount,rw /; rw_owned=1 ;;
    *,rw,*) ;; *) return 1 ;;
  esac
  if [[ "$mode" == --rollback ]]; then
    ue_restore "$backup/before" "$(mktemp -d "$backup/rollback.XXXXXXXX")"
    sync -f "$backup"
    printf 'OK: EOS-Update zurueckgesetzt; Zwischenstand gesichert, Altbetrieb unberuehrt.\n'
    return 0
  fi
  backup="$(mktemp -d /var/backups/camera-rpi-eos-before.XXXXXXXX)"
  ue_snapshot "$backup/before"
  cp /etc/machine-id "$backup/machine-id"
  # Independent persistent rollback script and its verified dependencies.
  install -D -m 0644 "$project/ops/update-eos-legacy.sh" "$backup/package/ops/update-eos-legacy.sh"
  install -D -m 0644 "$project/ops/lib/legacy-install.sh" "$backup/package/ops/lib/legacy-install.sh"
  (cd "$backup/package" && sha256sum ops/update-eos-legacy.sh ops/lib/legacy-install.sh > SHA256SUMS)
  (cd "$backup" && find before -type f -print0 | sort -z | xargs -0 sha256sum > before.sha256)
  ue_render_config "$config" > "$backup/after.conf"
  # Verify both the new reader and the actual installed worker without I/O.
  env -i PATH="$PATH" CAMERA_RPI_COMMON_LIB="$project/scripts/lib/camera-rpi-common.sh" \
    bash /usr/local/libexec/camera-rpi-worker --check-config "$backup/after.conf"
  sync -f "$backup"
  li_require_quiet_new_stack
  cmp -s "$config" "$backup/before/${config#/}"
  publishing=1
  for i in "${!UE_SOURCES[@]}"; do
    ue_publish_file "$project/${UE_SOURCES[$i]}" "${UE_PATHS[$i]}" "${UE_MODES[$i]}"
  done
  ue_publish_file "$backup/after.conf" "${config#/}" 0600
  env -i PATH="$PATH" bash /usr/local/libexec/camera-rpi-worker --check-config "$config"
  (cd / && sha256sum "${UE_PATHS[@]}" > "$backup/installed.sha256")
  sync -f "$backup"
  publishing=0
  printf 'OK: EOS-Legacy installiert, bielefeld-legacy bleibt enabled=false.\n'
  printf 'Alt-Poller/fan.py, Keys, Bilder, GPIO, Apache und Dienste unveraendert.\n'
  printf 'Rollback: bash %s/package/ops/update-eos-legacy.sh --rollback %s\n' "$backup" "$backup"
)

if [[ "${BASH_SOURCE[0]}" == "$0" ]]; then ue_main "$@"; fi
