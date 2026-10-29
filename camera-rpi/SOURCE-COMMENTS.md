# Quellkommentar-Nachkontrolle

## Nachtrag 13.09.2026 / Raspi 1.0

Uncommittierte Änderungen seit der Legacy-Migration erneut blockweise geprüft:
Poolgenerationen/Lock-Vertrag, Queue-Scope/Promotion, getrennte ACKs/Hardlinks,
Kamera-Profilwahl, Journalbegrenzung/Einmalausgabe, Schlüsselkompatibilität und
gezielte Update-/Rollback-Phasen. Kommentare stehen direkt an diesen Blöcken.
README/Architektur behaupten nicht mehr drei Einzelaufnahmen bei aktivem Pool,
einen generell persistenten Spool oder eine noch nicht erfolgte Installation.
Reale Abnahme und simulierte Tests werden in `CAPTURE-POOL.md` getrennt benannt.
Die Baseline-Hashes historischer Updater bleiben bewusst unverändert; siehe
`ops/UPDATE-PAKETE.md`. Version 1.0 bezeichnet die Raspi-Variante, nicht den Server.

Nachtrag 12.09.2026: Die neue Gehaeuseregelung ist direkt in den PHP-
Klassen-/Funktionsvertraegen dokumentiert (Validator, Magnus, Puffer, Hysterese,
Anlaufimpuls, GPIO-Ausfallpfad, RAM-/RO-Grenzen, CAS und Capture-ACK). Der
gemeinsame Capturehelper dokumentiert den geerbten Lock; PHP trennt Formulardaten
und Darstellung. Bedienhilfe und Betriebsanleitung: `CLIMATE.md`. Kein Ersatz
der Quellkommentare durch ausschliessliche Markdown-Dokumentation. Der kleine
C-PWM-Helfer dokumentiert GPIO-Grenzen, Initialpegel, Pipe-Vertrag und Watchdog.
Die neue Erweiterung verwendet auf Betreiberwunsch kein Python.
Die spaeter hinzugefuegte `climate-history.php` dokumentiert TSV-Validierung,
RAM-/Groessen-/Zeitgrenzen, 30-s-/Ereignisaufzeichnung, Neustart/Uhrkorrektur
und SVG-Luecken/Stufen direkt an den Klassen und Funktionsbloecken. Der
Supervisor erklaert die Trennung von Verlaufsfehlern und Regelung/Capture-ACK.
Die Tagesarchivierung dokumentiert Berlin-Tagesgrenze, getrennten Dateinamenraum,
30-Snapshot-Retention, atomare SD-/RAM-Veroeffentlichung und Restore-Sperre direkt
im PHP-Helfer. Shell-Kommentare erklaeren das gemeinsame RO/RW-Fenster und dass
auch bei Fehlern beide Archive versucht werden. Kein Restore von Stellwerten.
Der DHT-Rueckstell-Timerhelfer erklaert Fristberechnung ab Originalaktivierung,
OnBootSec statt verschiebbarer Aktivierungsfrist, RAM-Drop-in, Vorher-Sicherung,
D-Bus-Nachpruefung und Rueckweg ohne Dienst-/Treiberneustart. Der Diagnose-
Runner dokumentiert die identische monotone Zeitbasis fuer kuenftige Pakete.

Stand: 12.09.2026. Parallel zur Webcam-Nachkontrolle seit dem Entzerren wurden
hier alle **19 Commits `10f825e..533c693`** geprüft, also die neue Pi-Software
seit der letzten August-Basis. Maßgeblich waren die geänderten Quellblöcke und
deren heutiger Vertrag, nicht allein die Betriebsdokumentation.

## Ergebnis

Die ursprünglichen Kommentare zu Konfigurationsprüfung, Secret-Dateien,
RO-Fenstern, Mehrserver-Sperren und Chunk-Transfer sind erhalten. Ergänzt wurden
die späteren Sensor-, Fokus-, Tunnel-, Legacy- und Webblöcke sowie die
Tagesarchivierung und die Rückwege mehrerer Updater.

Ein veralteter Parser-Kommentar behauptete noch, es seien nur zwei feste
Drei-Zeilen-Antworten erlaubt. Er beschreibt jetzt auch den historischen
Capture-Marker-Adapter. Dieser führt Antworttext niemals als Shell aus, ist aber
breiter als der moderne Fokusvertrag. Ein Curl-Timeout wird derzeit als Warten
behandelt; das ist ausdrücklich kein Beweis für eine gesunde Verbindung.

