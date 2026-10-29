# PHP-Code-Wegweiser

Stand: 01.09.2026

Dieser Wegweiser erklärt den PHP-Teil der Webcam-Plattform in Alltagssprache. Er richtet sich an Menschen, die PHP lesen können oder gerade lernen, aber die Anwendung und ihre Architektur noch nicht kennen. Die verbindlichen technischen Regeln stehen weiterhin in `AGENTS.md`; die Soll-Architektur steht in `docs/ARCHITEKTUR.md`.

Die produktiven PHP-Dateien sind zusätzlich direkt im Quellcode dokumentiert. Datei- und Klassenkommentare erklären die Verantwortung; Kommentare an komplexen Methoden beschreiben Reihenfolge, Datenquelle, Schreibwirkung, Locks und Fehlerpfade. Offensichtliche einzelne Zuweisungen werden bewusst nicht wiederholt, damit wichtige Hinweise auffindbar bleiben.

Seit Iteration 111 beginnen auch die größten, historisch gewachsenen Dateien mit einer kurzen Leseführung und sind in fachliche Abschnitte gegliedert:

- `assets/js/camera.js` verbindet die fachlich getrennten Kamera-Controller, hält gemeinsame URL-/DOM-Adapter und startet deren Lebenszyklus nach einem DOM-Austausch neu.
- `assets/js/camera-partial-navigation.js` erklärt Same-Origin- und Kameragrenzen, Request-Abbruch und Generationen, die Übernahme vorgeholter Dokumente, Root-Austausch, Browser-History sowie Maus- und Tastaturbedienung der Timeline-Hotspots.
- `assets/js/camera-live-image.js` erklärt Fokus und Reload, Placeholder und Vollbildtausch, Ladeabschluss und Prefetch, gefilterte Lightbox-Klicks, Streamzustände sowie den Rückweg aus der Informationsansicht.
- `assets/js/camera-timeline-data.js` koordiniert Timeline-Requests, Abbruch und den nur seitenlokalen Tagescache. Das DOM-freie `camera-timeline-model.js` erklärt API-Normalisierung, URL-Aufbau, Kalenderlisten und die Nachbarsuche für Bild, Stunde, Tag, Monat und Jahr.
- `assets/js/camera-timeline-view.js` erklärt den DOM-Aufbau der Timeline-Gruppen, Uhrzeitauswahl, seitliche Hotspots, Snapshot-Präsentation und den Lade-/Timeoutzustand des Hauptbildes.
- `assets/js/camera-captcha.js` erklärt Aufbau, Netzwerkabruf, Promise-Lebenszyklus und Fehlerzustände des Like-Captcha-Dialogs.
- `assets/js/camera-bestof-like.js` erklärt Pfadnormalisierung, CSRF-/Captcha-Request, Herzzustand, Fehlerfälle und die bestätigte Best-of-Navigation.
- `assets/js/camera-status.js` erklärt Telemetrieformatierung, zusammengeführte API-Anfragen, seitenlokalen Detailcache und das Neubinden des Dialogs nach partieller Navigation.
- `assets/js/camera-history.js` erklärt den eingebetteten Tageshistorie-Dialog sowie API-Abruf, Reiter, seitenlokalen Cache und dokumentrelative Links des großen History-Panels.
- `assets/js/camera-playback.js` erklärt Timelapse-Frames, Vorladen, Scrubber, Wiedergabetimer, Tastatur und Vollbild. `camera-dayvideo.js` besitzt getrennt davon Tagesvideo-Build, Polling und den kontrollierten Wechsel zwischen Video und Livebild.
- `assets/js/camera-snapshot-prefetch.js` erklärt URL- und Snapshot-Schlüssel, begrenzte Dokument-/Blob-Caches, Request-Deduplizierung, Bildhydrierung und das Vorladen benachbarter Snapshots.
- `assets/js/camera-labels.js` erklärt Eingabenormalisierung, sichere DOM-Erzeugung, Farbblasen, persönliche Sichtbarkeit und die Unterdrückung doppelter Alt-Overlays.
- `assets/js/camera-meta-modal.js` erklärt den gemeinsamen Lebenszyklus von EXIF-, Wetter-, Umgebungskarten- und Bildvergleichsdialog einschließlich Fokus, Seitensperre, Race-Schutz und Aufräumen. Zeilenorientierte EXIF-Sidecars werden dort sicher als Schlüssel/Wert-Tabelle aufbereitet; unbekannte Zeilen bleiben unverändert sichtbar.
- `assets/js/camera-timeline.js` bündelt Navigationsmodus und Bedienung der fünf Timeline-Picker.
- `assets/js/camera-lightbox.js` koordiniert Bilddialog und Tageswechsel. `camera-lightbox-entry.js` normalisiert Snapshot-, EXIF- und Overlaydaten; `camera-lightbox-controls.js` kapselt Zoom, Verschieben, Tastatur und Hilfe.
- `assets/js/camera-stream.js` koordiniert MJPEG-Probes, Viewerlimits, Reconnects und kontrollierte Fallbacks. `camera-stream-preference.js` besitzt nur die sitzungsbezogene Snapshot-/Stream-Auswahl; `camera-stream-hls.js` besitzt hls.js, natives HLS, Decoder-Timeout und Freigabe der Videoressourcen.
- `assets/js/camera-weather.js` besitzt Tagesabruf, Diagrammgeometrie, Tooltip, Vorschau und Modalinhalt. Das DOM-freie `camera-weather-model.js` normalisiert und sortiert Legacy-Wettertexte und stellt die Messreihen bereit.
- `assets/js/camera-surroundings.js` erklärt Standortprüfung, zeitliche Bildzuordnung, sichere Popups, Blickfeldpolygone und den Leaflet-Lebenszyklus der kameraübergreifenden Umgebungskarte.
- `assets/js/camera-map.js` erklärt Standortmarker, Sichtfeldbogen, Kartenoffset und den verzögerten Start nach dem Bildladen.
- `assets/js/overview.js` ist nur noch der Startkoordinator. Die ausführlich
  kommentierten Module `overview-cards.js`, `overview-recent.js`,
  `overview-map.js` und `overview-lightbox.js` erklären Karten/Favoriten,
  lokale Verlaufsliste, Leaflet-Karte und Bilddialog getrennt; `index.php` und
  `templates/overview.php` liefern dafür nur Daten und HTML.
- `assets/js/status.js` besitzt Detaildialog und minütlichen API-Abgleich der
  öffentlichen Statusseite. `templates/status.php` liefert nur noch das
  serverseitige Fallback-HTML und ein sicher JSON-kodiertes Startmodell.
- `assets/js/admin.js` hält den einzigen Browserzustand, die gemeinsame API-
  und Formularlogik sowie die Adapter zu ausgelagerten Admin-Bereichen.
- `assets/js/admin-status.js` erklärt Admin-/Public-Fallback, begrenzte
  Detailabfragen, Status- und Streamdarstellung sowie Trennen, Sperren und
  Freigeben von Stream-Clients.
- `assets/js/admin-maintenance.js` erklärt Scheduler-Defaults, Laufstatus,
  Scriptkarten, Payload-Aufbau, Pfad-/Argumentgrenzen und die Abhängigkeit des
  Wetterchecks vom vorherigen Datenbankbackup.
