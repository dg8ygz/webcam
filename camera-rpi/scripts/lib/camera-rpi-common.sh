#!/usr/bin/env bash

# Wire/admin enum, never a shell command supplied by the server.
cr_focus_mode_valid() {
  case "$1" in --|-|auto|+|++) return 0 ;; *) return 1 ;; esac
}

# Optional electrical AF/MF gate. No enrolled profile means legacy behaviour.
# A configured gate must ACK LOW before capture; missing helpers fail closed.
cr_focus_gpio() {
  local command="$1"
  local config="${CAMERA_RPI_GPIO_CONFIG:-/etc/camera-rpi/gpio.json}"
  local active="${CAMERA_RPI_GPIO_ACTIVE:-/run/camera-rpi-gpio/active.json}"
  [[ -e "$config" || -L "$config" || -e "$active" || -L "$active" ]] || return 0
  local helper="${CAMERA_RPI_FOCUS_GPIO_HELPER:-$(dirname -- "${BASH_SOURCE[0]}")/camera-rpi-focus-gpio.php}"
  [[ -r "$helper" ]] || { cr_fail 'Fokus-GPIO-Helfer fehlt'; return 1; }
  /usr/bin/timeout --kill-after=1 4 /usr/bin/php "$helper" "$command"
}

# Called ONLY for a consumed explicit focus request, with operation.lock held.
# No discovery at worker startup, capture, upload or configuration validation.
# Query the connected camera instead of assuming old eosviewfinder aliases or
# fixed radio indices. All command/value strings below are local allowlists.
cr_focus_to_camera() (
  local mode="$1" properties details drive label switch result=0 touched=0
  local gphoto="${CAMERA_RPI_GPHOTO_BIN:-/usr/bin/gphoto2}"
  local timeout_bin="${CAMERA_RPI_TIMEOUT_BIN:-/usr/bin/timeout}"
  local -a setup=() restore=() action=()
  cr_focus_mode_valid "$mode" || return 1
  # A separate bounded cleanup runs even if gphoto aborts before its final
  # option. It never retries the focus drive. Keep the original failure code;
  # failure to leave liveview also counts as a failed request, not success.
  focus_restore() {
    local rc=$?
    trap - EXIT HUP INT TERM
    if (( touched )); then
      "$timeout_bin" --kill-after=2 5 "$gphoto" "${restore[@]}" >/dev/null 2>&1 || {
        (( rc != 0 )) || rc=1
      }
    fi
    cr_focus_gpio OFF || { (( rc != 0 )) || rc=1; }
    exit "$rc"
  }
  trap focus_restore EXIT
  trap 'exit 129' HUP
  trap 'exit 130' INT
  trap 'exit 143' TERM
  cr_focus_gpio ON || return $?
  export LC_ALL=C
  properties="$("$timeout_bin" --kill-after=2 5 "$gphoto" --list-config)" || return $?
  case "$mode" in
    auto) drive=autofocusdrive ;;
    -) drive=manualfocusdrive; label='Near 1' ;;
    --) drive=manualfocusdrive; label='Near 2' ;;
    +) drive=manualfocusdrive; label='Far 1' ;;
    ++) drive=manualfocusdrive; label='Far 2' ;;
  esac
  grep -Eq "^/[^[:space:]]+/$drive$" <<< "$properties" || return 1
  details="$("$timeout_bin" --kill-after=2 5 "$gphoto" --get-config "$drive")" || return $?
  grep -qx 'Readonly: 0' <<< "$details" || return 1
  if [[ "$mode" == auto ]]; then
    grep -qx 'Type: TOGGLE' <<< "$details" || return 1
    action=(--set-config autofocusdrive=1 --wait-event=2s)
  else
    grep -qx 'Type: RADIO' <<< "$details" \
      && grep -Eq "^Choice: [0-9]+ $label$" <<< "$details" || return 1
    action=(--set-config-value "manualfocusdrive=$label")
  fi

  # Canon .16 exposes PC/Off output and needs a preview to initialise liveview.
  # If that property exists but its contract is unknown, stop BEFORE mutation;
  # never try another backend after a failed or possibly partial movement.
  if grep -Eq '^/[^[:space:]]+/output$' <<< "$properties"; then
    details="$("$timeout_bin" --kill-after=2 5 "$gphoto" --get-config output)" || return $?
    grep -qx 'Readonly: 0' <<< "$details" && grep -qx 'Type: RADIO' <<< "$details" \
      && grep -Eq '^Choice: [0-9]+ PC$' <<< "$details" \
      && grep -Eq '^Choice: [0-9]+ Off$' <<< "$details" \
      && grep -Eq '^/[^[:space:]]+/evfmode$' <<< "$properties" || return 1
    details="$("$timeout_bin" --kill-after=2 5 "$gphoto" --get-config evfmode)" || return $?
    grep -qx 'Readonly: 0' <<< "$details" && grep -qx 'Type: RADIO' <<< "$details" \
      && grep -Eq '^Choice: [0-9]+ 1$' <<< "$details" || return 1
    setup=(--set-config-value evfmode=1 --set-config-value output=PC)
    restore=(--set-config-value output=Off)
  else
    # Older Canon/property variants: prefer the current name, only use the
    # historical alias when actually advertised. Do not guess a numeric enum.
    switch=''
    for label in viewfinder eosviewfinder; do
      if grep -Eq "^/[^[:space:]]+/$label$" <<< "$properties"; then switch="$label"; break; fi
    done
    [[ -n "$switch" ]] || return 1
    details="$("$timeout_bin" --kill-after=2 5 "$gphoto" --get-config "$switch")" || return $?
    grep -qx 'Readonly: 0' <<< "$details" && grep -qx 'Type: TOGGLE' <<< "$details" || return 1
    setup=(--set-config "$switch=1")
    restore=(--set-config "$switch=0")
  fi

  touched=1
  # Preview bytes go straight to /dev/null; no JPEG, shutter release or pool
  # capture. gphoto processes setup/preview/ONE drive in the same connection.
  "$timeout_bin" --kill-after=2 15 "$gphoto" "${setup[@]}" \
    --capture-preview --stdout "${action[@]}" >/dev/null 2>&1 || result=$?
  exit "$result"
)

