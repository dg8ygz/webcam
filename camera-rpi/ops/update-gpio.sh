#!/usr/bin/env bash
# Verified Testcam/.16/.17 GPIO enrollment. No service starts or GPIO claims.
# Publish by rename; private hardlinks keep old readers compatible with RO.
set -euo pipefail
umask 077
export PATH=/usr/sbin:/usr/bin:/sbin:/bin
SRC="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")/.." && pwd -P)"
[[ $EUID == 0 && $# == 1 && $1 == --apply ]] || { echo 'Als root: bash ops/update-gpio.sh --apply' >&2; exit 1; }
cd "$SRC"
sha256sum -c SHA256SUMS
source "$SRC/ops/lib/update-inodes.sh"
CAMERA_POWER=null
RC_HASH=''
case $(hostname -s) in
  raspberrypi) BOOT=/boot/firmware/config.txt; BOOT_MOUNT=/boot/firmware ;;
  webcam-bfld)
    BOOT=/boot/config.txt; BOOT_MOUNT=/boot
    RC_HASH=0438a0fe65beff9338edd395bc7b89f64d347f53c5121efffe7c6822c23e01fa ;;
  webcam-sth)
    BOOT=/boot/config.txt; BOOT_MOUNT=/boot; CAMERA_POWER=6
    RC_HASH=735eb9a7909b015f6b348308a20d9c2f5224efd6aed09aa605728ebb29433075 ;;
  *) echo 'Nur fuer die geprueften Kameras raspberrypi/webcam-bfld/webcam-sth.' >&2; exit 1 ;;
esac
[[ ! -e /etc/camera-rpi/gpio.json && ! -e /run/camera-rpi-gpio/active.json ]]
[[ ! -e /etc/systemd/system/camera-rpi-gpio.service && ! -e /etc/systemd/system/camera-rpi-camera-power.service ]]
if [[ -n $RC_HASH ]]; then
  printf '%s  %s\n' "$RC_HASH" /etc/rc.local | sha256sum -c -
else
  [[ ! -e /etc/rc.local ]] || { echo 'rc.local zuerst auf alte GPIO-Steuerung pruefen.' >&2; exit 1; }
fi
# Verified read-only against the running Testcam; avoid overwriting an unknown
# newer sensor/climate implementation, particularly DHT retry/grace changes.
printf '%s\n' \
 'de6e807c8456fc17a9cbe4696f04f59e9f082acdde0a79393d655236e0d92763  /usr/local/libexec/camera-rpi-climate.php' \
 '1d1ab6032aa4186b86def4012de42ad9aa082b95cb429f3cfb2e183a546ce388  /usr/local/libexec/climate-gpio.php' \
 'd126ac291a6e079160949104293138118477431c9cccc91b794b3473834f78e2  /usr/local/libexec/camera-rpi-sensor' | sha256sum -c -
