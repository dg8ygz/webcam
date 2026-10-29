#!/usr/bin/env bash
set -euo pipefail

# Simple burst benchmark helper.
# Uses `hey` if available; falls back to ApacheBench `ab` with same n/c.
#
# Usage:
#   TARGET="http://127.0.0.1:8080/camera/bielefeld" scripts/bench_burst.sh
# Optional env:
#   N=150   (requests)
#   C=5     (concurrency)
#   HOST=   (adds Host: header if set)

TARGET="${TARGET:-${1:-}}"
if [[ -z "${TARGET}" ]]; then
  echo "TARGET empty. Set TARGET env or pass as first arg." >&2
  exit 1
fi
N="${N:-150}"
C="${C:-5}"
HOST="${HOST:-}"
HEY_ARGS_RAW="${HEY_ARGS:-"-disable-keepalive"}"
IFS=' ' read -r -a HEY_ARGS <<<"$HEY_ARGS_RAW"
AB_EXTRA=(${AB_EXTRA:-})

if command -v hey >/dev/null 2>&1; then
  echo "Running hey (n=${N}, c=${C}) on ${TARGET}"
  set +e
  if [[ -n "${HOST}" ]]; then
    hey "${HEY_ARGS[@]}" -n "${N}" -c "${C}" -host "${HOST}" "${TARGET}"
  else
    hey "${HEY_ARGS[@]}" -n "${N}" -c "${C}" "${TARGET}"
  fi
  rc=$?
  set -e
  if [[ $rc -eq 0 ]]; then
    exit 0
  fi
  echo "hey failed with exit ${rc}, falling back to ab..." >&2
fi

if command -v ab >/dev/null 2>&1; then
  echo "hey not found, falling back to ab (n=${N}, c=${C}) on ${TARGET}"
  AB_ARGS=("-n" "${N}" "-c" "${C}")
  if [[ -n "${HOST}" ]]; then
    AB_ARGS+=("-H" "Host: ${HOST}")
  fi
  if [[ -n "${HOST}" ]]; then
    AB_ARGS+=("-H" "Host: ${HOST}")
  fi
  if [[ ${#AB_EXTRA[@]} -gt 0 ]]; then
    AB_ARGS+=("${AB_EXTRA[@]}")
  fi
  ab "${AB_ARGS[@]}" "${TARGET}"
  exit $?
fi

echo "Neither hey nor ab found. Install one of them (e.g. 'apt install apache2-utils') and retry." >&2
exit 1
