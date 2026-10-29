# Betriebshandbuch der Webcam-Plattform

Stand: 06.09.2026

Dieses Dokument richtet sich an Personen, die die Webcam-Plattform betreiben, aber nicht am Programm mitentwickeln. Es erklärt den normalen Betrieb, sichere Prüfungen und typische Störungen.

## 1. Was das System macht

Die Plattform verwaltet mehrere Webcams. Sie:

- fordert Kameras zur Aufnahme auf oder holt Bilder selbst ab,
- speichert das aktuelle Bild und ältere Aufnahmen,
- zeigt Livebild, Archiv, Zeitraffer, Wetter und Bildinformationen an,
- verwaltet Einstellungen im Admin-Bereich,
- protokolliert Fehler und erstellt Datenbank-Backups.

Eine Aufnahme besteht nicht nur aus einem Bild. Zu ihr können mehrere Bildgrößen, EXIF-Daten, Wetterwerte, Likes und Qualitätsmarkierungen gehören.

## 2. Wo die Daten liegen

### Aktuelles Bild

Das jeweils aktuelle Bild liegt unter:

```text
<kamera>/current/
```

Beispiel: `bielefeld/current/`. Dort liegen `current.jpg`, `full.jpg`, `hu.jpg` und kleinere nummerische Varianten.

### Neuere Bilder auf SSD

Neuere Aufnahmen liegen nach Datum sortiert unter:

```text
cache/<kamera>/<jahr>/<monat>/<tag>/
```

`cache` bedeutet hier Bildspeicher für einen begrenzten Zeitraum. Es ist kein zusätzlicher Kalender- oder Datenbank-Cache.

### Langzeitarchiv auf HDD

Ältere Aufnahmen liegen unter:

```text
storage/archive/<kamera>/<jahr>/<monat>/<tag>/
```

Das Archiv enthält Produktivdaten. Auf dem Entwicklungssystem ist es absichtlich nur lesbar. Dort niemals Reparaturen, Backups oder Löschungen erzwingen.

Für den Aufbau der zweiten SSD-Installation unter `/var/www/html/webcam2` gilt
das eigene SSD-Produktions-Runbook (lokale Betriebsdokumentation, nicht im Export). Das
gemeinsam genutzte HDD-Archiv hat genau einen Schreiber: Produktion read-write,
DEV read-only. Anwendung, Cache, Current, Best-Of, Runtime-State und Live-DBs
bleiben je Installation getrennt.

Am Archiv-Root muss `storage/archive/.webcam-archive-mounted` vorhanden und
lesbar sein. Fehlt die Datei, bricht der Archiv-Rollover sichtbar ab; die
Cache-Retention protokolliert `cache retention archive mount guard failed` und
loescht keinen Cache-Tag. Den Sentinel niemals im leeren lokalen Mountpunkt
nachbauen, sondern nur auf dem nachweislich eingebundenen OMV-Archiv anlegen.

Auf dem Webserver stehen beide Archivziele als `noauto`-CIFS-Einträge in
`/etc/fstab`. Der aktivierte Dienst `webcam-archive-mounts.service` bindet DEV
read-only und PROD read-write ein. Er versucht bei einem noch nicht erreichbaren
OMV alle 15 Sekunden erneut. Die Archivpfade nicht als verschachtelte
`x-systemd.automount`-Einträge konfigurieren; genau diese Kombination wurde am
02.08.2026 von systemd wegen eines Ordering-Cycles verworfen.

Reguläre Kontrolle:

```bash
systemctl status webcam-archive-mounts.service
findmnt -T /var/www/html/webcam/storage/archive
findmnt -T /var/www/html/webcam2/storage/archive
test -r /var/www/html/webcam/storage/archive/.webcam-archive-mounted
test -r /var/www/html/webcam2/storage/archive/.webcam-archive-mounted
```

Soll: DEV enthält `ro`, PROD enthält `rw`, der Dienst ist `enabled` und
`active`, und beide Sentinel-Prüfungen enden erfolgreich. Einen fehlenden
Sentinel niemals lokal nachbauen.

### Archivierung und Cache-Retention

Der Archiv-Rollover läuft ausschließlich in Produktion täglich um `01:00` und
verarbeitet standardmäßig den Vortag. Er ist als eingebauter interner
Scheduler-Job vorhanden und in der aktiven PROD-Konfiguration aktiviert; DEV
bleibt deaktiviert. In einer neuen oder wiederhergestellten Installation ist
der Job fail-closed standardmaessig deaktiviert und darf erst nach der
Verifikation von PROD-RW-Mount, Sentinel, Config-/Kamera-DB-Pfaden und dem
Entfernen eines gegebenenfalls noch vorhandenen alten Root-Crons aktiviert
werden. Der Job kopiert
beziehungsweise erzeugt Archivvarianten; er verschiebt den Cache nicht.

