#!/usr/bin/env bash
set -uo pipefail

# Simuliert genau eine externe Webcam:
# - pollt include/command.php mit dem Kamera-Schluessel,
# - holt bei webcam_capture ein JPEG vom Alt-System,
# - stellt es mit dem vom PROD-Server gelieferten Zeitstempel bevorzugt per
#   ausgehandeltem Chunk-v1, sonst kontrolliert bei upload.php ein.
# Eine systemd-Template-Instanz pro Kamera hält Fehler und Spool getrennt.

umask 077

usage() {
  cat <<'EOF'
Usage:
  webcam-legacy-helper CONFIG
  webcam-legacy-helper --once CONFIG
  webcam-legacy-helper --forward-slot EPOCH CONFIG
  webcam-legacy-helper --check-config CONFIG
EOF
}

log() {
  local level="$1"
  shift
  printf '%s [%s] [%s] %s\n' "$(date '+%Y-%m-%dT%H:%M:%S%z')" "$level" "${CAMERA_SLUG:-unknown}" "$*"
}

fail() {
  log ERROR "$*" >&2
  exit 1
}

run_mode="daemon"
forward_slot=""
case "${1:-}" in
  --forward-slot)
    run_mode="forward"
    forward_slot="${2:-}"
    [[ "$forward_slot" =~ ^[1-9][0-9]{8,11}$ ]] || fail "Ungültiger Weiterleitungs-Slot"
    shift 2
    ;;
  --once)
    run_mode="once"
    shift
    ;;
  --check-config)
    run_mode="check"
    shift
    ;;
  -h|--help)
    usage
    exit 0
    ;;
esac

