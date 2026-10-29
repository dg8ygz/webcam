# Webcam-Server

Bereinigter Quellstand; Version und Iteration stehen in `VERSION` und `ITERATION`.
Setup, Umfang und Sicherheitspruefung siehe [Repository-README](../README.md).

## Architekturüberblick

- Öffentliche Einstiegspunkte: `index.php`, `camera.php`, `bestof.php`, `weather.php`, `status/index.php`, `api/public.php`
- Admin-Oberfläche und PHP-API: `admin/`, `admin/api.php`
- Domain- und Servicecode: `src/Domain`, `src/Service`, `src/Infrastructure`
- Templates: `templates/`
- Browser-Assets und lokale Vendor-Dateien: `assets/`
- CLI- und Betriebswerkzeuge: `bin/`, `scripts/`, `ops/`

Die Kameraansicht und ihre Haupttimeline werden serverseitig gerendert. Public-API-Aufrufe ergänzen gezielte dynamische Funktionen wie History, Timelapse, Statusaktualisierung und modale Detailansichten. Ein clientseitiger Neuaufbau der Haupttimeline ist kein Sollpfad.

Metadaten werden vorrangig aus der jeweiligen Kamera-SQLite gelesen. Dateisystem-Scans dienen nur als Legacy- oder Fallbackpfad. Für Archiv-, History- oder Timeline-Performance dürfen keine neuen JSON-, Kalender- oder State-Caches eingeführt oder alte Cache-Rebuilds reaktiviert werden.

### Öffentliche Übersicht

Startseite, das von dort geöffnete globale Best Of, die Statusseite und die
Wetterseite verwenden dieselbe gemeinsame, bewusst einzeilige Kopfzeile aus
`templates/overview_header.php`:
links stehen „Webcam-Übersicht“ und der über `overview_hero` gepflegte Kurztext,
rechts die dokumentrelativen Einstiege „Globales Best-Of“, „Status“ und
„Wetter“. Die Kopfzeile bleibt dabei als eigenstaendiger Orientierungsblock
stehen; Galerie, Status und Wetterdaten beginnen jeweils in einer separaten
Inhaltsflaeche darunter. Wird
das globale Best Of dagegen aus einer Kamera geöffnet, bleibt deren Kopfzeile
mit „Übersicht“, „Infos“ und „Optionen“ stehen; „Globales Best-Of“ ersetzt dort
den Kameranamen und der Zeitbaum ist deaktiviert. Überschrift und Erklärung
werden nicht nochmals im Galerieinhalt wiederholt. In beiden Fällen wird
darunter derselbe Galeriebaustein
`templates/global_bestof_gallery.php` eingesetzt. Auf schmalen Viewports darf
die Kopfzeile ohne horizontales Scrollen umbrechen; Breite und seitlicher
Abstand bleiben beim jeweiligen Seitenwechsel identisch.

Darunter folgen in fester Reihenfolge die Webcam-Karten, die rechtsbündigen
Layout-/Favoriten-Regler, „Zuletzt angesehen“ und zuletzt die Karte. Die Karten
zeigen Bild, Titel, Status, Favorit, Öffnen, Best Shots und öffentliche Tags.
„Schnellansicht“ sowie die Detailzeilen für letzte Auslösung, Intervall und
Koordinaten gehören nicht mehr zur Übersicht; Standortdaten bleiben über die
Karte erreichbar. Der Playwright-Test `tests/e2e/overview.order.test.js` schützt
Reihenfolge, kompakte Kopfzeile und Mobil-Layout.

## Datenbanken und Speicherlayout

### Live-Datenbanken

- Config-/Admin-DB: `/var/lib/webcam/webcams.sqlite`
- Kamera-DB: `/var/lib/webcam/camera-db/<slug>/db.sqlite`
- Public-Rate-Limiter: `/var/lib/webcam/ratelimit_public.sqlite`
- Repo-Sample/Schema: `database/webcams.sqlite`, niemals stillschweigend als Produktions-DB verwenden

SQLite läuft auf den produktiven Pfaden mit `journal_mode=DELETE`, nicht mit WAL. Config-Lese- und Schreibverbindungen sind getrennt. Kamera-Schreibvorgänge verwenden Locking, Busy-Retry und atomare bzw. gestagte Veröffentlichung.

### Bilder und Runtime-State

