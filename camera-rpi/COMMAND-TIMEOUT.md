# Legacy-Command-Poll mit 700 Sekunden

Vorbereitet am 11.09.2026 fuer Pi-Ziel `test` und Proxy 192.0.2.2.
Kein globales Anheben der Apache- oder Upload-Zeitlimits.

- Pi: `command_timeout_seconds=700` nur in `servers/test.conf`.
- Config-Parser akzeptiert 2..900 Sekunden; Standard bleibt 30 Sekunden.
- Chunk-Capability-Abfrage verwendet maximal 30 Sekunden, unabhaengig vom
  langen Command-Poll. Upload- und Connect-Timeout bleiben unveraendert.
- `.2`: eigener Backend-Worker fuer
  `http://192.0.2.14/webcam/include/command.php`, timeout=710 und
  connectiontimeout=10. Allgemeine `/webcam/`-Zuordnung bleibt unveraendert.
  Laengerer Worker wird zuerst definiert; spezifische Location folgt der
  allgemeinen Location. Das verhindert ungewolltes Worker-Sharing.
  Grundlage: https://httpd.apache.org/docs/2.4/mod/mod_proxy.html#workers
- `dev-test` behaelt seine bisherige Konfiguration.

Der alte Server darf frueher antworten; 700 Sekunden sind nur eine Obergrenze.
Ein Auftrag vom Server beendet den Poll sofort. Lokale manuelle Capture-
Anforderungen werden dagegen erst nach dem laufenden Poll verarbeitet und
koennen bis zu 700 Sekunden warten. Ein Netzfehler wird gegebenenfalls spaeter
bemerkt; Connect-Fehler haben weiterhin den kurzen separaten Timeout.
Backend/PHP-Grenzen auf `.14` wurden mangels Zugriff nicht geaendert.

## Installation

Zuerst auf `.2` als root das vorbereitete Paket entpacken und
`bash ops/update-proxy-command-timeout.sh` ausfuehren. Das Script prueft den
bekannten Config-Hash, sichert die Datei, testet die Konfiguration und fuehrt
nur einen Apache-Reload aus. Bei Fehlern wird die vorige Datei restauriert.

Danach auf dem Pi als root:

```sh
bash /home/admin/camera-rpi-command-timeout.o2ddi2Xo/ops/update-command-timeout.sh
```

Pi-Installer prueft beide bekannten Ausgangs-Hashes, sichert Bibliothek,
Worker und `test.conf`, stoppt nur `test`, installiert nach
`/usr/local/libexec`, validiert Config/Key ohne Poll und startet die zuvor
aktive Instanz wieder. Keys, DEV-Config, RAM-Spool und Sensoren bleiben erhalten.
Ein Reboot ist nicht erforderlich. Bereits vorhandene Low-write-Aenderungen
im Worker werden beibehalten, nicht durch den alten Worker ersetzt.

## Tests und Abnahme

`bash tests/command-timeouts`: echte Parsergrenzen, Command-Curl mit700,
Capability-Curl mit30 (bzw. kleinerem konfigurierten Wert).
`python3 -B tests/test-command-proxy.py`: isolierter echter Apache mit lokalem
verzoegerten HTTP-Backend. Command darf laenger warten, Upload und andere
Pfade laufen weiterhin ins kurze allgemeine Limit. Keine Produktionsrequests.

Offen bis Root-Installation: effektive Proxy-Konfiguration auf `.2`, Pi-
Config nach Update und mindestens ein neuer erfolgreicher Capture-/Upload-
Zyklus. Ausbleibende Timeout-Logs allein beweisen keinen funktionierenden Poll.