- `assets/js/admin-archive-cache.js` erklärt den optionalen Legacy-Cache,
  Status-Hydrierung, explizite Admin-Freigabe, Rebuild-/Dry-Run-Payload,
  Fehlersichtbarkeit und das ausschließlich während eines laufenden Jobs
  aktive Status-Polling.
- `assets/js/admin-settings.js` erklärt die globalen Einstellungen für
  Übersicht, Runtime, Timeline und Public-API-Limit, sichtbare Defaults ohne
  stilles Speichern, HTML-Maskierung, Formulargrenzen und die delegierten
  API-Payloads.
- `assets/js/admin-capture-editor.js` erklärt DB-Vorschau, Jahresauflösung,
  Slot-/Datumssuche, normalisierte Capture-Antworten, Relations-JSON,
  typisierte Speicher-Payloads und einmalig delegierte Editorereignisse.
- `assets/js/admin-map-preview.js` erklärt Formularwertgrenzen, geografische
  Sichtkegelberechnung, Leaflet-Darstellung, Canvas-Fallback und das vollständige
  Aufräumen der Eingabe-/Resize-Listener bei jedem Tab- und Renderwechsel.
- `assets/js/admin-overlay-editor.js` erklärt helle/dunkle Varianten,
  dokumentrelative Vorschau-URLs, durchgängige Attributmaskierung,
  Multipart-Upload über den gemeinsamen CSRF-Adapter, Zustandsübernahme,
  Payload-Normalisierung und Listenerbindung nach einem Neurender.
- `assets/js/admin-label-editor.js` erklärt Farbvorgaben, Schriftkontrast,
  Positionstypen, maskiertes Tabellenrendering, Link-/Farbprüfung,
  Payload-Normalisierung, Leerzustand und einmalig delegierte DOM-Ereignisse.
- `assets/js/admin-form-validation.js` erklärt zusätzliche Slug-, Text- und
  Pfadregeln, sichere Fehlerausgabe, Tab-/Fokuswahl, erzwungene Gesamt- und
  Abschnittsprüfung sowie Sichtbarkeit und Disabled-Zustand der RTSP-Felder.
- `assets/js/admin-camera-editor.js` erklärt Kameraauflösung und gemeinsames
  View-Model, die zehn bearbeitbaren Fachabschnitte, die lesende DB-Diagnose,
  maskierte Configwerte, delegierte Unterrenderer und den Tab-/Formularrahmen.
- `assets/js/admin-camera-payload.js` erklärt den gemeinsamen FormData-Kontext,
  Listen-, Wetter-, Stream- und Watchdogparser, zehn fachliche Payload-Builder
  sowie die identische Nutzung bei Abschnitts- und Gesamtspeicherung.
- `assets/js/admin-page.js` erklärt den vollständigen Admin-Neuaufbau vom
  sicheren Flash und der Kameratabelle über Haupttabs und DOM-Austausch bis zu
  einmalig delegierten Ereignissen und dem Start der sichtbaren Facheditoren.
- `CameraConfigNormalizer` erklärt den Weg vom vollständigen oder
  abschnittsweisen Admin-Kamerapayload über Feld-Positivlisten und gemeinsame
  Defaults bis zu Transport- und Pfadgrenzen. `CameraOverlayNormalizer` und
  `CameraLabelNormalizer` erklären Logo- beziehungsweise Hotspotregeln separat.
  HTTP-Antworten und Persistenz bleiben ausdrücklich im Admin-Router.
- `CapturePayloadNormalizer` erklärt die Sicherheitsgrenzen für Capture-
  Hauptfelder, kameragebundene Pfade, Varianten, EXIF und Wetter.
- `CameraCaptureEditor` führt von kompakter DB-Vorschau und Slotdetail über die
  gemeinsame Schreibtransaktion bis zum stabilen Admin-API-Ausgabeformat.
- `CameraCaptureQueryService` erklärt die read-only Capture-Suche von
  Query-Normalisierung und Datumsbereich über Filter/Pagination bis zur Auswahl
  eines exakten, neuesten oder zeitlich nächsten Bildes.
- `CameraAdminHandler` erklärt die vollständige `/cameras`-Route von CRUD und
  Abschnittsupdates über Jahres-/DB-/Capture-Zugriffe bis zu Overlay,
  Like-Sidecar, Fehlerstatus und Page-Cache-Invalidierung.
- `GlobalConfigAdminHandler` erklärt `/config` und `/scripts` von verzögertem
  JSON-Lesen über Feldvalidierung und ConfigStore bis zur Wartungsübersicht.
- `ArchiveCacheAdminHandler` erklärt das optionale Legacy-Werkzeug von
  Freigabe und Lock über shellfreie Argumente bis zu Timeout und Fehlerstatus.
- `StreamAdminHandler` erklärt Viewerübersicht, Kameragruppierung, Trennen und
  IP-Sperren, während `StreamState` alleiniger Besitzer des Runtime-State bleibt.
- `StatusAdminHandler` erklärt die lesende Kamera-Statusübersicht von einem
  gemeinsamen Request-Zeitpunkt bis zur intervallabhängigen Schweregradbildung.
- `OverlayUploadService` erklärt die Admin-Uploadgrenze von tatsächlicher
  Größe und Bildprüfung über gemeinsamen Lock und sicheren Dateinamen bis zur
  atomaren Publikation hinter `overlay.php`.
- `templates/camera.php` ist die HTML-Ausgabehülle. Die kommentierten
  Teiltemplates `camera/prepare.php`, `camera/navigation.php` und
  `camera/history.php` erklären Datenvorbereitung, wiederverwendete Menüs und
  History-spezifische Vorbereitung getrennt.
- `camera.php` ist der kleine HTTP-Rahmen. `Http/Public/camera/request.php`
  erklärt Request/Kamera/Modus, `presentation.php` Cache, Timeline und Rendern.
- `bin/migrate_camera_db.php` koordiniert nur CLI und Fortschrittsausgabe.
  `CameraDatabaseMigrationSource`, `CameraDatabaseMigrationPayloadBuilder` und
  `CameraDatabaseRebuildManager` erklären Quelle, Payload-Aufbau und atomaren
  Neuaufbau der Kamera-DB.
- `bin/backup_daily.php` koordiniert den Tageslauf. `SqliteBackupService`,
  `DailyDatabaseBackup` und `BackupRunState` erklären Snapshotprüfung,
  Zielwahl/Retention sowie Lock und Voll-/Teilstatus getrennt.
- `bin/extract_wx_ocr.php` beschreibt den Weg vom Quellbild über OCR-Pässe und Plausibilitätsprüfungen bis zum Sidecar oder Fehlermarker.
- `ArchiveBrowser` und `ImageStorage` kennzeichnen Datenquellen, Hydrierung,
  Dateischritte, Retention und SQLite-Abschluss. Kalender-/Timeline-Auswahl,
  EXIF, Bildvarianten, Backup-Anstoß und Retention liegen in getrennten,
  kommentierten Helfern. `CameraDataStore` ist nur noch
  die kompatible Fassade vor den getrennten Kamera-DB-Repositories; deren
  Dateiköpfe führen durch Schema, Captures, Likes/Best-Of sowie Wetter/Logo.
- `BestOfGallery` delegiert Dateisichtung, Like-Abfragen und Cacheverwaltung
  an `BestOfFileCatalog`, `BestOfLikeRepository` und `BestOfGalleryCache`.
