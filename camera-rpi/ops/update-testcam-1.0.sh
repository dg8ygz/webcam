#!/usr/bin/env bash
# Scoped 13.09.2026 test-Pi migration to 1.0. No sensor/climate/DHT/tunnel
# restart, no APT, no reboot. Workers are paused together for atomic publication.
set -euo pipefail
umask 077
export PATH=/usr/sbin:/usr/bin:/sbin:/bin

# Fixed source/target/mode contract used for snapshots, publication and rollback.
# Only the new shared V4L2 profile is configuration; existing targets/keys stay.
release_paths() {
  UE_SOURCES=(scripts/camera-rpi-worker scripts/lib/camera-rpi-common.sh
    scripts/admin/camera-rpi-admin admin/public/index.php admin/public/style.css
    admin/src/bootstrap.php status/public/index.php status/public/style.css
    apache/camera-rpi-admin.conf scripts/lib/camera-rpi-pool.sh
    scripts/lib/camera-rpi-eos-legacy config/capture-pool.testcam.conf)
  UE_PATHS=(usr/local/libexec/camera-rpi-worker usr/local/libexec/camera-rpi-common.sh
    usr/local/sbin/camera-rpi-admin usr/local/share/camera-rpi-admin/public/index.php
    usr/local/share/camera-rpi-admin/public/style.css usr/local/share/camera-rpi-admin/src/bootstrap.php
    usr/local/share/camera-rpi-status/public/index.php usr/local/share/camera-rpi-status/public/style.css
    etc/apache2/conf-available/camera-rpi-admin.conf usr/local/libexec/camera-rpi-pool.sh
    usr/local/libexec/camera-rpi-eos-legacy etc/camera-rpi/capture-pool.conf)
  UE_MODES=(0755 0644 0755 0644 0644 0644 0644 0644 0644 0644 0755 0600)
  # These four installed files differ only by source comments/docstrings.
  # Atomic replacement leaves the running sensor/tunnel processes untouched.
  UE_SOURCES+=(scripts/camera-rpi-sensor scripts/lib/camera-rpi-jpeg.py scripts/lib/camera-rpi-weather.py scripts/camera-rpi-tunnel.py)
  UE_PATHS+=(usr/local/libexec/camera-rpi-sensor usr/local/libexec/camera-rpi-jpeg.py usr/local/libexec/camera-rpi-weather.py usr/local/libexec/camera-rpi-tunnel.py)
  UE_MODES+=(0755 0644 0644 0644)
  local doc
  for doc in VERSION README.md ARCHITEKTUR.md CAPTURE-POOL.md DEVELOPMENT.md FOCUS.md STATUSSEITE.md SOURCE-COMMENTS.md; do
    UE_SOURCES+=("$doc"); UE_PATHS+=("usr/local/share/doc/camera-rpi/$doc"); UE_MODES+=(0644)
  done
}

# These hashes came from a read-only inventory of the actual V4L2 test Pi.
# Refuse unknown hotfixes and an existing pool policy, rather than overwriting.
release_baseline() {
  local i
  local -a expected=(
    0f92e754ab18ef53372b456064ccf3ee53f2331dae640cc7fbc29376f362f9e9
    7649a61f15417bcb6af192031cbe5742381095a2f37b2ee2b1a86b771bd1577c
    2d90771d432cf26041c2441c92593b3962e2a706e473ebe5cc2e3bc2ca9a5259
    b1f945bd62d294f4a5223547b1cd9debe8cc3983779c8f57fedd1a3d77b0c2c2
    65154ff19cec3ed74a6871345e05be299128fb40b703804369980b00e3b88fbe
    bece2ccc5d67d5100605d42098650561ab58b8462ee10898c557a4c14ce105da
    01a6eb1515fbc829d0b296921653f8b8c98cc45001ca541927b6a98cdb87e524
    8d08329007c5c1cac9418914adaeb11e8799df9157c5b2de4fe215ce096d2971
    3f8c8ed2ad5fd6d1e80ddac239cf484d9af67573fd59c2b7a22334ff4e0e4434)
  for i in "${!expected[@]}"; do
    [[ "$(sha256sum "/${UE_PATHS[$i]}" | awk '{print $1}')" == "${expected[$i]}" ]] || {
      echo "Unbekannter Ausgangsstand: ${UE_PATHS[$i]}" >&2; return 1;
    }
  done
  for i in 9 10 11; do [[ ! -e "/${UE_PATHS[$i]}" && ! -L "/${UE_PATHS[$i]}" ]] || return 1; done
  expected=(2d6d55fb9dfa4d2daf51015f14153e4335c6a1b3db4fe2442e23ec6e22a946f5
    11113564bb8395a2edc9e6ddb39b0fde09384f6cef4be05ac54be8e194493c59
    b84e58b157802b4d6b1f1dec58a3ee2b56365d1a335a6cbf336bf6992d7c8f81
    61a245459cb522adbaca693c3783dc4c1d56a7e4243e15f6a7206ee668ac14dd)
  for i in "${!expected[@]}"; do
    [[ "$(sha256sum "/${UE_PATHS[$((i+12))]}" | awk '{print $1}')" == "${expected[$i]}" ]] || return 1
  done
}

