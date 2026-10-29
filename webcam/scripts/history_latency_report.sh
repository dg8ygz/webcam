#!/usr/bin/env bash
set -euo pipefail

# Summarize history latency logs (p50/p95/avg/max) per mode.
#
# Env:
#   LOG_FILE    Log file path (default: var/log/history_latency.log)
#   SINCE_EPOCH Only include entries with epoch >= SINCE_EPOCH (optional)
#   SLUG        Filter by camera slug (optional)

LOG_FILE="${LOG_FILE:-var/log/history_latency.log}"
SINCE_EPOCH="${SINCE_EPOCH:-}"
SLUG_FILTER="${SLUG:-}"

if [[ ! -f "${LOG_FILE}" ]]; then
  echo "Log file not found: ${LOG_FILE}" >&2
  exit 1
fi

awk -F'\t' -v since="${SINCE_EPOCH}" -v slug="${SLUG_FILTER}" '
  function emit(mode, count, sum, max, p50, p95) {
    if (count == 0) {
      printf "%s: n=0\n", mode;
      return;
    }
    printf "%s: n=%d avg=%.3fs p50=%.3fs p95=%.3fs max=%.3fs\n",
      mode, count, sum / count, p50, p95, max;
  }
  {
    epoch=$1; iso=$2; slugVal=$3; mode=$4; code=$5; t=$6;
    if (since != "" && epoch < since) next;
    if (slug != "" && slugVal != slug) next;
    if (t == "" || t ~ /[^0-9.]/) next;
    key=mode;
    times[key, ++count[key]] = t + 0;
    sum[key] += t + 0;
    if ((t + 0) > max[key]) max[key] = t + 0;
  }
  END {
    for (m in count) {
      n = count[m];
      # sort times
      for (i = 1; i <= n; i++) {
        arr[i] = times[m, i];
      }
      # simple insertion sort (n is small per mode)
      for (i = 2; i <= n; i++) {
        v = arr[i];
        j = i - 1;
        while (j >= 1 && arr[j] > v) {
          arr[j + 1] = arr[j];
          j--;
        }
        arr[j + 1] = v;
      }
      idx50 = int(n * 0.50); if (idx50 < 1) idx50 = 1;
      idx95 = int(n * 0.95); if (idx95 < 1) idx95 = 1;
      p50 = arr[idx50];
      p95 = arr[idx95];
      emit(m, n, sum[m], max[m], p50, p95);
      delete arr;
    }
  }
' "${LOG_FILE}" | sort