Vorhandene Zielsets werden nicht allein anhand ihrer Dateinamen uebersprungen.
Der Rollover prueft JPEG-Typ, Sollabmessungen, Originalklone und vorhandene
Sidecars. Ein defektes Set wird vollstaendig atomar repariert und danach erneut
geprueft. Fuer den kompletten Tag wird auf dem gemeinsamen Archiv-Mount
`.webcam-archive-locks/<YYYY-MM-DD>.lock` gehalten. Ein Lockfehler bricht den
Lauf sichtbar ab; er erlaubt niemals einen zweiten autorisierten Schreiber. Die
reale OMV-Gegenprobe ueber PROD-RW und DEV-RO wurde am 01.08.2026 erfolgreich
durchgefuehrt.

Der Lock wartet standardmaessig hoechstens 30 Sekunden
(`WEBCAM_ARCHIVE_DAY_LOCK_TIMEOUT_SECONDS`, 0-300). Die Abschaltung mit
`WEBCAM_ARCHIVE_DAY_LOCK_ENABLED=0` ist nur fuer isolierte Tests vorgesehen,
nicht fuer DEV oder Produktion.

Alte Cache-Tage werden nur nach erfolgreichem Vollständigkeitsnachweis gelöscht.
Für jede Originalaufnahme müssen alle stabilen Archivvarianten als gültige JPEGs
mit erwarteten Abmessungen vorhanden sein. Vorhandene EXIF-/Wetter-Sidecars
müssen ebenfalls im Archiv angekommen sein. Schlägt eine Prüfung fehl, bleibt
der gesamte Tag im Cache. Die Warnung steht in `var/log/app.log`:

Historische Tagesverzeichnisse, die keine Originalaufnahmen mehr enthalten und
nur noch abgeleitete Cache-Varianten tragen, werden nach vorhandener
Archivtag-Prüfung ebenfalls entfernt. Fehlt der Archivtag, bleibt auch dieser
Bestand erhalten.

```text
cache retention archive verification failed
```

Kamera, Datum, Cache-/Archivpfad und Ursache stehen im strukturierten
Log-Kontext. Den Cache in diesem Zustand nicht manuell löschen; zuerst den
Archiv-Rollover beziehungsweise die fehlerhafte Zieldatei reparieren und die
erneute Prüfung abwarten.

### Datenbanken

Die zentrale Einstellungsdatenbank liegt produktiv hier:

```text
/var/lib/webcam/webcams.sqlite
```

Die Datenbank einer Kamera liegt hier:

```text
/var/lib/webcam/camera-db/<kamera>/db.sqlite
```

Alte Datenbankkopien unter `database/`, `cache/` oder `storage/archive/*/db_backups/` sind nicht die aktiven Datenbanken. `database/webcams.sqlite` ist nur eine anonymisierte Muster- und Testdatenbank.

### Duplikat-/Bildbestandsabgleich

`captures.is_duplicate=1` behaelt Slot, Wetter und EXIF in der Kamera-DB,
schliesst die Zeile aber aus Timeline, Bildsuche, Dayvideo und Best-Of aus.
Neue Uploads werden vor der Dateipublikation bytegenau mit dem letzten
verwendbaren Capture verglichen; identische JPEGs werden nicht gespeichert und
setzen den Kamerastatus bis zum naechsten echten Bild auf Rot.

Historische Korrekturen immer zuerst als begrenzten Dry-Run und als realer
Web-/Helper-User ausfuehren. Fuer die parallele PROD-Installation muessen beide
DB-Variablen explizit gesetzt sein:

```bash
cd /var/www/html/webcam2
runuser -u www-data -- env \
  WEBCAM_CONFIG_DB=/var/lib/webcam2/webcams.sqlite \
  WEBCAM_CAMERA_DB_PATH=/var/lib/webcam2/camera-db \
  php bin/reconcile_capture_images.php \
    --slug=bielefeld --from="2026-08-14 06:10" --verbose
```

Erst nach Kontrolle der Zaehler und Einzelzeilen denselben Lauf mit `--apply`
wiederholen. Vor jeder schreibenden Ausfuehrung entsteht automatisch ein
konsistentes Backup unter `var/state/backup/db/<slug>/`. Das Skript loescht
weder Bilddateien noch Varianten-, Wetter- oder EXIF-Zeilen. Jede Variante wird
jedoch einzeln gegen die reale JPEG-Datei geprueft: `size_bytes` und `mtime`
werden synchronisiert, fehlende, leere oder ungueltige Varianten mit
`size_bytes=0` aus der Bildauswahl genommen. `--clear-restored` ist eine
separate, ausdrueckliche Option fuer zuvor markierte und inzwischen wieder
vorhandene nutzbare Bilder.

