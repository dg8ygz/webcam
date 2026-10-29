#!/usr/bin/env bash
# Visible fault + authenticated acknowledgement, inventoried .16/.17 UI only.
# No service restart or acknowledgement during installation. Keep inode links
# for Apache readers so an initially read-only root can be restored safely.
set -euo pipefail
umask 077
[[ $EUID == 0 && $# == 1 && ( $1 == --check || $1 == --apply ) ]] || exit 1
action=$1
SRC="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")/.." && pwd -P)"
cd "$SRC"
sha256sum -c SHA256SUMS
source ops/lib/update-inodes.sh
sources=(admin/src/climate-panel.php admin/public/index.php status/public/index.php status/public/style.css)
targets=(/usr/local/share/camera-rpi-admin/src/climate-panel.php /usr/local/share/camera-rpi-admin/public/index.php /usr/local/share/camera-rpi-status/public/index.php /usr/local/share/camera-rpi-status/public/style.css)
baselines=(caf0c113a431d6a34fb82e1fde455d1b6f1b170b5f85ef6f7f829a86997b49e1 3fab8fe89c6dfd73efd84488f907a6ab1810da58a50fb017c2fb2d3065d093fd 45b474ff2339376e1033b1d23ef5448c926c741a6f11dee56d14fde03852a51a d2ffd2a2d799584001884db5c880f891f576dfe54ee85561eff2caa5d997296e)
for source in "${sources[@]:0:3}"; do php -l "$source"; done
exec 9>/run/lock/camera-rpi.operation.lock
flock -w 120 9
# The UI help describes the already-installed capture fix, never an older daemon.
printf '%s  %s\n' 6f56c7e698c5f5d23f8dc7b395da3e3d43c4dea34bb17fef4918b6529bd26cb2 /usr/local/libexec/climate-controller.php 940728886d06516d58a7dcedae501e9156e224858fceb0559a65f65ddf19afbe /usr/local/libexec/camera-rpi-climate.php | sha256sum -c -
for i in "${!targets[@]}"; do
  [[ -f ${targets[$i]} && ! -L ${targets[$i]} ]]
  read -r current _ < <(sha256sum "${targets[$i]}")
  read -r next _ < <(sha256sum "${sources[$i]}")
  [[ $current == "${baselines[$i]}" || $current == "$next" ]] || {
    echo "Abweichender Ausgangsstand: ${targets[$i]}; nichts geaendert." >&2; exit 1;
  }
done
if [[ $action == --check ]]; then echo 'OK: Klima-Stoerungsanzeige Vorpruefung.'; exit 0; fi
SAVE='' pending='' rw_owned=0 publishing=0
replace_file() {
  pending="$(mktemp "${2}.fault-ui.XXXXXXXX")" || return 1
  install -o root -g root -m 0644 -- "$1" "$pending" || return 1
  cu_preserve_inode "$2" "$SAVE/inodes" || return 1
  mv -f -- "$pending" "$2" || return 1
  pending=''
}
finish() {
  local rc=$? restored=1
  trap - EXIT
  if [[ -n "$pending" ]]; then rm -f -- "$pending"; fi
  if (( rc != 0 && publishing )); then
    for i in "${!targets[@]}"; do replace_file "$SAVE/$i" "${targets[$i]}" || restored=0; done
    echo "Update fehlgeschlagen; Rueckstellung=$restored, Sicherung: $SAVE" >&2
  fi
  if (( rw_owned )); then
    sync -f /usr/local/share || rc=1
    mount -o remount,ro / || { echo 'Root-Schreibschutz nicht restauriert!' >&2; rc=1; }
  fi
  exit "$rc"
}
trap finish EXIT
trap 'exit 130' INT
trap 'exit 143' TERM
trap 'exit 129' HUP
case ",$(findmnt -n -o OPTIONS -T /)," in
  *,ro,*) mount -o remount,rw /; rw_owned=1 ;;
  *,rw,*) ;;
  *) echo 'Root-Mountstatus unbekannt.' >&2; exit 1 ;;
esac
SAVE="$(mktemp -d /root/camera-rpi-climate-fault-ui-before.XXXXXXXX)"
install -d -m 0700 "$SAVE/inodes"
for i in "${!targets[@]}"; do cp -a -- "${targets[$i]}" "$SAVE/$i"; done
printf '%s\n' "${targets[@]}" > "$SAVE/targets.txt"
cp -- SHA256SUMS "$SAVE/package-SHA256SUMS"
echo "Sicherung: $SAVE"
publishing=1
for i in "${!targets[@]}"; do
  replace_file "${sources[$i]}" "${targets[$i]}"
  cmp -s "${sources[$i]}" "${targets[$i]}"
done
publishing=0
sha256sum "${targets[@]}"
echo 'OK: Klima-Stoerungsanzeige und Quittierbutton installiert; keine Dienste neu gestartet.'