```text
<slug>/current/
  full.jpg
  hu.jpg
  <width>.jpg
  current.jpg

cache/<slug>/<yyyy>/<MM>/<dd>/
  YYYYMMDD_HHMMSS_<width>.jpg

storage/archive/<slug>/<yyyy>/<MM>/<dd>/
  YYYYMMDD_HHMMSS_<width>.jpg
```

- `<slug>/current/`: aktuelle Live-Kopien auf SSD
- `cache/`: Rolling Window und laufendes Jahr auf SSD
- `storage/archive/`: ältere Archivbilder auf HDD, im DEV read-only
- `storage/bestof/<slug>/`: Best-Of-Bilder
- `var/state/`: kurzlebiger bzw. betrieblicher Runtime-State
- `var/log/`: Anwendungs- und Joblogs
- `var/backups/config/` und `var/backups/<slug>/`: aktive SQLite-Backups auf SSD
- `var/backups/sidecars/<slug>/<yyyy>/<MM>/<dd>/`: physische Wetter-/EXIF-Sicherungen aus Cache und Archiv

Alte Dateien unter `cache/<slug>/db.sqlite`, `var/state/archive-cache/`, `var/state/archive-calendar/` oder `storage/archive/<slug>/db_backups/` sind kein Live-Sollpfad. Legacy-Bestände dürfen nur nach Inventar, Prozessprüfung und ausdrücklicher Cleanup-Entscheidung entfernt werden.

Beim Aufbau der zweiten SSD-Installation unter `/var/www/html/webcam2` bleibt
das Archiv auf dem OMV-HDD-Share. Produktion ist dabei der einzige
Archivschreiber; DEV bindet denselben Bestand ausschließlich read-only ein.
Beide Installationen verlangen auf dem getrennten Archiv-Mount den Sentinel
`storage/archive/.webcam-archive-mounted`; ohne ihn brechen Archiv-Rollover und
Cache-Retention fail-closed ab.
Kopierumfang, getrennte DB-Pfade, Mount-Regeln, Cutover und Rollback stehen im
SSD-Produktions-Runbook (lokale Betriebsdokumentation, nicht im Export).

Der Produktionsumschaltvorgang auf `/var/www/html/webcam2` ist erfolgt. DEV
steht auf Version 6.1.0, Iteration 175; PROD bleibt auf Iteration 174. PROD-Release-Commit ist
`bc0ccd67f95ccd9c85bdd5267ae118ab0e9766b9`. Sicherung, Rueckweg und
Abnahmegrenzen stehen im Produktions-Runbook.
Die erste vollständige 15:00-Capture-Runde nach dem vorausgehenden
Iteration-97-Rollout enthielt alle fünf Sollslots. Die gezielte Nachkontrolle
der Pull-Slot-Nachholung bei der nächsten außergewöhnlich langen
beziehungsweise Mitternachtsrunde bleibt ein getrennter Betriebsschritt.

Auf dem Webserver sind die beiden Archivziele als reguläre `noauto`-CIFS-Mounts
in `/etc/fstab` eingetragen. `webcam-archive-mounts.service` startet sie nach
dem Netzwerk und wiederholt einen fehlgeschlagenen Start alle 15 Sekunden, bis
OMV erreichbar ist. Verschachtelte systemd-Automounts dürfen hier nicht wieder
eingesetzt werden: Sie verursachten beim Boot vom 02.08.2026 einen
Ordering-Cycle. DEV bleibt `ro`, PROD `rw`; beide Pfade müssen danach denselben
Sentinel sehen.

Während des Parallelbetriebs verwendet `/webcam2` eigene Config-, Kamera- und
Rate-Limit-SQLites unter `/var/lib/webcam2/`. Ein manueller PROD-CLI-Aufruf muss
deshalb mindestens `WEBCAM_CONFIG_DB=/var/lib/webcam2/webcams.sqlite` und
`WEBCAM_CAMERA_DB_PATH=/var/lib/webcam2/camera-db` gemeinsam setzen. Eine
Apache-Konfiguration per `SetEnv` gilt nicht automatisch für einen unabhängigen
Shell-Prozess. Vor dem Lauf ist der tatsächlich aufgelöste Kamera-DB-Root zu
prüfen, damit kein gemischter DEV-/PROD-Zugriff entsteht.