# Gemeinsame, absichtlich kleine Hilfsfunktionen fuer alle Camera-RPi-Scripte.
#
# Diese Datei wird von den anderen Scripten geladen. Sie fuehrt selbst keinen
# Capture aus. Ihre wichtigste Aufgabe ist, eine Konfigurationsdatei als reine
# Daten zu lesen. Die Datei wird NICHT mit `source` ausgefuehrt. Dadurch kann
# ein Tippfehler in der Weboberflaeche nicht versehentlich zu einem Shellbefehl
# werden.

cr_log() {
  local level="$1"
  shift
  printf '%s [%s] [%s] %s\n' \
    "$(date '+%Y-%m-%dT%H:%M:%S%z')" \
    "$level" \
    "${SERVER_ID:-unknown}" \
    "$*"
}

cr_fail() {
  cr_log ERROR "$*" >&2
  return 1
}

# Entfernt nur Leerraum am Anfang und Ende. Zeichen innerhalb eines Wertes
# bleiben unveraendert.
cr_trim() {
  local value="$1"
  value="${value#"${value%%[![:space:]]*}"}"
  value="${value%"${value##*[![:space:]]}"}"
  printf '%s' "$value"
}

cr_bool() {
  case "${1,,}" in
    1|true|yes|on) printf '1' ;;
    0|false|no|off) printf '0' ;;
    *) return 1 ;;
  esac
}

cr_number_in_range() {
  local name="$1"
  local value="$2"
  local minimum="$3"
  local maximum="$4"

  [[ "$value" =~ ^[0-9]+$ ]] \
    || { cr_fail "$name muss eine ganze Zahl sein"; return 1; }
  (( value >= minimum && value <= maximum )) \
    || { cr_fail "$name muss zwischen $minimum und $maximum liegen"; return 1; }
}

cr_private_file() {
  local path="$1"
  local purpose="$2"
  local mode
  local uid

  [[ "$path" == /* && -f "$path" && -r "$path" ]] \
    || { cr_fail "$purpose ist nicht als lesbare absolute Datei vorhanden: $path"; return 1; }
  mode="$(stat -c '%a' "$path" 2>/dev/null)" \
    || { cr_fail "Rechte von $purpose sind nicht lesbar: $path"; return 1; }
  uid="$(stat -c '%u' "$path" 2>/dev/null)" \
    || { cr_fail "Besitzer von $purpose ist nicht lesbar: $path"; return 1; }
  [[ "$mode" =~ ^[0-7]{3,4}$ ]] \
    || { cr_fail "Ungueltiger Dateimodus fuer $purpose: $path"; return 1; }

  # In Tests darf eine unprivilegierte, temporaere Datei verwendet werden.
  # Im Betrieb bleibt die Vorgabe strikt root-only.
  if [[ "${CAMERA_RPI_ALLOW_NONROOT_CONFIG:-0}" != "1" ]]; then
    (( uid == 0 )) || { cr_fail "$purpose muss root gehoeren: $path"; return 1; }
    [[ "$mode" == "600" || "$mode" == "400" ]] \
      || { cr_fail "$purpose muss Modus 0600 oder 0400 besitzen: $path"; return 1; }
  else
    (( (8#$mode & 0022) == 0 )) \
      || { cr_fail "$purpose darf nicht gruppen- oder weltbeschreibbar sein: $path"; return 1; }
  fi
}

# Persistente Pfade werden spaeter teilweise in Curl-Formularsyntax benutzt.
# Eine kleine, eindeutige Zeichenmenge vermeidet dort Sonderbedeutungen und
# schliesst zugleich Pfadspruenge mit .. aus.
cr_safe_absolute_path() {
  local path="$1"
  [[ "$path" =~ ^/[A-Za-z0-9._/-]+$ ]] || return 1
  [[ "$path" != *"/../"* && "$path" != */.. && "$path" != *"/./"* && "$path" != */. ]]
}