## 3. Was im Normalbetrieb automatisch passiert

- Produktive Kameras fragen `include/command.php` ab.
- Ist ein Aufnahmezeitpunkt fällig, antwortet der Server mit `webcam_capture`.
- Die Kamera lädt das Bild anschließend über `include/upload.php` hoch. Neue
  Helfer können vorher authentifiziert Chunk-v1 aushandeln und das JPEG über
  `include/upload-chunk.php` in kleinen Teilen senden. Antwortet die
  Gegenstelle nicht ausdrücklich mit `camera-rpi-chunk-v1`, bleibt der
  Legacy-Endpunkt der kompatible Weg.
- Apache begrenzt den vollständigen Legacy-Request auf 26 MiB und einen
  Chunk-v1-Request auf 2 MiB, noch bevor PHP den Upload-Key prüft. Prüfung,
  der auf 403/413/411 abgenommene vHost-Guard des öffentlichen Proxys und der
  Rückweg stehen in `ops/upload-request-hardening.md`. `mod_qos` bleibt auf dem
  dortigen MPM prefork deaktiviert. Stattdessen zählen getrennte Fail2Ban-
  Jails Legacy- und Chunk-POSTs; eine eigene nftables-Tabelle begrenzt frühe
  Frontend-Verbindungen pro Quell-IP. Beide Schichten sind seit 05.09.2026,
  22:12:10 CEST auf dem Proxy aktiv. Die beobachtete Legacy-Runde verlief
  erfolgreich; Testban und legitimer Chunk-Transfer bleiben als betriebliche
  Abnahme offen.
- Die separate Proxy-Fail2Ban-Bestandsreparatur und ihre engen vHost-/WP-
  Ergänzungen sind in `ops/proxy-fail2ban-repair-2026-09-05.md` und
  `ops/proxy-fail2ban-extension-2026-09-05.md` beschrieben. Punkte 1-3 und
  4/5 sind seit 05.09. aktiviert. Die anschliessenden Filterverfeinerungen
  sind seit 06.09., 00:07:29 CEST aktiv; Ban-Erhalt und nftables-Abgleich
  siehe `ops/proxy-fail2ban-refinement-2026-09-06.md`. Der echte Joomla-Blog unter
  `/joomla/index.php/blogg/blog` bleibt ausgenommen; kein WordPress vorhanden.
- Serverseitig abgeholte Kameras werden hinter einer `wait`-Antwort verarbeitet.
- Pro Kamera verhindert ein Lock doppelte Verarbeitung desselben Zeitfensters.
- Der tägliche Backup-Job sichert die zentrale Datenbank und alle Kamera-Datenbanken.
- `chkrootkit` prueft das lokale System taeglich ab `05:00`, sieht die beiden
  Webcam-CIFS-Mounts in seinem systemd-Namespace jedoch nicht. Lynis laeuft
  sonntags ab `06:00`; sein Zertifikatstest durchsucht `/var/www` nicht mehr.
  Beide Timer verwenden bis zu zehn Minuten Zufallsverzoegerung und holen
  verpasste Termine nicht ungeplant nach.
- Scannerberichte gehen ueber den lokalen SMTP-Port 1025 an Mailpit:
  chkrootkit nur bei einem nicht leeren Abweichungsbericht, Lynis nach jedem
  Wochenlauf als kompakte Zusammenfassung. Ein Ausfall der Benachrichtigung
  darf den eigentlichen Scan nicht fehlschlagen lassen.

Es gibt keinen lokalen Capture-Cron, der alle Kameras regelmäßig startet. Externe Kameras melden sich selbst beim Server.

Öffentliche API-Limits werden pro vertrauenswürdig ermittelter Client-IP
gezählt. Eine vom Browser oder Client gesetzte `X-Session-ID` ändert dieses
Budget nicht. Hinter einem Reverse Proxy muss `WEBCAM_TRUSTED_PROXIES` dessen
tatsächliche, am Origin sichtbare `REMOTE_ADDR` beziehungsweise das kleinste
notwendige CIDR enthalten. Nur dann darf `ClientIpResolver` die
`X-Forwarded-For`-Kette von rechts nach links bis zum ersten nicht vertrauten
Hop auswerten. Fehlt der Proxy in dieser Liste, bleibt das Verhalten sicher,
aber sämtliche Besucher teilen sich das Budget der Proxy-IP. Niemals pauschal
beliebige Netze als vertrauenswürdig eintragen.