Produktionscode wird aus einem ausdrücklich ausgewählten Commit und nur über
die Positivliste `ops/production-release.include` bereitgestellt. Cache,
Current-Bilder, Best-Of, Live-SQLites, Backups, Logs und Runtime-State dürfen
bei einem Code-Update nicht aus DEV überschrieben werden.

Die Cache-Retention löscht einen alten Aufnahmetag nur noch nach erfolgreicher
Vollständigkeitsprüfung aller zugehörigen Archivvarianten und vorhandenen
Sidecars. Schlägt sie fehl, bleibt der ganze Tag erhalten und `var/log/app.log`
enthält `cache retention archive verification failed` mit Kamera, Datum und
Ursache.

Neue Capture-, Current- und Archivbilder sowie zugehörige Sidecars werden im
jeweiligen Zielverzeichnis temporär geschrieben, geprüft und erst danach atomar
umbenannt. Derselbe Veröffentlichungsweg schützt Overlay-Uploads, Best-Of-
Ableitungen und Dayvideo-Refreshes davor, halbfertige Zieldateien sichtbar zu
machen.

## Capture und Scheduling

Es gibt keinen lokalen Capture-Cron oder Capture-Systemd-Timer. Produktive
Active-Capture-Kameras rufen `include/command.php` von externen Helpern auf.
Der kompatible Upload bleibt `include/upload.php`. Iteration 105 ergänzt
`include/upload-chunk.php` für authentifiziert ausgehandelte Teiluploads nach
`camera-rpi-chunk-v1`: Jeder Teil und das zusammengesetzte Bild werden per
SHA-256 geprüft, unvollständige Übertragungen bleiben wiederholbar, und erst
danach läuft exakt derselbe Speicher-, Deduplikat-, Wetter- und Statusweg wie
beim Legacy-Upload. Beide Endpunkte übergeben die lokale Uploaddatei direkt an
`ImageStorage`; das absolute Gesamtlimit beträgt 64 MiB und ein nicht
verfügbarer Deduplizierungs-Lock liefert wiederholbares HTTP 503. Alte Helfer
und Server bleiben unverändert kompatibel.

Bereits Apache begrenzt den vollständigen HTTP-Body des Legacy-Endpunkts auf
26 MiB und den Body eines Chunk-v1-Requests auf 2 MiB. Damit erreichen
übergroße, auch nicht authentifizierte Multipart-Anfragen PHP nicht mehr. Das
Anwendungsdefault bleibt 25 MiB Bilddaten; eine spätere Erhöhung muss mit der
Apache-Grenze abgestimmt werden. Am öffentlichen Proxy war der erste globale
`LocationMatch`-Versuch wirkungslos. Dort muss die korrigierte Regel deshalb
vHost-lokal bereits anhand der `Content-Length` entscheiden: unbekannte Länge
ergibt HTTP 411, Übergröße HTTP 413. `mod_qos` bleibt wegen MPM prefork
deaktiviert; Rate und Parallelität sind ein separater Restpunkt. Prüf- und
Rollbackbefehle stehen in `ops/upload-request-hardening.md`.

- `command.php` entscheidet Slots atomar per `var/state/command/<slug>.slot` und `flock`.
- Nach Fehlern gilt exponentieller Backoff mit maximal 30 Minuten Cooldown.
- Pull-Snapshot-Kameras werden opportunistisch nur hinter einer `wait`-Antwort abgearbeitet.
- Eine echte `webcam_capture`-Antwort startet keine lange Pull-Runde.
- `var/state/pull/tick.lock` schützt die komplette Due-/Capture-Runde vor Parallelstarts.
- Pull-Fälligkeit basiert auf dem neuesten `captures.slot_ts`, nicht auf Upload- oder Abschlusszeit.
- Im Modus `pull-snapshot` ist Pull die vorrangige Bildquelle. Das Admin-Feld
  `Active-Upload-Fallback nach Pull-Fehlern` legt fest, ab wie vielen
  aufeinanderfolgenden Pull-Fehlern externe Uploads wieder angenommen werden;
  `0` deaktiviert diesen Fallback. Ein erfolgreicher Pull sperrt ihn sofort
  wieder. Solange der Fallback gesperrt ist, beantwortet `command.php` auch den
  alten Helper nur mit `wait`, statt die Kamera unnötig auszulösen. Der
  Laufzeitzustand liegt unter `var/state/pull-fallback/<slug>.json`.

