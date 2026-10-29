#!/usr/bin/env bash
# Explicit .17 trial replacement, NOT a permanent driver installation.
# Preserve old evidence and program inodes; never stop worker/climate or reboot.
set -Eeuo pipefail
export PATH=/usr/sbin:/usr/bin:/sbin:/bin
umask 077
[[ $EUID == 0 && $# == 1 ]] || exit 1
[[ $(hostname -s) == webcam-sth && $(uname -r) == 6.1.21-v7+ ]] || exit 1
source_dir=$1
oldrun=/run/camera-rpi-dht-driver.05ojfFJl
sensor=/usr/local/libexec/camera-rpi-sensor
page=/usr/local/share/camera-rpi-status/public/index.php
[[ -d "$oldrun" && ! -L "$oldrun" && ! -f "$oldrun/RESTORED" ]] || exit 1
[[ $(cat /sys/module/dht11/srcversion) == 40B29366E3DAB80BFD021E8 ]] || exit 1
[[ $(sha256sum "$sensor" | cut -d ' ' -f 1) == 7123b2b7747181e1a684f96d24e85752a731b1e5448af28d222baf8aab2ecf52 ]] || exit 1
[[ $(sha256sum "$page" | cut -d ' ' -f 1) == 999a46731142c856ca2ff898acbd385bc4a1c17f800982a731f6a9389835b2fe ]] || exit 1
[[ ! -L "$sensor" && ! -L "$page" ]]
# Root-owned RAM copy prevents later changes to the supplied build directory.
stage=$(mktemp -d /run/camera-dht-counters-rollout.XXXXXXXX)
for name in dht11.ko run-diagnostic.sh start-long-test.sh observe-counters.php camera-rpi-sensor index.php ROLLOUT-SHA256SUMS; do
  install -m 0600 "$source_dir/$name" "$stage/$name"
done
(cd "$stage" && sha256sum -c ROLLOUT-SHA256SUMS && sha256sum dht11.ko run-diagnostic.sh start-long-test.sh observe-counters.php > SHA256SUMS)
[[ $(modinfo -F vermagic "$stage/dht11.ko") == "$(modinfo -F vermagic dht11)" ]]
php -l "$stage/observe-counters.php"
php -l "$stage/index.php"
bash -n "$stage/camera-rpi-sensor" "$stage/run-diagnostic.sh" "$stage/start-long-test.sh"
exec 9>/run/lock/camera-rpi.operation.lock
flock -w 90 9
rw_owned=0
backup=''
published=0
finish() {
  result=$?; trap - EXIT
  if (( result != 0 && published )); then
    # The independent driver restore timer is never removed on failure.
    cp -a "$backup/sensor.before" "$sensor"
    cp -a "$backup/page.before" "$page"
    systemctl start camera-rpi-sensor.service || true
  fi
  if (( rw_owned )); then sync; mount -o remount,ro / || { echo 'KRITISCH: Root blieb RW' >&2; result=1; }; fi
  exit "$result"
}
trap finish EXIT
case ",$(findmnt -rn -o OPTIONS /)," in *,ro,*) mount -o remount,rw /; rw_owned=1 ;; esac
backup=$(mktemp -d /var/backups/camera-dht-counters.XXXXXXXX)
cp -a "$sensor" "$backup/sensor.before"
cp -a "$page" "$backup/page.before"
cp -a /etc/camera-rpi/device.conf "$backup/device.conf.before"
ln "$sensor" "$backup/sensor.inode"
ln "$page" "$backup/page.inode"
# First snapshot precedes stop; final snapshot includes the restoration result.
tar -czf "$backup/old-trial-before-stop.tar.gz" -C /run camera-rpi-dht-driver.05ojfFJl
systemctl stop camera-rpi-dht-longtest.service
[[ -f "$oldrun/RESTORED" && ! -e /sys/module/dht11/parameters/diag_seconds ]]
[[ $(cat /sys/module/dht11/srcversion) == "$(modinfo -F srcversion dht11)" ]]
tar -czf "$backup/old-trial-final.tar.gz" -C /run camera-rpi-dht-driver.05ojfFJl
cp -a /run/camera-rpi-status/history.tsv /run/camera-rpi-status/climate-history.tsv "$backup/"
published=1
tmp=$(mktemp "$sensor.update.XXXXXXXX")
install -m 0755 "$stage/camera-rpi-sensor" "$tmp"; mv -f "$tmp" "$sensor"
tmp=$(mktemp "$page.update.XXXXXXXX")
install -m 0644 "$stage/index.php" "$tmp"; mv -f "$tmp" "$page"
cmp -s /etc/camera-rpi/device.conf "$backup/device.conf.before"
# Persist only the evidence/program update; new measurements remain RAM-only.
if (( rw_owned )); then sync; mount -o remount,ro /; rw_owned=0; fi
published=0
flock -u 9
bash "$stage/start-long-test.sh"
printf 'Sicherung: %s\nPaket: %s\nWebseite 60s; Sensor-Konfiguration unverändert.\n' "$backup" "$stage"