Kann ein Alt-System nur aktuelle JPEGs unter `/<slug>/current/raw.jpg`
bereitstellen, simuliert der eigenständige Helper unter
[`ops/legacy-webcam-helper/`](../ops/legacy-webcam-helper/) auf einer separaten
Linux-Maschine das Kamera-Protokoll. Pro Kamera läuft eine isolierte
systemd-Instanz. Bereits geladene, aber von PROD noch nicht bestätigte Bilder
bleiben persistent im Spool; Heartbeats und Fehler stehen in Runtime-State und
Journal. Da die Altquelle ihr aktuelles Bild zeitversetzt veröffentlicht,
wartet der Helper standardmäßig 210 Sekunden vor dem Abruf, behält dabei aber
den ursprünglichen PROD-Slot-Zeitstempel bei.

## 4. Täglicher Schnellcheck

Der folgende Befehl prüft unter anderem Aufnahmeabstände, Datenbank-Backups und bekannte Zustände:

```bash
WEBCAM_CONFIG_DB=/var/lib/webcam/webcams.sqlite php bin/check_cron_health.php
```

Bei einer vollständigen Prüfung zusätzlich ansehen:

```bash
tail -n 100 var/log/upload.log
tail -n 100 var/log/webcam.log
ps -o pid,ppid,stat,etime,cmd -C ffmpeg
```

Wichtig: Ein letzter erfolgreicher Upload beweist nicht, dass alle erwarteten Bilder vorhanden sind. Die Serverzeit muss mit dem Aufnahmeintervall und den erwarteten Slots verglichen werden. Fehlt mehr als ein Intervall, ist das eine Lücke.

Bei OCR-Logs mit `binary file matches` erneut mit Textmodus suchen:

```bash
rg -a 'ERROR|timeout|Done' var/log/wx_ocr*.log
```

## 5. Datenbanken und Sidecars sicher sichern

Vollständiges tägliches Backup:

```bash
runuser -u www-data -- env WEBCAM_CONFIG_DB=/var/lib/webcam/webcams.sqlite \
  php bin/backup_daily.php --verbose
```

Gezieltes Backup einer Kamera:

```bash
runuser -u www-data -- env WEBCAM_CONFIG_DB=/var/lib/webcam/webcams.sqlite \
  php bin/backup_daily.php --slug=steinhagen --force --verbose
```

Im Parallelbetrieb mit `/webcam` und `/webcam2` muss jeder manuelle PROD-Aufruf
zusätzlich den Kamera-DB-Root setzen. Apache-`SetEnv` gilt nicht automatisch für
einen unabhängigen Shell-Prozess. Nur die PROD-Config-DB zu setzen kann sonst
einen gemischten Lauf erzeugen, der Kamera-DBs aus DEV sichert oder verändert:

```bash
cd /var/www/html/webcam2
runuser -u www-data -- env \
  WEBCAM_CONFIG_DB=/var/lib/webcam2/webcams.sqlite \
  WEBCAM_CAMERA_DB_PATH=/var/lib/webcam2/camera-db \
  WEBCAM_SQLITE_BACKUP_TMPDIR=/var/local/webcam \
  php bin/backup_daily.php --force --verbose
```

Vorher die tatsächlich aufgelöste Quelle kontrollieren:

```bash
runuser -u www-data -- env \
  WEBCAM_CONFIG_DB=/var/lib/webcam2/webcams.sqlite \
  WEBCAM_CAMERA_DB_PATH=/var/lib/webcam2/camera-db \
  php -r '$app=require "/var/www/html/webcam2/bootstrap.php"; echo $app->paths()->cameraDbPath(), PHP_EOL;'
```

Die Ausgabe muss `/var/lib/webcam2/camera-db` sein. Diese Zwei-Variablen-Regel
gilt ebenso für Wetterprüfung, Migration, Watchdog und andere CLI-Befehle mit
Kamera-DB-Zugriff. Ein `quick_check = ok` allein beweist nicht, dass die richtige
Installation als Quelle verwendet wurde. Der vollständige Ablauf steht in
`ops/ssd-production-rollout.md`.

Das Skript erzeugt den konsistenten SQLite-Snapshot zuerst auf dem lokalen
Dateisystem unter `/var/local/webcam`, prüft ihn und kopiert erst die
geschlossene Datei in das Backup-Verzeichnis. Der Pfad kann mit
`WEBCAM_SQLITE_BACKUP_TMPDIR` auf ein anderes geprüftes lokales Verzeichnis
gelegt werden. Fehlt dieses Verzeichnis oder ist es nicht beschreibbar, bricht
das jeweilige Backup sichtbar ab; es gibt keinen stillen Fallback auf `/tmp`
oder ein SMB-/CIFS-Ziel. Einmalig einrichten mit:

```bash
install -d -o www-data -g www-data -m 0700 /var/local/webcam
```

Ein gezieltes Backup wird getrennt protokolliert und ersetzt nicht den
vollständigen Tageslauf.

