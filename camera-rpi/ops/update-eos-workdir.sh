#!/usr/bin/env bash
# Targeted .16 repair: EOS CWD, legacy ACK/frames/time and bounded ISO control.
# Never starts/enables/stops a service, never captures/polls or changes GPIO.
# Reuse the reviewed atomic file/backup primitives, not the inactive install's
# main entry point (sensor/climate and Apache are already live on this camera).
set -euo pipefail
umask 077

ew_paths() {
  UE_SOURCES=(scripts/camera-rpi-worker scripts/lib/camera-rpi-eos-legacy EOS-LEGACY.md)
  UE_PATHS=(usr/local/libexec/camera-rpi-worker usr/local/libexec/camera-rpi-eos-legacy
    usr/local/share/doc/camera-rpi/EOS-LEGACY.md)
  UE_MODES=(0755 0755 0644)
}

# Query failures are fatal. Stop/disable was the operator's deliberate choice;
# this repair must not undo it. Other instances share these program files too.
ew_require_stopped() {
  local active state enabled main_pid control_pid
  state="$(systemctl show camera-rpi-worker@bielefeld-legacy.service -p ActiveState --value)" || return 1
  enabled="$(systemctl show camera-rpi-worker@bielefeld-legacy.service -p UnitFileState --value)" || return 1
  main_pid="$(systemctl show camera-rpi-worker@bielefeld-legacy.service -p MainPID --value)" || return 1
  control_pid="$(systemctl show camera-rpi-worker@bielefeld-legacy.service -p ControlPID --value)" || return 1
  # SIGTERM can leave this Bash unit in failed/143 after an intentional stop.
  # Accept that only when both main/control processes are actually gone.
  [[ ( "$state" == inactive || "$state" == failed ) && "$enabled" == disabled \
    && "$main_pid" == 0 && "$control_pid" == 0 ]] \
    || { li_fail 'Bielefeld-Worker muss bereits gestoppt und disabled sein'; return 1; }
  active="$(systemctl list-units 'camera-rpi-worker@*.service' --state=active,activating,deactivating --no-legend --no-pager)" || return 1
  [[ -z "$active" ]] || { li_fail 'Ein anderer neuer Worker ist noch aktiv'; return 1; }
}

