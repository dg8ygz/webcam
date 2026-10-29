#!/usr/bin/env bash
set -euo pipefail

BASE="${BASE:-http://127.0.0.1/webcam}"

fail() { echo "FAIL: $1" >&2; exit 1; }

# 1) Index reachable
curl -fsS "$BASE/" >/dev/null || fail "index"

# 2) Public cameras list
cams_json=$(curl -fsS "$BASE/api/public/cameras") || fail "list"
slug=$(php -r '
$d=json_decode(file_get_contents("php://stdin"), true);
if (!isset($d["cameras"][0]["slug"])) { exit(2); }
echo $d["cameras"][0]["slug"];
' <<<"$cams_json") || fail "parse slug"
[ -n "$slug" ] || fail "empty slug"

# 3) Camera detail
curl -fsS "$BASE/api/public/cameras/$slug" >/dev/null || fail "camera detail"

# 4) Like endpoint without body should 400
code=$(curl -s -o /dev/null -w '%{http_code}' -X POST "$BASE/like.php")
[ "$code" = "400" ] || fail "like.php expected 400 got $code"

# 5) Status page
curl -fsS "$BASE/status/" >/dev/null || fail "status page"

echo "smokes ok ($BASE, slug=$slug)"