Im selben Task werden alle physischen Wetter- und EXIF-`*.txt`-Sidecars des
abgeschlossenen Vortags sowohl aus dem Cache als auch aus dem Archiv gesichert.
Damit bleiben die Textdaten unabhängig von einer Kamera-DB wiederherstellbar.
Die Originalbilder bleiben unverändert; Metadaten werden nicht in das JPEG
eingebrannt.

Ziele:

```text
var/backups/config/webcams.sqlite.<zeit>.bak
var/backups/<kamera>/db.sqlite.bak-<zeit>
var/backups/sidecars/<kamera>/<jahr>/<monat>/<tag>/cache/*.txt
var/backups/sidecars/<kamera>/<jahr>/<monat>/<tag>/archive/*.txt
```

Sidecar-Dateien werden gestagt, mit Größe und SHA-256 geprüft und atomar
veröffentlicht. Leere, nicht lesbare oder widersprüchliche Dateien setzen den
gesamten Backupstatus auf `partial`; Details stehen in `var/log/app.log`. Die
nachgelagerte Wetterprüfung startet dann nicht. Historische Basisläufe und
Restore-Befehle beschreibt ops/sidecar-backup.md (lokale Betriebsdokumentation, nicht im Export).

Vor jedem Import, Rebuild oder manuellen Eingriff in eine aktive Datenbank ist ein frisches Backup Pflicht. Änderungen an Live-Datenbanken benötigen eine ausdrückliche Freigabe.

## 6. Häufige Störungen

### Eine Kamera liefert keine neuen Bilder

1. `var/log/upload.log` nach Kamera und Fehlern durchsuchen.
2. Das Kamera-Log unter `var/log/capture/` prüfen.
3. Den letzten Eintrag in der Kamera-Datenbank mit dem erwarteten Slot vergleichen.
4. Prüfen, ob das Kamera-Verzeichnis auf der produktiven Kamera wirklich eingehängt ist.
5. Meldungen wie `capture failed` nicht ausblenden; sie zeigen oft die richtige Fehlerursache.

### `database is locked`

Die aktiven Datenbanken liegen lokal und verwenden SQLite `journal_mode=DELETE`. `WAL` ist auf CIFS/NFS ausdrücklich nicht erlaubt. Einzelne kurze Sperren können bei parallelen Schreibzugriffen auftreten; wiederholte Meldungen müssen nach Datenbank, Zeitpunkt und auslösendem Prozess untersucht werden.

Nicht als Lösung einen neuen Datei-, JSON- oder Kalender-Cache einführen. Für Archivmetadaten ist die Kamera-Datenbank die einzige vorgesehene Beschleunigung.

### Der Livestream ist angeblich belegt

Eine Kameraansicht kann bewusst direkt mit HLS geoeffnet werden:

```text
/camera/<kamera>?stream=hls
/camera/<kamera>?format=hls
```

Beide Schreibweisen sind gleichwertig. Unten rechts muss dann „Livestream HLS“
stehen; die Seite darf vorher keinen MJPEG-Slot belegen. Nicht mit dem
technischen Stream-Endpunkt `/stream/<kamera>?format=hls` verwechseln.

Prüfen:

```text
var/state/stream/<kamera>/*.lock
var/state/stream-hls/<kamera>/
```

Ein vorhandenes Lockfile allein bedeutet noch keine aktive Verbindung. Entscheidend ist, ob der Lock gehalten wird. Zusätzlich Admin-Liste, Access-Log und Reconnects gemeinsam bewerten. Bei HLS auch Zeitstempel von `index.m3u8`, Segmenten sowie `ffmpeg.pid` und `ffmpeg.log` prüfen.

Ein laufender ffmpeg-Prozess ist nicht automatisch ein Zombie. Der ueberwachte
HLS-Runner beendet ffmpeg standardmaessig 45 Sekunden nach dem letzten frischen
Playlist-/Segment-Heartbeat. Die Frist kann mit
`WEBCAM_HLS_IDLE_TIMEOUT_SECONDS` zwischen 30 und 300 Sekunden gesetzt werden;
`stream.hls_idle_timeout_seconds` hat Vorrang, wenn ein Deployment dieses Feld
bereitstellt. Das bestehende `max_runtime_seconds`-Limit bleibt als harter
Laufzeitstopp erhalten. Beim Leerlaufende muessen `process.lock` wieder frei und
`ffmpeg.pid` entfernt sein; Grund und Exit-Code stehen in `ffmpeg.log`.

### Ein Best-Of-Like schlägt fehl