- `stream.php` delegiert HLS-Playlist/Runner und MJPEG-Slot/Prozesspflege an
  `src/Service/Stream/hls_functions.php` und `mjpeg_functions.php`.
- `TimelineNavigator` erklärt und testet Auswahl, Zeitnormalisierung und Nächstsuche ohne Datenbank oder Dateisystem; `ArchiveEntryImageResolver` kapselt die Bildvariantenprüfung.
- `ArchiveStoragePaths` erklärt historische Storage-Pfadformen;
  `LegacyArchiveCache` verwaltet den optionalen Cache und
  `LegacyArchiveCacheCodec` übersetzt sein kompaktes JSON schrittweise.
- `UploadIngestor` und `BoundedProcessRunner` erklären die fail-closed Upload- und Prozessgrenzen direkt an den sicherheitsrelevanten Abläufen.

Diese Kommentare sind Orientierungspunkte. Für Details bleibt der tatsächlich ausgeführte Code maßgeblich.

## 1. Das wichtigste Bild im Kopf

Ein Aufruf der Website läuft meistens in fünf Schritten ab:

```text
Browser oder Kamera
        |
        v
PHP-Einstiegspunkt, z. B. camera.php oder include/upload.php
        |
        v
bootstrap.php erzeugt das gemeinsame App-Objekt
        |
        v
Services lesen Konfiguration, SQLite und/oder Bilddateien
        |
        v
HTML, JSON, Bilddaten oder ein kurzer Kamerabefehl werden ausgegeben
```

Die Einstiegspunkte nehmen eine Anfrage entgegen. Die Klassen unter `src/` erledigen die eigentliche Arbeit. Die Templates unter `templates/` erzeugen HTML. Diese Trennung ist nicht überall vollständig, weil einige historisch gewachsene Dateien noch groß sind; neue Änderungen sollen die Zuständigkeiten schrittweise klarer trennen.

## 2. Kleine PHP-Lesehilfe

Einige Schreibweisen kommen im Projekt ständig vor:

| Schreibweise | Bedeutung |
| --- | --- |
| `require 'datei.php'` | Führt eine andere PHP-Datei aus und übernimmt deren Rückgabewert. |
| `$app->cameras()` | Ruft die Methode `cameras()` des Objekts `$app` auf. |
| `Camera $camera` | Die Variable muss ein Objekt der Klasse `Camera` sein. |
| `?string` | Entweder eine Zeichenkette oder `null`, also „kein Wert“. |
| `array` | Eine Liste oder eine Sammlung benannter Werte. |
| `return` | Gibt ein Ergebnis zurück und beendet die aktuelle Funktion. |
| `throw` | Bricht den normalen Ablauf mit einem sichtbaren Fehler ab. |
| `try` / `catch` | Führt Code aus und behandelt einen möglichen Fehler kontrolliert. |
| `finally` | Wird auch bei einem Fehler ausgeführt, etwa um einen Lock zu lösen. |
| `match` | Wählt abhängig von einem Wert genau einen Ablauf aus. |
| `static fn(...) => ...` | Eine kurze Funktion, oft zum Filtern oder Umformen einer Liste. |
| `declare(strict_types=1)` | PHP soll Typfehler nicht stillschweigend durch Umwandlungen verdecken. |

Wichtige Projektbegriffe:

- **Slug:** kurze technische Kamera-ID, zum Beispiel `stromberg-nord`.
- **Entry-Point/Einstiegspunkt:** PHP-Datei, die direkt per HTTP oder CLI gestartet wird.
- **Route:** Kombination aus HTTP-Methode und URL, die eine bestimmte Aktion auswählt.
- **Service:** Klasse, die einen fachlichen Ablauf erledigt, etwa Aufnahme oder Archivsuche.
- **Store/Repository:** Zugriffsschicht auf gespeicherte Daten.
- **Presenter:** bereitet interne Daten für eine HTML- oder API-Ausgabe auf.
- **Template:** PHP-Datei, die aus vorbereiteten Daten HTML erzeugt.
- **Side Effect/Nebenwirkung:** eine Änderung außerhalb der laufenden Funktion, zum Beispiel eine Datei oder einen DB-Eintrag schreiben.
- **Lock/Sperre:** verhindert, dass zwei parallele Prozesse dieselbe Arbeit gleichzeitig ausführen.
- **Fallback:** Ersatzweg, wenn die bevorzugte Quelle nicht verfügbar ist. Fallbacks sind hier nur erlaubt, wenn sie ausdrücklich vorgesehen und sichtbar sind.
- **Slot:** auf das Aufnahmeintervall ausgerichteter Zeitpunkt. Mehrere Anfragen im selben Slot sollen nicht mehrere Aufnahmen erzeugen.

## 3. Verzeichnisübersicht

| Pfad | Was dort passiert |
| --- | --- |
| `bootstrap.php` | Lädt Hilfsfunktionen, registriert den Autoloader, liest die App-Konfiguration und erzeugt `Webcam\App`. |
| `src/Domain/` | Beschreibt Kameras und fachliche Werte, ohne selbst HTTP auszugeben. |
| `src/Service/` | Enthält Abläufe wie Capture, Archiv, Status, Likes, Streams und Wetter. |
| `src/Infrastructure/` | Kapselt SQLite, Dateisystem, HTTP-Aufrufe, Logging und Rendering. |
| `templates/` | Erzeugt die sichtbaren HTML-Seiten aus vorbereiteten Daten. |
| `api/` | Öffentliche JSON-Lesezugriffe. |
| `admin/` | Admin-Oberfläche und geschützte Admin-API. |
| `include/` | Endpunkte für externe Kameras sowie gemeinsam genutzte HTTP-Helfer. |
| `bin/` | PHP-Programme für Kommandozeile, Wartung, Migration und Diagnose. |
| `config/` | Baut die Laufzeitkonfiguration aus Umgebung und Config-SQLite auf. |

`assets/` enthält JavaScript, CSS und lokale Browser-Bibliotheken. Diese Dateien gehören zum Frontend und werden in diesem PHP-Wegweiser nur dort erwähnt, wo PHP Daten an den Browser übergibt.

## 4. Startpunkt jeder PHP-Ausführung

Fast alle HTTP- und CLI-Abläufe laden zuerst `bootstrap.php`:

```php
$app = require __DIR__ . '/bootstrap.php';
```

`bootstrap.php` erledigt drei Dinge:

1. Es lädt allgemeine Hilfsfunktionen aus `src/Support/helpers.php`.
2. Es registriert einen Autoloader. Wenn PHP zum Beispiel `Webcam\Service\Status\StatusService` benötigt, wird automatisch `src/Service/Status/StatusService.php` geladen.
3. Es lädt `config/app.php` und gibt ein gemeinsames `Webcam\App`-Objekt zurück.

`src/App.php` ist die zentrale Objektablage der Anwendung. Darüber erhält anderer Code unter anderem Kamera-Konfiguration, Pfade, Logger, Presenter, Capture-, Status- und Archiv-Services. Die Services werden erst erzeugt, wenn sie wirklich gebraucht werden. Das nennt man „lazy initialization“.

Das `App`-Objekt ist kein globaler Datenspeicher für beliebige Werte. Es verbindet Konfiguration und klar benannte Dienste miteinander.

