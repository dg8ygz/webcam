#!/usr/bin/env bash
# Download a pinned upstream binary into a NEW staging directory, never curl|sh.
set -euo pipefail
[[ $# == 2 && -d "$2" && ! -e "$2/chisel" ]] || { echo 'Aufruf: fetch-chisel.sh amd64|arm64 STAGING' >&2; exit 1; }
case "$1" in
  amd64) expected=2506226c4710a3c8d3b332f83d0f3b412fe2a8436b710ea0dbec44b288ab309b ;;
  arm64) expected=1a4f16aad733f022b9aa90903268d730bfb20b03f9484f7ab4863e442737fa9a ;;
  *) echo 'Nur amd64/arm64 freigegeben' >&2; exit 1 ;;
esac
curl --fail --silent --show-error --location --proto '=https' --proto-redir '=https' \
  --connect-timeout 10 --max-time 120 --max-filesize 20000000 \
  "https://github.com/jpillora/chisel/releases/download/v1.11.5/chisel_1.11.5_linux_$1.gz" -o "$2/chisel.gz"
printf '%s  %s\n' "$expected" "$2/chisel.gz" | sha256sum -c -
gzip -dc "$2/chisel.gz" > "$2/chisel"
chmod 0755 "$2/chisel"