Das System legt das Best-Of-Bild zuerst vollständig und atomar an. Erst danach wird der Like in der Kamera-Datenbank gezählt. Wird der Like abgelehnt oder tritt ein Datenbankfehler auf, entfernt das System nur das gerade neu angelegte Bild. Ein bereits vorhandenes Best-Of-Bild bleibt bestehen. War ein Like schon früher gespeichert, aber die Bilddatei fehlt, stellt ein erneuter Aufruf die Datei wieder her.

EXIF-Text und Vorschaubild sind Begleitdaten. Fehler dabei werden protokolliert, machen einen bereits erfolgreich gespeicherten Like aber nicht rückwirkend ungültig.

### Wetterwerte fehlen oder sind unplausibel

Nur prüfen:

```bash
WEBCAM_CONFIG_DB=/var/lib/webcam/webcams.sqlite \
  php bin/check_weather_quality.php --slug=<kamera> --hours=168 --verbose
```

Dieser Lauf ändert nichts. `--write` schreibt Qualitätsmarkierungen in die Kamera-Datenbank und darf erst nach Backup und Freigabe verwendet werden. Markierte Werte werden auf den Wetterseiten nicht angezeigt und im Status als Fehler gemeldet.

Bei einem manuellen Lauf für `/webcam2` müssen auch hier sowohl
`WEBCAM_CONFIG_DB=/var/lib/webcam2/webcams.sqlite` als auch
`WEBCAM_CAMERA_DB_PATH=/var/lib/webcam2/camera-db` gesetzt sein. Andernfalls
kann der Check trotz PROD-Config die DEV-Kamera-DB prüfen oder markieren.

Der automatisierte Stromberg-Lauf wird unter Admin → Scripte aktiviert und
zeitlich auf `03:30` eingestellt. Zuvor muss das vollstaendige Tagesbackup um
`03:20` erfolgreich abgeschlossen und Stromberg darin enthalten sein. Erst dann
synchronisiert `bin/weather_quality_previous_day.sh` die Qualitaetsmarker des
abgeschlossenen Vortags. Im Scheduler-Lauf entsteht kein zweiter gezielter
Stromberg-Snapshot. Rohwerte werden nicht veraendert; Backup- oder Prueffehler
verhindern den Schreiblauf. Details stehen in `ops/weather-quality-check.md`.

### Archiv oder History ist langsam

Zuerst vorhandene Messwerte in `ops/performance-baselines.md` vergleichen. Danach mit Profiling und den Kamera-Datenbankabfragen eingrenzen.

Nicht verwenden:

- neue JSON- oder State-Caches,
- Kalender-Caches,
- einen nächtlichen Archive-Cache-Rebuild,
- Cache-Warmup als dauerhafte Lösung.

Zulässig sind passende SQLite-Abfragen und Indizes, vollständige Kamera-Datenbanken und das Vermeiden unnötiger Dateisystem-Scans.

## 7. OCR und Wetterimport

Der ausführliche und sichere Ablauf steht in `ops/wx_ocr_archive_runbook.md`. Die Reihenfolge ist:

1. Vollständigen Zeitraum und vorhandene Marker prüfen.
2. OCR mit Zeitlimits starten.
3. Ergebnis und fehlende Slots vollständig zählen.
4. Kamera-Datenbank sichern.
5. Import zunächst als Probelauf ausführen.
6. Erst danach mit `--write` importieren.
7. Wetterqualität erneut prüfen.

`--batch` beim Import ist nur die Anzahl der Datensätze pro Datenbank-Transaktion. Es aktiviert keinen Cache.

## 8. Änderungen im Admin-Bereich

Der Admin-Bereich verändert die zentrale Live-Konfiguration. Falsche Kamera-Schlüssel, Endpunkte oder Pfade können Aufnahmen stoppen. Deshalb:

1. Nur eine sachliche Änderung gleichzeitig durchführen.
2. Nach dem Speichern die betroffene Kamera und den Status prüfen.
3. Bei Aufnahmeänderungen den nächsten erwarteten Slot abwarten und im Upload-Log bestätigen.
4. Geheimnisse und echte Kamera-Endpunkte nie in die Repo-Musterdatenbank übernehmen.

### Neue Kamera und Apache-Current-Freigabe

Der Apache-Prozess darf den Programmbaum nicht verändern. Für jede konfigurierte
Kamera ist ausschließlich `<projekt>/<slug>/current` als zusätzlicher
Schreibpfad freigegeben. Nach dem Anlegen einer Kamera prüft das Admin-Panel
diesen Pfad aus Sicht des laufenden Webprozesses. Solange Verzeichnis oder
systemd-Ausnahme fehlen, erscheint im Reiter **Speicher** ein gelber Hinweis
mit Pfad und vollständigem Root-Befehl.