Für den Übergang von einem Alt-System steht unter
[`ops/legacy-webcam-helper/`](ops/legacy-webcam-helper/) ein eigenständiger
Bash-/curl-Helper bereit. Eine systemd-Instanz simuliert eine Kamera, lädt bei
`webcam_capture` das konfigurierte `/<slug>/current/raw.jpg` und überträgt es
mit dem PROD-Zeitstempel. Vier Kameras laufen als vier isolierte Instanzen;
fehlgeschlagene Uploads bleiben persistent im Spool. Weil das Alt-System
`current/raw.jpg` erst nach dem nominellen Slot aktualisiert, wartet der Helper
standardmäßig 210 Sekunden vor dem Abruf, behält aber den ursprünglichen PROD-
Slot bei. Cache-Bypass verhindert dabei die Wiederverwendung eines alten
Quellbilds.

Für eine Freshness-Prüfung müssen Serverzeit, erwartete Slots, Command-State, Kamera-DB, Capture-/Uploadlogs sowie Cache- und Current-Dateien gemeinsam betrachtet werden. Ein letzter erfolgreicher Upload allein schließt eine Lücke nicht aus.

## Zentrale CLI-Kommandos

```bash
php bin/capture.php [slug]
php bin/watchdog.php
php bin/archive_rollover.php [YYYY-MM-DD] [--overwrite] [--verbose]
php bin/cleanup_overlays.php [--delete] [--verbose]
php bin/migrate_storage.php
php bin/check_cron_health.php
```

Overlay-Upload und `cleanup_overlays.php` verwenden denselben exklusiven
Datei-Lock. Noch nicht referenzierte neue Uploads bleiben standardmaessig 24
Stunden vom Loeschen ausgenommen; die Frist kann mit
`WEBCAM_OVERLAY_ORPHAN_GRACE_SECONDS` zwischen 60 und 604800 Sekunden gesetzt
werden.

Kamera-DB aus Cache und Archiv aufbauen:

```bash
php bin/migrate_camera_db.php \
  [--slug=<kamera>] [--year=YYYY] [--month=MM] [--day=DD] \
  [--legacy-root=<pfad>] [--db-path=<sqlite>] [--overwrite] [--rebuild]
```

Konsistente SQLite-Backups:

```bash
WEBCAM_CONFIG_DB=/var/lib/webcam/webcams.sqlite \
php bin/backup_daily.php [--timestamp=YYYYmmdd_HHMMSS] [--slug=<kamera>] [--verbose]
```

Ein gezielter `--slug`-Lauf ist ein Zusatzlauf und ersetzt den vollständigen
Tageslauf nicht. Der Task sichert außerdem die physischen Wetter- und
EXIF-`*.txt`-Sidecars des Vortags aus Cache und Archiv. Historische Basisläufe
erfolgen kontrolliert mit `bin/backup_sidecars.php`; Details stehen im
Sidecar-Backup-Runbook (lokale Betriebsdokumentation, nicht im Export).

Weitere Spezialwerkzeuge:

```bash
php bin/build_dayvideo.php --help
php bin/check_weather_quality.php --help
php bin/check_weather_quality.php --slug=stromberg --previous-day --min-repeat=24
bash bin/weather_quality_previous_day.sh
php bin/maintenance_scheduler.php --dry-run  # Diagnose des internen Runners
php bin/extract_wx_ocr.php --help
php bin/import_weather_sidecars.php --help
php bin/backup_sidecars.php --help
php bin/archive_cache_rebuild.php --help
```

`archive_cache_rebuild.php` ist ausschließlich für bewusste Legacy-Analyse vorgesehen, nicht für normale Performance-Arbeit oder Cronbetrieb. OCR-/Wetter-Nacharbeit richtet sich nach ops/wx_ocr_archive_runbook.md (lokale Betriebsdokumentation, nicht im Export) und ops/weather-quality-check.md (lokale Betriebsdokumentation, nicht im Export).

## Öffentliche Routen und Interaktionen

