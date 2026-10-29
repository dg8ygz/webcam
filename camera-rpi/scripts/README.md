# Was die Scripte tun

Dies ist die Quelltextbeschreibung. Installierter Stand und Abnahmen stehen in
`DEVELOPMENT.md` und den jeweiligen Betriebsanleitungen; ein Commit allein rollt
keine Dateien auf den Pi aus.

## Kommentare im Quelltext

Funktionsbloecke erklaeren direkt im Code ihren Zweck, Eingaben/Ergebnis und
wichtige Nebenwirkungen: Sperren, Retry-/Ablaufregeln, Secret-Behandlung und
RO-/RAM-Grenzen. Triviale Zuweisungen brauchen keinen Wiederholungskommentar.
Bei Vertragsaenderungen vorhandene Kommentare mitpflegen; Markdown-Anleitungen
ersetzen diese Erklaerungen nicht. Nachkontrolle: `SOURCE-COMMENTS.md` im Projektroot.

## `camera-rpi-worker`

Dies ist der eigentliche Kamera-Bote. Beim Start liest er genau eine
Serverkonfiguration, prüft deren Rechte und Key-Datei und nimmt eine Sperre
gegen eine doppelte Instanz desselben Servers. Danach wiederholt er:

1. Heartbeat aktualisieren und eventuell liegengebliebenes Bild zuerst senden.
2. Eigenen Server mit Zeitlimit nach einem Auftrag fragen.
3. Moderne Antwort und Zeitstempel prüfen; historischer Legacy-Adapter erkennt
   auch eingebettete Capture-Marker. Niemals Antworttext als Shell ausführen.
4. Auftrag im eigenen Spool vormerken (im Low-write-Profil ebenfalls RAM).
5. Globale Kamera-Sperre abwarten.
6. Root nur bei Bedarf rw setzen, GPhoto starten und JPEG prüfen.
7. Je nach Serverkonfiguration das ganze Bild per Legacy senden oder Chunk-v1
   authentifiziert aushandeln und in hashgeprüften Teilen übertragen. Ein
   Server ohne Chunk-v1 wird automatisch per Legacy bedient.
8. Bild nur nach der abschließenden Bestätigung löschen.
9. Status aktualisieren; jedes zuvor benötigte rw-Fenster wird schon vor dem
   Netzwerktransfer beendet. Low-write-Captures benötigen kein SD-Schreibfenster.

Aufnahmefehler werden unabhaengig vom Bild-Upload ueber den bestehenden
Log-Endpunkt des Zielservers gemeldet (auf .16/.17 und Stromberg installiert; Rolloutstatus siehe
`ops/capture-diagnostics-2026-10-11.md`). Poolfehler erreichen jedes Ziel mit
eigenem Key und ACK. Begrenzte, bereinigte Ausgabe; maximal ein Versuch je
fuenf Minuten, keine Kamera-Sperre waehrend des Transfers.

Lokale/remote Fokusaufträge und lokale manuelle Captures werden vor einem neuen
Server-Poll abgearbeitet. Fokus verändert das gemeinsame Objektiv, wird aber
zielbezogen vorgemerkt und nach einem Ausführungsversuch nicht automatisch wiederholt.

Mit `--once` läuft genau ein Durchgang für Tests. `--check-config` prüft nur
Konfiguration und Key. Ohne Option bleibt das Script als Dienst aktiv.

## `lib/camera-rpi-pool.sh`

Der optionale gemeinsame Pool lädt ausschließlich Kameraoptionen eines
Profilgebers. `cr_pool_capture_locked` entscheidet unter der globalen Sperre
zwischen Wiederverwendung und neuer Aufnahme. `cr_pool_distribute` stellt
Hardlinks samt unveränderlicher Metadaten zu; ACK und Trim entfernen nur die
betroffene Zielreferenz. Die letzte Referenz gibt den Master frei. Aktive
Uploads halten eine Inode-Lesesperre, HTTP hält nicht die globale Kamerasperre.
Queueformat, Wiederanlauf und Zehnergrenze: `CAPTURE-POOL.md`.

## `lib/camera-rpi-common.sh` (Aufnahme-/Konfigurationsvertrag)

Die gemeinsame Bibliothek liest Konfigurationen als harmlosen Text. Sie kennt
alle erlaubten Feldnamen, Grenzwerte und Pfadregeln. Außerdem prüft sie, dass
Konfiguration und Key privat sind, und erzeugt kurzlebige Curl-Headerdateien.
Das Laden der Bibliothek startet keine Aufnahme. Ihre Funktion
`cr_capture_to_file` stellt jedoch den gemeinsamen gphoto2-/V4L2-Aufnahmeweg
bereit; der Aufrufer muss dabei die globale Kamera-Sperre halten.
Bei installiertem Klima-Modul haelt diese Funktion ausserdem einen geerbten
Capture-Lock und wartet auf eine frische Ausgaenge-aus-Bestaetigung. Beim
Abbruch bleibt der bereits vorgemerkte Capture-Auftrag erhalten.

## `camera-rpi-climate.php`, `lib/climate-*.php`, `native/camera-rpi-pwm.c`

