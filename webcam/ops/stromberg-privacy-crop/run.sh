#!/bin/bash
# Auf DEV .34 als root in einer eigenen Screen-Sitzung starten.
set -euo pipefail
umask 077
if [ "$#" -gt 1 ] || { [ "$#" -eq 1 ] && [ "$1" != --resume ] && [ "$1" != --finish ]; }; then
  echo 'Aufruf: run.sh [--resume|--finish]' >&2
  exit 2
fi
if [ "$(id -u)" != 0 ]; then
  echo 'Als root auf DEV .34 starten.' >&2
  exit 1
fi
script=/var/www/html/webcam/ops/stromberg-privacy-crop/crop.py
state=/var/local/webcam/stromberg-privacy-20261018
install -d -m 0700 "$state"
exec 9>"$state/launcher.lock"
flock -n 9 || { echo 'Dieser Archivlauf ist bereits aktiv.' >&2; exit 1; }
{
  echo "START $(date --iso-8601=seconds) — Stromberg ab 30.03.2026 22:20 inklusive"
  if [ "${1:-}" = --finish ]; then
    echo 'Nur Phase 3: bestehendes Journal verwenden; keine Inventur und kein Beschnitt.'
  elif [ "${1:-}" = --resume ]; then
    echo 'Phase 1/3: Vorhandene abgeschlossene Inventur nutzen; apply prueft scan_complete.'
  else
    echo 'Phase 1/3: Inventur (bei Wiederholung ab Verzeichnis-Checkpoint)'
    nice -n 15 ionice -c 3 python3 -u "$script" --state "$state" --audit
  fi
  if [ "${1:-}" != --finish ]; then
    echo 'Phase 2/3: Sicherung, Beschnitt, Datenbank-Metadaten'
    nice -n 15 ionice -c 3 python3 -u "$script" --state "$state" --apply
  fi
  echo 'Phase 3/3: Ruecklesen und Datenbankabgleich'
  # Retention may have removed a completed cache day during the long apply.
  # Reconcile only after the archive pass; verify itself remains read-only.
  nice -n 15 ionice -c 3 python3 -u "$script" --state "$state" --reconcile-missing-cache
  nice -n 15 ionice -c 3 python3 -u "$script" --state "$state" --verify
  echo "ENDE $(date --iso-8601=seconds) — inventarisierter Bestand geprueft"
} 2>&1 | tee -a "$state/run.log"