- `GET /camera/<slug>` – Kameraansicht mit serverseitiger Timeline
- `GET /camera/<slug>?view=global-bestof` – globales Best Of im Kopf der zuletzt geöffneten Kamera; Zeitbaum nicht bedienbar
- `GET /bestof` – globales Best Of aller Kameras, chronologisch paginiert
- `GET /api/public.php/cameras` – Kameraübersicht und Statusdaten
- `GET /api/public.php/bestof` – globale Best-Of-Liste; Filter `camera` und `year`
- `GET /api/public.php/cameras/<slug>` – Kameradetails
- `GET /api/public.php/cameras/<slug>/history` – Tage, Monate oder Jahre
- `GET /api/public.php/cameras/<slug>/timeline` – Timeline-Daten für gezielte UI-Funktionen
- `GET /api/public.php/cameras/<slug>/timelapse` – Timelapse-Frames
- `GET /api/public.php/cameras/<slug>/bestof` – paginierte Best-Of-Liste
- `POST /like` – sessionbasierter Like mit Captcha und Rate-Limit
- `GET /stream/<slug>` – MJPEG-Stream
- `GET /stream/<slug>?format=hls` – technischer HLS-Fallback
- `GET /camera/<slug>?stream=hls` oder `?format=hls` – Kameraansicht bewusst direkt mit HLS öffnen

Frontend- und Embed-URLs müssen dokumentrelativ bleiben. Keine festen Präfixe wie `/webcam/` in HTML oder JavaScript einbauen, da die Installation unter unterschiedlichen Alias- und Proxy-Prefixen läuft.

## Livestream

MJPEG ist der primäre Stream. HLS wird automatisch nur bei technischen
MJPEG-Fehlern oder Timeouts versucht. `?stream=hls` und der gleichwertige Alias
`?format=hls` an der Kameraansicht sind dagegen eine bewusste Auswahl: Sie
belegen keinen MJPEG-Slot, starten HLS direkt und kennzeichnen den Bildmodus als
„Livestream HLS“. Admin-Trennen, IP-Sperren
und belegte Clientlimits sind kontrollierte Zustände und dürfen keinen stillen
HLS-Fallback auslösen.

- MJPEG-Viewer: gehaltene Locks unter `var/state/stream/<slug>/`
- HLS-State: `var/state/stream-hls/<slug>/`
- HLS-Viewer: frische Dateien unter `var/state/stream-hls/<slug>/clients/`
- blockierte Adressen: `var/state/stream/blocked_ips.json`

Forwarded-Header werden nur hinter `WEBCAM_TRUSTED_PROXIES` ausgewertet. IP-Adressen bleiben im kurzlebigen Runtime-State und dürfen nicht in Capture-Metadaten persistiert werden; dort wird nur die Viewer-Anzahl gespeichert.

## Likes und Best-Of

Likes sind sessionbasiert und pro Bild nur einmal zulässig. Das Best-Of-Bild wird unter einem bildbezogenen Lock geprüft und atomar veröffentlicht, bevor der DB-Like geschrieben wird. Bei Ablehnung oder DB-Fehler wird nur eine im aktuellen Request neu angelegte Datei zurückgenommen; `duplicate_image` darf eine fehlende Datei reparieren.

Bildpfade werden auf strikt erlaubte Präfixe begrenzt. Likes stehen in der Kamera-DB, werden ins EXIF-Sidecar übernommen und im Overlay als Herz-Zähler angezeigt.

Die globale Best-Of-Seite führt die vorhandenen per-Kamera-Listen zur Laufzeit
nach `slot_ts` zusammen. Sie kopiert keine Bilder und erzeugt weder eine globale
Datenbank noch einen zusätzlichen JSON-Cache. Undatierte Legacy-Bilder stehen
bewusst nach allen verlässlich datierten Aufnahmen.

## Konfiguration und Sicherheit

- Keine echten Kamera-Endpunkte, Keys oder Tokens ins Repository schreiben.
- Produktion muss `WEBCAM_CONFIG_DB=/var/lib/webcam/webcams.sqlite` setzen.
- Bewusste Sample-DB-Webtests benötigen `WEBCAM_ALLOW_SAMPLE_CONFIG=1`.
- Vor jeder Live-DB-Änderung ist eine Kopie und eine ausdrückliche Freigabe erforderlich.
- Admin-API: Bearer- bzw. `X-Admin-Token` plus Double-Submit-CSRF-Cookie `admin_csrf` und Header `X-CSRF-Token`.
- Public-API-Rate-Limit: SQLite speichert ausschließlich gehashte, über die
  vertrauenswürdige Proxy-Kette ermittelte Client-IP-Subjekte, keine Roh-IP.
