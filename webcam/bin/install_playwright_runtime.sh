#!/usr/bin/env bash
set -euo pipefail

# Erstellt eine persistente lokale Playwright-Kopie ausserhalb des CIFS-Repos.
# Der Standardpfad /opt/webcam-playwright benoetigt einmalig root-Rechte.

script_dir="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
repo_root="$(cd "$script_dir/.." && pwd)"
target_root="${PLAYWRIGHT_PERSISTENT_ROOT:-/opt/webcam-playwright}"

case "$target_root" in
  /opt/*|/src/*)
    ;;
  *)
    printf 'Refusing unexpected persistent target: %s\n' "$target_root" >&2
    printf 'Use a dedicated path below /opt or /src.\n' >&2
    exit 2
    ;;
esac

if [[ ! -d "$repo_root/.npmhome/ms-playwright" ]]; then
  printf 'Playwright browser source missing: %s\n' "$repo_root/.npmhome/ms-playwright" >&2
  exit 1
fi

for package_path in \
  "$repo_root/node_modules/playwright" \
  "$repo_root/node_modules/playwright-core" \
  "$repo_root/node_modules/@playwright/test"; do
  if [[ ! -d "$package_path" ]]; then
    printf 'Playwright Node package missing: %s\n' "$package_path" >&2
    exit 1
  fi
done

mkdir -p "$target_root/browsers" "$target_root/node_modules/@playwright"

copy_tree() {
  local source="$1"
  local target="$2"
  mkdir -p "$target"
  if command -v rsync >/dev/null 2>&1; then
    local rsync_args=(-a --no-owner --no-group --no-perms)
    if [[ "${PLAYWRIGHT_INSTALL_PROGRESS:-1}" != "0" ]]; then
      rsync_args+=(--human-readable --info=progress2)
    fi
    rsync "${rsync_args[@]}" "$source"/ "$target"/
  else
    cp -R "$source"/. "$target"/
  fi
}

printf '[1/5] Kopiere Browser nach %s/browsers ...\n' "$target_root"
copy_tree "$repo_root/.npmhome/ms-playwright" "$target_root/browsers"
printf '[2/5] Kopiere Node-Paket playwright ...\n'
copy_tree "$repo_root/node_modules/playwright" "$target_root/node_modules/playwright"
printf '[3/5] Kopiere Node-Paket playwright-core ...\n'
copy_tree "$repo_root/node_modules/playwright-core" "$target_root/node_modules/playwright-core"
printf '[4/5] Kopiere Node-Paket @playwright/test ...\n'
copy_tree "$repo_root/node_modules/@playwright/test" "$target_root/node_modules/@playwright/test"

printf '[5/5] Setze Exec-Bits der bekannten Browser-Starter ...\n'
find "$target_root/browsers" -type f \( \
  -name chrome \
  -o -name chrome-headless-shell \
  -o -name chromium \
  -o -name firefox \
  -o -name ffmpeg \
  -o -name ffmpeg-linux \
  -o -name MiniBrowser \
\) -exec chmod 0755 {} +

du -sh "$target_root"
printf 'Playwright runtime stored in %s\n' "$target_root"
printf 'After a reboot run: bash %s/bin/prepare_playwright_runtime.sh\n' "$repo_root"
