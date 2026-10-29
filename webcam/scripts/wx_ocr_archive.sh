#!/usr/bin/env bash
set -u
set -o pipefail

BASE="${BASE:-/var/www/html/webcam/storage/archive}"
LOG="${LOG:-/var/www/html/webcam/var/log/wx_ocr_archive.log}"
PARALLEL="${PARALLEL:-4}"
PASS1="${PASS1:-1}"
PASS2="${PASS2:-1}"
OVERWRITE="${OVERWRITE:-0}"
VARIANT="${VARIANT:-la}"
START_DATE="${START_DATE:-}"
MAX_OCR_FAILURES="${MAX_OCR_FAILURES:-${WX_OCR_MAX_FAILURES:-2}}"
OCR_TIMEOUT_SECONDS="${OCR_TIMEOUT_SECONDS:-${WX_OCR_TIMEOUT_SECONDS:-20}}"
CONVERT_TIMEOUT_SECONDS="${CONVERT_TIMEOUT_SECONDS:-${WX_OCR_CONVERT_TIMEOUT_SECONDS:-20}}"
IMAGE_TIMEOUT_SECONDS="${IMAGE_TIMEOUT_SECONDS:-${WX_OCR_IMAGE_TIMEOUT_SECONDS:-45}}"
OCR_THREAD_LIMIT="${OCR_THREAD_LIMIT:-${WX_OCR_THREAD_LIMIT:-1}}"
export OMP_THREAD_LIMIT="${OMP_THREAD_LIMIT:-${OCR_THREAD_LIMIT}}"
export OMP_NUM_THREADS="${OMP_NUM_THREADS:-${OCR_THREAD_LIMIT}}"

stamp() {
  date -u +"%Y-%m-%dT%H:%M:%SZ"
}

if [[ ! -d "${BASE}" ]]; then
  echo "Base directory not found: ${BASE}" >&2
  exit 1
fi

if ! command -v php >/dev/null 2>&1; then
  echo "Missing required binary: php" >&2
  exit 1
fi

if ! command -v convert >/dev/null 2>&1; then
  echo "Missing required binary: convert" >&2
  exit 1
fi

if ! command -v tesseract >/dev/null 2>&1; then
  echo "Missing required binary: tesseract" >&2
  exit 1
fi

mkdir -p "$(dirname "${LOG}")"

start_path=""
start_year=""
start_month=""
start_day=""
if [[ -n "${START_DATE}" ]]; then
  if [[ ! "${START_DATE}" =~ ^[0-9]{4}-[0-9]{2}-[0-9]{2}$ ]]; then
    echo "Invalid START_DATE format (expected YYYY-MM-DD): ${START_DATE}" >&2
    exit 1
  fi
  start_path="${START_DATE//-//}"
  start_year="${START_DATE:0:4}"
  start_month="${START_DATE:5:2}"
  start_day="${START_DATE:8:2}"
fi

echo "[$(stamp)] START base=${BASE} parallel=${PARALLEL} variant=${VARIANT} pass1=${PASS1} pass2=${PASS2} overwrite=${OVERWRITE} max_ocr_failures=${MAX_OCR_FAILURES} ocr_timeout=${OCR_TIMEOUT_SECONDS} convert_timeout=${CONVERT_TIMEOUT_SECONDS} image_timeout=${IMAGE_TIMEOUT_SECONDS} ocr_thread_limit=${OCR_THREAD_LIMIT} start_date=${START_DATE:-none}" >> "${LOG}"

tmp_dirs="$(mktemp)"
echo "[$(stamp)] DIRS scan start" >> "${LOG}"
shopt -s nullglob
for slug_dir in "${BASE}"/*; do
  [[ -d "${slug_dir}" ]] || continue
  slug="$(basename "${slug_dir}")"
  for year_dir in "${slug_dir}"/*; do
    [[ -d "${year_dir}" ]] || continue
    year="$(basename "${year_dir}")"
    if [[ ! "${year}" =~ ^[0-9]{4}$ ]]; then
      continue
    fi
    if [[ -n "${START_DATE}" ]]; then
      if ((10#${year} < 10#${start_year})); then
        continue
      fi
    fi
    echo "[$(stamp)] SCAN slug=${slug} year=${year}" >> "${LOG}"
    for month_dir in "${year_dir}"/*; do
      [[ -d "${month_dir}" ]] || continue
      month="$(basename "${month_dir}")"
      if [[ ! "${month}" =~ ^[0-9]{2}$ ]]; then
        continue
      fi
      if [[ -n "${START_DATE}" ]]; then
        if ((10#${year} == 10#${start_year} && 10#${month} < 10#${start_month})); then
          continue
        fi
      fi
      for day_dir in "${month_dir}"/*; do
        [[ -d "${day_dir}" ]] || continue
        day="$(basename "${day_dir}")"
        if [[ ! "${day}" =~ ^[0-9]{2}$ ]]; then
          continue
        fi
        if [[ -n "${START_DATE}" ]]; then
          if ((10#${year} == 10#${start_year} && 10#${month} == 10#${start_month} && 10#${day} < 10#${start_day})); then
            continue
          fi
        fi
        echo "${day_dir}"
      done
    done
  done
done > "${tmp_dirs}"
shopt -u nullglob
dir_count="$(wc -l < "${tmp_dirs}" | tr -d ' ')"
echo "[$(stamp)] DIRS count=${dir_count}" >> "${LOG}"

overwrite_flag=""
if [[ "${OVERWRITE}" == "1" ]]; then
  overwrite_flag="--overwrite"
fi

run_pass() {
  local name="$1"
  shift
  export PASS_ARGS="$* ${overwrite_flag}"
  export MAX_OCR_FAILURES
  export OCR_TIMEOUT_SECONDS
  export CONVERT_TIMEOUT_SECONDS
  export IMAGE_TIMEOUT_SECONDS
  export OMP_THREAD_LIMIT
  export OMP_NUM_THREADS
  echo "[$(stamp)] PASS ${name} start args=${PASS_ARGS}" >> "${LOG}"
  xargs -P "${PARALLEL}" -I{} bash -lc 'export OMP_THREAD_LIMIT OMP_NUM_THREADS; dir="$1"; php /var/www/html/webcam/bin/extract_wx_ocr.php --dir="$dir" --max-ocr-failures="${MAX_OCR_FAILURES}" --ocr-timeout="${OCR_TIMEOUT_SECONDS}" --convert-timeout="${CONVERT_TIMEOUT_SECONDS}" --image-timeout="${IMAGE_TIMEOUT_SECONDS}" $PASS_ARGS' _ {} \
    < "${tmp_dirs}" >> "${LOG}" 2>&1
  echo "[$(stamp)] PASS ${name} done" >> "${LOG}"
}

if [[ "${PASS1}" == "1" ]]; then
  run_pass "fast" "--variant=${VARIANT}" "--no-precision"
fi

if [[ "${PASS2}" == "1" ]]; then
  run_pass "rescue" "--variant=${VARIANT}" "--precision" "--rescue"
fi

echo "[$(stamp)] DONE base=${BASE}" >> "${LOG}"
rm -f "${tmp_dirs}"