# Liest genau eine nichtleere Key-Zeile. Der Wert wird nur in einer
# Shellvariablen gehalten und nie als Argument an ein externes Programm
# uebergeben.
cr_read_secret() {
  local path="$1"
  local -a lines=()

  cr_private_file "$path" "Key-Datei" || return 1
  mapfile -t lines < "$path"
  ((${#lines[@]} == 1)) \
    || { cr_fail "Key-Datei muss genau eine Zeile enthalten: $path"; return 1; }
  # Bestehende DEV-/Legacy-Server verwenden auch vierstellige Upload-Keys.
  # Kompatibilitaetspruefung, keine Bewertung der Schluesselstaerke.
  [[ "${lines[0]}" =~ ^[A-Za-z0-9._~+/=-]{4,512}$ ]] \
    || { cr_fail "Key-Datei enthaelt keinen gueltigen Token: $path"; return 1; }
  CR_SECRET="${lines[0]}"
}

# Erstellt eine private Headerdatei auf dem fluechtigen /run-Dateisystem.
# Curl bekommt spaeter nur den Dateipfad zu sehen, nicht CR_SECRET.
cr_write_header_file() {
  local header_name="$1"
  local key_file="$2"
  local target="$3"

  cr_read_secret "$key_file" || return 1
  (umask 077; printf '%s: %s\n' "$header_name" "$CR_SECRET" > "$target") \
    || { unset CR_SECRET; cr_fail "Private Headerdatei kann nicht geschrieben werden"; return 1; }
  unset CR_SECRET
  chmod 0600 "$target" 2>/dev/null || true
}

# Nur Kamera-Geraetepfade, keine beliebigen Dateien/Argumente. Die Existenz
# wird erst beim Capture geprueft: abgezogene Kameras verhindern kein Speichern.
cr_validate_capture_config() {
  [[ "${GPHOTO_CAPTURE_MODE:-eos-legacy}" == standard || "${GPHOTO_CAPTURE_MODE:-eos-legacy}" == eos-legacy ]] \
    || { cr_fail "gphoto_capture_mode muss standard oder eos-legacy sein"; return 1; }
  [[ "$CAPTURE_BACKEND" == gphoto2 || "$CAPTURE_BACKEND" == v4l2 ]] \
    || { cr_fail "capture_backend muss gphoto2 oder v4l2 sein"; return 1; }
  [[ "$VIDEO_DEVICE" =~ ^/dev/video[0-9]+$ || "$VIDEO_DEVICE" =~ ^/dev/v4l/by-id/[A-Za-z0-9_.:+-]+-video-index[0-9]+$ ]] \
    || { cr_fail "video_device muss /dev/videoN oder ein /dev/v4l/by-id/*-video-indexN sein"; return 1; }
  local name value maximum
  for name in VIDEO_WIDTH VIDEO_HEIGHT VIDEO_WARMUP_FRAMES; do
    value="${!name}"
    # Kanonische Dezimalzahlen: kein Oktal, keine Arithmetik-Ausdruecke.
    [[ "$value" =~ ^(0|[1-9][0-9]{0,3})$ ]] \
      || { cr_fail "$name muss eine kurze Dezimalzahl sein"; return 1; }
    case "$name" in
      VIDEO_WIDTH) maximum=4096 ;;
      VIDEO_HEIGHT) maximum=2160 ;;
      VIDEO_WARMUP_FRAMES) maximum=120 ;;
    esac
    cr_number_in_range "$name" "$value" 0 "$maximum" || return 1
  done
  (( VIDEO_WIDTH >= 16 && VIDEO_HEIGHT >= 16 )) \
    || { cr_fail "Videodimensionen muessen mindestens 16 Pixel betragen"; return 1; }
}