config_path="${1:-}"
[[ -n "$config_path" && $# -eq 1 ]] || {
  usage >&2
  exit 2
}
[[ -f "$config_path" && -r "$config_path" ]] || fail "Konfiguration nicht lesbar: $config_path"

config_mode="$(stat -c '%a' "$config_path" 2>/dev/null || true)"
[[ "$config_mode" =~ ^[0-7]{3,4}$ ]] || fail "Konfigurationsrechte nicht lesbar: $config_path"
if (( (8#$config_mode & 0022) != 0 )); then
  fail "Konfiguration darf nicht gruppen- oder weltbeschreibbar sein: $config_path"
fi

# Nur diese drei Test-/Betriebspfade dürfen von außen überschrieben werden.
# Werte aus der Konfigurationsdatei können dadurch keine beliebigen Schreibziele
# oder Programme einschleusen.
helper_curl_override="${WEBCAM_HELPER_CURL_BIN:-}"
helper_state_override="${WEBCAM_HELPER_STATE_ROOT:-}"
helper_runtime_override="${WEBCAM_HELPER_RUNTIME_ROOT:-}"

# Die Datei ist rootverwaltet und nicht schreibbar für den Dienstbenutzer.
# shellcheck source=/dev/null
source "$config_path"

CAMERA_SLUG="${CAMERA_SLUG:-}"
INSTANCE_ID="${INSTANCE_ID:-$CAMERA_SLUG}"
SERVER_PROTOCOL="${SERVER_PROTOCOL:-webcam-v2}"
SOURCE_TIMEZONE="${SOURCE_TIMEZONE:-Europe/Berlin}"
UPLOAD_KEY="${UPLOAD_KEY:-}"
PROD_BASE_URL="${PROD_BASE_URL:-}"
SOURCE_URL="${SOURCE_URL:-}"
SOURCE_SLUG="${SOURCE_SLUG:-$CAMERA_SLUG}"
LEGACY_BASE_URL="${LEGACY_BASE_URL:-}"
POLL_SECONDS="${POLL_SECONDS:-5}"
ERROR_BACKOFF_SECONDS="${ERROR_BACKOFF_SECONDS:-20}"
CONNECT_TIMEOUT_SECONDS="${CONNECT_TIMEOUT_SECONDS:-10}"
if [[ "$SERVER_PROTOCOL" == "foto-webcam-v1" ]]; then
  COMMAND_TIMEOUT_SECONDS="${COMMAND_TIMEOUT_SECONDS:-90}"
else
  COMMAND_TIMEOUT_SECONDS="${COMMAND_TIMEOUT_SECONDS:-30}"
fi
SOURCE_TIMEOUT_SECONDS="${SOURCE_TIMEOUT_SECONDS:-30}"
UPLOAD_TIMEOUT_SECONDS="${UPLOAD_TIMEOUT_SECONDS:-90}"
CHUNK_TIMEOUT_SECONDS="${CHUNK_TIMEOUT_SECONDS:-30}"
CHUNK_RETRY_COUNT="${CHUNK_RETRY_COUNT:-3}"
CHUNK_RETRY_DELAY_SECONDS="${CHUNK_RETRY_DELAY_SECONDS:-2}"
UPLOAD_TRANSPORT="${UPLOAD_TRANSPORT:-auto}"
SOURCE_DELAY_SECONDS="${SOURCE_DELAY_SECONDS:-210}"
MAX_SOURCE_AGE_SECONDS="${MAX_SOURCE_AGE_SECONDS:-900}"
MIN_IMAGE_BYTES="${MIN_IMAGE_BYTES:-1024}"
MAX_IMAGE_BYTES="${MAX_IMAGE_BYTES:-26214400}"
PROD_CA_CERT="${PROD_CA_CERT:-}"
SOURCE_CA_CERT="${SOURCE_CA_CERT:-}"

[[ "$CAMERA_SLUG" =~ ^[a-z0-9]+([a-z0-9-]*[a-z0-9])?$ ]] || fail "Ungültiger CAMERA_SLUG"
[[ "$INSTANCE_ID" =~ ^[a-z0-9]+([a-z0-9-]*[a-z0-9])?$ ]] || fail "Ungültige INSTANCE_ID"
[[ "$SERVER_PROTOCOL" == "webcam-v2" || "$SERVER_PROTOCOL" == "foto-webcam-v1" ]] \
  || fail "Unbekanntes SERVER_PROTOCOL"
[[ "$SOURCE_TIMEZONE" =~ ^[A-Za-z_]+(/[A-Za-z_+-]+)*$ && -f "/usr/share/zoneinfo/$SOURCE_TIMEZONE" ]] \
  || fail "Ungültige SOURCE_TIMEZONE"
[[ -n "$UPLOAD_KEY" ]] || fail "UPLOAD_KEY fehlt"
[[ "$UPLOAD_KEY" != *$'\n'* && "$UPLOAD_KEY" != *$'\r'* ]] || fail "UPLOAD_KEY enthält ungültige Zeichen"
[[ "$PROD_BASE_URL" =~ ^https?://[^[:space:]]+$ ]] \
  || fail "PROD_BASE_URL muss eine HTTP(S)-URL ohne Leerzeichen sein"
[[ ! "$PROD_BASE_URL" =~ ^https?://[^/]*@ ]] \
  || fail "Zugangsdaten dürfen nicht im PROD_BASE_URL stehen"
PROD_BASE_URL="${PROD_BASE_URL%/}"

if [[ -z "$SOURCE_URL" ]]; then
  [[ "$LEGACY_BASE_URL" =~ ^https?:// ]] || fail "SOURCE_URL oder LEGACY_BASE_URL fehlt"
  [[ "$SOURCE_SLUG" =~ ^[a-z0-9]+([a-z0-9-]*[a-z0-9])?$ ]] || fail "Ungültiger SOURCE_SLUG"
  SOURCE_URL="${LEGACY_BASE_URL%/}/${SOURCE_SLUG}/current/raw.jpg"
fi
[[ "$SOURCE_URL" =~ ^https?://[^[:space:]]+$ ]] \
  || fail "SOURCE_URL muss eine HTTP(S)-URL ohne Leerzeichen sein"
[[ ! "$SOURCE_URL" =~ ^https?://[^/]*@ ]] \
  || fail "Zugangsdaten dürfen nicht in SOURCE_URL stehen"

validate_number() {
  local name="$1"
  local minimum="$2"
  local maximum="$3"
  local value="${!name:-}"
  [[ "$value" =~ ^[0-9]+$ ]] || fail "$name muss eine ganze Zahl sein"
  (( value >= minimum && value <= maximum )) || fail "$name außerhalb des Bereichs $minimum-$maximum"
}

validate_number POLL_SECONDS 1 300
validate_number ERROR_BACKOFF_SECONDS 1 3600
validate_number CONNECT_TIMEOUT_SECONDS 1 120
validate_number COMMAND_TIMEOUT_SECONDS 2 300
validate_number SOURCE_TIMEOUT_SECONDS 2 600
validate_number UPLOAD_TIMEOUT_SECONDS 5 1800
validate_number CHUNK_TIMEOUT_SECONDS 5 300
validate_number CHUNK_RETRY_COUNT 1 10
validate_number CHUNK_RETRY_DELAY_SECONDS 0 60
validate_number SOURCE_DELAY_SECONDS 0 900
validate_number MAX_SOURCE_AGE_SECONDS 30 3600
validate_number MIN_IMAGE_BYTES 128 1048576
validate_number MAX_IMAGE_BYTES 1024 26214400
(( MIN_IMAGE_BYTES < MAX_IMAGE_BYTES )) || fail "MIN_IMAGE_BYTES muss kleiner als MAX_IMAGE_BYTES sein"
(( SOURCE_DELAY_SECONDS < MAX_SOURCE_AGE_SECONDS )) \
  || fail "SOURCE_DELAY_SECONDS muss kleiner als MAX_SOURCE_AGE_SECONDS sein"
[[ "$UPLOAD_TRANSPORT" == "auto" || "$UPLOAD_TRANSPORT" == "chunk" || "$UPLOAD_TRANSPORT" == "legacy" ]] \
  || fail "UPLOAD_TRANSPORT muss auto, chunk oder legacy sein"

for certificate in "$PROD_CA_CERT" "$SOURCE_CA_CERT"; do
  [[ -z "$certificate" || ( -f "$certificate" && -r "$certificate" ) ]] \
    || fail "CA-Datei nicht lesbar: $certificate"
done

CURL_BIN="${helper_curl_override:-/usr/bin/curl}"
STATE_ROOT="${helper_state_override:-/var/lib/webcam-legacy-helper}"
RUNTIME_ROOT="${helper_runtime_override:-/run/webcam-legacy-helper/$INSTANCE_ID}"
[[ -x "$CURL_BIN" ]] || fail "curl nicht ausführbar: $CURL_BIN"

COMMAND_URL="$PROD_BASE_URL/include/command.php"
UPLOAD_URL="$PROD_BASE_URL/include/upload.php"
CHUNK_UPLOAD_URL="$PROD_BASE_URL/include/upload-chunk.php"
CHUNK_PROTOCOL="camera-rpi-chunk-v1"
CHUNK_MIN_BYTES=65536
CHUNK_MAX_BYTES=1048576

if [[ "$run_mode" == "check" ]]; then
  printf 'Konfiguration gültig: slug=%s source=konfiguriert transport=%s command=%s upload=%s chunk=%s\n' \
    "$CAMERA_SLUG" "$UPLOAD_TRANSPORT" "$COMMAND_URL" "$UPLOAD_URL" "$CHUNK_UPLOAD_URL"
  exit 0
fi

spool_dir="$STATE_ROOT/$INSTANCE_ID"
failed_dir="$spool_dir/failed"
mkdir -p "$spool_dir" "$failed_dir" "$RUNTIME_ROOT" \
  || fail "State-/Runtime-Verzeichnis kann nicht angelegt werden"
chmod 0700 "$spool_dir" "$failed_dir" "$RUNTIME_ROOT" 2>/dev/null || true

lock_path="$RUNTIME_ROOT/$CAMERA_SLUG.lock"
heartbeat_path="$RUNTIME_ROOT/$CAMERA_SLUG.heartbeat"
header_path="$RUNTIME_ROOT/$CAMERA_SLUG.headers"
key_path="$RUNTIME_ROOT/$CAMERA_SLUG.key"
command_response="$RUNTIME_ROOT/$CAMERA_SLUG.command.response"
upload_response="$RUNTIME_ROOT/$CAMERA_SLUG.upload.response"
chunk_path="$RUNTIME_ROOT/$CAMERA_SLUG.chunk"

exec 9>"$lock_path" || fail "Lock kann nicht geöffnet werden: $lock_path"
if ! flock -n 9; then
  log INFO "bereits aktiv"
  exit 0
fi

printf 'X-Command-Key: %s\nX-Upload-Key: %s\n' "$UPLOAD_KEY" "$UPLOAD_KEY" > "$header_path" \
  || fail "privater Header kann nicht geschrieben werden"
if [[ "$SERVER_PROTOCOL" == "foto-webcam-v1" ]]; then
  printf '%s' "$UPLOAD_KEY" > "$key_path" || fail "privater Formularschlüssel kann nicht geschrieben werden"
fi

cleanup_runtime() {
  local runtime_file
  for runtime_file in "$header_path" "$key_path" "$command_response" "$upload_response" "$chunk_path"; do
    unlink "$runtime_file" 2>/dev/null || true
  done
}
trap cleanup_runtime EXIT
trap 'exit 0' INT TERM

write_heartbeat() {
  printf '%s\n' "$(date +%s)" > "$heartbeat_path"
}

prod_curl_args=(
  --silent
  --show-error
  --connect-timeout "$CONNECT_TIMEOUT_SECONDS"
  --proto '=http,https'
)
if [[ "$SERVER_PROTOCOL" == "foto-webcam-v1" ]]; then
  # curl reads the field value from a private file; never put the key in argv.
  prod_curl_args+=(--form "key=<$key_path")
else
  prod_curl_args+=(--header "@$header_path")
fi
[[ -z "$PROD_CA_CERT" ]] || prod_curl_args+=(--cacert "$PROD_CA_CERT")

source_curl_args=(
  --silent
  --show-error
  --fail
  --location
  --connect-timeout "$CONNECT_TIMEOUT_SECONDS"
  --max-time "$SOURCE_TIMEOUT_SECONDS"
  --retry 2
  --retry-delay 1
  --retry-max-time "$SOURCE_TIMEOUT_SECONDS"
  --header 'Cache-Control: no-cache'
  --header 'Pragma: no-cache'
  --proto '=http,https'
  --proto-redir '=http,https'
)
[[ -z "$SOURCE_CA_CERT" ]] || source_curl_args+=(--cacert "$SOURCE_CA_CERT")

POLL_ACTION=""
POLL_SERIAL=""

poll_command() {
  local http_code
  local curl_exit
  local -a lines=()

  : > "$command_response"
  http_code="$(
    "$CURL_BIN" "${prod_curl_args[@]}" \
      --max-time "$COMMAND_TIMEOUT_SECONDS" \
      --output "$command_response" \
      --write-out '%{http_code}' \
      --form-string "wc=$CAMERA_SLUG" \
      "$COMMAND_URL"
  )"
  curl_exit=$?
  if (( curl_exit != 0 )); then
    log ERROR "Command-Poll fehlgeschlagen (curl=$curl_exit)"
    return 1
  fi
  if [[ "$http_code" != "200" ]]; then
    log ERROR "Command-Poll lieferte HTTP $http_code"
    return 1
  fi

  # Legacy command.php ends after 120 half-second waits with an empty body.
  # This is idle, not an upload acknowledgement or proof of authentication.
  if [[ "$SERVER_PROTOCOL" == "foto-webcam-v1" && ! -s "$command_response" ]]; then
    POLL_ACTION="wait"
    POLL_SERIAL=""
    write_heartbeat
    return 0
  fi
  mapfile -t lines < "$command_response"
  ((${#lines[@]} >= 3)) || {
    log ERROR "ungültige Command-Antwort"
    return 1
  }
  lines[0]="${lines[0]%$'\r'}"
  lines[1]="${lines[1]%$'\r'}"
  lines[2]="${lines[2]%$'\r'}"

  if [[ "$SERVER_PROTOCOL" == "foto-webcam-v1" && "${lines[0]}" == "command" ]]; then
    # Strict data parsing, never execute the command returned by a server.
    [[ "${lines[1]}" =~ ^[0-9]{9,12}$ && "${lines[2]}" =~ ^webcam_capture\ ([0-9]{8}_[0-9]{4})$ ]] || {
      log ERROR "Altserver-Befehl ist kein unterstützter Aufnahmeauftrag"
      return 1
    }
    local stamp="${BASH_REMATCH[1]}"
    local expected
    expected="$(TZ="$SOURCE_TIMEZONE" date -d "@${lines[1]}" '+%Y%m%d_%H%M')" || return 1
    [[ "$stamp" == "$expected" ]] || {
      log ERROR "Altserver-Slot passt nicht zur Befehlszeit"
      return 1
    }
    POLL_ACTION="capture"
    POLL_SERIAL="$((10#${lines[1]} / 60 * 60))"
  elif [[ "${lines[0]}" == "command" && "${lines[2]}" == "webcam_capture" ]]; then
    [[ "${lines[1]}" =~ ^[0-9]{9,12}$ ]] || {
      log ERROR "ungültiger Capture-Zeitstempel"
      return 1
    }
    POLL_ACTION="capture"
    POLL_SERIAL="${lines[1]}"
  elif [[ "${lines[0]}" == "wait" && "${lines[2]}" == "wait" ]]; then
    POLL_ACTION="wait"
    POLL_SERIAL=""
  else
    log ERROR "unbekannte Command-Antwort"
    return 1
  fi
  write_heartbeat
  return 0
}

queue_capture_request() {
  local serial="$1"
  local request_path="$spool_dir/$serial.request"
  local request_tmp="$request_path.part.$$"

  [[ -f "$spool_dir/$serial.jpg" || -f "$request_path" ]] && return 0
  printf '%s %s\n' "$serial" "$(date '+%Y-%m-%dT%H:%M:%S%z')" > "$request_tmp" \
    || return 1
  mv -- "$request_tmp" "$request_path"
  log INFO "Capture angefordert (serial=$serial)"
}

download_request() {
  local request_path="$1"
  local serial
  local now
  local age
  local image_path
  local part_path
  local size
  local magic
  local source_url
  local slot_path

  serial="$(basename "$request_path" .request)"
  [[ "$serial" =~ ^[0-9]{9,12}$ ]] || {
    log ERROR "ungültige Request-Datei: $request_path"
    return 1
  }
  image_path="$spool_dir/$serial.jpg"
  if [[ -f "$image_path" ]]; then
    unlink "$request_path"
    return 0
  fi

  now="$(date +%s)"
  age=$((now - serial))
  if (( age < SOURCE_DELAY_SECONDS )); then
    return 0
  fi
  if (( age > MAX_SOURCE_AGE_SECONDS )); then
    mv -- "$request_path" "$failed_dir/$serial.request"
    log ERROR "Quellbild nicht rechtzeitig verfügbar; Request archiviert (serial=$serial age=${age}s)"
    return 1
  fi

  part_path="$spool_dir/$serial.part"
  unlink "$part_path" 2>/dev/null || true
  slot_path="$(TZ="$SOURCE_TIMEZONE" date -d "@$serial" '+%Y/%m/%d/%Y%m%d_%H%M%S')" || return 1
  source_url="${SOURCE_URL//\{slot_path\}/$slot_path}"
  if ! "$CURL_BIN" "${source_curl_args[@]}" --output "$part_path" "$source_url"; then
    unlink "$part_path" 2>/dev/null || true
    log ERROR "Quellbild konnte nicht geladen werden (serial=$serial)"
    return 1
  fi

  size="$(stat -c '%s' "$part_path" 2>/dev/null || printf '0')"
  magic="$(od -An -tx1 -N2 "$part_path" 2>/dev/null | tr -d '[:space:]')"
  if [[ ! "$size" =~ ^[0-9]+$ ]] \
    || (( size < MIN_IMAGE_BYTES || size > MAX_IMAGE_BYTES )) \
    || [[ "$magic" != "ffd8" ]]; then
    unlink "$part_path" 2>/dev/null || true
    log ERROR "Quellantwort ist kein gültiges JPEG (serial=$serial bytes=$size)"
    return 1
  fi

  mv -- "$part_path" "$image_path"
  unlink "$request_path"
  log INFO "Quellbild gespeichert (serial=$serial bytes=$size)"
  return 0
}

process_oldest_request() {
  local -a requests=()
  shopt -s nullglob
  requests=("$spool_dir"/*.request)
  shopt -u nullglob
  ((${#requests[@]} > 0)) || return 0
  download_request "${requests[0]}"
}

upload_image() {
  local image_path="$1"
  if [[ "$SERVER_PROTOCOL" == "foto-webcam-v1" ]]; then
    upload_image_oldprod "$image_path"
    return $?
  fi
  local capability_result

  if [[ "$UPLOAD_TRANSPORT" == "legacy" ]]; then
    upload_image_legacy "$image_path"
    return $?
  fi

  if probe_chunk_capability "$image_path"; then
    upload_image_chunked "$image_path"
    return $?
  else
    capability_result=$?
  fi
  if (( capability_result == 2 )) && [[ "$UPLOAD_TRANSPORT" == "auto" ]]; then
    log INFO "Chunk-v1 nicht angeboten; verwende Legacy-Upload"
    upload_image_legacy "$image_path"
    return $?
  fi
  if (( capability_result == 2 )); then
    log ERROR "Chunk-v1 wird verlangt, aber nicht angeboten; Bild bleibt im Spool"
  fi
  return 1
}

json_has_ok_true() {
  local response_path="$1"
  grep -Eq '"ok"[[:space:]]*:[[:space:]]*true' "$response_path"
}

json_has_exact_protocol() {
  local response_path="$1"
  grep -Eq "\"protocol\"[[:space:]]*:[[:space:]]*\"${CHUNK_PROTOCOL}\"" "$response_path"
}

json_number() {
  local response_path="$1"
  local field="$2"
  sed -n -E \
    "s/.*\"${field}\"[[:space:]]*:[[:space:]]*([0-9]+).*/\\1/p" \
    "$response_path" | head -n 1
}

CHUNK_SIZE_BYTES=0

probe_chunk_capability() {
  local image_path="$1"
  local image_size
  local http_code
  local curl_exit
  local preferred
  local advertised_min
  local advertised_max
  local advertised_upload_max

  image_size="$(stat -c '%s' "$image_path" 2>/dev/null || printf '0')"
  : > "$upload_response"
  http_code="$(
    "$CURL_BIN" "${prod_curl_args[@]}" \
      --max-time "$CHUNK_TIMEOUT_SECONDS" \
      --output "$upload_response" \
      --write-out '%{http_code}' \
      --form-string 'action=capabilities' \
      --form-string "wc=$CAMERA_SLUG" \
      "$CHUNK_UPLOAD_URL"
  )"
  curl_exit=$?
  if (( curl_exit != 0 )); then
    log ERROR "Chunk-Capability-Prüfung fehlgeschlagen; Bild bleibt im Spool (curl=$curl_exit)"
    return 1
  fi

  if [[ "$http_code" == "404" || "$http_code" == "405" || "$http_code" == "410" || "$http_code" == "501" ]]; then
    return 2
  fi
  if [[ "$http_code" != "200" ]]; then
    log ERROR "Chunk-Capability-Prüfung lieferte HTTP $http_code; Bild bleibt im Spool"
    return 1
  fi
  if ! json_has_ok_true "$upload_response" || ! json_has_exact_protocol "$upload_response"; then
    return 2
  fi

  preferred="$(json_number "$upload_response" preferred_chunk_bytes)"
  advertised_min="$(json_number "$upload_response" min_chunk_bytes)"
  advertised_max="$(json_number "$upload_response" max_chunk_bytes)"
  advertised_upload_max="$(json_number "$upload_response" max_upload_bytes)"
  if [[ ! "$preferred" =~ ^[0-9]+$ \
    || ! "$advertised_min" =~ ^[0-9]+$ \
    || ! "$advertised_max" =~ ^[0-9]+$ \
    || ! "$advertised_upload_max" =~ ^[0-9]+$ ]] \
    || (( advertised_min > advertised_max \
      || preferred < advertised_min \
      || preferred > advertised_max \
      || preferred < CHUNK_MIN_BYTES \
      || preferred > CHUNK_MAX_BYTES )); then
    log ERROR "Chunk-v1-Capabilities sind ungültig; Bild bleibt im Spool"
    return 1
  fi
  if [[ ! "$image_size" =~ ^[0-9]+$ ]] || (( image_size > advertised_upload_max )); then
    log ERROR "Bild überschreitet die ausgehandelte Chunk-v1-Gesamtgröße; Bild bleibt im Spool"
    return 1
  fi

  CHUNK_SIZE_BYTES="$preferred"
  return 0
}

chunk_request() {
  local action="$1"
  local serial="$2"
  local upload_id="$3"
  local total_size="$4"
  local total_chunks="$5"
  local image_hash="$6"
  local chunk_index="${7:-}"
  local chunk_hash="${8:-}"
  local attempt
  local http_code
  local curl_exit
  local -a request_args=(
    --form-string "action=$action"
    --form-string "wc=$CAMERA_SLUG"
    --form-string "upload_id=$upload_id"
    --form-string "now=$serial"
    --form-string "total_size=$total_size"
    --form-string "chunk_size=$CHUNK_SIZE_BYTES"
    --form-string "total_chunks=$total_chunks"
    --form-string "sha256=$image_hash"
  )

  if [[ "$action" == "chunk" ]]; then
    request_args+=(
      --form-string "chunk_index=$chunk_index"
      --form-string "chunk_sha256=$chunk_hash"
      --form "upload=@${chunk_path};type=application/octet-stream;filename=chunk.bin"
    )
  fi

  for ((attempt = 1; attempt <= CHUNK_RETRY_COUNT; attempt++)); do
    : > "$upload_response"
    http_code="$(
      "$CURL_BIN" "${prod_curl_args[@]}" \
        --max-time "$CHUNK_TIMEOUT_SECONDS" \
        --output "$upload_response" \
        --write-out '%{http_code}' \
        "${request_args[@]}" \
        "$CHUNK_UPLOAD_URL"
    )"
    curl_exit=$?
    if (( curl_exit == 0 )) \
      && [[ "$http_code" =~ ^2[0-9]{2}$ ]] \
      && json_has_ok_true "$upload_response" \
      && json_has_exact_protocol "$upload_response"; then
      return 0
    fi

    if (( attempt < CHUNK_RETRY_COUNT )); then
      log WARN "Chunk-v1-$action fehlgeschlagen; wiederhole (serial=$serial attempt=$attempt/$CHUNK_RETRY_COUNT curl=$curl_exit http=${http_code:-000})"
      sleep "$CHUNK_RETRY_DELAY_SECONDS"
    fi
  done

  log ERROR "Chunk-v1-$action endgültig fehlgeschlagen; Bild bleibt im Spool (serial=$serial curl=$curl_exit http=${http_code:-000})"
  return 1
}

upload_image_chunked() {
  local image_path="$1"
  local serial
  local total_size
  local total_chunks
  local image_hash
  local upload_id
  local chunk_index
  local expected_size
  local actual_size
  local chunk_hash

  serial="$(basename "$image_path" .jpg)"
  [[ "$serial" =~ ^[0-9]{9,12}$ ]] || {
    log ERROR "ungültige Spool-Datei: $image_path"
    return 1
  }
  total_size="$(stat -c '%s' "$image_path" 2>/dev/null || printf '0')"
  image_hash="$(sha256sum "$image_path" 2>/dev/null | cut -d' ' -f1)"
  if [[ ! "$total_size" =~ ^[0-9]+$ || ! "$image_hash" =~ ^[a-f0-9]{64}$ || "$CHUNK_SIZE_BYTES" -le 0 ]]; then
    log ERROR "Chunk-Metadaten konnten nicht erzeugt werden; Bild bleibt im Spool (serial=$serial)"
    return 1
  fi
  total_chunks=$(((total_size + CHUNK_SIZE_BYTES - 1) / CHUNK_SIZE_BYTES))
  if (( total_chunks < 1 || total_chunks > 512 )); then
    log ERROR "Ungültige Chunk-Anzahl; Bild bleibt im Spool (serial=$serial chunks=$total_chunks)"
    return 1
  fi
  upload_id="$(printf '%s:%s:%s' "$CAMERA_SLUG" "$serial" "$image_hash" | sha256sum | cut -c1-32)"

  for ((chunk_index = 0; chunk_index < total_chunks; chunk_index++)); do
    unlink "$chunk_path" 2>/dev/null || true
    if ! dd if="$image_path" of="$chunk_path" bs="$CHUNK_SIZE_BYTES" skip="$chunk_index" count=1 status=none; then
      log ERROR "Chunk konnte nicht gelesen werden; Bild bleibt im Spool (serial=$serial index=$chunk_index)"
      return 1
    fi
    expected_size="$CHUNK_SIZE_BYTES"
    if (( chunk_index == total_chunks - 1 )); then
      expected_size=$((total_size - (chunk_index * CHUNK_SIZE_BYTES)))
    fi
    actual_size="$(stat -c '%s' "$chunk_path" 2>/dev/null || printf '0')"
    chunk_hash="$(sha256sum "$chunk_path" 2>/dev/null | cut -d' ' -f1)"
    if [[ "$actual_size" != "$expected_size" || ! "$chunk_hash" =~ ^[a-f0-9]{64}$ ]]; then
      log ERROR "Chunk-Prüfung fehlgeschlagen; Bild bleibt im Spool (serial=$serial index=$chunk_index)"
      return 1
    fi
    chunk_request chunk "$serial" "$upload_id" "$total_size" "$total_chunks" "$image_hash" "$chunk_index" "$chunk_hash" \
      || return 1
  done
  unlink "$chunk_path" 2>/dev/null || true

  if chunk_request finalize "$serial" "$upload_id" "$total_size" "$total_chunks" "$image_hash"; then
    unlink "$image_path"
    log INFO "Chunk-v1-Upload bestätigt (serial=$serial chunks=$total_chunks)"
    return 0
  fi
  return 1
}

upload_image_oldprod() {
  local image_path="$1" serial stamp http_code curl_exit
  serial="$(basename "$image_path" .jpg)"
  [[ "$serial" =~ ^[0-9]{9,12}$ ]] || return 1
  stamp="$(TZ="$SOURCE_TIMEZONE" date -d "@$serial" '+%Y%m%d_%H%M')" || return 1
  : > "$upload_response"
  http_code="$("$CURL_BIN" "${prod_curl_args[@]}" \
    --max-time "$UPLOAD_TIMEOUT_SECONDS" --output "$upload_response" \
    --write-out '%{http_code}' --header 'X-Webcam-Helper-Ack: slot-v1' \
    --form-string "wc=$CAMERA_SLUG" --form-string "now=$stamp" \
    --form "upload=@${image_path};type=image/jpeg;filename=raw.jpg" "$UPLOAD_URL")"
  curl_exit=$?
  if (( curl_exit == 0 )) && [[ "$http_code" =~ ^2[0-9]{2}$ ]] \
    && [[ "$(grep -Fxc "upload-ok $stamp" "$upload_response")" == "1" ]] \
    && ! grep -Eiq 'state=error|Fatal error|Warning:|Parse error|No valid JPG' "$upload_response"; then
    unlink "$image_path"
    log INFO "Alt-PROD Upload bestätigt (slot=$stamp http=$http_code)"
    return 0
  fi
  log ERROR "Alt-PROD ohne gültige Slot-Bestätigung; Bild bleibt im Spool (slot=$stamp curl=$curl_exit http=$http_code)"
  return 1
}

upload_image_legacy() {
  local image_path="$1"
  local serial
  local http_code
  local curl_exit
  local response_summary

  serial="$(basename "$image_path" .jpg)"
  [[ "$serial" =~ ^[0-9]{9,12}$ ]] || {
    log ERROR "ungültige Spool-Datei: $image_path"
    return 1
  }

  : > "$upload_response"
  http_code="$(
    "$CURL_BIN" "${prod_curl_args[@]}" \
      --max-time "$UPLOAD_TIMEOUT_SECONDS" \
      --output "$upload_response" \
      --write-out '%{http_code}' \
      --header "X-Capture-Timestamp: $serial" \
      --form-string "wc=$CAMERA_SLUG" \
      --form "upload=@${image_path};type=image/jpeg;filename=raw.jpg" \
      "$UPLOAD_URL"
  )"
  curl_exit=$?
  if (( curl_exit != 0 )); then
    log ERROR "Upload fehlgeschlagen; Bild bleibt im Spool (serial=$serial curl=$curl_exit)"
    return 1
  fi

  if [[ "$http_code" =~ ^2[0-9]{2}$ ]] \
    && grep -Eq '"ok"[[:space:]]*:[[:space:]]*true' "$upload_response"; then
    unlink "$image_path"
    log INFO "Upload bestätigt (serial=$serial http=$http_code)"
    return 0
  fi

  response_summary="$(tr '\r\n' '  ' < "$upload_response" | cut -c1-300)"
  log ERROR "Upload abgelehnt; Bild bleibt im Spool (serial=$serial http=$http_code body=$response_summary)"
  return 1
}

upload_oldest_image() {
  local -a images=()
  shopt -s nullglob
  images=("$spool_dir"/*.jpg)
  shopt -u nullglob
  ((${#images[@]} > 0)) || return 0
  upload_image "${images[0]}"
}

run_iteration() {
  local result=0

  write_heartbeat
  process_oldest_request || result=1
  upload_oldest_image || result=1

  if ! poll_command; then
    return 2
  fi
  if [[ "$POLL_ACTION" == "capture" ]]; then
    queue_capture_request "$POLL_SERIAL" || {
      log ERROR "Capture-Request konnte nicht persistiert werden (serial=$POLL_SERIAL)"
      return 1
    }
    process_oldest_request || result=1
    upload_oldest_image || result=1
  fi
  return "$result"
}

log INFO "gestartet (source=konfiguriert command=$COMMAND_URL)"

if [[ "$run_mode" == "forward" ]]; then
  [[ "$SOURCE_URL" == *'{slot_path}'* ]] || fail "Weiterleitung erfordert eine exakte Slotquelle"
  age=$(( $(date +%s) - forward_slot ))
  (( age >= SOURCE_DELAY_SECONDS && age <= MAX_SOURCE_AGE_SECONDS )) \
    || fail "Weiterleitungs-Slot noch nicht bereit oder zu alt"
  queue_capture_request "$forward_slot" || exit 1
  download_request "$spool_dir/$forward_slot.request" || exit 1
  upload_image "$spool_dir/$forward_slot.jpg"
  exit $?
fi

if [[ "$run_mode" == "once" ]]; then
  run_iteration
  exit $?
fi

while true; do
  if run_iteration; then
    sleep "$POLL_SECONDS"
  else
    sleep "$ERROR_BACKOFF_SECONDS"
  fi
done