Der angezeigte Befehl muss einmal als root auf dem Webserver ausgeführt werden.
Er liest alle Kamera-Slugs aus der jeweiligen Live-Config-DB, legt fehlende
Current-Verzeichnisse an, ersetzt die projektspezifische Apache-Drop-in-Datei
atomar und startet Apache neu. Slugs niemals von Hand in der generierten Datei
ergänzen. Nach dem Lauf Admin-Seite neu laden; der Hinweis muss verschwinden.

Für DEV lautet der kontrollierte Abgleich:

```bash
/usr/local/sbin/webcam-sync-apache-runtime-paths \
  --check \
  --project-root /var/www/html/webcam \
  --config-db /var/lib/webcam/webcams.sqlite
```

Bei `AKTION NÖTIG` exakt denselben Aufruf mit `--apply` wiederholen. Die
vollständige Installation, PROD-Variante, HTTP-Gegenprobe und der Rollback
stehen in `ops/apache-webroot-hardening.md`.

Die Admin-Navigation hat keinen eigenen Timeline-Hauptreiter mehr. Das weiterhin
wirksame Limit für unbeschränkte DB-Timeline-Abfragen liegt unter **Meta**. Der
historische Wert `0` wird aus Speicherschutzgründen wie der sichere Standard
von 300 Einträgen behandelt; gültige positive Werte liegen zwischen 50 und
5000. Das Limit greift bereits bei der Capture-Abfrage vor dem Laden von
Varianten, EXIF und Wetter. Der
standardmäßig deaktivierte Legacy Timeline-Cache bleibt für bewusste
Altanalysen als eingeklappter Diagnoseblock unter **Status** erreichbar.

Auch ausdrücklich gewählte Timeline-Zeiträume werden vor der Hydrierung
begrenzt. `WEBCAM_TIMELINE_RANGE_MAX_ENTRIES` ist standardmäßig 20.000 und kann
nur zwischen 1 und 50.000 liegen. Ein größerer Wert ist keine Lösung für einen
Jahres-Massendownload; solche Auswertungen müssen gezielt in kleinere
Zeitbereiche zerlegt werden.

### Arbeitsfläche Scripte

Unter Admin → Scripte sind drei tägliche Wartungsjobs fest eingebaut:

- standardmaessig deaktivierter Archiv-Rollover um `01:00`,
- vollständiges Datenbank-Backup um `03:20`,
- davon abhängige Stromberg-Wetterprüfung des abgeschlossenen Vortags um `03:30`.

Alle drei Einträge behalten ihre stabile interne ID, können aber einschließlich
Name, Beschreibung, Uhrzeit, Ausführungspfad, Argumenten und Timeout bearbeitet
werden. Zusätzliche tägliche Scripte lassen sich in derselben Arbeitsfläche
hinzufügen und wieder entfernen. Ihr Pfad muss dokumentrelativ unter `bin/`
liegen, beispielsweise `bin/cleanup_overlays.php`; so verwendet `/webcam2`
automatisch seine eigene Datei. Der Admin-API-Check lehnt beim Speichern einen
nicht vorhandenen oder aus `bin/` herausführenden Pfad ab. Argumente werden einzeln und ohne Shell-
Auswertung an den Prozess übergeben. Dadurch sind weder Shell-Operatoren noch
Pfade außerhalb der jeweiligen Installation ausführbar.

Jeder Job kann separat aktiviert und deaktiviert werden. Die Oberfläche zeigt
den letzten protokollierten Lauf und dessen Exit-Code. Bei einem roten
`error`-Status blendet ein Klick höchstens die letzten fünf bereinigten
Logeinträge genau dieses Jobs ein; fehlen eigene Einträge, bleibt die Anzeige
mit einem entsprechenden Hinweis leer. Der interne Zeitgeber
nutzt die ohnehin regelmaessigen Kamera-Polls an `include/command.php` und die
aktuelle Serverzeit. Beim ersten Poll ab der eingestellten Minute wird ein
faelliger Job abgekoppelt vom Webrequest gestartet. Zeiten wie `01:00` und
`15:00` sind daher ohne Aenderung an einer System-Crontab frei kombinierbar.

Der Wettercheck darf nur bei aktiviertem Backup und mit einer spaeteren Uhrzeit
gespeichert werden. Der interne Runner holt verpasste Faelligkeiten nach, startet den
Wettercheck aber erst nach einem erfolgreichen vollstaendigen Backup desselben
Tages. Ein weiterhin auftauchender Backup-Lauf um `03:10` kommt nicht vom neuen
Zeitgeber, sondern vom alten festen Cron und muss einmalig deaktiviert werden.

Der Runner führt einen Job pro Kalendertag höchstens einmal aus und schreibt
seinen Zustand nach `var/state/maintenance-scheduler/`. Sein Sammellog liegt in
`var/log/maintenance_scheduler.log`. Änderungen an den Zeitplänen schreiben in
die produktive Config-DB und benötigen deshalb dieselbe ausdrückliche Freigabe
wie andere Live-Konfigurationsänderungen.

