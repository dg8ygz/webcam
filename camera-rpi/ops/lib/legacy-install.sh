#!/usr/bin/env bash
# Filesystem-only transaction primitives. LI_ROOT is set by the caller, never
# taken from the environment by the production entry point. Tests use a private
# fixture root. The service guard only queries systemctl; nothing here changes
# services, mounts, packages or GPIO.

li_fail() { printf 'FEHLER: %s\n' "$*" >&2; return 1; }

# An invalid password hash is deliberate: no empty/default/random shared
# credential and no interactive prompt during an inactive remote installation.
# Only a fresh private payload may receive this entry; never replace a login.
li_write_locked_admin() {
  local target="$1" user="$2"
  [[ "$user" =~ ^[A-Za-z0-9._-]{1,64}$ ]] || return 1
  (umask 077; set -o noclobber; printf '%s:!\n' "$user" > "$target")
}

# Check both installed unit files and instantiated/transient services. Failure
# to query systemd is NOT equivalent to an idle camera. Tests stub these reads.
li_require_quiet_new_stack() {
  local unit state units active
  # systemctl returns 1 for a glob with no matching unit files (normal on a
  # legacy camera). Query the catalog without a glob, then select our namespace
  # here. Do NOT accept exit 1 generally: real lookup/permission errors must fail.
  units="$(systemctl list-unit-files --no-legend --no-pager)" \
    || { li_fail 'systemd-Unitdateien konnten nicht abgefragt werden'; return 1; }
  while read -r unit state _; do
    [[ "$unit" == camera-rpi-* ]] || continue
    case "$state" in
      disabled|static|masked|indirect) ;;
      *) li_fail "Neuer Dienst noch aktiviert: $unit"; return 1 ;;
    esac
    # Templates have no runnable ActiveState; their instances are checked below.
    [[ "$unit" != *@.* ]] || continue
    case "$(systemctl show "$unit" -p ActiveState --value)" in
      inactive|failed) ;;
      *) li_fail "Neuer Dienst noch aktiv: $unit"; return 1 ;;
    esac
  done <<< "$units"
  active="$(systemctl list-units 'camera-rpi-*' --state=active,activating,deactivating --no-legend --no-pager)" \
    || { li_fail 'Aktive Camera-RPi-Dienste konnten nicht abgefragt werden'; return 1; }
  [[ -z "$active" ]] || { li_fail 'Camera-RPi-Prozess/Dienst noch aktiv'; return 1; }
  [[ ! -e "$LI_ROOT/etc/apache2/conf-enabled/camera-rpi-admin.conf" \
     && ! -L "$LI_ROOT/etc/apache2/conf-enabled/camera-rpi-admin.conf" ]] \
    || { li_fail 'Neue Apache-Konfiguration zuerst bewusst deaktivieren und Apache reloaden'; return 1; }
}

