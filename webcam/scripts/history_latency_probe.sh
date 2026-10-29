#!/usr/bin/env bash
set -euo pipefail

# Probe history endpoints and append latency metrics to a local log.
#
# Env:
#   BASE_URL   Base URL (default: http://127.0.0.1/webcam)
#   SLUG       Camera slug (default: bielefeld)
#   LOG_FILE   Log file path (default: var/log/history_latency.log)
#   MAX_TIME   Curl max time in seconds (default: 20)
#   REF_TS     Override reference timestamp (default: now)

BASE_URL="${BASE_URL:-http://127.0.0.1/webcam}"
SLUG="${SLUG:-bielefeld}"
LOG_FILE="${LOG_FILE:-var/log/history_latency.log}"
MAX_TIME="${MAX_TIME:-20}"
REF_TS="${REF_TS:-$(date +%s)}"

mkdir -p "$(dirname "${LOG_FILE}")"

timestamp_iso="$(date -u +"%Y-%m-%dT%H:%M:%SZ")"
timestamp_epoch="$(date -u +%s)"

emit_line() {
  local mode="$1"
  local url="$2"
  local result rc time_total http_code
  set +e
  result="$(curl --max-time "${MAX_TIME}" -s -o /dev/null -w "%{time_total} %{http_code}" "${url}")"
  rc=$?
  set -e
  time_total="$(awk '{print $1}' <<<"${result}")"
  http_code="$(awk '{print $2}' <<<"${result}")"
  if [[ -z "${time_total}" ]]; then
    time_total="0"
  fi
  if [[ -z "${http_code}" ]]; then
    http_code="000"
  fi
  printf "%s\t%s\t%s\t%s\t%s\t%s\t%s\t%s\n" \
    "${timestamp_epoch}" \
    "${timestamp_iso}" \
    "${SLUG}" \
    "${mode}" \
    "${http_code}" \
    "${time_total}" \
    "${rc}" \
    "${url}" >> "${LOG_FILE}"
}

emit_line "days" "${BASE_URL}/api/public.php/cameras/${SLUG}/history?mode=days&days=30&ts=${REF_TS}"
emit_line "months" "${BASE_URL}/api/public.php/cameras/${SLUG}/history?mode=months&months=13&ts=${REF_TS}"
emit_line "years" "${BASE_URL}/api/public.php/cameras/${SLUG}/history?mode=years&years=30&ts=${REF_TS}"
