#!/usr/bin/env bash
# Inventoried .17 only. No config writes; existing pin6, camera currently on.
# Restart only the supply owner; preserve root inodes and restore RO on exit.
set -euo pipefail
umask 077
[[ $EUID == 0 && $# == 1 && ( $1 == --check || $1 == --apply ) ]] || exit 1
mode=$1
SRC="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")/.." && pwd -P)"
cd "$SRC"
sha256sum -c SHA256SUMS
source ops/lib/update-inodes.sh
ip -4 -o address show | grep -q '192.0.2.17/'
command -v lsusb >/dev/null
php -r 'require "/usr/local/libexec/camera-rpi-gpio.php"; exit(CameraGpioConfig::effective()["camera_power"]===6 ? 0 : 1);'
systemctl is-active --quiet camera-rpi-camera-power.service
build=$(mktemp -d /tmp/camera-power-build.XXXXXXXX)
trap 'rm -rf -- "$build"' EXIT
bash tests/climate-native
cc -O2 -Wall -Wextra -Werror -o "$build/camera-rpi-pwm" scripts/native/camera-rpi-pwm.c
sources=("$build/camera-rpi-pwm")
targets=(/usr/local/libexec/camera-rpi-pwm)
modes=(0755)
baselines=(9ce06d4a8d0531ebfee5d1ba0fc60efa180d5b1a8a8d0758319f482d8ce745f5)
while IFS='|' read -r source target perm baseline; do
  [[ -z $source || $source == \#* ]] && continue
  sources+=("$source"); targets+=("$target"); modes+=("$perm"); baselines+=("$baseline")
  [[ $source != *.php ]] || php -l "$source"
done < ops/power-install-files.tsv
bash -n scripts/admin/camera-rpi-admin
exec 9>/run/lock/camera-rpi.operation.lock
flock -w 120 9
for i in "${!targets[@]}"; do
  target=${targets[$i]}
  [[ ! -L $target ]]
  parent=$(dirname "$target")
  while [[ $parent != / ]]; do [[ ! -L $parent ]]; parent=$(dirname "$parent"); done
  if [[ ${baselines[$i]} == absent ]]; then [[ ! -e $target ]];
  else [[ -f $target && $(sha256sum "$target" | cut -d' ' -f1) == "${baselines[$i]}" ]]; fi
done
# Installation must not unexpectedly switch an intentionally absent/off camera on.
timeout --kill-after=1 3 lsusb | grep -q Canon
if [[ $mode == --check ]]; then echo 'OK: .17 power update preflight; no GPIO changed.'; exit 0; fi
save='' pending='' rw_owned=0 publishing=0
replace() {
  pending=$(mktemp "${2}.power.XXXXXXXX")
  install -o root -g root -m "$3" "$1" "$pending"
  cu_preserve_inode "$2" "$save/inodes"
  mv -f -- "$pending" "$2"; pending=''
}
finish() {
  local rc=$?
  trap - EXIT
  [[ -z $pending ]] || rm -f -- "$pending"
  if (( rc != 0 && publishing )); then
    for i in "${!targets[@]}"; do
      if [[ -e $save/$i ]]; then replace "$save/$i" "${targets[$i]}" "${modes[$i]}" || true;
      else cu_preserve_inode "${targets[$i]}" "$save/inodes" && rm -f -- "${targets[$i]}"; fi
    done
    systemctl daemon-reload
    systemctl restart camera-rpi-camera-power.service || true
    echo "Rollback after error; inspect $save" >&2
  fi
  if (( rw_owned )); then sync -f /usr/local; mount -o remount,ro / || { echo 'Root RO restore failed' >&2; rc=1; }; fi
  rm -rf -- "$build"
  exit "$rc"
}
trap finish EXIT
trap 'exit 130' INT
trap 'exit 143' TERM
trap 'exit 129' HUP
case ",$(findmnt -n -o OPTIONS -T /)," in
  *,ro,*) mount -o remount,rw /; rw_owned=1 ;;
  *,rw,*) ;;
  *) exit 1 ;;
esac
save=$(mktemp -d /root/camera-rpi-power-before.XXXXXXXX)
install -d -m 0700 "$save/inodes"
for i in "${!targets[@]}"; do
  if [[ -e ${targets[$i]} ]]; then cp -a "${targets[$i]}" "$save/$i"; fi
  printf '%s|%s|%s\n' "$i" "${targets[$i]}" "${modes[$i]}" >> "$save/targets.tsv"
done
cp SHA256SUMS "$save/package-SHA256SUMS"
echo "Backup: $save"
publishing=1
for i in "${!targets[@]}"; do replace "${sources[$i]}" "${targets[$i]}" "${modes[$i]}"; done
systemctl daemon-reload
systemctl restart camera-rpi-camera-power.service
for attempt in {1..30}; do [[ ! -S /run/camera-rpi-power/control.sock ]] || break; sleep .1; done
systemctl is-active --quiet camera-rpi-camera-power.service
php -r 'require "/usr/local/libexec/camera-rpi-power.php"; exit((new CameraPowerApp)->command("STATUS")==="ON"?0:1);'
for i in "${!targets[@]}"; do cmp -s "${sources[$i]}" "${targets[$i]}"; done
publishing=0
printf 'OK: .17 camera power installed; only supply service restarted. Backup: %s\n' "$save"