- History und Captcha besitzen davon getrennte, engere IP-Budgets. Captcha-
  Challenges liegen nur als HMAC und höchstens zehn Minuten im Runtime-State.
- Public-Fehler maskieren interne Details; Details gehören nur ins Log.
- Overlay-Uploads akzeptieren ausschließlich PNG/JPEG und werden nur über `overlay.php` ausgeliefert.
- Kurzlebige externe Wetter-, Watchdog-, OCR-, ImageMagick- und ffmpeg-Aufrufe
  laufen über einen gemeinsamen Runner mit Argumentlisten, Ausgabelimit,
  Timeout und Prozessgruppen-TERM/KILL.
- `.htaccess` sperrt Runtime-, Repo-, DB-, Backup- und Logdateien unter dem Docroot.

Wichtige Umgebungsvariablen:

```text
WEBCAM_CONFIG_DB
WEBCAM_BASE_URL
WEBCAM_ASSETS_URL
WEBCAM_ADMIN_API_TOKEN
WEBCAM_ALLOW_SAMPLE_CONFIG
WEBCAM_CACHE_RETENTION_DAYS
WEBCAM_UPLOAD_MAX_BYTES
WEBCAM_TIMELINE_RANGE_MAX_ENTRIES
WEBCAM_WEATHER_COMMAND_TIMEOUT_SECONDS
WEBCAM_WATCHDOG_SHELL_TIMEOUT_SECONDS
WEBCAM_DAYVIDEO_BUILD_TIMEOUT_SECONDS
WEBCAM_DAYVIDEO_PUBLIC_BUILD
WEBCAM_DAYVIDEO_MAX_CONCURRENT_BUILDS
WEBCAM_DAYVIDEO_GLOBAL_LOCK_DIR
WEBCAM_DAYVIDEO_BUILD_LIMIT
WEBCAM_DAYVIDEO_BUILD_WINDOW_SECONDS
WEBCAM_TRUSTED_PROXIES
WEBCAM_PUBLIC_API_LIMIT
WEBCAM_PUBLIC_API_WINDOW
WEBCAM_HISTORY_API_LIMIT
WEBCAM_HISTORY_API_WINDOW
WEBCAM_CAPTCHA_LIMIT
WEBCAM_CAPTCHA_WINDOW
WEBCAM_CAPTCHA_SECRET
WEBCAM_FFMPEG_PATH
```

Config-DB-Recovery auf Network-Mounts: ops/config-db-cifs-runbook.md (lokale Betriebsdokumentation, nicht im Export). Checkliste für echte Endpunkte und Secrets: ops/restore-config-db-checklist.md (lokale Betriebsdokumentation, nicht im Export).

## Entwicklung und Tests

PHP verwendet `declare(strict_types=1)`, PSR-12 mit zwei Leerzeichen, typisierte Parameter/Properties und frühe Returns. Dateisystemzugriffe sollen über `Webcam\Infrastructure\Filesystem\PathResolver` laufen.

```bash
find src -name '*.php' -print0 | xargs -0 php -l
php vendor/bin/phpunit
```

Das aktuelle PHPUnit-Inventar umfasst 238 Testfälle. Diese Zahl beschreibt
den gegenwärtigen Suite-Umfang; vollständige historische Läufe mit ihrer
jeweiligen Assertion-Zahl bleiben im `CHANGELOG.md` dem geprüften Stand
zugeordnet.

Die HTTP-Integrationstests starten einen PHP-Testserver auf `127.0.0.1:8082` und verwenden eine isolierte Test-DB sowie den Token `unittest-token`.

Playwright auf CIFS/SMB:

```bash
bash bin/prepare_playwright_runtime.sh
cd /tmp/pw-workspace
PLAYWRIGHT_BROWSERS_PATH=/tmp/pw-browsers \
NODE_PATH=/tmp/pw-node-modules \
HOME=/tmp/pw-home \
node /tmp/pw-node-modules/playwright/cli.js test
```