## 5. Woher die Daten kommen

Es gibt drei unterschiedliche Arten von Daten. Beim Lesen von Code sollte immer geklärt werden, welche davon gerade gemeint ist.

### Zentrale Konfiguration

Die produktive Config-/Admin-Datenbank liegt unter
`/var/lib/webcam/webcams.sqlite`. `config/camera_store.php` bleibt der
kompatible prozedurale Einstieg, delegiert aber Verbindung/Schema an
`ConfigDatabaseConnection`, das Lesen und Zusammensetzen an
`ConfigDocumentHydrator` sowie die SQL-Persistenz an
`ConfigDocumentPersister`. `src/Infrastructure/Config/ConfigStore.php` bietet
darüber eine kleine objektorientierte Schnittstelle und schreibt nach
Änderungen das Signal `var/state/config.reload`.

Die Datei `database/webcams.sqlite` ist nur eine anonymisierte Beispiel- und Schema-Datenbank. Der Code verweigert ihre unbemerkte Verwendung im Live-Betrieb.

### Metadaten je Kamera

Jede Kamera besitzt eine SQLite-Datenbank unter
`/var/lib/webcam/camera-db/<slug>/db.sqlite`. Bestehende Aufrufer verwenden
weiterhin `CameraDataStore`; die Fassade delegiert die SQL-Arbeit inzwischen an:

- `CameraDataSchemaRepository` für Sollschema und additive Migrationen,
- `CameraCaptureRepository` als Fassade vor `CameraCaptureWriter` für Capture,
  Varianten, EXIF und Wetter sowie `CameraCaptureReader` für Pfad-, Kalender-
  und Timeline-Abfragen,
- `CameraLikeBestOfRepository` für Like-Transaktionen und Top-Likes,
- `CameraWeatherLogoRepository` für Wetterqualität und Logo-Erkennung.

`CameraDataConnection` hält für alle vier Bereiche genau eine Verbindung sowie
Busy-Retry, Schreiblock und den kontrollierten CIFS-Staging-Fallback. Dadurch
bleiben zusammengehörige Schreibvorgänge atomar, ohne diese Betriebslogik in
jedem Repository zu duplizieren. Gespeichert werden unter anderem:

- Aufnahmen und ihren Slot-Zeitpunkt,
- Bildvarianten und relative Pfade,
- EXIF- und Wetterwerte,
- Likes und Best-Of-Zuordnung,
- ausgewählte Metadaten zur Aufnahme.

Schreibvorgänge verwenden Sperren und Wiederholungen bei einer kurzfristig belegten SQLite-Datenbank. Leser können die Datenbank ausdrücklich read-only öffnen.

### Bilder und Laufzeitdateien

`src/Infrastructure/Filesystem/PathResolver.php` baut allgemeine
Dateisystempfade aus den konfigurierten Wurzelpfaden. Der darauf aufbauende
`CameraStoragePathResolver` behandelt die historischen Varianten von
`storage.base_path`: Ein Kameraslug am Ende wird für den gemeinsamen Root
entfernt, relative Angaben werden stets an der Projektwurzel verankert und
gespeicherte Relativpfade werden vor der Verkettung gegen Traversal geprüft.
App, Bildspeicherung, Tagesarchivierung, Statusauswertung und Migration nutzen
dadurch exakt dieselben Regeln.

Die wichtigsten Bereiche sind:

```text
<slug>/current/                         aktuelle Live-Kopien
cache/<slug>/<Jahr>/<Monat>/<Tag>/      laufendes Jahr / Rolling Window
storage/archive/<slug>/<...>/           älteres HDD-Archiv
storage/bestof/<slug>/                  ausgewählte Bilder
var/state/                              Locks und Laufzeitstatus
var/log/                                Logs
var/backups/                            aktive SQLite- und Sidecar-Backups
```

Die Kamera-SQLite ist die bevorzugte Quelle für Timeline- und Archivmetadaten. Dateisystem-Scans sind nur ein Legacy- oder Fallbackweg. Der alte JSON-/Kalender-/Archive-Cache ist kein normaler Laufzeitpfad.

## 6. Die wichtigsten HTTP-Abläufe

### Kameraübersicht

```text
index.php
  -> CameraPresenter::overviewList()
  -> Karten- und URL-Daten ergänzen
  -> Renderer::render('overview', ...)
  -> templates/overview.php + templates/layout.php
```

`index.php` liest keine Formulare und verändert keine Kameradaten. Die Datei bereitet die Übersicht und die Karte vor. Relative URLs werden aus dem aktuellen Request abgeleitet, damit die Installation auch unter einem Proxy-Prefix funktioniert.

### Einzelne Kamera und Timeline

```text
camera.php
  -> Slug und gewählten Zeitpunkt prüfen
  -> CameraRepository liefert Camera
  -> CameraTimelineResolver begrenzt Zeitraum und wählt das Bild
  -> ArchiveBrowser liest Timeline bevorzugt aus Kamera-SQLite
  -> CameraOverlayMetadata berechnet Tag/Nacht und gültige Bildlabels
  -> CameraWeatherMetadata filtert Wetterfehler und Qualitätsmarker
  -> CameraTimelineCalendar bewegt sich durch geladene Kalendertage
  -> CameraTimelineNavigationBuilder baut Pfeile sowie Zeit-/Stunden-/Jahresoptionen
  -> CameraPresenter und weitere Helfer bauen das View-Model
  -> Renderer::render('camera', ...)
  -> templates/camera.php + templates/layout.php
```

`camera.php` ist historisch groß. Die Datei verbindet serverseitige Timeline,
Menüs, Best-Of, Stream-Informationen und die Übergabe ans Template. Wettertext,
Wetter-Qualitätsmarker, Tag-/Nacht-Erkennung, zeitabhängige Labels und die reine
Kalendertagsuche liegen inzwischen in den oben genannten Komponenten. Auch das
große serverseitige Navigations-View-Model liegt nicht mehr im Route, sondern
im `CameraTimelineNavigationBuilder`. Seine URL-Callbacks stammen weiterhin
aus `camera.php`, weil nur dort der aktuelle History-/Playback-Kontext bekannt
ist. Der Builder verdichtet die Aufnahmen eines Tages außerdem auf genau ein
Sprungziel je vorhandener Stunde; dadurch bleibt das Stunden-Pulldown mit den
Stundenpfeilen konsistent.
Auch die Zusatzdarstellung des gewählten Bildes ist ausgelagert:
`CameraSnapshotPresentationBuilder` liest und parst EXIF/Wetter, gleicht
Like-/Logo-Daten ab und liefert Overlay, Modaltexte, Labels sowie Herz-Zustand
in einem fertigen Array. Seine Methoden sind in derselben Reihenfolge wie der
Datenfluss benannt und kommentiert. Bei Änderungen an diesen Regeln deshalb
zuerst den Builder und `CameraSnapshotPresentationBuilderTest` prüfen; das
Template soll diese Werte nur darstellen und nicht erneut herleiten.
Die Standortkarte ist kein PHP-Stringblock mehr: `camera.php` baut nur deren
Datenpaket, das Kamera-Template legt es sicher am Container ab und
`assets/js/camera-map.js` zeichnet die eigentliche Leaflet-Karte.
Ein früher in `camera.php` aufgebautes Download-Auswahlmodell ist bewusst nicht
in eine neue Klasse gewandert: Seit dem Entfernen des Download-Selects gab es
keinen Verbraucher mehr. Route, Template und `lazy-meta.js` enthalten diesen
toten Pfad seit Iteration 117 nicht mehr.
Beim Ändern zuerst den betreffenden Abschnitt und dessen Eingabe-/Ausgabedaten
bestimmen; nicht die ganze Datei auf einmal umbauen.

