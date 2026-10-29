#!/usr/bin/env bash
set -euo pipefail

script_dir="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
repo_root="$(cd "$script_dir/.." && pwd)"

persistent_root="${PLAYWRIGHT_PERSISTENT_ROOT:-/opt/webcam-playwright}"
default_source="$repo_root/.npmhome/ms-playwright"
if [[ -d "$persistent_root/browsers" ]]; then
  default_source="$persistent_root/browsers"
fi
source_dir="${PLAYWRIGHT_BROWSERS_SOURCE:-$default_source}"
target_dir="${PLAYWRIGHT_BROWSERS_PATH:-/tmp/pw-browsers}"

if [[ ! -d "$source_dir" ]]; then
  echo "Source Playwright browser directory not found: $source_dir" >&2
  exit 1
fi

mkdir -p "$target_dir"

if command -v rsync >/dev/null 2>&1; then
  # CIFS kann Dateien als nobody:nogroup abbilden. Eigentum und Modus deshalb
  # nicht in das lokale Temp-Ziel uebernehmen; die benoetigten Starter erhalten
  # ihre Ausfuehrungsrechte gezielt weiter unten.
  rsync -a --no-owner --no-group --no-perms "$source_dir"/ "$target_dir"/
else
  cp -R "$source_dir"/. "$target_dir"/
fi

# Browser downloads on CIFS/SMB mounts can lose execute bits. Only mark known
# Playwright launchers executable, not the whole browser tree.
find "$target_dir" -type f \( \
  -name chrome \
  -o -name chrome-headless-shell \
  -o -name chromium \
  -o -name firefox \
  -o -name ffmpeg \
  -o -name ffmpeg-linux \
  -o -name MiniBrowser \
\) -exec chmod 0755 {} +

echo "Prepared Playwright browsers in $target_dir"
echo "Use: PLAYWRIGHT_BROWSERS_PATH=$target_dir HOME=/tmp/pw-home node node_modules/playwright/cli.js test ..."