Ein Geraeteregler fuer alle Zielserver. Die reine Modellbibliothek validiert
Parameter, prueft Messwertalter, bildet gepaarte Mittelwerte und entscheidet
zwischen Taupunktschutz, Sommer, Aufnahmepause und Schutzabschaltung. `ClimateFanStartup`
erzeugt ausschliesslich beim Anlaufen zwei Sekunden 100 %, ohne Sleep in der
Regellogik. Laufende Aenderungen verlaengern den Impuls nicht.

Nur der separate native GPIO-Helfer besitzt BCM5 (Relais LOW-aktiv) und BCM27 (PWM).
Er erwartet bestaetigte Stellbefehle ueber eine private Pipe; fehlen sie drei
Sekunden, schaltet er unabhaengig vom PHP-Regler ab. Keine neue Python-Abhaengigkeit.
Der Supervisor liest den vorhandenen Sensorstatus, schreibt RAM-Status und
verriegelt Fehler ueber Dienstneustarts. Er stellt feste get/save/reset/health/
pause-Operationen bereit, keine Shellausfuehrung. Default Aus, Simulation ohne
GPIO. `save` braucht das RO-Schreibfenster der Admin-Bruecke und sichert die
vorherige Konfiguration. Sicherheitsgrenzen/Rueckweg: `CLIMATE.md`.
`lib/climate-history.php` trennt den begrenzten TSV-Verlauf, seine Aufzeichnung
und die SVG-Geometrie. Alle 30 Sekunden oder bei Schalt-/Zustandswechsel wird
eine RAM-Datei atomar ersetzt: bis 48h/max. 8192 Eintraege/unter 1 MiB. Die
Statusseite liest sie ausschliesslich; keine DHT-Abfrage und keine laufenden SD-Schreibzugriffe.
Luecken, Betriebsartwechsel und unbekannte Werte bleiben in der Grafik sichtbar.

## `camera-rpi-climate-archive.php`, `camera-rpi-history-restore`, `camera-rpi-sensor-backup`

Der PHP-Archivhelfer uebernimmt nur den Reglerverlauf: taeglich eine private
`climate-YYYYMMDD.tsv` neben den unveraenderten Sensorarchiven, 30 erfolgreiche
Snapshots, Berlin-Datum, kein Ueberschreiben desselben Tages. Atomare Dateien
und fsync fuer SD; maximal 48h/8192 Punkte beim Ruecklesen. Der bestehende
Shell-Backupwrapper umfasst beide Archivierungen im gleichen RO/RW-Fenster.
Der neue Restorewrapper startet beide Rueckleser vor Sensor/Regler. Die eigene
Reglersperre verhindert das Ueberschreiben eines laufenden RAM-Verlaufs.
Aktuelle Messwerte/Stellwerte, Fehler und Regelpuffer werden nie restauriert;
nur die Grafik erhaelt historische Daten. Kein neues Python.

## `camera-rpi-healthcheck`

Der Check verändert nichts. Er prüft jede aktivierte Serverdatei, den
systemd-Dienst, Alter des Heartbeats, Laufzeitstatus, wartende Dateien, eine
  bewusst aktivierte Sensorabfrage und den read-only-Zustand von `/`. Seine
Rückgabewerte sind für Monitoring geeignet:

- `0 / OK`: alles gesund,
- `1 / WARNING`: deaktiviert, Queue oder vorübergehender Fehler,
- `2 / CRITICAL`: ungültige Konfiguration, toter Worker, alter Heartbeat oder
  Root nicht read-only.

## `camera-rpi-sensor`

Dieser unabhängige Beobachter fragt in einem konfigurierbaren Abstand DHT22,
Heizungsrückmeldung, Lüfteransteuerung und optional Drehzahl oder Laufkontakt
ab. Er steuert nichts. Die Werte landen atomar unter
`/run/camera-rpi-status/current.status`; gültige Temperatur-/Feuchtewerte
werden dort zusätzlich als begrenzter Verlauf geführt.

`--once` führt genau eine Messung aus. `--check-config` prüft nur die Datei
`/etc/camera-rpi/device.conf`. Fehlende Hardware betrifft nicht die Kamera-
Worker und wird auf der lokalen Seite verständlich als nicht verfügbar gezeigt.

## `admin/camera-rpi-admin`

Nur diese Brücke erhält Root-Rechte von der Webseite. Sie liest ein sehr
kleines `name=wert`-Protokoll über stdin und akzeptiert nur:

- `list`: Status aller Server ohne Keys,
- `save`: validierten Server atomar speichern,
- `validate`: Konfiguration und Key prüfen,
- `set_enabled`: genau einen Worker ein- oder ausschalten,
- `restart`: genau einen vorhandenen Worker neu starten,
- `capture`: nur für ein Ziel einen RAM-Auftrag vormerken,
- `focus`: erlaubte gphoto2-Fokusaktion für ein Ziel vormerken.

`logs` liefert ausschließlich die letzten zehn Journal-Einträge des validierten
Ziels (fünf Sekunden/32 KiB, eigener Key bestmöglich maskiert). Die PHP-Seite
zeigt diese nach CSRF-geschütztem POST einmalig als escaped Text an. Klima-
Aktionen gehen an den getrennten Regler, nicht an eine allgemeine Shell.

Sie akzeptiert keine freien Dateipfade und keine Shellbefehle. Während einer
Änderung verwendet sie dieselbe globale Sperre und denselben ro/rw-Schutz wie
der Worker.