# Coordinate just the two inventoried workers: preflight without interruption,
# snapshot before publication, validate installed files before releasing locks.
# Sensor/climate/DHT services and their boot state are deliberately out of scope.
release_main() (
  local mode="${1:-}" project backup='' candidate='' publishing=0 stopped=0 success=0 relative i config
  local -a active=() units=(camera-rpi-worker@test.service camera-rpi-worker@dev-test.service)
  [[ $EUID == 0 && ( ($# == 1 && ( "$mode" == --check || "$mode" == --apply )) || ($# == 2 && "$mode" == --rollback) ) ]]
  project="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd -P)"
  (cd "$project" && sha256sum -c SHA256SUMS)
  source "$project/ops/update-eos-legacy.sh"
  source "$project/ops/lib/legacy-install.sh"
  LI_ROOT=/
  release_paths
  [[ "$(hostname)" == raspberrypi && "$(findmnt -n -o FSTYPE -T /var/lib/camera-rpi/spool)" == tmpfs ]]
  [[ ",$(findmnt -n -o OPTIONS -T /)," == *,rw,* ]] || { echo 'Test-Pi muss bereits RW sein; kein implizites Remount' >&2; return 1; }
  for relative in "${UE_PATHS[@]}" var/backups; do li_no_symlink_parents "$relative"; done
  for config in /etc/camera-rpi/servers/*.conf; do
    [[ "$config" == /etc/camera-rpi/servers/test.conf || "$config" == /etc/camera-rpi/servers/dev-test.conf ]] || return 1
  done
  for relative in "${units[@]}"; do
    if systemctl is-active --quiet "$relative"; then active+=("$relative"); fi
  done

  # Restore code only while consumers are stopped. Never remove queue data;
  # restore previously active workers even after a failed pre-publication step.
  release_finish() {
    local rc=$?
    trap - EXIT HUP INT TERM
    if ((publishing && !success)); then
      if ! ue_restore "$backup/before" "$(mktemp -d "$backup/failed.XXXXXXXX")"; then
        echo "Rueckstellung fehlgeschlagen, Worker bleiben aus: $backup" >&2
        exit 1
      fi
      apache2ctl configtest && systemctl reload apache2 || rc=1
    fi
    flock -u 9 2>/dev/null || true
    if ((stopped)) && ((${#active[@]})); then systemctl start "${active[@]}" || rc=1; fi
    [[ -z "$backup" ]] || echo "Sicherung: $backup"
    exit "$rc"
  }
  trap release_finish EXIT
  trap 'exit 143' HUP TERM
  trap 'exit 130' INT

  if [[ "$mode" == --rollback ]]; then
    backup="$2"
    [[ "$backup" =~ ^/var/backups/camera-rpi-testcam-1\.0\.[A-Za-z0-9]+$ && ! -L "$backup" && "$(stat -c '%u:%a' "$backup")" == 0:700 ]]
    cmp /etc/machine-id "$backup/machine-id"
    (cd / && sha256sum -c "$backup/installed.sha256")
  else
    release_baseline
    candidate="$(mktemp -d /run/camera-testcam-check.XXXXXXXX)"
    mkdir "$candidate/servers"
    cp -a /etc/camera-rpi/servers/test.conf /etc/camera-rpi/servers/dev-test.conf "$candidate/servers/"
    install -m 0600 "$project/config/capture-pool.testcam.conf" "$candidate/capture-pool.conf"
    for config in "$candidate/servers/"*.conf; do
      env CAMERA_RPI_POOL_CONFIG="$candidate/capture-pool.conf" bash "$project/scripts/camera-rpi-worker" --check-config "$config"
    done
    (
      source "$project/scripts/lib/camera-rpi-common.sh"
      cr_load_server_config "$candidate/servers/dev-test.conf"
      [[ "$CAPTURE_BACKEND" == v4l2 && "$SPOOL_ROOT" == /var/lib/camera-rpi/spool ]]
    )
    for relative in scripts/camera-rpi-worker scripts/admin/camera-rpi-admin scripts/lib/camera-rpi-common.sh scripts/lib/camera-rpi-pool.sh; do bash -n "$project/$relative"; done
    for relative in admin/public/index.php admin/src/bootstrap.php status/public/index.php; do php -l "$project/$relative"; done
    [[ "$mode" != --check ]] || { echo 'OK: Testcam 1.0 vorbereitet, keine Dienste/Dateien installiert'; return; }
  fi

  # Stop gracefully, then take the shared camera lock. No unit enablement changes.
  stopped=1
  systemctl stop "${units[@]}"
  exec 9>/run/lock/camera-rpi.operation.lock
  flock -w 10 9
  for relative in "${units[@]}"; do [[ "$(systemctl show "$relative" -p MainPID --value)" == 0 ]]; done
  if [[ "$mode" == --rollback ]]; then
    # Old worker cannot interpret pool scope. Drain new requests before rollback.
    [[ -z "$(find /var/lib/camera-rpi/spool /run/camera-rpi -type f \( -name '*.request' -o -name '*.pending' \) -print -quit)" ]]
    if ! ue_restore "$backup/before" "$(mktemp -d "$backup/rollback.XXXXXXXX")"; then
      stopped=0
      echo 'Rollback unvollstaendig; Worker bleiben bewusst gestoppt' >&2
      return 1
    fi
    apache2ctl configtest
    systemctl reload apache2
    echo 'OK: Vorherige Software restauriert; JPEG/Pooldaten unveraendert'
    return
  fi
  release_baseline
  backup="$(mktemp -d /var/backups/camera-rpi-testcam-1.0.XXXXXXXX)"
  cp /etc/machine-id "$backup/machine-id"
  ue_snapshot "$backup/before"
  cp -a "$project" "$backup/package"
  sha256sum /etc/camera-rpi/servers/*.conf /etc/camera-rpi/keys/*.key /etc/camera-rpi/device.conf /etc/camera-rpi/climate.json > "$backup/config-before.sha256"
  publishing=1
  for i in "${!UE_PATHS[@]}"; do ue_publish_file "$project/${UE_SOURCES[$i]}" "${UE_PATHS[$i]}" "${UE_MODES[$i]}"; done
  for config in /etc/camera-rpi/servers/*.conf; do /usr/local/libexec/camera-rpi-worker --check-config "$config"; done
  apache2ctl configtest
  systemctl reload apache2
  sha256sum -c "$backup/config-before.sha256"
  (cd / && sha256sum "${UE_PATHS[@]}") > "$backup/installed.sha256"
  sync -f "$backup"
  success=1
  echo 'OK: Testcam 1.0, gemeinsamer V4L2-Pool (dev-test), Logs und Uebersicht / installiert'
  echo "Rollback: bash $backup/package/ops/update-testcam-1.0.sh --rollback $backup"
)

if [[ "${BASH_SOURCE[0]}" == "$0" ]]; then release_main "$@"; fi
