# Monatlicher Kamera-Uhrabgleich (opt-in)

Canon-Kamerazeit wird am 1. jedes Monats um 12:05 Europe/Berlin aus der
NTP-synchronisierten Pi-Uhr gesetzt. Kein Aufnahme-, Fokus- oder GPIO-Befehl.
Das Feature wird separat installiert; bestehende Installer aktivieren es
nicht automatisch auf ungeprueften Kameraquellen.

Dateien:

- scripts/camera-rpi-camera-clock.py nach /usr/local/libexec/ (0644/root).
- systemd/camera-rpi-camera-clock.service und .timer nach /etc/systemd/system/.

Nach `systemctl daemon-reload` zuerst den Service starten und Journal pruefen,
dann `systemctl enable --now camera-rpi-camera-clock.timer`.
Voraussetzungen: Python3, gphoto2, timedatectl, schreibbares Canon-DATE-Feld
datetime, richtige Pi-Zeitzone und aktives NTP. Persistent=true setzt einen
schreibbaren /var/lib/systemd/timers voraus: dieses Paket nicht unveraendert
auf einem dauerhaft RO-System aktivieren. Dort zusaetzlich
systemd/low-write/camera-clock.conf als
/etc/systemd/system/camera-rpi-camera-clock.timer.d/50-read-only.conf
installieren (Persistent=false). Der monatliche Lauf schreibt dann keinen
Timerstatus auf die SD; offline verpasste Termine werden dort nicht nachgeholt.
Auf RW-Systemen werden Kalendertermine waehrend einer
Offline-Zeit nachgeholt. Rebootabnahme steht noch aus.

Der Prozess wartet bis zu180s auf die gemeinsame camera-rpi.operation.lock.
Bei fehlendem NTP oder ungeeignetem Zeitfeld erfolgt kein Schreibzugriff.
Die lokale Kalenderzeit wird unter TZ=UTC als Zahl geschrieben, um den
Sommerzeitfehler in libgphoto2 2.5.31 zu umgehen. Danach unter normaler
Pi-Zeitzone zuruecklesen; mehr als5s Abweichung gilt als Fehler. USB-Zugriffe
sind auf20s begrenzt. Die Unit hat5min Gesamtlimit, bis zu vier Starts pro
Tag mit15min Abstand bei Fehlern. Danach bleibt der Fehler im Journal und
der naechste monatliche Termin bestehen. Keine dauerhafte Retry-Schleife.
Monatlicher Abgleich bedeutet: eine Sommer-/Winterzeitumstellung zwischen
Terminen wird erst beim folgenden Abgleich uebernommen.

Nur lesend pruefen:

```sh
sudo python3 /usr/local/libexec/camera-rpi-camera-clock.py --check
systemctl list-timers camera-rpi-camera-clock.timer
journalctl -u camera-rpi-camera-clock.service --no-pager -n 20
```

Tests: `python3 tests/camera-clock.py`; sechs Tests fuer Sommer/Winter,
fehlendes NTP, Readonly-Feld, lesende Pruefung, UTC-Schreiben und fehlerhafte
Ruecklesung. Dienst-/Timer-Syntax auf DK0RE mit systemd-analyze verify geprueft.

## DK0RE 09.10.2026

Nur drei neue Dateien installiert, keine bestehenden Worker ersetzt.
Rueckwegdateiliste /root/camera-clock-before-9ttzhmv0/new-files.json.
Echter Service-Lauf10:59:41–10:59:45 CEST erfolgreich: Rueckleseabweichung
-3,4s inklusive USB-Lesezeit, Exit0. Vorher NTP-Reparatur dokumentiert unter
../webcam/ops/camera-ntp-2026-10-09.md. Erster geplanter Termin01.11.12:05.

Rueckweg: `systemctl disable --now camera-rpi-camera-clock.timer`, dann
`systemctl stop camera-rpi-camera-clock.service`; drei neue Dateien entfernen,
`systemctl daemon-reload`. Keine Kamera-Zeitrueckstellung erforderlich.

## Bielefeld und Steinhagen 09.10.2026

Auf ausdruecklichen Folgeauftrag ebenfalls installiert, je drei Hauptdateien
und RO-Timer-Drop-in. Installation unter Operationslock, Root nur dafuer RW,
danachRO. Echte Service-Laeufe auf RO bestanden: Bielefeld11:06:33–11:06:42,
Ruecklesung-4,2s; Steinhagen11:06:36–11:06:42,-3,1s (einschliesslich USB-
Lesezeit). Beide Result=success/Exit0. Alle sechs Worker weiterhin active.
NTP synchronisiert, beide Timer active/enabled, Persistent=no, naechster
Termin01.11.2026 12:05 CET. Laufzeitcode identisch zum geprueften DK0RE-Stand.

Sicherungs-/Rueckwegdateilisten auf .16:
/root/camera-clock-before-7aisluno/new-files.json; auf .17:
/root/camera-clock-before-sgtxctqi/new-files.json. Rueckweg wie oben, zusaetzlich
RO-Drop-in entfernen; fuer Dateien/disable kurz RootRW und danach wiederRO.
Keine automatische RW-Umschaltung fuer monatliche Laeufe notwendig.
