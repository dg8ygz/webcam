#!/usr/bin/env bash
set -euo pipefail

# Stellt Browser, Node-Pakete und die kleine E2E-Arbeitskopie lokal unter /tmp
# bereit. Bevorzugt die persistente /opt-Kopie und faellt auf das Repo zurueck.

script_dir="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
repo_root="$(cd "$script_dir/.." && pwd)"
persistent_root="${PLAYWRIGHT_PERSISTENT_ROOT:-/opt/webcam-playwright}"
browser_target="${PLAYWRIGHT_BROWSERS_PATH:-/tmp/pw-browsers}"
node_target="${PLAYWRIGHT_NODE_MODULES_PATH:-/tmp/pw-node-modules}"
workspace_target="${PLAYWRIGHT_TEST_WORKSPACE:-/tmp/pw-workspace}"

browser_source="$repo_root/.npmhome/ms-playwright"
node_source="$repo_root/node_modules"
if [[ -d "$persistent_root/browsers" && -d "$persistent_root/node_modules/playwright" ]]; then
  browser_source="$persistent_root/browsers"
  node_source="$persistent_root/node_modules"
fi

PLAYWRIGHT_BROWSERS_SOURCE="$browser_source" \
PLAYWRIGHT_BROWSERS_PATH="$browser_target" \
  bash "$script_dir/prepare_playwright_browsers.sh"

copy_tree() {
  local source="$1"
  local target="$2"
  mkdir -p "$target"
  if command -v rsync >/dev/null 2>&1; then
    rsync -a --no-owner --no-group --no-perms "$source"/ "$target"/
  else
    cp -R "$source"/. "$target"/
  fi
}

copy_tree "$node_source/playwright" "$node_target/playwright"
copy_tree "$node_source/playwright-core" "$node_target/playwright-core"
copy_tree "$node_source/@playwright/test" "$node_target/@playwright/test"
copy_tree "$repo_root/tests/e2e" "$workspace_target/tests/e2e"
cp "$repo_root/playwright.config.js" "$workspace_target/playwright.config.js"

printf 'Local Playwright runtime prepared. Example:\n'
printf 'cd %q && NODE_PATH=%q PLAYWRIGHT_BROWSERS_PATH=%q HOME=/tmp/pw-home node %q/playwright/cli.js test\n' \
  "$workspace_target" "$node_target" "$browser_target" "$node_target"