`src/Service/Storage/ArchiveBrowser.php` bleibt die öffentliche Fassade,
orchestriert aber getrennte Komponenten: `ArchiveDatabaseReader` liest SQLite,
`ArchiveFilesystemReader` behandelt den Legacy-Fallback, `TimelineMapper` baut
die Struktur, `TimelineNavigator` wählt den Zeitpunkt und
`ArchiveEntryImageResolver` prüft Vergleichsvarianten. `ArchiveStoragePaths`
vereinheitlicht ältere Pfadkonfigurationen. `ArchiveCalendarCatalog` liefert
die DB-bevorzugten Kalenderlisten; `ArchiveTimelineSelector` entscheidet über
Bereich, Zieltag und Nächstsuche.

Der ausdrücklich optionale Alt-Cache ist ebenfalls getrennt:
`LegacyArchiveCache` kümmert sich um Jahresdateien, Frische, Suche, Rebuild und
Status. `LegacyArchiveCacheCodec` wandelt zwischen kurzen JSON-Schlüsseln und
den sprechenden Timeline-Feldern um. Die vielen Kommentare in diesen Klassen
erklären die Reihenfolge; die Kamera-SQLite bleibt trotzdem der bevorzugte
Normalweg. Neue Kalender- oder JSON-Caches gehören nicht hier hinein.

### Öffentliche JSON-API

```text
api/public.php
  -> URL in Segmente zerlegen und Session-Rate-Limit prüfen
  -> PublicCameraHandler, PublicBestOfHandler oder
     PublicTimelineHistoryHandler auswählen
  -> PublicTimelineHistoryHandler verteilt weiter an
     PublicTimelineHandler, PublicHistoryHandler oder PublicTimelapseHandler
  -> PublicTemporalRequest prüft gemeinsame Datums-/Referenzparameter
  -> Fachhandler delegiert an Presenter / ArchiveBrowser / CameraDataStore
  -> respond() sendet JSON
```

Der Einstiegspunkt besitzt nur noch Dispatcher, gemeinsame Antwortgrenze und
Fehlerabbildung. Kamera-/Statusdaten, Best-Of sowie Timeline/History liegen in
dokumentierten Handlern. Timeline, History und Timelapse teilen ausschließlich
die zustandslose Request-Normalisierung, während der bestehende
`PublicDayvideoController` für Tagesvideos zuständig bleibt. Der gemeinsame, IP-freie
SQLite-Limiter liegt in `include/session_rate_limit.php`; getrennte Scopes
verhindern, dass History- oder Captcha-Aufrufe das normale API-Budget
verbrauchen. Interne Fehler werden geloggt, nach außen aber nur als generischer
Fehler ausgegeben.

`api/history.php` ist ein Kompatibilitäts-Einstieg für History-Aufrufe. Neue öffentliche Funktionen gehören grundsätzlich in die zentrale Public-API, sofern die Architektur nichts anderes vorgibt.

Kleine JSON-State-Dateien sollen mit `webcam_atomic_write_json()` geschrieben
werden. Der Helfer veröffentlicht erst eine vollständig geschriebene Temp-Datei.
Davon ausgenommen sind Abläufe, die denselben bereits geöffneten Datei-Inode als
`flock`-Synchronisationsobjekt verwenden; dort muss unter dem gehaltenen Lock
in-place geschrieben werden.

Für Bilder, Sidecars und größere Kopien gibt es
`Infrastructure\Filesystem\AtomicFileWriter`. Er schreibt streamend in eine
eindeutige Temp-Datei direkt im Zielverzeichnis, prüft den fertigen Inhalt und
ersetzt den öffentlichen Zielnamen erst anschließend per Rename. Dadurch bleibt
bei einem Fehler eine schon vorhandene vollständige Datei unverändert.

### Admin-API

```text
admin/api.php
  -> Admin-Authentifizierung
  -> bei Änderungen zusätzlich CSRF-Prüfung
  -> route() wählt Aktion
  -> /config und /scripts an GlobalConfigAdminHandler
     -> Werte validieren und über ConfigStore speichern
     -> Wartungsdefinitionen und begrenzten Laufstatus lesen
  -> /archive-cache an ArchiveCacheAdminHandler
     -> Freigabe und tatsächlich gehaltenen Lock prüfen
     -> Rebuild ohne Shell und mit hartem Timeout ausführen
  -> alle /cameras-Routen geschlossen an CameraAdminHandler
     -> CameraConfigNormalizer begrenzt Kamera-Eingaben
     -> CapturePayloadNormalizer prüft Capture-Änderungen
     -> CameraCaptureQueryService beantwortet Capture-Suchen
     -> CameraCaptureEditor liest oder ändert die Kamera-SQLite atomar
     -> OverlayUploadService veröffentlicht Overlaybilder atomar
  -> /streams an StreamAdminHandler
     -> StreamState liest Viewer und ändert Disconnect-/IP-Sperr-State
  -> /status an StatusAdminHandler
     -> StatusService lesen und Capture-Alter pro Intervall einordnen
  -> ConfigStore oder CameraDataStore ändern Daten
  -> betroffenen Page-Cache invalidieren
  -> JSON-Antwort
```

Die Admin-API darf Daten verändern. Vor Änderungen an der Live-Config-DB ist deshalb immer eine ausdrückliche Freigabe nötig. Schreibende Routen verwenden POST, PUT oder DELETE; reine GET-Routen lesen nur.

### Befehl an eine externe Kamera

```text
externer Helper
  -> include/command.php?wc=<slug>
  -> Kamera und Schlüssel prüfen
  -> aktuellen Intervall-Slot berechnen
  -> Slot-Datei exklusiv sperren
  -> einmal "webcam_capture", sonst "wait"
```

Eine echte Capture-Antwort wird sofort beendet, damit der externe Helper nicht in ein Timeout läuft. Nur nach einer `wait`-Antwort kann der Server opportunistisch fällige Pull-Snapshot-Kameras abarbeiten. Ein globaler Pull-Lock verhindert doppelte parallele Runden.

### Upload einer Aufnahme

```text
externe Kamera
  -> Apache prüft zuerst die endpunktspezifische Request-Body-Grenze
  -> include/upload.php
  -> Methode, Kamera und Upload-Key prüfen
  -> freien Platz, PHP-Uploadfehler und Größenlimit prüfen
  -> UploadIngestor bestimmt Slot und sperrt gegen Doppelupload
  -> UploadIngestor ruft dateibasiert ImageStorage::storeSnapshotFile() auf
  -> Dateien + Kamera-SQLite + Status aktualisieren
  -> JSON-Antwort
```

