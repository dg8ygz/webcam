#!/usr/bin/env bash
set -euo pipefail

# Daily fail-closed maintenance for one camera. Manual runs create a targeted
# snapshot first. The scheduler may skip that duplicate snapshot only after it
# has verified the successful full daily backup itself.

project_root="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
config_db="${WEBCAM_CONFIG_DB:-/var/lib/webcam/webcams.sqlite}"
camera_slug="${WEBCAM_WEATHER_QUALITY_SLUG:-stromberg}"
backup_timeout="${WEBCAM_WEATHER_QUALITY_BACKUP_TIMEOUT_SECONDS:-300}"
check_timeout="${WEBCAM_WEATHER_QUALITY_CHECK_TIMEOUT_SECONDS:-120}"
skip_target_backup="${WEBCAM_WEATHER_QUALITY_SKIP_TARGET_BACKUP:-0}"
lock_dir="$project_root/var/state/weather-quality"
lock_file="$lock_dir/previous-day-$camera_slug.lock"

mkdir -p "$lock_dir"
exec 9>"$lock_file"
if ! flock -n 9; then
  printf '[SKIP] weather quality previous-day run already active for %s\n' "$camera_slug"
  exit 0
fi

printf '[START] weather quality previous-day run for %s at %s\n' "$camera_slug" "$(date --iso-8601=seconds)"

if [[ "$skip_target_backup" == "1" ]]; then
  printf '[INFO] using the successful full daily database backup verified by the scheduler\n'
else
  if ! WEBCAM_CONFIG_DB="$config_db" timeout "${backup_timeout}s" \
    /usr/bin/php "$project_root/bin/backup_daily.php" \
    --slug="$camera_slug" --force --verbose; then
    printf '[ERROR] camera DB backup failed; weather markers were not changed\n' >&2
    exit 2
  fi
fi

set +e
WEBCAM_CONFIG_DB="$config_db" timeout "${check_timeout}s" \
  /usr/bin/php "$project_root/bin/check_weather_quality.php" \
  --slug="$camera_slug" --previous-day --min-repeat=24 --write --verbose
check_rc=$?
set -e

case "$check_rc" in
  0)
    printf '[OK] previous-day weather data contained no quality findings\n'
    ;;
  1)
    printf '[OK] previous-day weather quality findings were synchronized\n'
    ;;
  124)
    printf '[ERROR] weather quality check timed out after %ss\n' "$check_timeout" >&2
    exit 2
    ;;
  *)
    printf '[ERROR] weather quality check failed with exit %s\n' "$check_rc" >&2
    exit 2
    ;;
esac

printf '[DONE] weather quality previous-day run for %s at %s\n' "$camera_slug" "$(date --iso-8601=seconds)"