# Optional shared source, cached until a coordinated worker/video restart.
# Private JSON remains shell-local; never export it or print it to diagnostics.
cr_source_load() {
  local path="${CAMERA_RPI_SOURCE_CONFIG:-/etc/camera-rpi/camera-source.conf}" profile
  CR_SOURCE_HELPER="${CAMERA_RPI_SOURCE_HELPER:-$(dirname -- "${BASH_SOURCE[0]}")/camera-source.php}"
  if [[ "${CR_SOURCE_LOADED:-0}" != 1 ]]; then
    CR_SOURCE_FIELDS=()
    CR_SOURCE_GPHOTO_MODE=eos-legacy
    if [[ -e "$path" || -L "$path" ]]; then
      [[ -r "$CR_SOURCE_HELPER" ]] || { cr_fail 'Gemeinsamer Quellenhelfer fehlt'; return 1; }
      profile="$(/usr/bin/php "$CR_SOURCE_HELPER" shell)" || return 1
      mapfile -t CR_SOURCE_FIELDS <<< "$profile"
      (( ${#CR_SOURCE_FIELDS[@]} == 6 )) || {
        cr_fail "Quellenhelfer lieferte ${#CR_SOURCE_FIELDS[@]} statt 6 Felder; CLI-Ausgabe ungueltig"
        return 1
      }
    fi
    if (( ${#CR_SOURCE_FIELDS[@]} )); then
      CR_SOURCE_GPHOTO_MODE="$(printf '%s' "${CR_SOURCE_FIELDS[5]}" | /usr/bin/php -r '$c=json_decode(stream_get_contents(STDIN),true,16,JSON_THROW_ON_ERROR); echo $c["gphoto_capture_mode"]??"eos-legacy";')" || return 1
    fi
    CR_SOURCE_LOADED=1
  fi
  SOURCE_CONFIGURED=0
  (( ${#CR_SOURCE_FIELDS[@]} )) || return 0
  SOURCE_CONFIGURED=1
  if [[ "${CR_SOURCE_FIELDS[0]}" == gphoto2 ]]; then GPHOTO_CAPTURE_MODE="$CR_SOURCE_GPHOTO_MODE"; fi
  CAPTURE_BACKEND="${CR_SOURCE_FIELDS[0]}" VIDEO_DEVICE="${CR_SOURCE_FIELDS[1]}"
  VIDEO_WIDTH="${CR_SOURCE_FIELDS[2]}" VIDEO_HEIGHT="${CR_SOURCE_FIELDS[3]}" VIDEO_WARMUP_FRAMES="${CR_SOURCE_FIELDS[4]}"
}

cr_capture_backend_to_file() (
  local target="$1" format
  # Only physical V4L2/RTSP capture interrupts video, not bookkeeping/uploads.
  # The subshell owns both descriptors through capture/exec; exit also releases
  # them after errors. SH announces priority, EX waits for the encoder to stop.
  # A separate device lock avoids holding the global spool lock for a stream.
  if [[ "${CAPTURE_BACKEND:-gphoto2}" == v4l2 || "${CAPTURE_BACKEND:-gphoto2}" == rtsp ]]; then
    local video_lock="${CAMERA_RPI_OPERATION_LOCK:-/run/lock/camera-rpi.operation.lock}.video"
    local video_priority_fd video_capture_fd
    exec {video_priority_fd}>"$video_lock.priority"
    "${CAMERA_RPI_FLOCK_BIN:-/usr/bin/flock}" -s -w 8 "$video_priority_fd" || return 1
    exec {video_capture_fd}>"$video_lock"
    "${CAMERA_RPI_FLOCK_BIN:-/usr/bin/flock}" -w 8 "$video_capture_fd" \
      || { cr_fail 'Video hat die Kamera nicht rechtzeitig freigegeben'; return 1; }
  fi
  # Legacy scripts do not participate in operation.lock and their download
  # hook uses killall gphoto2. Refuse coexistence BEFORE GPIO/USB access, even
  # when an old cron watchdog unexpectedly restarts the legacy poller.
  local legacy_rc legacy_name
  # Separate exact names avoid procps' misleading >15-character regex warning.
  for legacy_name in webcam_wait webcam_capture; do
    legacy_rc=0
    /usr/bin/pgrep -x "$legacy_name" >/dev/null || legacy_rc=$?
    if (( legacy_rc == 0 )); then
      cr_fail 'Alter webcam_wait/webcam_capture ist aktiv; keine parallele Kameraaufnahme'
      return 1
    fi
    (( legacy_rc == 1 )) || { cr_fail 'Alt-Poller-Prozesspruefung fehlgeschlagen'; return 1; }
  done
  if [[ "$CAPTURE_BACKEND" == rtsp ]]; then
    # The IP camera does not use the Pi's USB/GPIO outputs. Keep photo/video
    # serialization and privacy, but no enclosure actuator pause is needed.
    ulimit -f "$(((MAX_IMAGE_BYTES + 1023) / 1024))" || return 1
    printf '%s\n' "${CR_SOURCE_FIELDS[5]}" | /usr/bin/php "$CR_SOURCE_HELPER" capture "$target"
    return $?
  fi
  # Physical camera power pause is device-wide, not per upload target. The
  # inherited flock remains held through exec/timeout and is released on exit.
  # A fresh nonce is acknowledged ONLY after heater/PWM are off. No supervisor
  # or no ACK means no capture; its existing spool request remains for retry.
  local climate_config="${CAMERA_RPI_CLIMATE_CONFIG:-/etc/camera-rpi/climate.json}"
  if [[ -e "$climate_config" || -L "$climate_config" ]]; then
    local climate_helper="${CAMERA_RPI_CLIMATE_HELPER:-/usr/local/libexec/camera-rpi-climate.php}"
    local climate_runtime="${CAMERA_RPI_CLIMATE_RUNTIME:-/run/camera-rpi-climate}"
    local climate_fd pause_seconds="$((GPHOTO_TIMEOUT_SECONDS + 30))"
    (( pause_seconds >= 60 )) || pause_seconds=60
    [[ -r "$climate_helper" ]] || { cr_fail "Klima-Pausenhelfer fehlt"; return 1; }
    mkdir -p -- "$climate_runtime" && chmod 0700 "$climate_runtime" || return 1
    exec {climate_fd}>"$climate_runtime/capture.lock"
    "${CAMERA_RPI_FLOCK_BIN:-/usr/bin/flock}" -n "$climate_fd" || return 1
    /usr/bin/php "$climate_helper" pause --seconds "$pause_seconds" || return 1
  fi
  if [[ "$CAPTURE_BACKEND" == gphoto2 ]]; then
    cr_capture_recovery ready || return 1
    cr_focus_gpio OFF || return 1
    if [[ "${GPHOTO_CAPTURE_MODE:-eos-legacy}" == eos-legacy ]]; then
      # Same shutter sequence as the old camera, isolated download hook and
      # bounded release cleanup; existing shared camera/climate locks stay held.
      local eos_helper="${CAMERA_RPI_EOS_HELPER:-$(dirname -- "${BASH_SOURCE[0]}")/camera-rpi-eos-legacy}"
      [[ -x "$eos_helper" ]] || { cr_fail "EOS-Legacy-Helfer fehlt"; return 1; }
      [[ -z "${CR_CAPTURE_ATTEMPT_MARKER:-}" ]] || : > "$CR_CAPTURE_ATTEMPT_MARKER" || return 1
      exec "${CAMERA_RPI_TIMEOUT_BIN:-/usr/bin/timeout}" --signal=TERM --kill-after=15 \
        "${GPHOTO_TIMEOUT_SECONDS}s" "$eos_helper" --capture "$target" "$MAX_IMAGE_BYTES"
    fi
    [[ -z "${CR_CAPTURE_ATTEMPT_MARKER:-}" ]] || : > "$CR_CAPTURE_ATTEMPT_MARKER" || return 1
    exec "${CAMERA_RPI_TIMEOUT_BIN:-/usr/bin/timeout}" --signal=TERM --kill-after=10 \
      "${GPHOTO_TIMEOUT_SECONDS}s" "${CAMERA_RPI_GPHOTO_BIN:-/usr/bin/gphoto2}" \
      --capture-image-and-download --force-overwrite --filename "$target"
  fi
  local video_bin="${CAMERA_RPI_V4L2_BIN:-/usr/bin/v4l2-ctl}"
  local timeout_bin="${CAMERA_RPI_TIMEOUT_BIN:-/usr/bin/timeout}"
  # Auch ein falsch geliefertes Rohbild darf die Spooldatei nicht beliebig
  # vergroessern. Bash verwendet hier 1024-Byte-Bloecke.
  ulimit -f "$(((MAX_IMAGE_BYTES + 1023) / 1024))" || return 1
  format="$(LC_ALL=C "$timeout_bin" --signal=TERM --kill-after=2 5s "$video_bin" \
    --device="$VIDEO_DEVICE" \
    --set-fmt-video="width=$VIDEO_WIDTH,height=$VIDEO_HEIGHT,pixelformat=MJPG" \
    --get-fmt-video)" || return 1
  # Treiber koennen still auf andere Formate/Groessen ausweichen.
  grep -Eq "Pixel Format[[:space:]]*:[[:space:]]*'MJPG'" <<< "$format" \
    && grep -Eq "Width/Height[[:space:]]*:[[:space:]]*$VIDEO_WIDTH/$VIDEO_HEIGHT([[:space:]]|$)" <<< "$format" \
    || { cr_fail "Kamera unterstuetzt die gewaehlte MJPEG-Aufloesung nicht"; return 1; }
  "$timeout_bin" --signal=TERM --kill-after=2 30s "$video_bin" \
    --device="$VIDEO_DEVICE" --stream-mmap=3 --stream-poll \
    --stream-skip="$VIDEO_WARMUP_FRAMES" --stream-count=1 --stream-to="$target" || return $?
  local jpeg_helper="${CAMERA_RPI_JPEG_HELPER:-$(dirname -- "${BASH_SOURCE[0]}")/camera-rpi-jpeg.py}"
  exec "$timeout_bin" --signal=TERM --kill-after=2 5s /usr/bin/python3 -B \
    "$jpeg_helper" "$target" "$VIDEO_WIDTH" "$VIDEO_HEIGHT" "$MAX_IMAGE_BYTES"
)

# Internal worker hook under operation.lock; no remote command exposes RESET.
# A marker is created only immediately before physical gphoto execution.
cr_capture_recovery() {
  [[ "${CAPTURE_BACKEND:-}" == gphoto2 ]] || return 0
  local helper="${CAMERA_RPI_POWER_HELPER:-$(dirname -- "${BASH_SOURCE[0]}")/../camera-rpi-power.php}" message
  [[ -r "$helper" ]] || helper=/usr/local/libexec/camera-rpi-power.php
  [[ -r "$helper" ]] || return 0
  if [[ "$1" != ready && ! -f "${CR_CAPTURE_ATTEMPT_MARKER:-/nonexistent}" ]]; then return 0; fi
  if message="$(/usr/bin/timeout --kill-after=2 8 /usr/bin/php "$helper" "capture_$1" 2>&1)"; then
    [[ -z "$message" ]] || cr_log WARNING "$message"
  else
    cr_log WARNING "Kamera-Recovery: $message"
    return 1
  fi
}

# Privacy wraps BOTH capture backends and every caller, including manual CLI
# and shared pool. Never decode or stage a rejected frame on SD. The caller
# consumes exit 3 as an intentional skip, not a capture retry or upload.
cr_capture_to_file() (
  local policy="${CAMERA_RPI_PRIVACY_CONFIG:-/etc/camera-rpi/privacy.json}"
  if [[ ! -e "$policy" && ! -L "$policy" ]]; then cr_capture_backend_to_file "$1"; return $?; fi
  local helper="${CAMERA_RPI_PRIVACY_HELPER:-/usr/local/libexec/camera-rpi-privacy.php}"
  local run="${CAMERA_RPI_PRIVACY_RUNTIME:-/run/camera-rpi-privacy}" stage
  [[ ! -e "$1" && ! -L "$1" ]] || { cr_fail 'Aufnahmeziel existiert bereits'; return 1; }
  [[ -r "$helper" ]] || { cr_fail 'Aufnahmeschutz konfiguriert, aber Helfer fehlt'; return 3; }
  /usr/bin/timeout --kill-after=2 10 /usr/bin/php "$helper" check || return 3
  [[ ! -L "$run" ]] && mkdir -p -- "$run" && chmod 0700 "$run" || return 3
  [[ "$("${CAMERA_RPI_FINDMNT_BIN:-/usr/bin/findmnt}" -n -o FSTYPE -T "$run")" == tmpfs ]] \
    || { cr_fail 'Aufnahmeschutz benötigt tmpfs; keine SD-Ausweichdatei'; return 3; }
  stage="$(mktemp -d "$run/capture.XXXXXXXX")" || return 3
  trap 'rm -f -- "$stage/image.jpg"; rmdir -- "$stage"' EXIT
  # Both this wrapper and the admin save use the outer operation.lock. Config
  # cannot change midway through a worker capture; wall time is checked twice.
  cr_capture_backend_to_file "$stage/image.jpg" || return 1
  /usr/bin/timeout --kill-after=2 20 /usr/bin/php -d memory_limit=256M "$helper" inspect "$stage/image.jpg" || return 3
  cp -- "$stage/image.jpg" "$1" || return 1
)

# Konfigurationswerte und Defaults. Die Funktion setzt globale Variablen, die
# der Worker danach verwendet.
cr_load_server_config() {
  local config_path="$1"
  local line
  local key
  local value
  local line_number=0
  local parsed_bool
  local -A seen=()

  cr_private_file "$config_path" "Serverkonfiguration" || return 1

  SERVER_ID=""
  SERVER_BASE_URL=""
  CAMERA_SLUG=""
  KEY_FILE=""
  ENABLED=1
  POLL_SECONDS=5
  ERROR_BACKOFF_SECONDS=20
  CONNECT_TIMEOUT_SECONDS=10
  COMMAND_TIMEOUT_SECONDS=30
  UPLOAD_TIMEOUT_SECONDS=90
  UPLOAD_MODE="legacy"
  UPLOAD_CHUNK_BYTES=262144
  GPHOTO_TIMEOUT_SECONDS=120
  CAPTURE_BACKEND=gphoto2
  GPHOTO_CAPTURE_MODE=eos-legacy
  VIDEO_DEVICE=/dev/video0
  VIDEO_WIDTH=1920
  VIDEO_HEIGHT=1080
  VIDEO_WARMUP_FRAMES=15
  OPERATION_LOCK_TIMEOUT_SECONDS=900
  MAX_COMMAND_AGE_SECONDS=900
  MAX_COMMAND_FUTURE_SECONDS=300
  MIN_IMAGE_BYTES=1024
  MAX_IMAGE_BYTES=26214400
  MANAGE_ROOT_MOUNT=1
  SPOOL_ROOT="/var/lib/camera-rpi/spool"
  RUNTIME_ROOT="/run/camera-rpi"
  CA_CERT=""

  while IFS= read -r line || [[ -n "$line" ]]; do
    ((line_number += 1))
    line="${line%$'\r'}"
    line="$(cr_trim "$line")"
    [[ -z "$line" || "${line:0:1}" == "#" ]] && continue
    [[ "$line" == *=* ]] \
      || { cr_fail "Konfigurationszeile $line_number enthaelt kein ="; return 1; }

    key="$(cr_trim "${line%%=*}")"
    value="$(cr_trim "${line#*=}")"
    [[ "$key" =~ ^[a-z_]+$ ]] \
      || { cr_fail "Ungueltiger Konfigurationsname in Zeile $line_number"; return 1; }
    [[ -z "${seen[$key]+x}" ]] \
      || { cr_fail "Konfigurationswert $key ist doppelt vorhanden"; return 1; }
    seen[$key]=1

    case "$key" in
      server_id) SERVER_ID="$value" ;;
      server_base_url) SERVER_BASE_URL="$value" ;;
      camera_slug) CAMERA_SLUG="$value" ;;
      key_file) KEY_FILE="$value" ;;
      enabled)
        parsed_bool="$(cr_bool "$value")" \
          || { cr_fail "enabled muss true oder false sein"; return 1; }
        ENABLED="$parsed_bool"
        ;;
      poll_seconds) POLL_SECONDS="$value" ;;
      error_backoff_seconds) ERROR_BACKOFF_SECONDS="$value" ;;
      connect_timeout_seconds) CONNECT_TIMEOUT_SECONDS="$value" ;;
      command_timeout_seconds) COMMAND_TIMEOUT_SECONDS="$value" ;;
      upload_timeout_seconds) UPLOAD_TIMEOUT_SECONDS="$value" ;;
      upload_mode) UPLOAD_MODE="$value" ;;
      upload_chunk_bytes) UPLOAD_CHUNK_BYTES="$value" ;;
      gphoto_timeout_seconds) GPHOTO_TIMEOUT_SECONDS="$value" ;;
      capture_backend) CAPTURE_BACKEND="$value" ;;
      gphoto_capture_mode) GPHOTO_CAPTURE_MODE="$value" ;;
      video_device) VIDEO_DEVICE="$value" ;;
      video_width) VIDEO_WIDTH="$value" ;;
      video_height) VIDEO_HEIGHT="$value" ;;
      video_warmup_frames) VIDEO_WARMUP_FRAMES="$value" ;;
      operation_lock_timeout_seconds) OPERATION_LOCK_TIMEOUT_SECONDS="$value" ;;
      max_command_age_seconds) MAX_COMMAND_AGE_SECONDS="$value" ;;
      max_command_future_seconds) MAX_COMMAND_FUTURE_SECONDS="$value" ;;
      min_image_bytes) MIN_IMAGE_BYTES="$value" ;;
      max_image_bytes) MAX_IMAGE_BYTES="$value" ;;
      manage_root_mount)
        parsed_bool="$(cr_bool "$value")" \
          || { cr_fail "manage_root_mount muss true oder false sein"; return 1; }
        MANAGE_ROOT_MOUNT="$parsed_bool"
        ;;
      spool_root) SPOOL_ROOT="$value" ;;
      runtime_root) RUNTIME_ROOT="$value" ;;
      ca_cert) CA_CERT="$value" ;;
      *) cr_fail "Unbekannter Konfigurationswert: $key"; return 1 ;;
    esac
  done < "$config_path"

  [[ "$SERVER_ID" =~ ^[a-z0-9]+([a-z0-9-]*[a-z0-9])?$ ]] \
    || { cr_fail "server_id ist ungueltig"; return 1; }
  [[ "$CAMERA_SLUG" =~ ^[a-z0-9]+([a-z0-9-]*[a-z0-9])?$ ]] \
    || { cr_fail "camera_slug ist ungueltig"; return 1; }
  # Erlaubt DNS-Namen, IPv4 und IPv6 in eckigen Klammern sowie einen
  # optionalen Port und Pfad. Query-Strings, Fragmente und Benutzername:Passwort
  # sind fuer diese feste Endpoint-Basis nicht erforderlich und verboten.
  [[ "$SERVER_BASE_URL" =~ ^https?://([A-Za-z0-9.-]+|\[[A-Fa-f0-9:]+\])(:[0-9]{1,5})?(/[^[:space:]?#]*)?$ ]] \
    || { cr_fail "server_base_url ist keine erlaubte HTTP(S)-Basis-URL"; return 1; }
  [[ ! "$SERVER_BASE_URL" =~ ^https?://[^/]*@ ]] \
    || { cr_fail "Zugangsdaten duerfen nicht in server_base_url stehen"; return 1; }
  SERVER_BASE_URL="${SERVER_BASE_URL%/}"

  cr_safe_absolute_path "$KEY_FILE" \
    || { cr_fail "key_file muss ein sicherer absoluter Pfad sein"; return 1; }
  cr_private_file "$KEY_FILE" "Key-Datei" || return 1
  cr_safe_absolute_path "$SPOOL_ROOT" && cr_safe_absolute_path "$RUNTIME_ROOT" \
    || { cr_fail "spool_root und runtime_root muessen sichere absolute Pfade sein"; return 1; }
  [[ -z "$CA_CERT" || ( "$CA_CERT" == /* && -r "$CA_CERT" ) ]] \
    || { cr_fail "ca_cert ist nicht lesbar"; return 1; }

  cr_number_in_range poll_seconds "$POLL_SECONDS" 1 300 || return 1
  cr_number_in_range error_backoff_seconds "$ERROR_BACKOFF_SECONDS" 1 3600 || return 1
  cr_number_in_range connect_timeout_seconds "$CONNECT_TIMEOUT_SECONDS" 1 120 || return 1
  cr_number_in_range command_timeout_seconds "$COMMAND_TIMEOUT_SECONDS" 2 900 || return 1
  cr_number_in_range upload_timeout_seconds "$UPLOAD_TIMEOUT_SECONDS" 5 1800 || return 1
  [[ "$UPLOAD_MODE" == "legacy" || "$UPLOAD_MODE" == "chunked" ]] \
    || { cr_fail "upload_mode muss legacy oder chunked sein"; return 1; }
  cr_number_in_range upload_chunk_bytes "$UPLOAD_CHUNK_BYTES" 65536 1048576 || return 1
  cr_number_in_range gphoto_timeout_seconds "$GPHOTO_TIMEOUT_SECONDS" 5 1800 || return 1
  cr_validate_capture_config || return 1
  cr_number_in_range operation_lock_timeout_seconds "$OPERATION_LOCK_TIMEOUT_SECONDS" 5 3600 || return 1
  cr_number_in_range max_command_age_seconds "$MAX_COMMAND_AGE_SECONDS" 30 86400 || return 1
  cr_number_in_range max_command_future_seconds "$MAX_COMMAND_FUTURE_SECONDS" 0 3600 || return 1
  cr_number_in_range min_image_bytes "$MIN_IMAGE_BYTES" 128 10485760 || return 1
  cr_number_in_range max_image_bytes "$MAX_IMAGE_BYTES" 1024 104857600 || return 1
  (( MIN_IMAGE_BYTES < MAX_IMAGE_BYTES )) \
    || { cr_fail "min_image_bytes muss kleiner als max_image_bytes sein"; return 1; }

  COMMAND_URL="$SERVER_BASE_URL/include/command.php"
  UPLOAD_URL="$SERVER_BASE_URL/include/upload.php"
  CHUNK_UPLOAD_URL="$SERVER_BASE_URL/include/upload-chunk.php"
  SERVER_SPOOL_DIR="$SPOOL_ROOT/$SERVER_ID"
  SERVER_RUNTIME_DIR="$RUNTIME_ROOT/$SERVER_ID"
}