`ImageStorage::storeSnapshot()` und `storeSnapshotFile()` halten denselben
Schreibablauf bewusst linear: Bild prüfen, Original und Varianten schreiben,
Metadaten erzeugen, Datenbank aktualisieren und aktuelle Live-Kopien
veröffentlichen. Die Orchestrierung delegiert EXIF an
`CaptureExifMetadata`, Varianten an `ImageVariantGenerator`, den Backup-Anstoß
an `CaptureBackupScheduler` und die fail-closed Retention an
`CacheRetentionManager`. Upload-Endpunkte verwenden die Dateivariante, damit kein
zweiter vollständiger JPEG-String im PHP-Speicher entsteht. Ein Fehler soll
sichtbar werden und darf nicht durch ein erfundenes Erfolgsresultat verdeckt
werden.

Ein neuer Kamera-Helper kann alternativ zuerst authentifiziert
`include/upload-chunk.php` mit `action=capabilities` fragen. Nur die genaue
Antwort `camera-rpi-chunk-v1` bedeutet Unterstützung. Danach speichert der
Server hashgeprüfte Teile wiederholbar und setzt sie bei `action=finalize`
zusammen. Die fertige Datei wird nicht nochmals vollständig in einen PHP-String
geladen. `ChunkUploadStore` ist nur für diesen Transport verantwortlich;
`UploadIngestor` enthält den gemeinsamen fachlichen Schlussweg, den auch
`include/upload.php` verwendet. Dadurch entstehen keine zwei unterschiedlichen
Regeln für Bildablage, Duplikate, Wetter oder Status.

Wichtig für die Sicherheitsgrenze: PHP sieht den Upload-Key erst nach Empfang
des Bodys. Deshalb setzt `.htaccess` vor beiden Einstiegen unterschiedliche
`LimitRequestBody`-Werte: 26 MiB für Legacy, 2 MiB für Chunk-v1. Diese
Webservergrenze ist absichtlich unabhängig vom späteren PHP-Limit und wird in
`ops/upload-request-hardening.md` zusammen mit dem vHost-lokalen Header-Guard
des öffentlichen Reverse Proxys erklärt.

### Direkte oder serverseitige Aufnahme

`bin/capture.php` ist der einfache CLI-Einstieg. Es wählt eine oder alle Kameras und ruft `CaptureManager::capture()` auf. `CaptureManager` unterscheidet drei Modi:

- **Active capture:** Ein externer Kamera-Endpunkt wird zum Auslösen angesprochen.
- **Pull snapshot:** Der Server lädt selbst ein Bild per HTTP oder RTSP und speichert es.
- **Passive upload:** Der Server wartet normalerweise auf einen externen Upload.

Fehler werden an `StatusService` und den Logger übergeben. Externe HTTP-, RTSP-, Wetter- und ffmpeg-Aufrufe brauchen harte Timeouts.

`CaptureSourcePriority` sitzt als kleine, gemeinsame Entscheidungsschicht vor
Pull-Capture und Upload-Ingest. Sie zählt ausschließlich fehlgeschlagene
Pull-Versuche und hält beide Quellen mit einem Lock auseinander. Solange Pull
Vorrang hat, bestätigt `UploadIngestor` externe Bilder als ignorierten No-op.
Nach der im Admin konfigurierten Fehlerzahl darf derselbe unveränderte
Uploadpfad als Fallback speichern; der nächste erfolgreiche Pull setzt die
Freigabe wieder zurück. `0` bedeutet, dass Active-Upload bei einer Pull-Kamera
niemals als Fallback verwendet wird. Auch `include/command.php` nutzt diese
Entscheidung und löst den externen Helper erst bei freigegebenem Fallback aus.

### Like und Best-Of

`like.php` validiert Kamera und Bild. Danach erzwingt
`include/like_request_guard.php` für diesen Legacy-Einstieg und für
`bestof-toggle.php` denselben Schutz: eine einmalig verbrauchbare
Captcha-Challenge sowie ein gemeinsames, vertrauenswürdig IP-basiertes
SQLite-Budget. Erst danach stellt der Einstieg die Session-ID bereit und
übergibt die DB-Arbeit an `LikeService`; ein Cookiewechsel allein kann dadurch
kein neues Sicherheitskontingent erzeugen.

`bestof-toggle.php` prüft zusätzlich den strengeren Best-Of-HTTP-/Dateivertrag.
`BestOfSourceResolver` besitzt die strenge Kamerapfadgrenze,
`BestOfCaptureRegistrar` den historischen DB-Fallback und
`BestOfAssetManager` Sidecar, Thumbnail, Log und Cachepflege.
`bestof-captcha.php` erzeugt die Grafik; `include/captcha_state.php` speichert nur
einen HMAC mit Ablaufzeit und verbraucht eine Challenge durch atomare Umbenennung
genau einmal. `BestOfLikeCoordinator` koordiniert die Best-Of-Datei mit dem
DB-Like. Da Dateisystem und SQLite keine gemeinsame Transaktion haben, schützt
ein bildbezogener Lock den Ablauf. Nur eine Datei, die der aktuelle Request neu
angelegt hat, darf bei einem späteren Fehler wieder entfernt werden.

### Livestream

```text
stream.php
  -> Kamera, Stream-Konfiguration und Clientidentität prüfen
  -> IP-Sperre / Admin-Trennung beachten
  -> MJPEG-Funktionen oder HLS-Funktionen wählen
  -> Viewer-Slot bzw. HLS-Client registrieren
  -> ffmpeg kontrolliert starten und Ausgabe streamen
  -> Prozess und Locks zuverlässig schließen
```

`StreamState` koordiniert nur kurzlebigen Viewer-State. Pfade/JSON liegen im
`StreamRuntimeStore`, Proxyketten im `StreamClientIdentityResolver`, Sperren in
`StreamBlocklist` und aktive Viewer im `StreamClientRegistry`. Weitergeleitete
IP-Header werden ausschließlich hinter konfigurierten vertrauenswürdigen
Proxies verwendet. Probe-Aufrufe prüfen die Belegung, ohne selbst einen
MJPEG-Slot zu belegen.
`stream.php` enthält dabei nur noch HTTP-Auswahl und gemeinsame Adapter.
`src/Service/Stream/mjpeg_functions.php` kapselt Slot-Locks und Prozesspflege;
`hls_functions.php` kapselt Playlist, Segmente und den geteilten Runner.

Wichtig sind zwei verschiedene Ebenen: In `camera.php` waehlen
`?stream=hls` und `?format=hls` gleichwertig die HLS-Darstellung der Seite aus.
Das Template laesst dann den MJPEG-Endpunkt leer und markiert die Ansicht als
„Livestream HLS“. Erst Browserzugriffe auf `stream.php?format=hls` liefern die
Playlist beziehungsweise Segmente und aktualisieren den HLS-Viewer-State.
Der gemeinsame HLS-Runner hält ffmpeg nicht ohne Zuschauer bis zum allgemeinen
Laufzeitlimit offen: Standardmäßig beendet er den Prozess 45 Sekunden nach dem
letzten frischen Viewer-Heartbeat und räumt PID-Datei sowie Prozess-Lock
kontrolliert auf.

## 7. Zentrale Klassen und ihre Verantwortung