[[ $(grep -c '^dtoverlay=dht11,gpiopin=4$' "$BOOT") == 1 ]]
[[ $(grep -c '^dtoverlay=dht11' "$BOOT") == 1 ]]
[[ $(cat /sys/bus/iio/devices/iio:device0/name) == dht11@4 ]]
[[ $(od -An -tu4 --endian=big /sys/bus/iio/devices/iio:device0/of_node/gpios | awk '{print $2}') == 4 ]]
grep -Eq '^dht_iio_device=(auto|/sys/bus/iio/devices/iio:device0)$' /etc/camera-rpi/device.conf
! grep -Eq '^(temperature_file|humidity_file)=.+' /etc/camera-rpi/device.conf
[[ $(findmnt -n -o TARGET -T "$BOOT") == "$BOOT_MOUNT" ]]
command -v dtoverlay >/dev/null
php -r 'exit(extension_loaded("pcntl") && extension_loaded("posix") ? 0 : 1);'
php tests/gpio-config.php
php tests/gpio-board.php
php tests/gpio-store.php
php tests/climate.php
bash tests/climate-native
while IFS='|' read -r source target mode; do
  [[ -z $source || $source == \#* ]] && continue
  [[ -f $source && ! -L $target ]]
  parent=$(dirname "$target")
  while [[ $parent != / ]]; do [[ ! -L $parent ]]; parent=$(dirname "$parent"); done
  [[ $source != *.php ]] || php -l "$source"
done < ops/gpio-install-files.tsv
bash -n scripts/camera-rpi-sensor
bash -n scripts/admin/camera-rpi-admin
# /run is deliberately noexec on the low-write Testcam; /tmp is RAM-backed
# and executable (the isolated native test above has already checked this).
BUILD="$(mktemp -d /tmp/camera-gpio-build.XXXXXXXX)"
# Seed the active wiring, never the generic default for another host. On .17
# wiringPi22 == BCM6 already holds supply LOW; ownership changes only next boot.
sed "s/\"camera_power\": null/\"camera_power\": $CAMERA_POWER/" config/gpio.example.json > "$BUILD/initial.json"
php -r 'require "scripts/lib/gpio-config.php"; $p=json_decode(file_get_contents($argv[1]),true,16,JSON_THROW_ON_ERROR); CameraGpioConfig::validate($p,CameraGpioBoard::allowedHeader());' "$BUILD/initial.json"
cc -O2 -Wall -Wextra -Werror -o "$BUILD/camera-rpi-pwm" scripts/native/camera-rpi-pwm.c
"$BUILD/camera-rpi-pwm" --version

# Record exact original bytes, modes and the boot ID. The rollback helper is
# deliberately limited to the same boot and an unchanged newly saved profile.
ROOT_RW=0 BOOT_RW=0 CHANGED=0 SAVE=''
finish() {
  local rc=$?
  trap - EXIT
  if (( rc != 0 && CHANGED )); then
    echo "Update unvollstaendig; Rueckstellung aus $SAVE" >&2
    bash "$SRC/ops/rollback-gpio.sh" "$SAVE" || echo "ACHTUNG: Rueckstellung pruefen: $SAVE" >&2
  fi
  if (( BOOT_RW )); then mount -o remount,ro "$BOOT_MOUNT" || rc=1; fi
  if (( ROOT_RW )); then mount -o remount,ro / || rc=1; fi
  exit "$rc"
}
trap finish EXIT
trap 'exit 130' INT
trap 'exit 143' TERM
exec 9>/run/lock/camera-rpi.operation.lock
flock -w 30 9 || { echo 'Aufnahme aktiv; nach Upload erneut ausfuehren.' >&2; exit 1; }
if [[ ,$(findmnt -n -o OPTIONS -T /), == *,ro,* ]]; then mount -o remount,rw /; ROOT_RW=1; fi
if [[ ,$(findmnt -n -o OPTIONS -T "$BOOT"), == *,ro,* ]]; then mount -o remount,rw "$BOOT_MOUNT"; BOOT_RW=1; fi
SAVE="$(mktemp -d /var/backups/camera-rpi-gpio.XXXXXXXX)"
cp ops/rollback-gpio.sh "$SAVE/rollback-gpio.sh"
cp ops/lib/update-inodes.sh "$SAVE/update-inodes.sh"
install -d -m 0700 "$SAVE/inodes"
cat /proc/sys/kernel/random/boot_id > "$SAVE/boot-id"
cp "$BUILD/initial.json" "$SAVE/initial.json"
printf '%s\n' "$BOOT" > "$SAVE/boot-path"
printf '%s\n' "$BOOT_MOUNT" > "$SAVE/boot-mount"
backup() {
  local path=$1
  [[ ! -L $path ]]
  printf '%s\n' "$path" >> "$SAVE/targets"
  if [[ -f $path ]]; then
    mkdir -p "$SAVE/original$(dirname "$path")"
    cp -a "$path" "$SAVE/original$path"
  elif [[ -e $path ]]; then return 1; fi
}
backup "$BOOT"
if [[ $CAMERA_POWER == 6 ]]; then backup /etc/rc.local; fi
backup /usr/local/libexec/camera-rpi-pwm
backup /etc/camera-rpi/gpio.json
backup /run/camera-rpi-gpio/active.json
while IFS='|' read -r source target mode; do
  [[ -z $source || $source == \#* ]] && continue
  backup "$target"
done < ops/gpio-install-files.tsv

# Native helper first: it accepts both the old fixed-pin invocation and the new
# explicit pins, so a currently running supervisor remains compatible.
publish() {
  local source=$1 target=$2 mode=$3 tmp
  mkdir -p "$(dirname "$target")"
  tmp="$(mktemp "$(dirname "$target")/.gpio-update.XXXXXXXX")"
  install -o root -g root -m "$mode" "$source" "$tmp"
  cu_preserve_inode "$target" "$SAVE/inodes" || { rm -f -- "$tmp"; return 1; }
  mv -f "$tmp" "$target"
}
CHANGED=1
publish "$BUILD/camera-rpi-pwm" /usr/local/libexec/camera-rpi-pwm 0755
while IFS='|' read -r source target mode; do
  [[ -z $source || $source == \#* ]] && continue
  publish "$source" "$target" "$mode"
done < ops/gpio-install-files.tsv
install -d -m 0700 /run/camera-rpi-gpio
publish "$BUILD/initial.json" /run/camera-rpi-gpio/active.json 0600
publish "$BUILD/initial.json" /etc/camera-rpi/gpio.json 0600
# Boot overlay ownership moves to the boot service, not during this installation.
sed 's/^dtoverlay=dht11,gpiopin=4$/# camera-rpi-gpio.service manages DHT: dtoverlay=dht11,gpiopin=4/' "$BOOT" > "$BUILD/config.txt"
cat "$BUILD/config.txt" > "$BOOT"
if [[ $CAMERA_POWER == 6 ]]; then
  # Do not execute rc.local. Remove only the verified legacy boot assignment,
  # otherwise it would override a future user-selected power GPIO on reboot.
  [[ $(grep -c '^gpio mode 22 out$' /etc/rc.local) == 1 ]]
  sed 's/^gpio mode 22 out$/# camera-rpi-camera-power.service owns BCM6 after reboot: gpio mode 22 out/' /etc/rc.local > "$BUILD/rc.local"
  publish "$BUILD/rc.local" /etc/rc.local 0755
fi
php /usr/local/libexec/camera-rpi-gpio.php get
php /usr/local/libexec/camera-rpi-gpio.php sensor
systemctl daemon-reload
systemctl enable camera-rpi-gpio.service camera-rpi-camera-power.service
sync -f /etc/camera-rpi/gpio.json
sync -f "$BOOT"
CHANGED=0
printf 'OK: Vier GPIO-Auswahlfelder + Modellerkennung; Belegung 4/5/27/%s unveraendert.\n' "$CAMERA_POWER"
printf 'Keine Dienste neu gestartet, kein GPIO geschaltet, kein Reboot.\n'
printf 'Sicherung: %s\nRollback vor Reboot: bash %s/rollback-gpio.sh %s\n' "$SAVE" "$SAVE" "$SAVE"
