#!/bin/bash
# Start a detached 24h trial in transient systemd, with root-owned RAM staging.
set -euo pipefail
export PATH=/usr/sbin:/usr/bin:/sbin:/bin
umask 077
[[ $EUID == 0 && $# == 0 ]] || { echo 'Bitte mit sudo bash und ohne Argumente starten.' >&2; exit 1; }
# Check the package and running stock driver before creating a detached trial.
SOURCE=$(cd -- "$(dirname -- "$0")" && pwd)
[[ $(findmnt -n -o FSTYPE -T /run) == tmpfs ]] || exit 1
(cd "$SOURCE" && sha256sum -c SHA256SUMS)
[[ $(modinfo -F vermagic "$SOURCE/dht11.ko") == "$(modinfo -F vermagic dht11)" ]] || exit 1
[[ $(modinfo -F intree dht11) == Y ]] || exit 1
[[ -d /sys/module/dht11 && ! -e /sys/module/dht11/parameters/diag_seconds ]] || exit 1
[[ $(cat /sys/module/dht11/srcversion) == "$(modinfo -F srcversion dht11)" ]] || exit 1
systemctl is-active --quiet camera-rpi-sensor.service || exit 1
# A stable unit name makes status and manual rollback unambiguous.
if systemctl is-active --quiet camera-rpi-dht-longtest.service; then
  echo 'Der Langzeittest laeuft bereits.' >&2
  exit 1
fi
# A deliberately stopped trial exits 143 after restoring stock. Its failed
# transient unit stays loaded until reset; release only that inactive unit.
# Earlier checks already proved stock restored and regular sampling active.
if systemctl is-failed --quiet camera-rpi-dht-longtest.service; then
  systemctl reset-failed camera-rpi-dht-longtest.service
fi
# Private RAM copy survives SSH logout, not reboot. The runner owns module
# switching and its separate restore timer; this wrapper only starts systemd.
STAGE=$(mktemp -d /run/camera-rpi-dht-long-package.XXXXXXXX)
install -m 0700 "$SOURCE/run-diagnostic.sh" "$STAGE/run-diagnostic.sh"
install -m 0700 "$SOURCE/start-long-test.sh" "$STAGE/start-long-test.sh"
[[ ! -f "$SOURCE/observe-counters.php" ]] || install -m 0600 "$SOURCE/observe-counters.php" "$STAGE/observe-counters.php"
install -m 0600 "$SOURCE/dht11.ko" "$STAGE/dht11.ko"
install -m 0600 "$SOURCE/SHA256SUMS" "$STAGE/SHA256SUMS"
systemd-run --quiet --unit=camera-rpi-dht-longtest --property=Type=exec \
  --property=RuntimeMaxSec=86520s --property=TimeoutStopSec=45s \
  --property=UMask=0077 --property=MemoryMax=64M \
  /bin/bash "$STAGE/run-diagnostic.sh" --long-worker
echo '24h-Testdienst gestartet; keine dauerhafte Treiberinstallation, kein Flanken-Debuglogging.'
echo 'Status: systemctl status camera-rpi-dht-longtest.service --no-pager'
echo 'Vorzeitig beenden/zurueckstellen: systemctl stop camera-rpi-dht-longtest.service'
echo 'SSH/Tunnel darf geschlossen werden. Reboot beendet den Test und laedt den Originaltreiber.'