| Klasse | Aufgabe |
| --- | --- |
| `Webcam\App` | Verbindet Konfiguration, Pfade, Repository, Logger und Services. |
| `Domain\Camera` | Typisierter Zugriff auf die Einstellungen genau einer Kamera. |
| `Domain\CameraRepository` | Lädt Kameras aus der generierten Config-Darstellung und findet sie per Slug. |
| `Infrastructure\Filesystem\PathResolver` | Baut Pfade ausschließlich aus konfigurierten Basisverzeichnissen. |
| `Infrastructure\Filesystem\CameraStoragePathResolver` | Normalisiert Kamera-Storage-Roots und löst geprüfte DB-/Sidecar-Relativpfade einheitlich auf. |
| `Infrastructure\Storage\CameraDataStore` | Kompatible Fassade vor den getrennten Kamera-DB-Repositories. |
| `Infrastructure\Storage\CameraDataConnection` | Teilt Verbindung, Retry, Schreiblock und Staging zwischen allen Kamera-DB-Repositories. |
| `Infrastructure\Storage\CameraDataSchemaRepository` | Verwaltet Sollschema und additive Kamera-DB-Migrationen. |
| `Infrastructure\Storage\CameraCaptureRepository` | Kompatible Fassade für Capture-Lese- und Schreibzugriffe. |
| `Infrastructure\Storage\CameraCaptureWriter` | Schreibt Capture, Varianten, EXIF und Wetter atomar und gleicht Duplikate ab. |
| `Infrastructure\Storage\CameraCaptureReader` | Liest Pfade, Slots, Kalender, Zeitbereiche und zeitlich nächste Captures. |
| `Infrastructure\Storage\CameraLikeBestOfRepository` | Verbucht Likes atomar und liefert Like-Zähler sowie Top-Likes. |
| `Infrastructure\Storage\CameraWeatherLogoRepository` | Aktualisiert Wetterzeilen, berücksichtigt Qualitätsflags und verwaltet Logo-Status. |
| `Infrastructure\Config\ConfigStore` | Liest/schreibt die zentrale Konfiguration und signalisiert Reloads. |
| `Infrastructure\Config\ConfigDatabaseConnection` | Öffnet und migriert die Config-SQLite mit passenden Read-/Write-Regeln. |
| `Infrastructure\Config\ConfigDocumentHydrator` | Baut aus SQL-Zeilen das vollständige Konfigurationsdokument. |
| `Infrastructure\Config\ConfigDocumentPersister` | Schreibt normalisierte Dokumentabschnitte kontrolliert in SQLite. |
| `Infrastructure\Process\BoundedProcessRunner` | Startet kurzlebige externe Prozesse mit Argumentliste, stdin, Ausgabelimit, Timeout und sicher beendetem Prozessbaum. |
| `Infrastructure\View\Renderer` | Übergibt Daten sicher an ein Template und fängt dessen Ausgabe als String ab. |
| `Service\View\CameraPagePayloadBuilder` | Hält gemeinsame Kamera-Templatewerte, Leerzustände und die geordnete Browser-Assetliste für alle Ansichten zusammen. |
| `Service\View\CameraPresenter` | Formt Kamera- und Statusdaten für Ansichten um. |
| `Service\View\CameraOverlayMetadata` | Ermittelt Tag/Nacht und gültige zeitabhängige Bildlabels. |
| `Service\View\CameraWeatherMetadata` | Bereinigt Wettertext und berücksichtigt read-only Qualitätsmarker. |
| `Service\View\CameraTimelineCalendar` | Findet Nachbartage in bereits geladenen Kalender-Maps. |
| `Service\View\CameraTimelineNavigationBuilder` | Lädt schmale Navigationsdaten und baut das Timeline-View-Model. |
| `Service\Storage\ArchiveBrowser` | Liefert Timeline, Kalendernavigation und Archivdaten; DB zuerst. |
| `Service\Storage\ArchiveCalendarCatalog` | Liefert Jahre, Monate, Tage und Stunden ohne zweite Kalenderlogik. |
| `Service\Storage\ArchiveTimelineSelector` | Wählt Bereiche und löst leere Tage über die Nächstsuche auf. |
| `Service\Storage\TimelineNavigator` | Wählt Zeitpunkte und Nachbareinträge ohne DB- oder Dateisystemzugriff. |
| `Service\Storage\ArchiveEntryImageResolver` | Wählt eine auslieferbare Bildvariante für Timeline-Vergleiche. |
| `Service\Storage\ArchiveStoragePaths` | Normalisiert gemeinsame Archivroots und historische Slug-Pfadformen. |
| `Service\Storage\LegacyArchiveCache` | Verwaltet den ausdrücklich optionalen Archive-Cache. |
| `Service\Storage\LegacyArchiveCacheCodec` | Übersetzt Cache-JSON und normale Timeline-Einträge ineinander. |
| `Service\Storage\ImageStorage` | Prüft, schreibt und veröffentlicht Bilder, Varianten und Metadaten. |
| `Service\Storage\ImageVariantGenerator` | Erzeugt die festen JPEG-Größen und prüft ihre Ergebnisse. |
| `Service\Storage\CaptureExifMetadata` | Liest EXIF und normalisiert die gespeicherten Metadaten. |
| `Service\Storage\CaptureBackupScheduler` | Kapselt ausschließlich den bestehenden Backup-Anstoß. |
| `Service\Storage\CacheRetentionManager` | Führt verifizierte, fail-closed Cache-Retention aus. |
| `Service\Storage\BestOfGallery` | Koordiniert Best-Of-Seiten und ihre Ausgabe. |
| `Service\Storage\BestOfFileCatalog` | Sichtet, sortiert und dedupliziert Best-Of-Dateien. |
| `Service\Storage\BestOfLikeRepository` | Liest die Like-Zuordnung für Galerieeinträge. |
| `Service\Storage\BestOfGalleryCache` | Verwaltet den vorhandenen Galerie-JSON-Cache und Fingerprint. |
| `Service\Storage\CameraDatabaseMigrationSource` | Lädt Backfill-Einträge DB-first oder ausdrücklich aus einem Legacy-Dateibaum. |
| `Service\Storage\CameraDatabaseMigrationPayloadBuilder` | Prüft Quelldateien und formt Capture-, Varianten-, EXIF- und Wetterdaten für den Import. |
| `Service\Storage\CameraDatabaseRebuildManager` | Stagt einen vollständigen Neuaufbau lokal und aktiviert ihn nach Erfolg atomar. |
| `Service\Backup\SqliteBackupService` | Erzeugt, prüft und veröffentlicht SQLite-Online-Snapshots samt Retention. |
| `Service\Backup\DailyDatabaseBackup` | Wählt Config-/Kameraziele und führt die Datenbank-Sicherungen eines Tageslaufs aus. |
| `Service\Backup\BackupRunState` | Koordiniert Backup-Lock, Tages-Deduplizierung und getrennten Voll-/Teilstatus. |
| `Service\Backup\SidecarBackup` | Sichert physische Wetter- und EXIF-Textdateien gestagt, geprüft und atomar. |
| `Service\Capture\CaptureManager` | Wählt und steuert den Capture-Ablauf je Kameramodus. |
| `Service\Status\StatusService` | Koordiniert Statusspeicher und Wetteranreicherung als schmale öffentliche Fassade. |
| `Service\Status\CameraStatusStore` | Liest und schreibt den kleinen JSON-Status unter gemeinsamem Dateilock und protokolliert Statusereignisse. |
| `Service\Status\StatusWeatherReader` | Liest aktuelle Wetter-Sidecars sowie Qualitätsdaten aus der Kamera-SQLite, ohne Status zu verändern. |
| `Service\Interaction\LikeService` | Prüft Session-/Rate-Limit-Regeln und verbucht Likes. |
| `Service\Interaction\BestOfLikeCoordinator` | Hält Best-Of-Datei und DB-Like bei Parallelzugriffen konsistent. |
| `Service\Interaction\BestOfSourceResolver` | Bindet Cache-/Archiv-/Galeriepfade strikt an die gewählte Kamera. |
| `Service\Interaction\BestOfCaptureRegistrar` | Registriert fehlende historische Captures für Likes. |
| `Service\Interaction\BestOfAssetManager` | Pflegt Best-Of-Sidecar, Thumbnail, Log und Cacheinvalidierung. |
| `Service\Stream\StreamState` | Kompatible Fassade für den kurzlebigen Viewer-State. |
| `Service\Stream\StreamRuntimeStore` | Besitzt Stream-Pfade und atomare Runtime-Dateien. |
| `Service\Stream\StreamClientIdentityResolver` | Ermittelt Clientidentitäten aus vertrauenswürdigen Proxyketten. |
| `Service\Stream\StreamBlocklist` | Verwaltet die persistente Stream-IP-Sperrliste. |
| `Service\Stream\StreamClientRegistry` | Verwaltet MJPEG-/HLS-Viewer und Disconnect-Marker. |
| `Service\Weather\WeatherCommandRunner` | Führt konfigurierte Wetterkommandos mit Zeit- und Ausgabelimit aus. |
| `Service\Weather\WeatherSidecarParser` | Findet, liest und plausibilisiert historische Wetter-Sidecars. |
| `Service\Weather\WeatherSidecarImporter` | Koordiniert Dry-Run und batchweisen Sidecarimport. |
| `Service\Weather\WeatherSidecarBatchWriter` | Ersetzt Wetterzeilen transaktional unter dem Kamera-DB-Lock. |
| `Service\Weather\WeatherQualityAnalyzer` | Erkennt Lücken, Grenzwerte und starre Wetterreihen und synchronisiert Marker. |

