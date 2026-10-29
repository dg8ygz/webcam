#!/usr/bin/env bash
set -euo pipefail

# Browser fixtures in a private copy, then PHPUnit's own fixtures.
# No live-server flags, inherited camera selection or sample DB.
if (( $# )); then
  echo 'test:all akzeptiert keine Live-URL-/Kamera-Optionen mehr. Siehe tests/isolated/README.md.' >&2
  exit 2
fi
cd -- "$(dirname -- "${BASH_SOURCE[0]}")/.."
node bin/test_e2e_isolated.cjs
php vendor/bin/phpunit --display-deprecations --display-warnings
