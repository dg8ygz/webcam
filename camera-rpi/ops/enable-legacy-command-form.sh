#!/usr/bin/env bash
set -euo pipefail

WORKER=/usr/local/bin/camera-rpi-worker
BACKUP="${WORKER}.before-legacy-command-$(date +%Y%m%d_%H%M%S)"

[[ -f "$WORKER" ]] || { echo "Worker fehlt: $WORKER" >&2; exit 1; }
cp -a "$WORKER" "$BACKUP"

python3 - "$WORKER" <<'PY'
from pathlib import Path
import sys

path = Path(sys.argv[1])
text = path.read_text()

if 'COMMAND_FORM_FILE=""' not in text:
    text = text.replace(
        'COMMAND_HEADER_FILE=""\n',
        'COMMAND_HEADER_FILE=""\nCOMMAND_FORM_FILE=""\n',
        1,
    )

if 'COMMAND_FORM_FILE="$SERVER_RUNTIME_DIR/command.form"' not in text:
    text = text.replace(
        'COMMAND_HEADER_FILE="$SERVER_RUNTIME_DIR/command.header"\n',
        'COMMAND_HEADER_FILE="$SERVER_RUNTIME_DIR/command.header"\n'
        'COMMAND_FORM_FILE="$SERVER_RUNTIME_DIR/command.form"\n',
        1,
    )

old_cleanup = '"$COMMAND_RESPONSE_FILE" "$COMMAND_RESPONSE_HEADERS" "$UPLOAD_RESPONSE_FILE"'
new_cleanup = '"$COMMAND_RESPONSE_FILE" "$COMMAND_RESPONSE_HEADERS" "$COMMAND_FORM_FILE" "$UPLOAD_RESPONSE_FILE"'
if old_cleanup in text and '"$COMMAND_FORM_FILE" "$UPLOAD_RESPONSE_FILE"' not in text:
    text = text.replace(old_cleanup, new_cleanup, 1)

start = text.index('poll_command() {')
end = text.index('\n}\n\n# Akzeptiert absichtlich', start) + 2

replacement = r'''poll_command() {
  local http_code

  # Legacy-Server erwarten den Schlüssel als Multipart-Feld "key".
  # Die private curl-Konfigurationsdatei verhindert, dass der Schlüssel
  # in der Prozessliste sichtbar wird.
  cr_read_secret "$KEY_FILE" || return 1
  (umask 077
   printf 'form-string = "key=%s"\n' "$CR_SECRET" > "$COMMAND_FORM_FILE"
  ) || {
    unset CR_SECRET
    cr_fail "Private Command-Formulardatei kann nicht geschrieben werden"
    return 1
  }
  unset CR_SECRET

  # Der Header bleibt zusätzlich erhalten und funktioniert mit neuen Servern.
  cr_write_header_file "X-Command-Key" "$KEY_FILE" "$COMMAND_HEADER_FILE" || {
    rm -f -- "$COMMAND_FORM_FILE"
    return 1
  }

  curl_common_arguments
  http_code="$($CURL_BIN "${CURL_ARGUMENTS[@]}" \
    --max-time "$COMMAND_TIMEOUT_SECONDS" \
    --max-filesize 4096 \
    --config "$COMMAND_FORM_FILE" \
    --header "@$COMMAND_HEADER_FILE" \
    --form-string "wc=$CAMERA_SLUG" \
    --output "$COMMAND_RESPONSE_FILE" \
    --dump-header "$COMMAND_RESPONSE_HEADERS" \
    --write-out '%{http_code}' \
    "$COMMAND_URL")" || {
      rm -f -- "$COMMAND_HEADER_FILE" "$COMMAND_FORM_FILE" "$COMMAND_RESPONSE_FILE"
      cr_fail "Befehlsabfrage ist technisch fehlgeschlagen"
      return 1
    }

  rm -f -- "$COMMAND_HEADER_FILE" "$COMMAND_FORM_FILE"

  [[ "$http_code" =~ ^2[0-9][0-9]$ ]] \
    || {
      rm -f -- "$COMMAND_RESPONSE_FILE"
      cr_fail "Befehlsabfrage lieferte HTTP $http_code"
      return 1
    }

  read_upload_mode
}'''

text = text[:start] + replacement + text[end:]
path.write_text(text)
PY

bash -n "$WORKER"
systemctl restart camera-rpi-worker@test.service
echo "OK: Legacy-Command-Formular aktiviert"
echo "Backup: $BACKUP"