## 8. Lesen oder Schreiben?

Vor einer Änderung sollte klar sein, ob der betroffene Code nur liest oder etwas verändert.

Typische reine Leser:

- `index.php` und normale GET-Routen in `api/public.php`,
- `CameraPresenter`,
- read-only erzeugte `CameraDataStore`-Instanzen,
- die meisten Timeline-Abfragen in `ArchiveBrowser`.

Typische Schreiber mit Nebenwirkungen:

- `include/upload.php` und `ImageStorage`,
- schreibende Routen in `admin/api.php`,
- `LikeService` und `BestOfLikeCoordinator`,
- Backup-, Migrations-, OCR- und Cleanup-Programme unter `bin/`,
- Stream-State und Capture-/Scheduler-State unter `var/state/`.

Ein Konstruktoraufruf wie

```php
new CameraDataStore($app, $camera, null, true)
```

öffnet die Kamera-Datenbank ausdrücklich read-only. Ohne das letzte `true` kann die Instanz für Schreiboperationen verwendet werden. Ob wirklich geschrieben wird, entscheidet anschließend die aufgerufene Methode.

## 9. So verfolgt man eine Funktion durch den Code

Beispiel „Woher kommt die Timeline?“:

1. In `camera.php` nach `timeline(` oder `archiveBrowser()` suchen.
2. Den Aufruf und die übergebenen Filter ansehen.
3. In `ArchiveBrowser::timeline()` wechseln.
4. Dort zuerst die DB-Abfragen und danach ausdrücklich markierte Dateisystem-Fallbacks unterscheiden.
5. Suchen, wo das Ergebnis an `templates/camera.php` übergeben wird.
6. Für Browser-Interaktionen anschließend die zugehörigen Dateien unter `assets/` prüfen.

Beispiel „Was passiert nach einem Upload?“:

1. `include/upload.php` von oben nach unten lesen: Methode, Authentifizierung, Limits, Slot und Lock.
2. Den Aufruf von `ImageStorage::storeSnapshot()` öffnen.
3. Dort den linearen Schreibablauf verfolgen.
4. Für DB-Felder `CameraDataStore::recordCapture()` prüfen.
5. Für sichtbaren Laufzeitstatus die Aufrufe von `StatusService` und die Logs prüfen.

Praktische Suchbefehle:

```bash
rg -n "archiveBrowser|timeline\(" camera.php src
rg -n "storeSnapshot" include bin src
rg -n "recordCapture" src include bin
rg -n "function route" api/public.php admin/api.php
```

Bei einer vollständigen Workspace-Suche müssen auch ignorierte Dateien einbezogen werden, zum Beispiel mit `rg -uuu`. Große Archivbäume sollten dabei gezielt eingegrenzt und nicht blind rekursiv gescannt werden.

## 10. Sicher ändern

Für eine überschaubare Änderung ist dieser Ablauf sinnvoll:

1. Einstiegspunkt und betroffene Klasse bestimmen.
2. Eingaben, Rückgabewert und Nebenwirkungen notieren.
3. Bevorzugte Datenquelle von Legacy-Fallbacks trennen.
4. Nur den kleinsten zusammenhängenden Teil ändern.
5. Fehler sichtbar lassen, keine stillen Ersatzwerte ergänzen.
6. Neue Dateipfade über `PathResolver` beziehen.
7. Kurzlebige externe Prozesse über `BoundedProcessRunner` mit Argumentliste,
   Timeout und begrenzter Ausgabe ausführen. Shell-Syntax nur sichtbar über
   `/bin/sh -c`; Secrets über stdin statt Argumente übergeben.
8. PHP-Syntax und passende Tests ausführen.
9. UI-Änderungen zusätzlich im Browser und in relevanten Viewports prüfen.

Schnelle Syntaxprüfung:

```bash
find src admin api include config status templates bin -name '*.php' -print0 \
  | xargs -0 -n1 php -l
```

Test-Suite:

```bash
php vendor/bin/phpunit
```

Je nach Änderung kommen gezielte Integrationstests, `php bin/watchdog.php` im Dev-Kontext und Playwright-Tests hinzu. Live-DBs und das read-only HDD-Archiv dürfen nicht als beiläufige Testziele verwendet werden.

## 11. Warum große Dateien nicht sofort komplett zerlegt werden

Historisch gewachsene Einstiegspunkte und Services werden nur entlang klarer
Verantwortungen getrennt. Die bisherigen großen Blöcke in `admin/api.php`,
`api/public.php`, `stream.php`, `ArchiveBrowser`, `ImageStorage`,
`BestOfGallery`, `config/camera_store.php` und der Übersicht sind inzwischen
in benannte, kommentierte Komponenten zerlegt. Verbleibende große Dateien
werden weiterhin erst mit reproduzierbaren Tests und klarer fachlicher Grenze
geteilt.

Darum gilt: Wenn eine konkrete Funktion geändert wird, kann genau dieser Abschnitt in eine klar benannte Funktion oder Klasse ausgelagert werden. Vorher wird das bestehende Verhalten mit einem Test oder einem reproduzierbaren Aufruf festgehalten. So wird die Codebasis schrittweise verständlicher, ohne nebenbei unbemerkte Funktionsänderungen einzuführen.