ew_main() (
  export PATH=/usr/sbin:/usr/bin:/sbin:/bin
  local mode="${1:---help}" project backup='' rw_owned=0 publishing=0 i relative opts
  [[ "$mode" != --help ]] || {
    printf 'bash ops/update-eos-workdir.sh --check|--apply|--rollback BACKUP\nWorker bleibt gestoppt/disabled; keine Aufnahme.\n'
    return 0
  }
  [[ ( $# == 1 && ( "$mode" == --check || "$mode" == --apply ) ) \
    || ( $# == 2 && "$mode" == --rollback ) ]] || return 2
  (( EUID == 0 )) || { printf 'Bitte als root ausfuehren.\n' >&2; return 1; }
  project="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")/.." && pwd -P)"
  (cd "$project" && sha256sum -c SHA256SUMS)
  source "$project/ops/update-eos-legacy.sh"
  source "$project/ops/lib/legacy-install.sh"
  LI_ROOT=/
  ew_paths
  [[ -f /etc/webcam.cfg && -f /etc/camera-rpi/servers/bielefeld-legacy.conf ]]
  for relative in "${UE_PATHS[@]}" var/backups; do li_no_symlink_parents "$relative"; done
  ew_require_stopped
  exec 9>/run/lock/camera-rpi.operation.lock
  flock -n 9 || { li_fail 'Kameraoperation aktiv'; return 1; }
  ew_require_stopped
  if [[ "$mode" == --rollback ]]; then
    backup="$2"
    [[ "$backup" =~ ^/var/backups/camera-rpi-eos-workdir\.[A-Za-z0-9]+$ \
      && ! -L "$backup" && "$(stat -c '%u:%a' "$backup")" == 0:700 ]]
    cmp -s /etc/machine-id "$backup/machine-id"
    (cd "$backup" && sha256sum -c before.sha256)
    (cd / && sha256sum -c "$backup/installed.sha256")
  else
    # Exact read-only SSH inventory, or an identical already installed repair.
    # Do not silently replace a different operator hotfix.
    local installed_hash
    local -a baseline=(
      0f92e754ab18ef53372b456064ccf3ee53f2331dae640cc7fbc29376f362f9e9:010ede2fe3e2317a67b061f5802496aef8deb5bfef6fe10b0ba8ab9e70b6416f:39d9913e82d56235b0336bf7ccdaab5a20c2863efc7e5484bae0735879ba7356:69eed641b5956f00347502f1729944d9c66f7ba06147105adbe9b98842f365fa
      3334b01155c0338ccde044bdadc6e1c792db7ab4efdac8cd46094645cadee781:09912a125ad3372fb4fb6984eca65dc449e00c05d1e4fdb4126b34703d88530c
      f4b1883367b4b4f9ac1c4722f071abc88b59f4f1a957f34e0fa172eb25ec8c57:a3d342545e502902d6ae802950444721486597451c8b52413c67ddc524da1984:be6f9b2b0cb52af0fa7cc813205a5e143c318c3e0fa85425a365eb83ae2e6ad3:32c2546cd943df57a2ba32ba27c2b136ed778996436db8af30f2d35a0a6229ff)
    # Verified installed timestamp repair (12.09.2026, archive slot accepted).
    baseline[0]+=:9a7e4db9b1755a0e9534bb28aa0dc7961138ace7580f5b4929adb01f14788868
    baseline[2]+=:f3ab7b456d1042fe9323d7b7b6cc79087a20c579394a5eca5a969d6040129854
    # Installed ISO package documentation predates its successful hardware run.
    # Permit that verified version when publishing the updated acceptance notes.
    baseline[2]+=:ce3c083989df9159d508738f445a2f32b1cc84779fbb5ec3ca40550ac58f545c
    for i in "${!UE_PATHS[@]}"; do
      if ! cmp -s "/${UE_PATHS[$i]}" "$project/${UE_SOURCES[$i]}"; then
        installed_hash="$(sha256sum "/${UE_PATHS[$i]}" | awk '{print $1}')"
        [[ ":${baseline[$i]}:" == *":$installed_hash:"* ]] \
          || { li_fail "Unbekannter Ausgangsstand: /${UE_PATHS[$i]}"; return 1; }
      fi
    done
    bash -n "$project/scripts/camera-rpi-worker"
    bash -n "$project/scripts/lib/camera-rpi-eos-legacy"
    env -i PATH="$PATH" bash "$project/scripts/camera-rpi-worker" --check-config \
      /etc/camera-rpi/servers/bielefeld-legacy.conf
    [[ "$mode" != --check ]] || { printf 'OK: Update moeglich; nichts geaendert.\n'; return 0; }
  fi
  case "$(findmnt -n -o FSTYPE -T /var/backups)" in
    ext4|ext3|ext2|btrfs|xfs|f2fs) ;; *) li_fail 'Persistenter lokaler Backup-Speicher erforderlich'; return 1 ;;
  esac

  # Restore all replaced files on an interrupted/failed publication, then
  # close only an RW window this invocation opened. No runtime data are targets.
  ew_finish() {
    local result=$?
    trap - EXIT HUP INT TERM
    if (( publishing && result != 0 )); then
      ue_restore "$backup/before" "$(mktemp -d "$backup/failed.XXXXXXXX")" \
        || printf 'ACHTUNG: Rueckstellung unvollstaendig; %s pruefen.\n' "$backup" >&2
    fi
    if (( rw_owned )); then
      sync
      mount -o remount,ro / || { printf 'ACHTUNG: Root blieb RW.\n' >&2; result=1; }
    fi
    [[ -z "$backup" ]] || printf 'Sicherung: %s\n' "$backup"
    exit "$result"
  }
  trap ew_finish EXIT
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
    printf 'OK: Ausgangsdateien restauriert, Zwischenstand gesichert; Worker bleibt aus.\n'
    return 0
  fi
  backup="$(mktemp -d /var/backups/camera-rpi-eos-workdir.XXXXXXXX)"
  ue_snapshot "$backup/before"
  cp /etc/machine-id "$backup/machine-id"
  for relative in ops/update-eos-workdir.sh ops/update-eos-legacy.sh ops/lib/legacy-install.sh; do
    install -D -m 0644 "$project/$relative" "$backup/package/$relative"
  done
  (cd "$backup/package" && sha256sum ops/update-eos-workdir.sh ops/update-eos-legacy.sh \
    ops/lib/legacy-install.sh > SHA256SUMS)
  (cd "$backup" && find before -type f -print0 | sort -z | xargs -0 sha256sum > before.sha256)
  sync -f "$backup"
  ew_require_stopped
  for relative in "${UE_PATHS[@]}"; do cmp -s "/$relative" "$backup/before/$relative"; done
  publishing=1
  for i in "${!UE_PATHS[@]}"; do
    ue_publish_file "$project/${UE_SOURCES[$i]}" "${UE_PATHS[$i]}" "${UE_MODES[$i]}"
  done
  (cd / && sha256sum "${UE_PATHS[@]}" > "$backup/installed.sha256")
  sync -f "$backup"
  publishing=0
  printf 'OK: EOS-Arbeitsverzeichnis, RAM-Fehlerdiagnose, Legacy-Uploadbestaetigung und Command-Zeilenenden korrigiert.\n'
  printf 'Automatische Legacy-Archivzeit auf 10 Minuten abgerundet, dann bis Upload/Retry erhalten; keine Aenderung alter Archivbilder.\n'
  printf 'webcam_isoexp im EOS-Legacy-Command-Poll: ISO 0 unveraendert, Stufen 0..5 -> Indices 14/17/20/23/26/29.\n'
  printf 'Kamerasperre, 15s Zeitlimit und Replay-Schutz; Uploadantworten werden weiterhin NICHT ausgefuehrt.\n'
  printf 'Worker bleibt gestoppt/disabled. Keine Aufnahme, kein Upload, keine Dienst-/GPIO-Aktion.\n'
  printf 'Keys, Konfiguration, Altprogramme und wartende Bilder unveraendert. Hardwaretest steht aus.\n'
  printf 'Rollback: bash %s/package/ops/update-eos-workdir.sh --rollback %s\n' "$backup" "$backup"
)

if [[ "${BASH_SOURCE[0]}" == "$0" ]]; then ew_main "$@"; fi