# Both the package manifest and rollback scope are data, never shell input.
# Paths cannot escape their root; destinations must stay in Camera-RPi namespaces.
li_path_valid() {
  [[ "$1" =~ ^[A-Za-z0-9_./@-]+$ && "$1" != /* && "$1" != */ \
     && "/$1/" != */../* && "/$1/" != */./* && "$1" != *//* ]]
}

li_load_manifest() {
  local source target mode extra
  LI_SOURCES=(); LI_DESTINATIONS=(); LI_MODES=(); LI_TARGETS=()
  while IFS='|' read -r source target mode extra; do
    [[ -n "$source" && "$source" != \#* ]] || continue
    li_path_valid "$source" && li_path_valid "$target" && [[ -z "$extra" ]] \
      || { li_fail 'Ungueltiger Manifestpfad'; return 1; }
    case "$target" in
      usr/local/libexec/camera-rpi-*|usr/local/libexec/climate-*|usr/local/libexec/gpio-config.php|usr/local/libexec/capture-privacy.php|usr/local/libexec/camera-source.php|usr/local/sbin/camera-rpi-*|\
      etc/systemd/system/camera-rpi-*|etc/tmpfiles.d/camera-rpi*|\
      etc/camera-rpi/*|usr/local/share/camera-rpi-admin/*|usr/local/share/camera-rpi-status/*|\
      usr/local/share/doc/camera-rpi/*|etc/apache2/conf-available/camera-rpi-admin.conf|\
      etc/sudoers.d/camera-rpi-admin) ;;
      *) li_fail 'Ziel ausserhalb des Camera-RPi-Bereichs'; return 1 ;;
    esac
    [[ "$mode" == 0644 || "$mode" == 0755 || "$mode" == 0600 || "$mode" == 0440 ]] \
      || { li_fail 'Ungueltiger Dateimodus'; return 1; }
    [[ ! " ${LI_DESTINATIONS[*]} " == *" $target "* ]] \
      || { li_fail 'Doppeltes Manifestziel'; return 1; }
    LI_SOURCES+=("$source"); LI_DESTINATIONS+=("$target"); LI_MODES+=("$mode")
  done < "$1"
  ((${#LI_SOURCES[@]} > 0)) || { li_fail 'Leeres Manifest'; return 1; }
  # Whole application-owned directories include later keys, images and archives.
  # Shared parents (/etc, /usr/local, /run) are NEVER restoration/deletion targets.
  LI_TARGETS=(etc/camera-rpi var/lib/camera-rpi run/camera-rpi run/camera-rpi-status
    run/camera-rpi-admin run/camera-rpi-climate run/camera-rpi-maintenance
    usr/local/share/camera-rpi-admin usr/local/share/camera-rpi-status usr/local/share/doc/camera-rpi)
  for target in "${LI_DESTINATIONS[@]}"; do
    case "$target" in
      etc/camera-rpi/*|usr/local/share/camera-rpi-admin/*|usr/local/share/camera-rpi-status/*|usr/local/share/doc/camera-rpi/*) ;;
      *) LI_TARGETS+=("$target") ;;
    esac
  done
  LI_TARGETS+=(usr/local/libexec/camera-rpi-pwm)
}

# Reject symlink parents even when the final target does not yet exist. Never
# follow a replaced application directory into unrelated files during rollback.
li_no_symlink_parents() {
  local relative="$1" current="$LI_ROOT" part
  local -a parts
  IFS=/ read -r -a parts <<< "$relative"
  for part in "${parts[@]}"; do
    current="$current/$part"
    [[ ! -L "$current" ]] || { li_fail "Symlink im Zielpfad: $relative"; return 1; }
  done
}

li_require_fresh() {
  local target
  for target in "${LI_TARGETS[@]}"; do
    li_no_symlink_parents "$target" || return 1
    [[ ! -e "$LI_ROOT/$target" ]] \
      || { li_fail "Schon vorhanden; kein Erstinstallationsziel: /$target"; return 1; }
  done
}

# Materialize the complete payload in the private persistent backup before any
# live publication. Special generated files (native PWM, htpasswd) are added by
# the entry point. Defaults remain sensor=false / climate=off, no target keys.
li_prepare_payload() {
  local project="$1" payload="$2" i source target
  for i in "${!LI_SOURCES[@]}"; do
    source="$project/${LI_SOURCES[$i]}"; target="$payload/${LI_DESTINATIONS[$i]}"
    [[ -f "$source" && ! -L "$source" ]] || { li_fail 'Quelldatei fehlt oder ist ein Symlink'; return 1; }
    install -D -m "${LI_MODES[$i]}" -- "$source" "$target" || return 1
  done
}

li_publish() {
  local payload="$1" target parent
  # Absence is checked again under the caller's operation lock, immediately
  # before publication. No shared-parent chmod or old-config overwrite.
  li_require_fresh || return 1
  # Arm the entry point's failure handler only AFTER the final absence check.
  PUBLISHED=1
  for target in "${LI_TARGETS[@]}"; do
    [[ -e "$payload/$target" ]] || continue
    parent="$(dirname -- "$LI_ROOT/$target")"
    [[ -d "$parent" ]] || install -d -m 0755 -- "$parent" || return 1
    cp -a -- "$payload/$target" "$LI_ROOT/$target" || return 1
  done
}

# Preserve current (not just installation-time) contents, then move the exact
# application targets into quarantine. On ENOSPC/tar/verification failure no
# target is moved. Successful moves are recoverable; nothing is rm -rf'ed.
# Data directories remain additionally at their original location. In particular
# do not attempt mv/unmount on an optionally mounted RAM-spool directory.
# Services and admin writes MUST be quiescent; caller holds operation lock.
li_quarantine() {
  local backup="$1" target recovery
  for target in "${LI_TARGETS[@]}"; do li_no_symlink_parents "$target" || return 1; done
  recovery="$(mktemp -d "$backup/rollback.XXXXXXXX")" || return 1
  : > "$recovery/paths.list"
  for target in "${LI_TARGETS[@]}"; do
    [[ ! -e "$LI_ROOT/$target" ]] || printf '%s\n' "$target" >> "$recovery/paths.list"
  done
  tar --acls --xattrs --numeric-owner -cpf "$recovery/current.tar" \
    -C "$LI_ROOT" -T "$recovery/paths.list" || return 1
  tar --acls --xattrs --compare -f "$recovery/current.tar" -C "$LI_ROOT" || return 1
  (cd "$recovery" && sha256sum current.tar > SHA256SUMS && sha256sum -c SHA256SUMS) || return 1
  sync -f "$recovery/current.tar" || return 1
  for target in "${LI_TARGETS[@]}"; do
    [[ -e "$LI_ROOT/$target" ]] || continue
    case "$target" in var/lib/*|run/*) continue ;; esac
    install -d -m 0700 -- "$(dirname -- "$recovery/files/$target")" || return 1
    mv -- "$LI_ROOT/$target" "$recovery/files/$target" || return 1
  done
  sync -f "$recovery" || return 1
  printf 'Rueckbau: aktuelle Daten unverkuerzt gesichert unter %s\n' "$recovery"
}
