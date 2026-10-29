#!/usr/bin/env bash
# Scoped Pi upgrade, deliberately NOT executed by development/tests. Never touch
# the DHT service/driver, sensor history, target configs, keys or package database.
set -euo pipefail
umask 077
[[ ${1:-} == --apply && $# == 1 ]] || { echo 'Aufruf: bash ops/update-climate.sh --apply (als root auf dem Pi)'; exit 2; }
[[ $EUID == 0 ]] || { echo 'Root erforderlich' >&2; exit 1; }
SRC="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")/.." && pwd -P)"
cd "$SRC"
sha256sum -c SHA256SUMS
php -r 'if (PHP_VERSION_ID < 80200) exit(1); foreach (["pcntl_async_signals","posix_geteuid","proc_open","socket_sendto","fsync"] as $f) if (!function_exists($f)) exit(1);'
command -v cc >/dev/null || { echo 'Compiler fehlt: gcc libc6-dev linux-libc-dev erforderlich' >&2; exit 1; }
[[ ",$(findmnt -n -o OPTIONS -T /)," == *,rw,* ]] || { echo 'Update braucht ein bewusst freigegebenes RW-Wartungsfenster.' >&2; exit 1; }
php tests/climate.php
php tests/climate-history.php
bash tests/climate-native
for file in scripts/camera-rpi-worker scripts/admin/camera-rpi-admin scripts/lib/camera-rpi-common.sh; do bash -n "$file"; done
for file in admin/public/index.php admin/src/climate.php admin/src/climate-panel.php status/public/index.php; do php -l "$file"; done
php scripts/camera-rpi-climate.php check
# An already armed enclosure is not silently interrupted by an update.
php scripts/camera-rpi-climate.php get | php -r '$c=json_decode(stream_get_contents(STDIN),true,16,JSON_THROW_ON_ERROR); if ($c["config"]["mode"]==="auto") {fwrite(STDERR,"Zuerst Klima im Admin auf Aus stellen\n");exit(1);}'
BUILD="$(mktemp -d /tmp/camera-climate-build.XXXXXXXX)"
trap 'rm -rf -- "$BUILD"' EXIT
cc -O2 -Wall -Wextra -Werror -Wl,-z,relro,-z,now -o "$BUILD/camera-rpi-pwm" scripts/native/camera-rpi-pwm.c
"$BUILD/camera-rpi-pwm" --version
[[ -f /usr/local/libexec/camera-rpi-worker && -f /usr/local/share/camera-rpi-admin/src/bootstrap.php ]]
sources=(scripts/camera-rpi-climate.php scripts/lib/climate-controller.php scripts/lib/climate-gpio.php "$BUILD/camera-rpi-pwm"
  scripts/lib/camera-rpi-common.sh scripts/camera-rpi-worker scripts/admin/camera-rpi-admin
  admin/public/index.php admin/public/style.css admin/src/climate.php admin/src/climate-panel.php
  status/public/index.php systemd/camera-rpi-climate.service CLIMATE.md status/public/style.css scripts/lib/climate-history.php)
targets=(/usr/local/libexec/camera-rpi-climate.php /usr/local/libexec/climate-controller.php /usr/local/libexec/climate-gpio.php /usr/local/libexec/camera-rpi-pwm
  /usr/local/libexec/camera-rpi-common.sh /usr/local/libexec/camera-rpi-worker /usr/local/sbin/camera-rpi-admin
  /usr/local/share/camera-rpi-admin/public/index.php /usr/local/share/camera-rpi-admin/public/style.css
  /usr/local/share/camera-rpi-admin/src/climate.php /usr/local/share/camera-rpi-admin/src/climate-panel.php
  /usr/local/share/camera-rpi-status/public/index.php /etc/systemd/system/camera-rpi-climate.service
  /usr/local/share/doc/camera-rpi/CLIMATE.md /usr/local/share/camera-rpi-status/public/style.css /usr/local/libexec/climate-history.php)
sources+=(scripts/lib/gpio-config.php)
targets+=(/usr/local/libexec/gpio-config.php)
modes=(0644 0644 0644 0755 0644 0755 0750 0644 0644 0644 0644 0644 0644 0644 0644 0644 0644)
for target in "${targets[@]}" /etc/camera-rpi/climate.json; do
  [[ ! -L "$target" ]] || { echo "Symlink-Ziel nicht erlaubt: $target" >&2; exit 1; }
done
SAVE="$(mktemp -d /root/camera-rpi-climate-before.XXXXXXXX)"
for i in "${!targets[@]}"; do
  printf '%s\t%s\n' "$i" "${targets[$i]}" >> "$SAVE/targets.tsv"
  if [[ -e "${targets[$i]}" ]]; then cp -a -- "${targets[$i]}" "$SAVE/$i"; fi
done
had_config=0
if [[ -e /etc/camera-rpi/climate.json ]]; then
  had_config=1
  cp -a /etc/camera-rpi/climate.json "$SAVE/climate.json"
fi
had_previous=0
if [[ -e /etc/camera-rpi/climate.json.previous ]]; then
  [[ ! -L /etc/camera-rpi/climate.json.previous ]]
  had_previous=1
  cp -a /etc/camera-rpi/climate.json.previous "$SAVE/climate.json.previous"
fi
systemctl is-enabled camera-rpi-climate.service > "$SAVE/climate-enabled" 2>/dev/null || true
systemctl is-active camera-rpi-climate.service > "$SAVE/climate-active" 2>/dev/null || true
mapfile -t workers < <(systemctl list-units --type=service --state=active --plain --no-legend 'camera-rpi-worker@*.service' | awk '{print $1}')
printf '%s\n' "${workers[@]}" > "$SAVE/workers"
echo "Sicherung: $SAVE"
changed=0

restore() {
  # Roll back only the exact target list, including newly installed files.
  # No broad deletion, no old sensor restart, no runtime/spool reset.
  local rc=$?
  trap - EXIT
  if (( rc != 0 )); then
    if (( changed == 1 )); then
      systemctl disable --now camera-rpi-climate.service || true
      for i in "${!targets[@]}"; do
        rm -f -- "${targets[$i]}.climate-new"
        if [[ -f "$SAVE/$i" ]]; then cp -a -- "$SAVE/$i" "${targets[$i]}";
        else rm -f -- "${targets[$i]}"; fi
      done
      if (( had_config == 0 )); then rm -f -- /etc/camera-rpi/climate.json;
      else cp -a -- "$SAVE/climate.json" /etc/camera-rpi/climate.json; fi
      if (( had_previous == 0 )); then rm -f -- /etc/camera-rpi/climate.json.previous;
      else cp -a -- "$SAVE/climate.json.previous" /etc/camera-rpi/climate.json.previous; fi
      systemctl daemon-reload
      if grep -qx enabled "$SAVE/climate-enabled"; then systemctl enable camera-rpi-climate.service || true; fi
    fi
    if grep -qx active "$SAVE/climate-active"; then systemctl start camera-rpi-climate.service || true; fi
    ((${#workers[@]} == 0)) || systemctl start "${workers[@]}" || true
    systemctl reload apache2 || true
    echo "Update fehlgeschlagen. Dateirueckweg ausgefuehrt; Status und Sicherung $SAVE pruefen." >&2
  fi
  rm -rf -- "$BUILD"
  exit "$rc"
}
trap restore EXIT
trap 'exit 130' INT
trap 'exit 143' TERM
systemctl stop camera-rpi-climate.service 2>/dev/null || true
((${#workers[@]} == 0)) || systemctl stop "${workers[@]}"
exec 9>/run/lock/camera-rpi.operation.lock
flock -w 30 9
changed=1
for i in "${!targets[@]}"; do
  install -o root -g root -m "${modes[$i]}" -- "${sources[$i]}" "${targets[$i]}.climate-new"
  mv -f -- "${targets[$i]}.climate-new" "${targets[$i]}"
done
if (( had_config == 0 )); then
  install -o root -g root -m 0600 config/climate.example.json /etc/camera-rpi/climate.json
fi
# Strip only retired thermal/time limits, with CAS and the exact previous JSON
# backup. Current mode and all other values remain unchanged. No GPIO operation.
php -d pcre.jit=0 -r 'require "scripts/camera-rpi-climate.php"; (new ClimateApp())->migrateRetiredSettings();'
systemctl daemon-reload
systemctl enable --now camera-rpi-climate.service
systemctl is-active --quiet camera-rpi-climate.service
flock -u 9
exec 9>&-
((${#workers[@]} == 0)) || systemctl start "${workers[@]}"
for unit in "${workers[@]}"; do systemctl is-active --quiet "$unit"; done
systemctl reload apache2
rm -rf -- "$BUILD"
trap - EXIT INT TERM
echo 'OK: Klima-Modul installiert, bestehende Einstellung erhalten; Neuinstallation ist AUS.'
echo 'Tabelle bleibt; zusaetzliche RAM-Verlaufsgrafik startet ab Update. Admin-Quittierungsbutton entfernt.'
echo 'Keine feste Temperatur-/Heizzeitgrenze und keine Aktivierungs-Checkbox; alte Grenzfelder entfernt.'
echo 'Keine GPIO-Testaktion. DHT-Test, Sensor, Keys und Zielkonfigurationen unveraendert.'
echo 'Vor Automatik: Hardware/Sicherheitsabschaltung separat abnehmen. Neue Regelung ohne Python.'
