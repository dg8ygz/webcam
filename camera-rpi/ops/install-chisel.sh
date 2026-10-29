#!/usr/bin/env bash
# First install only: verify a pinned architecture-specific binary, start no service.
set -euo pipefail
umask 077
[[ $EUID == 0 && $# == 1 && "$1" == --apply ]] || { echo 'Aufruf: install-chisel.sh --apply'; exit 1; }
SRC="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")/.." && pwd -P)"
(cd "$SRC" && sha256sum -c SHA256SUMS)
[[ ! -e /usr/local/bin/chisel ]] || { echo 'Chisel existiert bereits; kein ungeprueftes Ueberschreiben.' >&2; exit 1; }
case "$(uname -m)" in x86_64) arch=amd64 ;; aarch64) arch=arm64 ;; *) exit 1 ;; esac
STAGE="$(mktemp -d /root/camera-chisel-install.XXXXXXXX)"
bash "$SRC/ops/fetch-chisel.sh" "$arch" "$STAGE"
[[ "$("$STAGE/chisel" --version)" == 1.11.5 ]]
install -o root -g root -m 0755 "$STAGE/chisel" /usr/local/bin/chisel
echo "OK: Chisel 1.11.5 installiert, kein Dienst gestartet. Download: $STAGE"