`archive_rollover` nur in der Config-DB von Produktion aktivieren, nachdem dort
Archiv-Mount, Sentinel und Schreibrecht geprueft wurden. Erst wenn der interne
Statuslauf funktioniert, die aktive Root-Cronzeile rueckrollbar entfernen.
Der Rollover führt vor Lock und Dateiverarbeitung selbst eine kurzlebige
Schreibprobe im Archiv-Root aus. Meldet er `Archive is not writable` und Exit 2,
ist der Mount read-only oder dem Laufzeitbenutzer fehlt das Schreibrecht; den
Lockcode nicht umgehen, sondern Mount und Autorisierung des einen vorgesehenen
Archivschreibers prüfen.

Im zeitweisen Parallelbetrieb sind die Installationen bewusst versetzt: DEV
führt Backup/Wetter um `03:20`/`03:30` aus, webcam2 um `03:40`/`03:50`. Die
Staging-Dateinamen unter `/var/local/webcam` sind eindeutig, der Zeitversatz
reduziert zusätzlich gleichzeitige I/O-Last. Er ist keine harte Sperre: Dauert
ein Lauf länger als 20 Minuten, können sich beide Installationen trotzdem
überlappen. Nach dem Cutover bleibt deshalb nur der Produktionslauf aktiv.

Es darf kein Wartungs-Dispatcher unter `/etc/cron.d/` installiert werden.
Vorhandene feste Backup-/Wetter-Crons sind Altbestand und müssen beim Rollout
einmalig entfernt oder rueckrollbar außerhalb von `/etc/cron.d/` abgelegt
werden. Das betrifft auf diesem Host insbesondere
`/etc/cron.d/cron-backup-camera-db` mit der alten Zeit `03:10`.
Debian-`cron` erkennt entfernte oder verschobene Dateien in `/etc/cron.d`
automatisch. `systemctl reload cron` wird von diesem Dienst nicht unterstützt
und ist nach dieser Bereinigung nicht erforderlich; nur bei einem tatsächlichen
Dienstproblem wäre ein bewusster Neustart sinnvoll.

### Playwright nach einem Reboot

Die ausführbare, persistente Playwright-Kopie liegt lokal unter
`/opt/webcam-playwright`; das Repo auf CIFS bleibt nur die Quell- und
Fallbackkopie. Nach einem Reboot wird die Laufzeitumgebung so nach `/tmp`
wiederhergestellt:

```bash
cd /var/www/html/webcam
bash bin/prepare_playwright_runtime.sh
```

Danach Tests aus `/tmp/pw-workspace` starten. Die `/opt`-Kopie wird einmalig und
nach Playwright-Updates mit `sudo bash bin/install_playwright_runtime.sh`
aktualisiert. Das Skript muss in der echten Host-Shell laufen; eingeschränkte
Entwicklungs-Sandboxes können `/opt` nicht beschreiben. Vollständige Befehle und
Prüfschritte stehen in `ops/playwright-runtime.md`.

## 9. Was niemals getan werden soll

- Nicht nach `/mnt/nfs/webcam_orginal/` schreiben.
- Nicht ohne Backup und Freigabe in aktive Datenbanken schreiben.
- Nicht `database/webcams.sqlite` als produktive Datenbank verwenden.
- Nicht `WAL` auf CIFS/NFS aktivieren.
- Nicht das HDD-Archiv auf dem Entwicklungssystem beschreiben.
- Nicht alte Runtime-Dateien blind löschen.
- Nicht Fehler durch stillschweigende Standardwerte oder Fallbacks verstecken.
- Nicht einen neuen Archiv-, Timeline- oder Kalender-Cache als Performance-Lösung einführen.

## 10. Begriffe

- **Slug:** Kurzer technischer Kameraname, zum Beispiel `bielefeld-nord`.
- **Slot:** Erwarteter Aufnahmezeitpunkt, zum Beispiel alle fünf oder zehn Minuten.
- **Capture:** Eine Aufnahme mit Bild und zugehörigen Metadaten.
- **Sidecar:** Kleine Textdatei neben einem Bild, etwa für EXIF- oder Wetterdaten.
- **Backfill:** Nachträgliches Einlesen älterer Bilder oder Metadaten in die Kamera-Datenbank.
- **Live-DB:** Die tatsächlich vom laufenden System verwendete Datenbank.
- **Legacy:** Alter, nur noch aus Kompatibilitätsgründen vorhandener Weg.
- **Dry-Run:** Probelauf, der zeigt, was passieren würde, aber nichts schreibt.