Die Script-Übersicht wurde ebenfalls berichtigt: Sie behauptet nicht mehr,
dass nichts installiert sei; sie berücksichtigt RAM-Spool und den gemeinsamen
Capture-Helper. Kommentare erklären Zweck, Ergebnis und Nebenwirkungen statt
jede Zuweisung zu wiederholen. Runbooks ersetzen sie nicht.

## Commit-Abdeckung

Ergänzungen stehen im aktuellen Quellstand, nicht in umgeschriebenen Commits.

| Commit | Bereich | Ergebnis |
| --- | --- | --- |
| `79d33e4` | Trixie, DHT, Statusseite | Sensor-/Status-/Installerblöcke ergänzt |
| `698a4c0` | USB-Capture, lokale Admin-/Zielsteuerung | JPEG-/Formular-/Queue-Verträge ergänzt |
| `3941429` | Webupdate-Abnahme | Keine neuen Funktionsblöcke |
| `8dfe558` | Multipart-Command-Key | Poll-Vertrag ergänzt |
| `29948cd` | Multipart-Upload-Key | Legacy-Transfer erklärt |
| `1f3a293` | Leere Uploadbestätigung | Abgrenzung zu Chunk-v1 ergänzt |
| `6cb76b9` | Long-Poll-Timeout | Warten ist kein Verbindungsnachweis |
| `efa7550` | Leere Command-Antwort | Gemeinsamer Poll-Vertrag ergänzt |
| `1d52f1e` | Whitespace-Antwort | Gemeinsamer Poll-Vertrag ergänzt |
| `3f1a1c0` | Legacy-Capture-Status | Parserbeschreibung berichtigt |
| `46e7bcf` | Eingebettete Legacy-Zeilen | Parserbeschreibung berichtigt |
| `8642bb0` | Legacy-Marker | Parserbeschreibung berichtigt |
| `5813e55` | GPIO-Suffix im IIO-Namen | Geräteauswahl dokumentiert |
| `b81acd6` | Lesefehler/Retry-Abstand | Gepaarte Messung/Fehlerzustand erklärt |
| `6a1af6f` | Low-write/Tagesbackup | Archivfunktionen, Spool- und Rückweggrenzen ergänzt |
| `768a6c9` | 700s-Poll/Proxy-Zuordnung | Proxy-Rewrite-Vertrag ergänzt |
| `f8b838d` | Lokaler/Remote-Fokus | Queue, Lock, Ausführung ohne Replay erklärt |
| `a9794f1` | HTTPS-Tunnel/DHT-Upload | Lease-Supervisor, Provisionierung, Snapshot erklärt |
| `533c693` | Tunnel-Abnahme/Ablauf | Keine neuen Funktionsblöcke |

Zusätzlich wurden die bislang uncommitteten DHT-Diagnosehelfer und die
optische 404-Landingpage mitgeprüft und kommentiert. Deren Implementierung wird
getrennt von der reinen Kommentar-Nachpflege versioniert. Der DHT-24h-Test ist
damit **nicht** als abgeschlossen erklärt und wird hier nicht verändert.

## Prüfung

- `bash tests/run-tests`: vollständige isolierte Pi-Suite erfolgreich, inklusive
  Sensor-Retries, RAM-Spool, Fokus, Capture-Backends, Mehrserverbetrieb,
  Secret-Übergabe, Legacy/Chunk und lokalem TLS-/Chisel-Test.
- DHT-Zusatztests: `python3 -B tests/test-dht-diagnostic.py` (5),
  `tests/test-dht-start.py` (5), `tests/test-dht-restore.py` (3) bestanden.
  Die Modul-/Dienstoperationen der Restore-Tests sind simuliert.
- Kommentaränderungen gegenüber dem Ausgangsstand: PHP-Tokenvergleich ohne
  Kommentare/Whitespace, Python-AST-Vergleich ohne Docstrings, Shell-Diffs nur
  Kommentar-/Leerzeilen und `bash -n`; `git diff --check` bestanden.
  Die separat versionierte Landingpage-Implementierung ist davon ausgenommen.
- Keine Installation auf dem Pi, kein Dienstneustart, keine Daten-/Keyänderung
  durch die Kommentar-Nachpflege. Alte Paket-/Ausgangsstand-Hashes bleiben bewusst
  unverändert; bestehende Pakete werden nicht unter gleichem Namen neu gebaut.

Bei neuen oder geänderten Funktionen direkt am Code mitpflegen: Zweck,
Aufrufvoraussetzungen, Ergebnis, Locks, Fehler/Retry, RO/RAM und Secret-Grenzen.
Die Webcam-Gegenprüfung steht unter `webcam/docs/SOURCE-COMMENTS.md`.