Eine persistente lokale Kopie unter `/opt/webcam-playwright` wird einmalig mit
`sudo bash bin/install_playwright_runtime.sh` angelegt. Nach einem Reboot stellt
`prepare_playwright_runtime.sh` daraus Browser, Node-Pakete und eine kleine
Test-Arbeitskopie unter `/tmp` wieder her. Ohne `/opt`-Kopie faellt das Skript
bewusst auf die Quellen im Repo zurueck. Der vollstaendige Ablauf und die
Aktualisierung der persistenten Kopie stehen in
ops/playwright-runtime.md (lokale Betriebsdokumentation, nicht im Export).

UI- und Layoutänderungen müssen mit passenden Viewports geprüft werden. Für Timeline-/Modal-Navigation existieren unter anderem `tests/e2e/compare.modal.navlock.test.js` und `tests/e2e/timelapse.smoke.test.js`.

Serverprofiling ist nur für Loopback, Dev oder Test vorgesehen:

```text
WC_PROFILE: 1
?profile=1
```

Die Auswertung erscheint in `var/state/wc_profile.log` und als `[WC_PROFILE_SUMMARY]` im HTML.

## Regelmäßiger Betrieb

Interne tägliche Wartung:

- `include/command.php` prüft nach regulären `wait`-Antworten anhand der
  aktuellen Serverzeit, welche unter Admin → Scripte konfigurierten Jobs fällig
  sind. Archiv-Rollover (opt-in `01:00`), Datenbank-Backup (`03:20`) und davon
  abhängige Wetterprüfung (`03:30`) sind frei auf andere Tageszeiten
  einstellbar. Der Archivjob ist standardmäßig deaktiviert und darf nur auf
  dem einen RW-Archivschreiber aktiviert werden. Weitere tägliche Jobs können
  dort mit relativem Pfad unter `bin/`, einzelnen Argumenten und Timeout ergänzt
  werden; die Ausführung erfolgt ohne Shell-Auswertung. SQLite-Backups werden
  lokal unter `/var/local/webcam` gestagt, geprüft und erst dann geschlossen in
  das Backup-Ziel kopiert. Es ist kein Dispatcher-Cron erforderlich; ein Backup
  um `03:10` zeigt ausschließlich einen noch aktiven Alt-Cron an.

Weitere empfohlene Jobs:

- wöchentlich: `cleanup_overlays.php --delete --verbose`
- regelmäßig als Dry-Run: `check_weather_quality.php`

Nicht einrichten:

- keinen lokalen Capture-Cron
- keinen Wartungs-Dispatcher-Cron
- keinen nächtlichen Archive-Cache-Rebuild
- keinen JSON-/Kalender-Warmup für Timeline oder History

Public-Dayvideos sind standardmäßig cache-only. Videos bevorzugt per CLI/Job
vorbauen; Web-Builds nur bewusst mit `WEBCAM_DAYVIDEO_PUBLIC_BUILD=1`
aktivieren. Öffentliche Builds sind auf die UI-Profile 3/5/7/9 FPS und
1200/1600 px sowie 300 Frames begrenzt. Ein IP-Budget von standardmäßig drei
Buildstarts in zehn Minuten und ein hostweiter Slot-Pool mit standardmäßig
einem, hart höchstens zwei parallelen Builds schützen DEV und PROD gemeinsam.
Die Client-IP wird nur entlang der expliziten `WEBCAM_TRUSTED_PROXIES`-Kette
von rechts nach links aufgelöst.

## Diagnosehinweise

- Bei Capture-Problemen erwartete Slots gegen Kamera-DB, Logs und Dateien prüfen.
- Bei `rg: binary file matches` OCR-Logs mit `rg --text` bzw. `rg -a` durchsuchen.
- Bei Streamproblemen ffmpeg-Prozesse inklusive `Z`/`<defunct>`, Locks, HLS-Playlist, Segment-MTimes, PID und Log prüfen.
- Owner-/Group-Anzeigen aus der Codex-CIFS-Umgebung können fälschlich `nobody:nogroup` zeigen; belastbare Prüfungen direkt im Host-/Apache-Kontext durchführen.
- Auf CIFS kann `.git` ohne `nodfs` als read-only Submount erscheinen. In diesem Fall keine Git-Reparatur- oder Reset-Kommandos ausführen.

Historische Entscheidungen und abgeschlossene Migrationen bleiben im Changelog, Audit und den Issues nachvollziehbar, nicht als konkurrierende aktuelle Anleitung in dieser README.
