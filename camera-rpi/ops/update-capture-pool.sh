#!/usr/bin/env bash
# Coordinated .16 update. No capture, focus, GPIO, service or boot-state change.
# All consumers must be stopped while the common worker/library is replaced.
set -euo pipefail
umask 077

# Only the files enumerated below are deployed. Other settings remain unchanged.
up_paths() {
  UE_SOURCES=(scripts/camera-rpi-worker scripts/lib/camera-rpi-pool.sh
    scripts/admin/camera-rpi-admin admin/public/index.php admin/public/style.css
    CAPTURE-POOL.md config/capture-pool.bielefeld.conf)
  UE_PATHS=(usr/local/libexec/camera-rpi-worker usr/local/libexec/camera-rpi-pool.sh
    usr/local/sbin/camera-rpi-admin usr/local/share/camera-rpi-admin/public/index.php
    usr/local/share/camera-rpi-admin/public/style.css
    usr/local/share/doc/camera-rpi/CAPTURE-POOL.md etc/camera-rpi/capture-pool.conf)
  UE_MODES=(0755 0644 0755 0644 0644 0644 0600)
}

# Check all instances, including failed units with a surviving control process.
up_stopped() {
  local config unit state pid control active legacy_name legacy_rc
  for legacy_name in webcam_wait webcam_capture; do
    legacy_rc=0
    /usr/bin/pgrep -x "$legacy_name" >/dev/null || legacy_rc=$?
    if ((legacy_rc == 0)); then
      li_fail 'Alter Kamera-Poller aktiv; auch webcam_check-Cronjob deaktivieren'
      return 1
    fi
    ((legacy_rc == 1)) || return 1
  done
  if [[ -r /etc/crontab ]] && awk '!/^[[:space:]]*#/ && /\/usr\/local\/bin\/webcam_(check|wait)([[:space:]]|$)/ { found=1 } END {exit !found}' /etc/crontab; then
    li_fail 'Alter webcam_check/webcam_wait-Cronjob noch aktiv'
    return 1
  fi
  active="$(systemctl list-units 'camera-rpi-worker@*.service' --state=active,activating,deactivating --no-legend --no-pager)" || return 1
  [[ -z "$active" ]] || { li_fail 'Zuerst alle Kamera-Worker stoppen'; return 1; }
  for config in /etc/camera-rpi/servers/*.conf; do
    unit="camera-rpi-worker@$(basename "$config" .conf).service"
    state="$(systemctl show "$unit" -p ActiveState --value)" || return 1
    pid="$(systemctl show "$unit" -p MainPID --value)" || return 1
    control="$(systemctl show "$unit" -p ControlPID --value)" || return 1
    [[ ( "$state" == inactive || "$state" == failed ) && "$pid" == 0 && "$control" == 0 ]] || return 1
  done
}

# Refuse unknown local hotfixes. Identical reinstallation is permitted.
up_baseline() {
  local i actual expected
  local -a hashes=(eafb2315ebe5c2deb62d4d65d2573169bb682bb829aa6d973031b30052c6c359 absent
    5a1dddedc5c1d2c0336eddad67769368a9c8613c6021f26774c0e423f9e24eb9
    8ccd2d822ec74ffc8317d97d412e6c019bc4a41c5063501691b1dafb28975170
    65154ff19cec3ed74a6871345e05be299128fb40b703804369980b00e3b88fbe absent absent)
  for i in "${!UE_PATHS[@]}"; do
    cmp -s "$LI_ROOT/${UE_PATHS[$i]}" "$project/${UE_SOURCES[$i]}" && continue
    expected="${hashes[$i]}"
    if [[ "$expected" == absent ]]; then
      [[ ! -e "$LI_ROOT/${UE_PATHS[$i]}" && ! -L "$LI_ROOT/${UE_PATHS[$i]}" ]] || return 1
    else
      actual="$(sha256sum "$LI_ROOT/${UE_PATHS[$i]}" | awk '{print $1}')"
      [[ "$actual" == "$expected" ]] || { li_fail "Unbekannter Ausgangsstand: ${UE_PATHS[$i]}"; return 1; }
    fi
  done
}

# Old workers cannot interpret new request scope markers. Do not discard or
# silently rewrite pending work on rollback: drain it first with this version.
# JPEG hardlinks/sidecars remain readable by the old upload implementation.
up_rollback_queue_clear() {
  local pending
  pending="$(find /var/lib/camera-rpi/spool /run/camera-rpi -type f \( -name '*.request' -o -name '*.pending' \) -print -quit)"
  [[ -z "$pending" ]] || { li_fail 'Rollback blockiert: offene Aufnahmeauftraege zuerst abarbeiten; nichts geloescht'; return 1; }
}

# Subshell confines traps/locks/remount ownership to one installation attempt.
# Order: verify package + stopped consumers, validate baseline/profile, snapshot,
# publish, validate installed files. Failure restores the snapshot, not queues.
up_main() (
  export PATH=/usr/sbin:/usr/bin:/sbin:/bin
  local mode="${1:---help}" project backup='' candidate='' publishing=0 rw_owned=0 config relative i
  [[ "$mode" != --help ]] || { echo 'bash ops/update-capture-pool.sh --check|--apply|--rollback BACKUP'; return; }
  [[ ( $# == 1 && ( "$mode" == --apply || "$mode" == --check ) ) || ( $# == 2 && "$mode" == --rollback ) ]]
  (( EUID == 0 )) || { echo 'Bitte als root ausfuehren.' >&2; return 1; }
  project="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd -P)"
  (cd "$project" && sha256sum -c SHA256SUMS)
  source "$project/ops/update-eos-legacy.sh"
  source "$project/ops/lib/legacy-install.sh"
  LI_ROOT=/
  up_paths
  for relative in "${UE_PATHS[@]}" var/backups; do li_no_symlink_parents "$relative"; done
  up_stopped
  exec 9>/run/lock/camera-rpi.operation.lock
  flock -n 9 || { li_fail 'Kameraoperation aktiv'; return 1; }
  up_stopped
  [[ "$(findmnt -n -o FSTYPE -T /var/lib/camera-rpi/spool)" == tmpfs ]]
  case "$(findmnt -n -o FSTYPE -T /var/backups)" in ext4|ext3|ext2|btrfs|xfs|f2fs) ;; *) return 1 ;; esac

  # Fail closed and restore the whole publication on error. Never open an RW
  # window for tests; close only the remount window owned by this invocation.
  up_finish() {
    local rc=$?
    trap - EXIT HUP INT TERM
    if (( publishing && rc != 0 )); then
      ue_restore "$backup/before" "$(mktemp -d "$backup/failed.XXXXXXXX")" || echo "ACHTUNG: Rueckstellung pruefen: $backup" >&2
    fi
    if [[ -n "$candidate" ]]; then
      # Private mktemp directory created here; contains config copies, no keys.
      rm -rf -- "$candidate"
    fi
    if (( rw_owned )); then sync; mount -o remount,ro / || rc=1; fi
    [[ -z "$backup" ]] || printf 'Sicherung: %s\n' "$backup"
    (( rc == 0 )) || echo 'Update nicht abgeschlossen; Worker bleiben gestoppt.' >&2
    exit "$rc"
  }
  trap up_finish EXIT
  trap 'exit 143' HUP TERM
  trap 'exit 130' INT
  if [[ "$mode" == --rollback ]]; then
    backup="$2"
    [[ "$backup" =~ ^/var/backups/camera-rpi-pool\.[A-Za-z0-9]+$ && ! -L "$backup" && "$(stat -c '%u:%a' "$backup")" == 0:700 ]]
    cmp -s /etc/machine-id "$backup/machine-id"
    (cd "$backup" && sha256sum -c before.sha256)
    (cd / && sha256sum -c "$backup/installed.sha256")
    up_rollback_queue_clear
  else
    up_baseline
    cmp -s /usr/local/libexec/camera-rpi-common.sh "$project/scripts/lib/camera-rpi-common.sh"
    bash -n "$project/scripts/camera-rpi-worker"
    bash -n "$project/scripts/lib/camera-rpi-pool.sh"
    bash -n "$project/scripts/admin/camera-rpi-admin"
    php -l "$project/admin/public/index.php"
    candidate="$(mktemp -d /run/camera-rpi-pool-check.XXXXXXXX)"
    mkdir "$candidate/servers"
    for config in /etc/camera-rpi/servers/*.conf; do
      [[ ! -L "$config" && -f "$config" ]]
      cp -a "$config" "$candidate/servers/"
    done
    install -m 0600 "$project/config/capture-pool.bielefeld.conf" "$candidate/capture-pool.conf"
    # Validate the shared profile without polling, camera access or secret output.
    for config in "$candidate/servers/"*.conf; do
      env -i PATH="$PATH" CAMERA_RPI_POOL_CONFIG="$candidate/capture-pool.conf" \
        bash "$project/scripts/camera-rpi-worker" --check-config "$config"
    done
    (
      source "$project/scripts/lib/camera-rpi-common.sh"
      cr_load_server_config "$candidate/servers/bielefeld-legacy.conf"
      [[ "$CAPTURE_BACKEND" == gphoto2 && "$GPHOTO_CAPTURE_MODE" == eos-legacy && "$ENABLED" == 1 \
        && "$SPOOL_ROOT" == /var/lib/camera-rpi/spool ]]
    )
    [[ "$mode" != --check ]] || { echo 'OK: Pool-Update moeglich; nichts installiert.'; return; }
  fi
  case ",$(findmnt -n -o OPTIONS -T /)," in
    *,ro,*) mount -o remount,rw /; rw_owned=1 ;; *,rw,*) ;; *) return 1 ;;
  esac
  if [[ "$mode" == --rollback ]]; then
    ue_restore "$backup/before" "$(mktemp -d "$backup/rollback.XXXXXXXX")"
    sync -f "$backup"
    echo 'OK: Vorherige Software restauriert. Worker bleiben aus; DEV nicht mit altem Standard-Profil starten.'
    echo 'Bilder/Sidecars und RAM-Pool bleiben erhalten; keine automatische Pool-Bereinigung nach Rollback.'
    return
  fi
  backup="$(mktemp -d /var/backups/camera-rpi-pool.XXXXXXXX)"
  ue_snapshot "$backup/before"
  cp /etc/machine-id "$backup/machine-id"
  for relative in ops/update-capture-pool.sh ops/update-eos-legacy.sh ops/lib/legacy-install.sh; do
    install -D -m 0644 "$project/$relative" "$backup/package/$relative"
  done
  (cd "$backup/package" && find ops -type f -print0 | sort -z | xargs -0 sha256sum > SHA256SUMS)
  (cd "$backup" && find before -type f -print0 | sort -z | xargs -0 sha256sum > before.sha256)
  sync -f "$backup"
  up_stopped
  publishing=1
  for i in "${!UE_PATHS[@]}"; do
    ue_publish_file "$project/${UE_SOURCES[$i]}" "${UE_PATHS[$i]}" "${UE_MODES[$i]}"
  done
  for config in /etc/camera-rpi/servers/*.conf; do
    env -i PATH="$PATH" bash /usr/local/libexec/camera-rpi-worker --check-config "$config"
  done
  (cd / && sha256sum "${UE_PATHS[@]}" > "$backup/installed.sha256")
  sync -f "$backup"
  publishing=0
  echo 'OK: Gemeinsamer RAM-Pool, EOS-Legacy-Profil fuer alle Ziele und letzte 10 Logs installiert.'
  echo 'Maximal 10 wartende Bilder je Ziel; laufende Uploads geschuetzt. Keys und Zielkonfigurationen unveraendert.'
  echo 'Keine Aufnahme/Fokusbewegung, kein Dienststart; Sensor, Klima und Boot-Aktivierung unveraendert.'
  printf 'Rollback (alle Worker stoppen; Aufnahmeauftraege vorher abarbeiten): bash %s/package/ops/update-capture-pool.sh --rollback %s\n' "$backup" "$backup"
)

if [[ "${BASH_SOURCE[0]}" == "$0" ]]; then up_main "$@"; fi
