# Technische Architektur

Stand: 01.09.2026

Diese Übersicht beschreibt die derzeitige Soll-Architektur. Historische Entscheidungen und frühere Umsetzungen bleiben in `CHANGELOG.md`, `AUDIT.md` und `issues.md` erhalten.

Für einen Einstieg ohne tiefe PHP-Vorkenntnisse erklärt der [PHP-Code-Wegweiser](PHP-CODE-WEGWEISER.md) dieselben Bausteine anhand konkreter Request-, Datenbank- und Speicherabläufe.

## 1. Systemgrenzen

Die Anwendung ist eine klassische PHP-Anwendung ohne separates Python-Backend. Apache oder der PHP Built-in Server führt die Entry-Points direkt aus. SQLite hält Konfiguration und Bildmetadaten; Bilder liegen im Dateisystem.

Hauptbereiche:

| Bereich | Verantwortung |
| --- | --- |
| `index.php` | Öffentliche Kameraübersicht |
| `camera.php` | Kamera-, Archiv-, Playback- und Best-Of-Ansicht |
| `bestof.php` | Kameraübergreifendes Best Of in zeitlicher Reihenfolge |
| `weather.php` | Kameraübergreifende Wetterauswertung |
| `status/index.php` | Öffentliche Kamera- und Systemzustände |
| `api/public.php` | Dünner Dispatcher der öffentlichen Lese-API |
| `src/Http/Public/` | Kamera-, Best-Of-, Timeline-, History- und Timelapse-Handler |
| `src/Http/PublicDayvideoController.php` | Dünner HTTP-Adapter für öffentliche Tagesvideos |
| `admin/index.php` | Admin-Oberfläche |
| `admin/api.php` | Authentifizierte Admin-API |
| `include/command.php` | Polling und Capture-Befehl für externe Kameras |
| `include/upload.php` | Upload und Speicherung aktiver Captures |
| `include/upload-chunk.php` | Ausgehandelte, wiederholbare Teiluploads aktiver Captures |
| `stream.php` | MJPEG-/HLS-Streaming und Clientbegrenzung |
| `bin/` | Wartung, Migration, Backup, OCR und Diagnose |

Die Kameraansicht waehlt einen Stream bewusst mit
`/camera/<slug>?stream=hls` oder dem gleichwertigen Alias `?format=hls` direkt
als HLS. Beide Varianten betreffen `camera.php`: Sie unterdruecken den
MJPEG-Endpunkt, setzen die HLS-Praeferenz und zeigen „Livestream HLS“. Der
eigentliche HLS-Endpunkt bleibt davon getrennt
`/stream/<slug>?format=hls`; dessen `probe=1` prueft die Verfuegbarkeit.

Die öffentliche Übersicht wird in `index.php` vorbereitet und von
`templates/overview.php` serverseitig ausgegeben. Startseite, eigenständige
globale Best-Of-Route, Status- und Wetterseite teilen sich
`templates/overview_header.php`, damit Kopfbreite, Typografie und Abstände beim
Wechsel identisch bleiben. Die Kopfzeile ist jeweils ein eigenständiger
Orientierungsblock; Galerie, Status und Wetterdaten beginnen in einer davon
getrennten Inhaltsfläche. Die Soll-Reihenfolge der Startseite ist:

```text
kompakte Kopfzeile: Titel + overview_hero | Globales Best-Of + Status + Wetter
Webcam-Karten
Layout-/Favoriten-Regler (rechtsbündig unter den Karten)
Zuletzt angesehen (lokaler Browser-State)
Karte
```

Karten enthalten keine Schnellansicht und keinen separaten Block für letzte
Auslösung, Intervall oder Koordinaten. Tags und Status-Badges bleiben sichtbar.
Der konfigurierbare Kartenausschnitt kommt aus `overview_map`; Head- und
Kamera-Links bleiben dokumentrelativ.

Die Browserlogik der Startseite wird von `assets/js/overview.js` nur noch
koordiniert. Die fachlichen Teile liegen in `overview-cards.js` für
Kartenstatus und Favoriten, `overview-recent.js` für die lokale Verlaufsliste,
`overview-map.js` für Leaflet und `overview-lightbox.js` für den Bilddialog.
`index.php` lädt diese vier Module vor dem Koordinator und bereitet Marker und
Kartenkonfiguration als Daten vor; `templates/overview.php` schreibt sie
zusammen mit API-Pfad und Zeitzone in einen gehärteten
`application/json`-Block. Es gibt keinen zweiten ausführbaren
Inline-Initialisierungspfad und keinen zweiten veränderlichen Seitenzustand.

Die Admin-Oberfläche behält ihren einzigen veränderlichen Browserzustand in
`assets/js/admin.js`. Der Kamera- und Livestreamstatus ist als erster fachlicher
Schnitt in `assets/js/admin-status.js` gekapselt. Das Modul erhält API-, CSRF-,
Formatierungs- und Renderfunktionen über einen kleinen Adapter, lädt Admin-
Status und Public-Fallback, rendert den Statusabschnitt und behandelt nur seine
Stream-Aktionen. Es erzeugt keinen zweiten Zustand und kennt die übrigen
Admin-Formulare nicht. `admin/index.php` lädt die Komponente vor `admin.js`;
ein gemeinsamer Iterationsparameter verhindert inkompatible Cache-Mischungen.
Der gleiche serverseitig gelesene Iterationswert wird als Data-Attribut an den
Admin-Einstieg übergeben und in der Orientierungsschiene neben dem Config-Stand
angezeigt; die Anzeige erfindet keinen eigenen Versionszustand im JavaScript.

`assets/js/admin-maintenance.js` bildet den zweiten Admin-Schnitt. Es verwaltet
die sichtbaren Scheduler-Defaults, übernimmt die normalisierte Scriptantwort,
rendert Konfiguration und letzte Laufzustände, validiert das Formular und
delegiert den gespeicherten Payload an dieselbe Admin-API. Das Modul startet
keine Prozesse im Browser: Ausführung, Pfadprüfung und Tageszustand bleiben
serverseitig. Eigene Scriptkarten können ergänzt oder entfernt werden; die drei
fachlich erforderlichen eingebauten Aufgaben behalten stabile Schlüssel.

`assets/js/admin-archive-cache.js` kapselt als dritten Schnitt ausschließlich
das optionale Diagnosewerkzeug für den alten Timeline-Cache. Die Komponente
führt Status aus Config- und Diagnose-API zusammen, rendert Umschalter,
Rebuild-/Dry-Run-Formular und Laufstatus und pollt nur im sichtbaren Status-Tab
solange ein Job tatsächlich läuft. Admin-Freigabe, Rebuild-Lock,
Pfadauflösung und Prozessstart bleiben serverseitig; API-Statusmeldungen werden
vor der HTML-Ausgabe maskiert und Ladefehler sichtbar statt still verworfen.

`assets/js/admin-settings.js` ist der vierte Admin-Schnitt und bündelt die
installationsweiten Formulare für Übersichts-Teaser und -Karte,
Wetter-Command-Timeout, Timeline-Grenze und IP-basiertes Public-API-Limit.
Fehlende Konfigurationsblöcke älterer Installationen werden nur für die
Anzeige mit dokumentierten Defaults ergänzt. Erst ein ausdrücklicher Submit
sendet einen validierten Payload über den gemeinsamen API-/CSRF-Adapter;
Backendvalidierung und Persistenz bleiben unverändert in der Admin-API.
Die Komponente maskiert dynamische Werte und besitzt weder Kameraeditor noch
eigenen Browserzustand.

`assets/js/admin-capture-editor.js` bildet den fünften Admin-Schnitt. Es lädt
die kompakte Vorschau der aktiven Kamera-SQLite, ermittelt verfügbare Jahre,
sucht einen Capture nach Slot oder Datum/Uhrzeit und rendert dessen skalare
Metadaten sowie Varianten-, EXIF- und Wetterrelationen. Der Browser akzeptiert
Relationsänderungen nur als JSON-Arrays und delegiert den geprüften Payload an
den gemeinsamen Admin-API-/CSRF-Adapter. Bilddateien werden in diesem Ablauf
weder hochgeladen noch gelöscht. Pro Kamera verhindern Ladeflags doppelte
Preview- und Jahresrequests bei unmittelbar aufeinanderfolgenden Neurenders.

`assets/js/admin-map-preview.js` bildet den sechsten Admin-Schnitt. Die
Komponente liest Standort, Ausrichtung, Sichtfeld, Reichweite und
Kartenzentrumsversatz aus dem aktiven Kameraformular und zeichnet daraus eine
Leaflet-Karte oder einen netzunabhängigen Canvas-Fallback. Sie persistiert
nichts; Speichern und Backendvalidierung bleiben im zentralen Kameraablauf.
`init()` räumt einen vorherigen Lauf zuerst vollständig ab, `destroy()` entfernt
Leaflet-, Resize- und Eingabe-Listener auch bei wiederholten Tabwechseln.

`assets/js/admin-overlay-editor.js` bildet den siebten Admin-Schnitt. Helle und
dunkle Logoformulare verwenden darin dieselbe Variantenbeschreibung für
Rendering, URL-Auflösung, Upload, Vorschau, Reset und Payload-Normalisierung.
Der Upload nutzt als `FormData` den gemeinsamen Admin-API-/CSRF-Adapter; der
Browser setzt die Multipart-Boundary, während Dateiformat, Zielpfad,
Atomizität und öffentliche `overlay.php`-Route weiterhin ausschließlich im
PHP-Backend geprüft beziehungsweise festgelegt werden. Die Komponente kopiert
einen bestätigten Upload vor dem durch Meldungen ausgelösten Neurender in den
zentralen Browserzustand, persistiert ihn aber erst beim bewussten Speichern.

`assets/js/admin-label-editor.js` bildet den achten Admin-Schnitt. Die
Komponente rendert öffentliche Bildlabels als maskierte Tabellenzeilen,
normalisiert Pflichtfelder, Position, Link, Farbe, Textkontrast,
Auflösungsschwelle und Gültigkeitsfenster und verwaltet Hinzufügen, Entfernen
sowie den Leerzustand. Sie besitzt weder API noch eigenen Configzustand: Der
zentrale Kameraablauf übergibt den gesammelten Payload, und `admin/api.php`
prüft alle Werte nochmals verbindlich. Delegierte Listener werden pro neu
gerenderter Editorinstanz genau einmal gebunden.

`assets/js/admin-form-validation.js` bildet den neunten Admin-Schnitt. Die
zustandslose Komponente ergänzt die nativen Formularfelder um verständliche
Slug-, Text- und Pfadmeldungen, markiert Fehler, öffnet den betroffenen
Kamera-Untertab und fokussiert das erste ungültige Feld. Sie synchronisiert
außerdem Sichtbarkeit und Disabled-Zustand der RTSP-Zusatzfelder. Direkte
Feldlistener werden pro Formularknoten, der Transportwechsel einmal an der
stabilen Admin-Wurzel gebunden. Persistenz und verbindliche Validierung bleiben
vollständig in der Admin-API.

`assets/js/admin-camera-editor.js` bildet den zehnten Admin-Schnitt. Der rein
darstellende Baustein löst die ausgewählte Kamera auf, bereitet ein gemeinsames
View-Model vor und rendert daraus die fachlichen Tabs für Allgemein, Texte,
Live-Ansicht, Standort, Aufnahme, Speicher, Overlay, Labels, Wetter, Watchdog
und die lesende DB-Diagnose. Alle direkt eingesetzten Configwerte werden
maskiert. Overlay-, Label- und Capture-Diagnose kommen über kleine injizierte
Renderer; DOM-Ereignisse, Formularvalidierung, Payload-Aufbau, API und der
einzige veränderliche Browserzustand bleiben in `admin.js`.

`assets/js/admin-camera-payload.js` bildet den elften Admin-Schnitt. Die
zustandslose Komponente synchronisiert den Rich-Text-Editor, liest genau einen
FormData-Snapshot und übersetzt dessen Werte über zehn fachliche Builder in
Teil- oder Gesamtpayloads. Standort, Capture/Stream, Speicher, Wetter und
Watchdog haben dadurch keine getrennte zweite Feldzuordnung mehr. Overlay und
Labels werden an ihre bestehenden Prüfer delegiert; API-Aufruf, CSRF,
Speicherflags, Meldungen und abschließende Backendvalidierung bleiben im
zentralen Admin-Ablauf.

`assets/js/admin-page.js` bildet den zwölften Admin-Schnitt. Er setzt die
fertigen Meta-, Status-, Script-, Rate-Limit-, Archivcache- und Kameraabschnitte
zum Admin-Seitengerüst zusammen. Kleine Funktionsblöcke erklären sichere
Tabellenwerte, Haupttabnormalisierung, DOM-Austausch, direkte Listener auf neu
gerenderten Knoten, einmalige Delegation am stabilen Wurzelelement und den
anschließenden Start der sichtbaren Editoren. API, Payloads und der einzige
veränderliche Browserzustand verbleiben in `admin.js`.

`src/Service/Admin/CameraConfigNormalizer.php` ist die zustandslose
Sicherheitsgrenze hinter den Kamera-Schreibrouten. Sie ergänzt gemeinsame
Defaults, prüft Transport-, Pfad-, Karten-, Overlay- und Labelwerte und lässt
bei Teilupdates nur die Felder des ausgewählten Editortabs passieren. Die
Klasse kennt keine HTTP-Antwort, keinen ConfigStore und kein Dateisystem.
`CameraOverlayNormalizer` besitzt dabei ausschließlich URL, Maße, Versatz und
Deckkraft der beiden Logoebenen; `CameraLabelNormalizer` prüft Hotspottexte,
Links, Farben, Gültigkeit und Position. So bleiben diese beiden umfangreichen
Listenregeln unabhängig vom allgemeinen Kamera-Payload testbar.
`CameraAdminHandler` fängt ihre `InvalidArgumentException` ab, ordnet HTTP 400
zu und übernimmt erst danach Persistenz und Page-Cache-Invalidierung.

`src/Service/Admin/CapturePayloadNormalizer.php` bildet dieselbe zustandslose
Grenze für den Capture-Editor. Die Klasse übernimmt ausschließlich erlaubte
Capture-Spalten, bindet relative Bildpfade an den vollständigen Kameraslug und
begrenzt Varianten-, EXIF- und Wetterlisten. Nicht gesendete Listen bleiben
unangetastet; eine ausdrücklich leere Liste bedeutet dagegen bewusstes
Löschen. Fachfehler erreichen SQLite nicht und werden von der Route als HTTP
400 ausgegeben.

`src/Service/Admin/CameraCaptureEditor.php` kapselt die zugehörige
Datenbankarbeit: kleine Vorschau, vollständiger Slot, stabile API-
Serialisierung und atomare Änderungen. Capture-Hauptzeile und die tatsächlich
gesendeten Kindlisten teilen Schreiblock und SQLite-Transaktion. HTTP-Ausgabe
und Authentifizierung verbleiben in `admin/api.php`; Statusabbildung, Logging
und Like-Sidecars liegen im `CameraAdminHandler`.

`src/Service/Admin/CameraCaptureQueryService.php` kapselt die lesende
Capture-Suche des Editors. Die Klasse normalisiert Slot-, Zeit-, Kalender-,
Such- und Paginierungsparameter, bestimmt den DB-Zeitbereich und erzeugt über
`CameraCaptureEditor` ein einheitliches Detail- oder Listenmodell. `latest` und
die Auswahl des zeitlich nächsten Captures arbeiten vor der Pagination. Der
Service kennt weder `$_GET` noch HTTP und fügt keinen zusätzlichen Cache ein;
`CameraAdminHandler` übersetzt Parameterfehler und fehlende Slots in 400
beziehungsweise 404.

`src/Http/Admin/CameraAdminHandler.php` ist der gemeinsame HTTP-Adapter für
alle Routen unter `/cameras`. Er ordnet Sammlung, Kameraobjekt, Abschnitt,
Jahresliste, DB-Vorschau, Capture und Overlay jeweils einem vorhandenen
Fachservice zu und liefert ein `AdminRouteResult`, ohne selbst Header zu senden
oder den Prozess zu beenden. Der JSON-Reader wird verzögert übergeben, damit
Pfad- und Existenzfehler wie bisher vor dem Body entschieden werden. Die
zentrale Authentifizierung und CSRF-Prüfung bleiben bewusst im globalen Router.

`src/Http/Admin/GlobalConfigAdminHandler.php` bündelt die globalen
Konfigurationsrouten und die daraus abgeleitete Wartungsübersicht. Der Handler
normalisiert Timeline-, Runtime-, Karten- und Rate-Limit-Werte, prüft
konfigurierbare Wartungskommandos gegen `bin/` und speichert über ConfigStore.
Sein kleiner Dokumentcache lebt nur für den einzelnen Request. JSON wird über
einen verzögerten Reader erst im tatsächlich passenden PUT-Zweig gelesen.

`src/Http/Admin/ArchiveCacheAdminHandler.php` isoliert das optionale
Legacy-Archivcache-Werkzeug vom normalen Router. Admin-Freigabe, aktivierte
Option, State und tatsächlich gehaltener `flock` werden vor dem Prozessstart
geprüft. Der Rebuild erhält ausschließlich eine feste Argumentliste und läuft
über `BoundedProcessRunner` mit Prozessgruppe, Timeout und begrenzter Ausgabe.
Der normale Timelinepfad bleibt davon unabhängig und verwendet weiterhin die
Kamera-SQLite ohne neuen Cache.

`src/Http/Admin/StreamAdminHandler.php` bildet den HTTP-Vertrag für die
kurzlebige Stream-Verwaltung. Er liefert Viewer und IP-Sperren, gruppiert die
Übersicht pro Kamera und ordnet Trennen, Sperren sowie Freigeben den bestehenden
Methoden von `StreamState` zu. Der JSON-Body wird erst nach exakter Routenwahl
gelesen. MJPEG-Locks, HLS-Heartbeats, Identitäten und Runtime-Dateien bleiben
ausschließlich Eigentum von `StreamState`.

`StreamState` ist dabei nur noch die kompatible Fassade: `StreamRuntimeStore`
ordnet Runtime-Pfade und atomare JSON-Dateien zu, `StreamClientIdentityResolver`
wertet vertrauenswürdige Proxyketten aus, `StreamBlocklist` besitzt die
persistente IP-Sperrliste und `StreamClientRegistry` verwaltet aktive MJPEG-/HLS-
Clients sowie kurzlebige Disconnect-Marker. Das Dateiformat und die öffentliche
Admin-Schnittstelle bleiben unverändert.

`src/Http/Admin/StatusAdminHandler.php` bereitet `GET /status` auf. Er liest
jede Kamera über den schmalen `StatusService`, berechnet alle Alterswerte
gegen denselben Request-Zeitpunkt und ordnet sie relativ zum Capture-Intervall
in `unknown`, `ok`, `warn` oder `error` ein. Der Handler ist read-only und legt
keinen zusätzlichen Statuscache an. Innerhalb der Fassade besitzt
`CameraStatusStore` JSON-Datei, Lock, atomaren Schreibpfad und Ereignislog;
`StatusWeatherReader` liest aktuelle Sidecars und Wetterqualitätsdaten aus der
Kamera-SQLite. Dadurch bleiben Persistenz und read-only Anreicherung getrennt.

Die öffentliche Statusseite bleibt serverseitig gerendert, enthält aber keine
Inline-Programmlogik mehr. `status/index.php` bindet `assets/js/status.js` ein,
`templates/status.php` liefert ausschließlich HTML und ein sicher kodiertes
JSON-Startmodell. Das JavaScript besitzt den minütlichen API-Abgleich und den
Detaildialog. Damit bleibt die Seite auch ohne JavaScript lesbar, während
Refresh- und Modalverhalten getrennt getestet und erweitert werden können.

Damit enthält `admin/api.php` keine fachliche Admin-Routenimplementierung mehr.
Der Einstiegspunkt bleibt bewusst für Bootstrap, Pfadauflösung, Health,
Authentifizierung, CSRF, Handler-Dispatch, JSON-Lesen und die einzige zentrale
HTTP-Fehler-/Antwortgrenze zuständig.

`src/Service/Overlay/OverlayUploadService.php` bildet die Dateisystemgrenze
hinter dem administrativen Multipart-Upload. `CameraAdminHandler` bestätigt die
echte PHP-Uploaddatei und übersetzt Fachfehler in HTTP. Der Service prüft reale
und gemeldete Größe, erkennt PNG/JPEG aus Inhalt und Bildkopf, erzeugt einen
sicheren Namen, teilt den vorhandenen Overlay-Lock mit der Bereinigung und
publiziert per `AtomicFileWriter`. Sein Rückgabemodell enthält weiterhin die
interne Speicherreferenz für den Admin-Editor, als öffentliche URL aber
ausschließlich die gehärtete dokumentrelative Route `overlay.php`.

In der Kameraansicht ist „Globales Best Of“ eine globale Navigation und steht
deshalb im Dropdown „Übersicht“ zusammen mit „Start“ und der Kameraliste. Das
kamerabezogene „Best Of“ bleibt im Dropdown „Optionen“; das globale Best Of darf
dort nicht zusätzlich erscheinen. Die eigenständige globale Best-Of-Seite
verwendet dieselbe kompakte Kopfzeile wie die Startseite. Innerhalb einer Kamera
führt der Link dagegen auf deren View `global-bestof`: Die bestehende
Kamera-Kopfzeile bleibt vollständig stehen, der Zeitbaum wird mit seinem
vorhandenen nicht-interaktiven Zustand gerendert, „Globales Best-Of“ ersetzt den
Kameranamen und nur der Inhalt darunter wechselt. Die Galerie wiederholt weder
Seitentitel noch Erklärung. Beide Wege binden `templates/global_bestof_gallery.php` ein; eine
Kopfzeile wird nicht rekonstruiert. Der alte generische Site-Header und die nicht
mehr gerenderte Sidebar samt Download-Select wurden vollständig aus Layout, CSS
und JavaScript entfernt.

Der Live-HTML-Page-Cache unter `var/state/page-cache/<slug>/` trennt seine
Dateien zusätzlich nach dem tatsächlichen Request-Pfad und der aufgelösten
`base_url`. Das ist erforderlich, weil `/camera/<slug>`, `/camera/<slug>/`,
`camera.php?cam=<slug>` und verschiedene Proxy-Prefixe bei dokumentrelativen
Assets unterschiedlich viele `../`-Segmente benötigen. Ein Cacheeintrag darf
daher nie zwischen diesen Routen wiederverwendet werden. Nur reine
Live-Requests ohne ausgaberelevante Query-Parameter sind cachefähig. Neue
Dateien werden unter einem routenspezifischen Lock atomar publiziert; das
öffentliche CSRF-Cookie wird bereits vor einem möglichen Cache-Treffer gesetzt.
Die Cache-Version umfasst neben den PHP-Templates auch die gerenderten
CSS-/JavaScript-Dateien und den gemeinsamen URL-Helfer.

## 2. PHP-Schichten

### Domain

`src/Domain/` enthält fachliche Datenobjekte, insbesondere `Camera`. Diese Klassen beschreiben Kameras und Einstellungen, führen aber keine HTTP-Antworten aus.

### Services

`src/Service/` enthält fachliche Abläufe:

- `Capture`: Aufnahme und externe Quellen,
- `Storage`: Bildablage, Archivierung und Pfadauflösung,
- `Status`: Kamera- und Laufzeitstatus,
- `Weather`: Wetterkommandos und Wetterdaten,
- `Interaction`: Likes und Best-Of,
- `Stream`: Streamzustand und Viewer-Identität,
- `Dayvideo`: begrenzte Frame-Auswahl, globale Build-Slots und ffmpeg-Lauf.
- `Upload`: gemeinsame Speicherung für Legacy- und Chunk-Transport sowie der
  gesicherte Zwischenspeicher für Teiluploads.

### Infrastructure

`src/Infrastructure/` kapselt technische Zugriffe:

- SQLite und Kamera-Datenbanken,
- HTTP-Aufrufe,
- Dateisystem und `PathResolver`,
- Logging und Konfigurationszugriff.

Neue Dateisystemzugriffe sollen über `Webcam\Infrastructure\Filesystem\PathResolver` laufen. Harte absolute Pfade gehören in Konfiguration oder Betriebsdokumentation, nicht in HTML oder JavaScript.

### Aktuelle Installations- und Mounttopologie

- DEV liegt unter `/var/www/html/webcam`, seine Live-DBs unter
  `/var/lib/webcam` und sein Archivzugriff ist read-only.
- PROD liegt unter `/var/www/html/webcam2`, seine Live-DBs unter
  `/var/lib/webcam2` und es ist der einzige read-write Archivschreiber.
- Beide Installationen verwenden dasselbe OMV-Share `webcam_archive` unter
  `storage/archive/` und verlangen dort `.webcam-archive-mounted`.
- Die übergeordneten DEV-/PROD-Shares bleiben systemd-Automounts. Die darunter
  liegenden Archivmounts dürfen nicht ebenfalls als Automounts angelegt werden,
  weil diese verschachtelte CIFS-Automount-Topologie beim Boot vom 02.08.2026
  von systemd wegen eines Ordering-Cycles verworfen wurde.
- Deshalb stehen die Archivziele als `noauto`-CIFS-Einträge in `/etc/fstab`.
  Der aktivierte `webcam-archive-mounts.service` mountet beide Ziele explizit
  und wiederholt einen fehlgeschlagenen Start alle 15 Sekunden. Der Dienst
  behandelt bereits gemountete Ziele idempotent als Erfolg.

DEV/Code und die vollständige PROD-Basis unter `/var/www/html/webcam2` stehen
auf Version 6.0.7, Iteration 159, Release-Commit
`d5fd15fdea184241d2e88c119b572cf13e474ab3`. Der Bielefelder Duplikatbestand ist ab dem Ausfallslot
markiert; der variantengenaue Bestandsabgleich verhindert, dass nicht mehr
vorhandene Cache-Varianten allein wegen alter DB-Groessen als Bildquelle
verwendet werden. Die Timeline-Begrenzung und die Aufteilung in
`CameraTimelineResolver`, `ArchiveDatabaseReader`, `ArchiveFilesystemReader`,
`TimelineMapper`, `TimelineNavigator` und `ArchiveEntryImageResolver` sind
Bestandteil der gemeinsam ausgerollten DEV-/PROD-Linie.
Der optionale Alt-Cache ist zusätzlich in
`LegacyArchiveCache` (Dateien, Frische, Rebuild, Status),
`LegacyArchiveCacheCodec` (kompaktes JSON und Timeline-Schema) sowie
`ArchiveStoragePaths` (historische Root-/Slug-Pfade) getrennt.
Die Kameraseite delegiert außerdem die Tag-/Nacht- und Labelregeln an
`CameraOverlayMetadata`, die Darstellung belastbarer Wetterwerte an
`CameraWeatherMetadata` und die Navigation durch bereits geladene
Kalenderlisten an `CameraTimelineCalendar`. Diese Komponenten führen keine
HTML-Ausgabe aus; `camera.php` verbindet ihre Ergebnisse weiterhin mit dem
Request und dem Template.
Der `CameraTimelineNavigationBuilder` übernimmt zusätzlich die schmalen
Tages-/Kalenderabfragen und baut daraus die templatefertigen Pfeile sowie Zeit-,
Stunden- und Jahresauswahlen. Dokumentrelative Link-Closures kommen weiterhin aus
`camera.php`, damit History- und Playback-Kontext erhalten bleiben. Reine
Kalendersortierung und Nachbarsuche delegiert der Builder an
`CameraTimelineCalendar`.

Tagespfeile sind dabei echte Tageskantensprünge: rückwärts wird die letzte
Aufnahme des vorherigen verfügbaren Tages, vorwärts die erste Aufnahme des
nächsten verfügbaren Tages gewählt. Die Uhrzeit des aktuellen Bildes bleibt
nur für die dafür vorgesehenen Stunden-, Monats- und Jahresziele relevant.
Im Template sind Menüblock und Kameraname getrennte Geschwister, damit die
Navigationsgruppen auf schmalen Viewports umbrechen können. Das Wettermodal
besitzt einen eigenen, viewportbegrenzten Breitmodus für seine Diagramme.
Tagesabruf, kurzlebiger Cache, SVG-Diagramme, Hover-Vorschau und Aufbau des
Wetterinhalts liegen in `assets/js/camera-weather.js`. Das DOM-freie
`camera-weather-model.js` normalisiert und sortiert die verschiedenen
Legacy-Wetterformate und liefert einheitliche Messreihen. Die allgemeine
Modalsteuerung liegt in `assets/js/camera-meta-modal.js`; `camera.js` übergibt
nur den ausgewählten Tag und verwendet dafür dieselbe Timeline-API
beziehungsweise denselben bereits geladenen Timeline-Zustand. Der gemeinsame
Controller koordiniert außerdem EXIF, Umgebungskarte und Bildvergleich, ohne
deren Fachlogik zu übernehmen. EXIF-Sidecars werden ausschließlich im Browser
aus ihren zeilenorientierten Schlüssel/Wert-Paaren in eine sichere DOM-Tabelle
überführt; unbekannte Zeilen bleiben als Klartextnotiz erhalten. Es entsteht
keine zusätzliche serverseitige Datenquelle.

Der gemeinsame Browserzustand dafür liegt in
`assets/js/camera-timeline-data.js`. Dieses Modul lädt Timeline-Tage und teilt
laufende Requests zwischen Hauptnavigation, Wetter und Umgebungskarte. Das
DOM-freie `camera-timeline-model.js` berechnet aus normalisierten API-Zeilen
Kalenderlisten und das Modell für Bild-, Stunden-, Tages-, Monats- und
Jahresnavigation. Der Cache lebt nur bis zum Schließen der Seite; SQLite bleibt
die serverseitige Sollquelle. Das
separate `camera-timeline.js` ist ausschließlich für Navigationsmodus,
Dropdown-Lebenszyklus und die Bedienung der Picker zuständig.

Die sichtbare Umsetzung dieses Modells liegt wiederum getrennt in
`assets/js/camera-timeline-view.js`. Das Modul erzeugt Navigationsgruppen,
Uhrzeitauswahl und seitliche Hotspots ausschließlich mit DOM-Methoden,
übernimmt Overlay und Labels des ausgewählten Snapshots und verwaltet den
Lade-/Timeoutzustand des Hauptbildes. Es lädt keine Timeline-Daten und
berechnet keine Nachbarn. Nach partieller Navigation fragt es den aktuellen
Kamera-Root erneut ab, statt Referenzen auf den entfernten DOM-Baum zu halten.

Das optionale Vorladen benachbarter Snapshots liegt in
`assets/js/camera-snapshot-prefetch.js`. Der Controller dedupliziert laufende
Same-Origin-Requests, hält höchstens sechs geparste Nachbardokumente und zehn
Bild-Blobs im aktuellen Browserdokument und gibt verdrängte Blob-URLs frei.
Ein erwarteter Snapshot-Key verhindert, dass ein vorgeholtes Dokument für den
falschen Zeitschritt eingesetzt wird. Nach einem partiellen DOM-Austausch kann
das Modul das neue Hauptbild aus dem Blob-Cache hydrieren. Fehlschläge bleiben
wirkungslos, weil die reguläre Navigation weiterhin der sichtbare Fallback ist;
es entsteht kein persistenter oder serverseitiger Cache.

Die Archivdialoge sind ebenfalls aus dem allgemeinen Kamera-Controller
herausgelöst. `assets/js/camera-history.js` rendert den eingebetteten
Tageshistorie-Dialog und lädt das Tages-, Monats- und Jahrespanel über die
Public-API. Antworten werden ausschließlich in einer seitenlokalen Map
gehalten. `assets/js/camera-captcha.js` besitzt nur den Dialoglebenszyklus und
lädt Captcha-Aufgaben; die sicherheitsrelevante Like-Prüfung verbleibt im
Backend. `include/like_request_guard.php` verbraucht fuer beide oeffentlichen
Like-Einstiege das Captcha und belastet danach denselben IP-basierten
Limiter-Scope. Session-Cookies bleiben nur die zusaetzliche Duplikatgrenze.
`camera.js` stellt beiden Modulen ausschließlich URL- und Seitenschnittstellen
bereit.

Status und Like-Aktion sind ebenfalls getrennte Fachcontroller.
`assets/js/camera-status.js` lädt die öffentliche Kameratelemetrie, formatiert
nur Datums- und Zahlenfelder und bindet den Dialog nach einem partiellen
Root-Austausch neu. Laufende Anfragen werden pro URL zusammengeführt; der
Detailcache lebt nur im aktuellen Browserdokument. `assets/js/camera-bestof-like.js`
koordiniert Captcha-Dialog, CSRF-Header, Legacy-Pfadnormalisierung und den
bestätigten Herzzustand. Die sicherheitsrelevante Entscheidung bleibt in
`bestof-toggle.php`; das Frontend schreibt weder Like-Datei noch Kamera-DB
direkt. Beide Module erhalten dokumentrelative Pfade als Adapter aus
`camera.js`.

Der PHP-Einstieg bleibt ebenfalls schmal: `BestOfSourceResolver` bindet Pfade
an die angeforderte Kamera und löst Cache, Archiv oder Galerie auf.
`BestOfCaptureRegistrar` erzeugt nur für historische, noch nicht indizierte
Bilder einen minimalen Capture-Datensatz. Nach erfolgreicher atomarer
Publikation pflegt `BestOfAssetManager` EXIF-Sidecar, Thumbnail, Log und die
betroffenen Seitencaches. CSRF und HTTP-Status bleiben sichtbar im Einstieg;
Captcha und IP-Budget liegen als gemeinsame Schutzschicht direkt davor.
`BestOfLikeCoordinator` behält Lock und Rollbackgrenze.

Die partielle Navigation innerhalb derselben Kamera liegt in
`assets/js/camera-partial-navigation.js`. Das Modul akzeptiert nur
Same-Origin-Ziele derselben Kamera, übernimmt bei Verfügbarkeit ein vom
Snapshot-Prefetch bereits geparstes Dokument und bricht sonst einen älteren
Fetch zugunsten der neuesten Navigation ab. Eine monoton steigende Generation
verhindert dabei, dass verspätete Antworten oder Abbruchpfade einen neueren
DOM-Stand überschreiben. Beim Austausch werden die erlaubten Attribute und
Kinder des Kamera-Roots sowie Titel und Browser-History aktualisiert; danach
stößt ein schmaler Callback in `camera.js` die bestehenden Fachcontroller am
neuen Root erneut an. Timeline-Hotspots und `popstate` gehören ebenfalls zu
diesem Modul. Wechsel zwischen Kameras bleiben absichtlich vollständige
Seitennavigationen. Es entsteht kein zusätzlicher persistenter Cache.
Vor jeder partiellen Snapshot-Navigation ruft das Modul den schmalen
Stream-Hook aus `camera.js` auf. Dieser gibt MJPEG-Viewer-Slot, HLS-Decoder und
Reconnect-Timer frei und setzt die sitzungsbezogene Anzeigeauswahl bewusst auf
Snapshot. Andernfalls würde der neue Kamera-Root den zuvor gewählten Stream
nach dem DOM-Austausch sofort wieder starten. Timeline, Hotspots und
Browser-Zurück folgen dabei demselben Ablauf.

Der sichtbare Hauptbild-Lebenszyklus liegt in
`assets/js/camera-live-image.js`. Das Modul koordiniert den schnellen
Placeholder, das kontrolliert vorgeladene Vollbild, Ladeabschluss und
Nachbar-Prefetch, Fokus, Reload-Timer, den gefilterten Lightbox-Klick sowie den
Rückweg aus der Informationsansicht. Streamauswahl, Timeline-Ladezustand,
Prefetch-Speicher und Lightbox bleiben in ihren bestehenden Fachcontrollern;
`camera.js` reicht ausschließlich deren schmale Schnittstellen hinein. Nach
einem partiellen Root-Austausch löst das Modul alle DOM-Knoten erneut auf und
bindet lokale Ereignisse idempotent. Es führt keine eigene Datenquelle und
keinen persistenten Cache ein.

Die frei positionierbaren Bildbeschriftungen liegen in
`assets/js/camera-labels.js`. Das Modul liest eingebettete beziehungsweise von
der Timeline gelieferte Labeldaten, reduziert sie auf erlaubte Text-, Positions-,
Farb- und Linkfelder und erzeugt die sichtbaren Elemente ausschließlich mit
DOM-Methoden und `textContent`. Es verwaltet außerdem die persönliche
Ein-/Auswahl im Browser und unterdrückt zusätzliche HTML-Labels, wenn ein
historisches Bild sein Overlay bereits in den Pixeln trägt. Der dokumentweit
delegierte Schalter wird genau einmal registriert und löst nach partieller
Navigation stets den aktuell gerenderten Kamera-Root auf. Es entsteht weder
eine neue serverseitige Datenquelle noch ein persistenter Anwendungscache.

Timelapse liegt in `assets/js/camera-playback.js`. Der Controller lädt und
normalisiert Frames und verwaltet Vorladen, Scrubber, Wiedergabetimer, Tastatur
und Vollbild. Das getrennte `camera-dayvideo.js` schaltet die Liveelemente für
ein Tagesvideo kontrolliert aus und wieder ein. Sein Poller setzt `refresh=1`
ausschließlich beim ersten
manuellen Neuaufbau; nachfolgende `202`-Abfragen starten keinen weiteren
FFmpeg-Build. Build-Limits, Rate-Limits und der hostweite Slot-Pool bleiben
serverseitig verbindlich. Das Modul erzeugt keinen zusätzlichen persistenten
Cache. Bei partieller Navigation beendet der Controller den alten
Wiedergabetimer und entfernt dessen globale Tastatur- und Vollbildlistener,
bevor er den neu gerenderten Timelapse-Block initialisiert.

Die Lightbox wird weiterhin von `camera-lightbox.js` koordiniert. Das
DOM-unabhängige `camera-lightbox-entry.js` löst Bildvarianten, EXIF-Kurztext,
sichere Overlays und Tageszuordnung auf. `camera-lightbox-controls.js` besitzt
Zoom, Verschieben, Tastaturbelegung und Hilfetext. Der Koordinator hält dadurch
nur Dialogzustand, Navigation und die Adapter zu diesen beiden Komponenten.

Auch der sichtbare Streamcontroller ist dreigeteilt. `camera-stream.js`
koordiniert MJPEG-Probe, Viewerlimit, Reconnect und den kontrollierten Wechsel
zum Snapshot. `camera-stream-preference.js` speichert ausschließlich die
bewusste Snapshot-/Stream-Auswahl in der Browsersitzung. Der Lebenszyklus von
hls.js beziehungsweise nativem HLS, Videoelement, Bereitschafts-Timeout und
Decoderfehlern liegt in `camera-stream-hls.js`. Technische Fallbacks verändern
die gespeicherte Benutzerauswahl nicht. Eine Archivnavigation ist dagegen eine
bewusste Rückkehr zum Snapshot und darf deshalb die Sitzungspräferenz ändern.

Die kameraübergreifende Umgebungskarte im Info-Modal liegt getrennt davon in
`assets/js/camera-surroundings.js`. Sie validiert Koordinaten, sucht über einen
von `camera.js` bereitgestellten Adapter pro Kamera das nächstliegende Bild des
ausgewählten Archivzeitpunkts, baut Popups und Blickfelder und räumt ihre
Leaflet-Instanz beim Schließen wieder auf. Der Adapter verwendet den bereits
vorhandenen kurzlebigen Timeline-Tagescache und die bestehende Public-API; die
Komponente führt keinen eigenen Servercache und keinen Dateisystemscan ein.

Die Präsentation des jeweils ausgewählten Bildes liegt im
`CameraSnapshotPresentationBuilder`. `camera.php` bestimmt nur noch das Bild
und den Ansichtsmodus. Der Builder verbindet anschließend bereits hydrierte
EXIF-/Wetterdaten mit kontrollierten Legacy-Sidecar-Fallbacks, gleicht Likes
und Logo-Status read-only mit der Kamera-SQLite ab und liefert Overlay,
Modaltexte, zeitabhängige Labels und Best-Of-Zustand als HTML-unabhängiges
Modell. `CameraWeatherMetadata` kapselt weiterhin Wetterbereinigung und
Qualitätsmarker; `CameraOverlayMetadata` bleibt für Sonnenzeiten und
Gültigkeitszeiträume der Labels zuständig.

Die Standortkarte der einzelnen Kamera ist ebenfalls vom PHP-Route getrennt.
`camera.php` liefert nur das kleine `mapPayload`; `templates/camera.php` legt es
HTML-kodiert am Kartencontainer ab und `assets/js/camera-map.js` übernimmt
Leaflet-Initialisierung, Marker, Sichtfeldbogen sowie den konfigurierten
Kartenausschnitt. Das Modul wird nur bei tatsächlich sichtbarer Karte geladen
und ist eine eigene Quelle der Live-Page-Cache-Version.

Auch das große Kamera-Template ist intern nach Aufgabe gegliedert.
`templates/camera/prepare.php` normalisiert die vom Controller übergebenen
Daten und stellt das View-Modell bereit. `navigation.php` definiert die
wiederverwendeten, zustandslosen Menü-Renderer; `history.php` bereitet nur die
History-Ansicht vor. `templates/camera.php` bindet diese drei kommentierten
Bausteine im gemeinsamen Template-Scope ein und enthält danach ausschließlich
die eigentliche HTML-Ausgabe. Die Teiltemplates sind keine eigenen
HTTP-Einstiegspunkte.

Vor dem Template ist auch der öffentliche PHP-Einstieg phasenweise lesbar:
`src/Http/Public/camera/request.php` normalisiert Route, Kamera, Ansichtsmodus
und den schnellen Best-Of-Pfad. `presentation.php` verbindet Cache, Timeline,
Snapshot-Präsentation und Rendering. Beide Teildateien teilen bewusst den
Request-Scope von `camera.php`; sie sind keine direkt aufrufbaren Endpunkte.

Die beiden Kartenmodule haben damit bewusst verschiedene Aufgaben:
`camera-map.js` zeichnet nur den festen Standort der geöffneten Kamera im
Livebereich; `camera-surroundings.js` baut bei Bedarf das Modal mit mehreren
Kameras und zeitlich passenden Archivvorschaubildern.

Der frühere serverseitige Download-Select gehört nicht mehr zur Kameraansicht.
Nach seinem UI-Ausbau verbliebene Berechnung, Template-Normalisierung und der
JavaScript-Handler wurden in Iteration 117 vollständig entfernt. Bildvarianten
werden weiterhin für Hauptbild, Lightbox, Timeline und API-Daten verwendet;
lediglich das nicht mehr erreichbare Download-View-Model existiert nicht mehr.

Iteration 104 trennt den Dayvideo-HTTP-Adapter, die Frame-Auswahl, den
ffmpeg-Runner, die Dateilocks und den SQLite-Limiter aus `api/public.php`.
Neue Web-Builds verwenden nur die festen Profile 3/5/7/9 FPS und 1200/1600 px,
höchstens 300 Frames, ein gehashtes Client-IP-Budget und einen gemeinsamen
hostweiten Slot-Pool. Die Client-IP stammt nur aus einer von `REMOTE_ADDR` aus
rechts nach links geprüften, explizit vertrauten Proxy-Kette. Ohne passenden
`WEBCAM_TRUSTED_PROXIES`-Eintrag wird kein Forwarded-Header vertraut. Auf dem
aktuellen Origin ist der TLS-Proxy `192.0.2.2` explizit eingetragen.

RTSP-Quellen werden für Snapshot, MJPEG und HLS nicht als ffmpeg-Argument oder
Runner-Konfiguration weitergereicht. Ein geschütztes ffconcat-Manifest gelangt
ausschließlich über stdin in ffmpeg. `SecretRedactor` bereinigt zusätzlich die
zentralen Log- und Statusgrenzen rekursiv; auch der authentifizierte Download
älterer Capture-Logs wird beim Lesen redigiert. Die Live-DB und die
authentifizierte Admin-Konfiguration bleiben notwendige privilegierte Quellen
der vollständigen Endpunkte.

## 3. Datenfluss einer Aufnahme

### Extern ausgelöste Kamera

```text
Kamera -> include/command.php -> webcam_capture
Kamera -> include/upload.php oder ausgehandeltes include/upload-chunk.php
beide Transportwege -> UploadIngestor -> ImageStorage
ImageStorage -> <slug>/current + cache/<slug>/...
ImageStorage -> CameraDataStore-Fassade -> Capture-/Wetter-Repository -> Kamera-DB
ImageStorage -> StatusService -> var/state + Log
```

`include/command.php` entscheidet den aktiven Slot unter einem Kamera-Lock. Parallele Polls dürfen denselben Slot nicht doppelt auslösen. Echte `webcam_capture`-Antworten werden sofort zurückgegeben; ein serverseitiger Pull-Tick läuft nur hinter `wait`.

Der Chunk-Endpunkt gibt seine Fähigkeit erst nach gültigem `X-Upload-Key` als
`camera-rpi-chunk-v1` bekannt. Er akzeptiert 64-KiB- bis 1-MiB-Teile, höchstens
die konfigurierte Gesamtgröße und eine begrenzte Zahl aktiver Uploads je
Kamera. Manifest, stabile Upload-ID und SHA-256 jedes Teils verhindern das
Vermischen widersprüchlicher Wiederholungen. `finalize` setzt unter einem Lock
alle Teile zusammen, prüft Größe und Gesamt-Hash und übergibt die fertige Datei
an `UploadIngestor`. Auch der Legacy-Endpunkt nutzt diesen dateibasierten Weg.
`ImageStorage` dekodiert und kopiert die Datei atomar, ohne zusätzlich das
gesamte komprimierte JPEG als PHP-String zu halten. Konfigurationen können die
absolute Gesamtgrenze von 64 MiB nicht überschreiten. Kann der Slot-Lock nicht
angelegt werden, antwortet der Ingest fail-closed mit 503; der Helper behält
das Bild für einen späteren Versuch. Der gemeinsame letzte Schritt hält
Bildablage, Wetter, Status und Logs für Legacy und Chunk-v1 identisch.
Erfolgreiche Transfers werden entfernt; abgebrochene bleiben sechs Stunden
wiederholbar und werden danach kontrolliert bereinigt.

Vor diesem PHP-Datenfluss liegt eine eigene HTTP-Grenze. Apache akzeptiert für
`upload.php` höchstens 26 MiB Request-Body und für `upload-chunk.php` höchstens
2 MiB. Übergroße anonyme Bodys werden dadurch mit HTTP 413 beendet, bevor
Bootstrap, Kameraauflösung oder Key-Prüfung laufen. Am öffentlichen Proxy muss
ein vHost-lokaler Header-Guard dieselben Wege bereits vor `ProxyPass` stoppen;
die globale `LocationMatch`-Variante erwies sich dort als wirkungslos.
Pfadspezifische Fail2Ban-Budgets und die isolierte nftables-Tabelle ergänzen
den Body-Guard nach `ops/upload-request-hardening.md`; ihre betriebliche
Freigabe verlangt eine normale Capture-Runde ohne Kameraban.

### Serverseitig abgeholte Kamera

```text
wait-Antwort -> globaler Pull-Lock -> Fälligkeitsprüfung -> CaptureManager
CaptureManager -> CaptureSourcePriority -> externe Quelle -> ImageStorage -> Kamera-DB
```

Die Fälligkeit richtet sich nach dem neuesten `captures.slot_ts`, nicht nach der Abschlusszeit einer langsamen Runde.

`CaptureSourcePriority` trennt die fachliche Quellenwahl vom HTTP- und
Speichercode. Bei `pull-snapshot` werden externe Active-Uploads normalerweise
mit HTTP 200 als bewusst ignorierter No-op bestätigt. So kann ein alter Helper
seine Spool-Datei entfernen, ohne das Pull-Bild zu überschreiben. Erst nach
`capture.active_upload_fallback_failures` aufeinanderfolgenden Pull-Fehlern darf
der Upload als Fallback bis zum nächsten erfolgreichen Pull wieder speichern;
der Wert `0` deaktiviert den Fallback. Pull und Upload halten dabei denselben
Kameralock. Zähler und letzte Pull-Ergebnisse liegen als kurzlebiger
Betriebszustand unter `var/state/pull-fallback/`, nicht in den Bilddatenbanken.
`include/command.php` fragt dieselbe Entscheidung ab: Ein Pull-Helper erhält
bis zur Fallback-Freigabe nur `wait`; danach wird der vorhandene atomare
Command-Slot wieder für `webcam_capture` verwendet.

## 4. Dateneigentum

### Zentrale Konfiguration

Produktive Quelle:

```text
/var/lib/webcam/webcams.sqlite
```

Sie enthält Kamerakonfiguration, Standort, Capture-Regeln, Stream-, Watchdog-, Overlay- und Wetterkonfiguration. `database/webcams.sqlite` ist ausschließlich anonymisierte Schema-/Testreferenz.

### Kamera-Metadaten

Produktive Quelle je Kamera:

```text
/var/lib/webcam/camera-db/<slug>/db.sqlite
```

Wichtige Tabellen sind `captures`, `variants`, `exif_entries`, `weather_entries`, `weather_quality_flags`, `likes_log`, `capture_logo` und `meta`. `captures.is_duplicate` bewahrt Slots und deren Wetter-/EXIF-Beziehungen, schließt identische oder fehlende Bildzeilen aber aus allen Bildabfragen aus.

Die Kamera-Datenbank ist die bevorzugte Quelle für Timeline, Kalender, Wetter und Bildvarianten. Ein Dateisystem-Scan ist nur Legacy- oder Fallbackpfad.

Hinter der kompatiblen `CameraCaptureRepository`-Fassade trennt
`CameraCaptureWriter` Capture-, Varianten-, EXIF- und Wetterschreibvorgänge
einschließlich Deduplikatabgleich von `CameraCaptureReader`, das Pfadsuche,
Kalenderlisten, Zeitbereiche und Nächstsuche read-only bedient. Beide verwenden
weiterhin dieselbe `CameraDataConnection` und damit denselben Lock-/Retryvertrag.

Die Wartungsprogramme für vorhandene Wetterdaten folgen derselben Grenze:
`WeatherSidecarParser` findet und plausibilisiert beide historischen
Sidecarformate, `WeatherSidecarBatchWriter` ersetzt Wetterzeilen unter dem
Kamera-DB-Lock und `WeatherSidecarImporter` koordiniert Dry-Run oder Batches.
`WeatherQualityAnalyzer` kapselt DB-Hydrierung, Grenz-/Stillstandserkennung und
die optionale Markertransaktion. Die Dateien unter `bin/` enthalten nur noch
CLI-Optionen, Kameraauswahl und Ausgabe; ohne `--write` bleiben sie read-only.

`CameraTimelineResolver` kapselt die Bereichsberechnung und Bildauswahl der
Kameraansicht. Leere Tage werden über die indexierte Nächstsuche der Kamera-DB
aufgelöst; danach wird nur der gefundene Tag materialisiert. Unbeschränkte
Timeline-Abfragen begrenzen die Capture-Zeilen bereits in SQLite, bevor
Varianten, EXIF und Wetter geladen werden. Das gilt auch für explizite
Zeiträume: `WEBCAM_TIMELINE_RANGE_MAX_ENTRIES` verwendet standardmäßig 20.000
und ist hart auf 50.000 begrenzt. Relationsdaten werden je 500 Capture-IDs
geladen und unmittelbar an die Ergebniszeilen angehängt. Der historische
Meta-Wert `timeline.max_entries=0` wird bei unbeschränkten Abfragen als sicherer
Standard von 300 behandelt.
Filesystem-Fallbacks filtern bekannte `is_duplicate`-Slots erneut, damit
liegengebliebene Dateien die DB-Entscheidung nicht umgehen.

Die interne Archive-Verarbeitung ist nach Verantwortung getrennt:
`ArchiveDatabaseReader` liest und hydriert Kamera-DB-Zeilen einschließlich
Varianten-, Duplikat- und Wetterregeln, `ArchiveFilesystemReader` enthält den
gezielten Legacy-Fallback für angeforderte Jahre oder Tage und
`TimelineMapper` baut aus bereits gelesenen Einträgen die sortierte Timeline
samt Kalender-Maps. `TimelineNavigator` wählt daraus ohne externe Zugriffe den
passenden Zeitpunkt; `ArchiveEntryImageResolver` prüft die bevorzugten
Bildvarianten für die Vergleichsansicht. `ArchiveStoragePaths` normalisiert die
historisch unterschiedlichen Root-/Slug-Konfigurationen.

`ArchiveCalendarCatalog` bündelt die DB-bevorzugten Listen für Jahre, Monate,
Tage und Stunden. `ArchiveTimelineSelector` kapselt die Auswahl eines
Zeitbereichs und das gezielte Auflösen leerer Archivtage. `ArchiveBrowser`
bleibt dadurch die kompatible Fassade und enthält keine zweite Kalender- oder
Navigationsimplementierung mehr.

`LegacyArchiveCache` verwaltet ausschließlich den optionalen Diagnosecache:
Jahresdateien, Frische, Nächstsuche, Rebuild und Admin-Status.
`LegacyArchiveCacheCodec` übersetzt dessen kompaktes JSON in normale
Timeline-Einträge und zurück. `ArchiveBrowser` bleibt die öffentliche Fassade
und delegiert Cache-Aufrufe unter den bisherigen Methodennamen. Der Cache wird
dabei ausdrücklich nicht zur neuen Sollquelle; regulär bleibt Kamera-SQLite
bevorzugt.

### Bilder

```text
<slug>/current/                              aktueller Stand
cache/<slug>/<yyyy>/<MM>/<dd>/               neuerer Rolling-Bestand
storage/archive/<slug>/<yyyy>/<MM>/<dd>/     Langzeitarchiv
storage/bestof/<slug>/                       ausgewählte Bilder
```

Bei der getrennten SSD-/HDD-Topologie sind Anwendung, Rolling-Cache,
Current-Bilder, Best-Of, Runtime-State und Live-SQLite installationslokal. Nur
`storage/archive/` darf als gemeinsamer OMV-HDD-Bestand eingebunden werden. Es
gibt dabei genau einen schreibenden Knoten; weitere Installationen lesen das
Archiv ausschließlich read-only. Die Live-SQLite-Dateien werden weder geteilt
noch auf SMB betrieben.

Reale Installationen verlangen am Archiv-Root die Datei
`.webcam-archive-mounted`. `ArchiveMountGuard` prüft sie vor jedem
`DailyArchiver`-Lauf und vor der Cache-Retention. Fehlt der Unter-Mount oder ist
der Sentinel nicht als lesbare reguläre Datei sichtbar, schreibt der Archiver
nichts und Retention bewahrt alle Cache-Tage. Die Deaktivierung über
`WEBCAM_ARCHIVE_MOUNT_SENTINEL_REQUIRED=0` ist ausschließlich für isolierte
Testumgebungen vorgesehen.

Die Retention arbeitet fail-closed auf Tagesebene. Sie löscht einen alten
Cache-Tag mit Originalaufnahmen erst, wenn `ArchiveCompletenessVerifier` jede
Cache-Originalaufnahme, alle fünf stabilen Archivvarianten und vorhandene
EXIF-/Wetter-Sidecars geprüft hat. Historische Tage ohne Originalaufnahmen
werden als reine abgeleitete Cache-Artefakte behandelt und nur bei vorhandenem
Archivtag entfernt; fehlt dieser, bleibt der Tag erhalten. Ein echter
Fehlschlag erhält den gesamten Cache-Tag und erzeugt eine strukturierte
Warnung. `DailyArchiver` löscht keine Cache-Jahre mehr; die verifizierte
tageweise Retention ist der einzige automatische Löschpfad für Capture-Tage.

Auch der Archiv-Rollover verwendet diese inhaltliche Pruefung pro Aufnahme.
Ein bereits vorhandener Dateiname reicht nicht als Skip-Kriterium: Alle fuenf
JPEGs muessen als JPEG lesbar sein und die erwarteten Abmessungen besitzen;
Originalklone muessen Quellgroesse und -abmessungen besitzen und vorhandene
Sidecars duerfen nicht leer sein. Ein defektes Set wird vollstaendig atomar ersetzt und danach
erneut verifiziert. Der gesamte Tageslauf haelt zusaetzlich einen Lock unter
`storage/archive/.webcam-archive-locks/<YYYY-MM-DD>.lock`; bei einem gemeinsamen
SMB-Mount ist damit dieselbe Lockdatei fuer alle Schreiber sichtbar. Die
RO/RW-Trennung und genau ein autorisierter Archivschreiber bleiben verbindlich.

Auch der Schreibpfad eines neuen Bildes ist intern in erklärbare Schritte
geteilt. `ImageStorage` hält weiterhin Reihenfolge, Fehlergrenze und
Transaktion zusammen. `CaptureExifMetadata` liest und normalisiert EXIF,
`ImageVariantGenerator` erzeugt die festen JPEG-Varianten,
`CaptureBackupScheduler` stößt die bereits vorhandene Sicherungslogik an und
`CacheRetentionManager` führt ausschließlich die verifizierte Retention aus.
Keiner dieser Helfer veröffentlicht eigenständig einen Capture oder umgeht die
Kamera-SQLite.

`BestOfGallery` koordiniert nur noch Seitenauswahl und Ausgabe.
`BestOfFileCatalog` ordnet und dedupliziert die vorhandenen Dateien,
`BestOfLikeRepository` liest die Like-Zuordnung und `BestOfGalleryCache`
verwaltet den bereits vorhandenen JSON-Cache samt Fingerprint. Für
`globalPage()` werden die sortierten per-Kamera-Listen für `/bestof` und
`/api/public.php/bestof` zusammengeführt. Die Pagination baut pro Kamera nur
den bis zur angeforderten globalen Seite benötigten Ausschnitt zu vollständigen
UI-Einträgen auf. Eine globale Best-Of-DB ist nicht erforderlich.

## 5. Nebenläufigkeit und SQLite

SQLite erlaubt parallele Leser, aber immer nur begrenzt parallele Schreiber. Die Anwendung verwendet deshalb kurze Transaktionen, Busy-Timeouts, Retry und gezielte Locks.

- Config-DB: getrennte Read-only- und Read-write-Verbindungen; Write-Pfad erzwingt `journal_mode=DELETE`.
- Kamera-DB: `CameraDataConnection` teilt eine Verbindung zwischen Schema-,
  Capture-, Like-/Best-Of- und Wetter-/Logo-Repository.
  `db.sqlite.write.lock` serialisiert deren Schreiboperationen; Busy-Retry und
  Staging-Fallback sichern problematische Dateisysteme ab. `CameraDataStore`
  bleibt als kompatible Fassade für bestehende Aufrufer erhalten.
- Capture-Slot: `var/state/command/<slug>.slot` wird per `flock` atomar entschieden.
- Pull-Capture: `var/state/pull/tick.lock` schützt die vollständige Due-Runde.
- Status: `var/state/cameras/<slug>.json.lock` schützt den gesamten Read-modify-write-Zyklus; JSON wird atomar umbenannt.
- Backups: `BackupRunState` besitzt Tagesstatus, Voll-/Teillauf-Semantik und
  `var/state/backup/backup_daily.run.lock`. `SqliteBackupService` erzeugt und
  prüft den konsistenten SQLite-Online-Snapshot; `DailyDatabaseBackup` wählt
  Config-/Kameraziele, Fallback und Retention. `bin/backup_daily.php` bleibt
  der kleine CLI-Koordinator.
- Wartung: Der regelmaessige Kamera-Poll an `include/command.php` prueft nach
  einer bereits gesendeten `wait`-Antwort die aktuelle Serverzeit. Bei
  Faelligkeit startet er `bin/maintenance_scheduler.php` abgekoppelt vom
  Webrequest. Aktivierung und beliebige Tageszeit kommen aus
  `meta.maintenance_scripts`; Tagesstatus und Locks liegen unter
  `var/state/maintenance-scheduler/`. Ein System-Cron ist nicht beteiligt. Der
  eingebaute `archive_rollover` ist standardmaessig deaktiviert und wird nur in
  der Config-DB des autorisierten RW-Archivschreibers aktiviert. Jeder
  Taskstatus kann höchstens fünf zentral bereinigte Ausgabezeilen seines
  eigenen Laufs enthalten. Alte Zustände dürfen nur über eine zu Label und
  Startzeit passende START/DONE-Grenze aus dem Sammellog ergänzt werden. Vor
  dem Tages-Lock prüft der Rollover den realen Archiv-Root durch Anlegen,
  Beschreiben und Entfernen einer exklusiven Zufallsdatei. Ein RO-Mount endet
  dadurch kontrolliert vor der ersten Archivoperation.
- Best-Of: `var/state/bestof-lock/<slug>/<hash>.lock` serialisiert Vorgänge pro Zielbild. Das Bild wird über eine temporäre Datei geprüft und atomar veröffentlicht, bevor der Like in SQLite geschrieben wird. Bei DB-Ablehnung oder Fehler wird nur ein in diesem Vorgang neu veröffentlichtes Bild entfernt. `duplicate_image` ist die Ausnahme: Fehlt das Bild trotz bereits vorhandenem DB-Like, bleibt die neue Datei als Reparatur erhalten.

`WAL` darf für Datenbanken auf CIFS/NFS nicht verwendet werden. Der Sollzustand ist eine lokale Live-Datenbank mit `journal_mode=DELETE`.

## 6. Cache-Regel

Für Archiv-, History-, Timeline- und Kalenderdaten wird kein neuer JSON-, State- oder Dateicache eingeführt. Auch alte Cache-Rebuilds werden nicht für normale Performance-Arbeit reaktiviert.

Zulässige schnelle Metadatenquelle ist höchstens die Kamera-SQLite-Datenbank. Optimierungen erfolgen über:

- vollständige DB-Backfills,
- passende SQL-Abfragen und Indizes,
- weniger unnötige Dateisystem-Fallbacks,
- begrenzte Ergebnismengen und Pagination.

Der Bildbestand unter `cache/` und der versionierte Live-Page-Cache sind davon getrennte, bestehende Funktionen.

## 7. Externe Prozesse

Wetterkommandos, Watchdog-Schritte, OCR, ImageMagick und ffmpeg brauchen harte Zeitlimits und begrenzte Ausgabe. Relevante Variablen:

| Prozess | Einstellung |
| --- | --- |
| Wetter | `WEBCAM_WEATHER_COMMAND_TIMEOUT_SECONDS` |
| Watchdog-Shell | `WEBCAM_WATCHDOG_SHELL_TIMEOUT_SECONDS` |
| Tagesvideo | `WEBCAM_DAYVIDEO_BUILD_TIMEOUT_SECONDS` |
| Tagesvideo-Parallelität | `WEBCAM_DAYVIDEO_MAX_CONCURRENT_BUILDS`, `WEBCAM_DAYVIDEO_GLOBAL_LOCK_DIR` |
| Tagesvideo-IP-Budget | `WEBCAM_DAYVIDEO_BUILD_LIMIT`, `WEBCAM_DAYVIDEO_BUILD_WINDOW_SECONDS` |
| Admin-CLI | `WEBCAM_ADMIN_PROCESS_TIMEOUT_SECONDS` |
| OCR | `WX_OCR_TIMEOUT_SECONDS`, `WX_OCR_CONVERT_TIMEOUT_SECONDS`, `WX_OCR_IMAGE_TIMEOUT_SECONDS` |

`Infrastructure\Process\BoundedProcessRunner` ist der gemeinsame Weg für
kurzlebige Wetter-, Watchdog-, OCR-, Dayvideo-, Capture-, Wartungs- und
Admin-Prozesse. Auf den Linux-Zielsystemen verlangt er eine mit `setsid`
isolierte Prozessgruppe, leert stdout und stderr fortlaufend, hält beide
Ausgaben im konfigurierten Speicherlimit und beendet bei Timeout erst mit TERM,
danach mit KILL. Aufrufer übergeben stets eine Argumentliste; bewusste
Shell-Auswertung muss als `/bin/sh -c` sichtbar angegeben werden. Geschützte
RTSP-Daten gelangen weiter nur über stdin an ffmpeg. HLS bleibt als
langlaufender, eigens überwachter Prozess ein Spezialfall.

## 8. HTTP- und Sicherheitsmodell

- Admin-Seite und Admin-API verlangen Token-Authentifizierung.
- Mutierende Admin-Requests nutzen Double-Submit-CSRF über `admin_csrf` und `X-CSRF-Token`.
- Öffentliche Mutationen nutzen `webcam_csrf` und gleichen Origin/Referer ab.
- Der Public-API-Limiter arbeitet IP-basiert in einer lokalen SQLite-Datenbank.
- Public API, History und Captcha verwenden getrennte gehashte IP-Scopes;
  Standardbudgets sind 120/60 s, 30/60 s und 10/60 s.
- Tatsächlich gestartete öffentliche Dayvideo-Builds verwenden zusätzlich ein
  gehashtes Client-IP-Budget von standardmäßig 3/600 s. Cachetreffer und Polls
  auf einen bereits gehaltenen Ausgabelock zählen nicht.
- Interne Fehler werden öffentlich generisch beantwortet und nur serverseitig detailliert geloggt.
- Uploads prüfen Größe und Bildinhalt vor der Ablage.
- Vor PHP begrenzt der Apache-Origin Legacy-/Chunk-Requests auf 26/2 MiB. Der
  öffentliche Proxy weist unbekannte oder zu große deklarierte Bodys vor
  `ProxyPass` mit 411/413 ab. Zwei pfadspezifische Fail2Ban-Jails besitzen
  getrennte Request-Budgets; davor begrenzt die ausschließlich für Port 80/443
  zuständige nftables-Tabelle `inet webcam_proxy_guard` neue und gleichzeitige
  Verbindungen pro Quell-IP. Diese L4-Tabelle schützt bewusst das gesamte
  gemeinsame Frontend, weil der HTTP-Pfad vor TLS noch nicht sichtbar ist.
  Sie darf keine fremde Firewalltabelle leeren oder ersetzen.
- Overlay-Dateien sind auf PNG/JPEG beschränkt und werden nur über `overlay.php` ausgeliefert.
- `X-Forwarded-For` und `X-Forwarded-Prefix` gelten nur hinter
  `WEBCAM_TRUSTED_PROXIES` (exakte IP oder CIDR). Die IP-Kette wird von
  `REMOTE_ADDR` aus rechts nach links geprüft; ungültige Ketten werden komplett
  verworfen. `X-Real-IP` und `CF-Connecting-IP` sind keine Vertrauensquelle.
- Kleine JSON-Runtime-Dateien werden über `webcam_atomic_write_json()` als
  Temp-Datei im Zielverzeichnis geschrieben und anschließend umbenannt. Eine
  In-place-Aktualisierung ist nur korrekt, wenn Leser und Schreiber bewusst
  denselben geöffneten Datei-Inode per `flock` koordinieren.
- Capture-, Current- und Archivbilder sowie große Dateikopien laufen über
  `AtomicFileWriter`: streamendes Staging im Zielverzeichnis, Größen- oder
  JPEG-Prüfung und erst danach atomarer Rename. Dasselbe Muster gilt für
  EXIF-/Wetter-/Like-/OCR-Sidecars, Overlay-Uploads und Dayvideo-Neubauten.
- URLs externer HTTP-Aufrufe werden vor Fehlermeldungen um Zugangsdaten und bekannte Secret-Parameter bereinigt.

Öffentliche und eingebettete URLs müssen dokumentrelativ bleiben. Feste Prefixe wie `/webcam/` brechen Installationen unter anderen Alias- oder Proxy-Pfaden.

## 9. Backup und Wiederherstellung

`bin/backup_daily.php` koordiniert den Tageslauf, während
`SqliteBackupService`, `DailyDatabaseBackup` und `BackupRunState` Snapshot,
Zielwahl/Retention sowie Lock/Status getrennt besitzen. Die zentrale und die
Kamera-Datenbanken werden über die SQLite Online Backup API gesichert. Das ist
gegenüber `copy()` konsistent, während parallel geschrieben wird. Der
SQLite-Snapshot entsteht auf dem lokalen Dateisystem unter `/var/local/webcam`
(konfigurierbar über `WEBCAM_SQLITE_BACKUP_TMPDIR`), wird dort mit
`PRAGMA quick_check(1)` geprüft und erst als geschlossene Datei in das
Backup-Ziel kopiert. Ein fehlender oder nicht beschreibbarer Stagingpfad ist ein
sichtbarer Fehler und fällt nicht auf `/tmp` zurück. Damit führt auch ein
Backup-Ziel auf SMB/CIFS keine aktiven SQLite-Schreibzugriffe auf dem
Netz-Mount aus.

Im selben Tageslauf sichert `SidecarBackup` alle physischen Wetter- und
EXIF-`*.txt`-Dateien des abgeschlossenen Vortags aus den exakten Cache- und
Archiv-Tagespfaden. Die Dateien werden getrennt vom DB-Snapshot unter
`var/backups/sidecars/` abgelegt, über Größe und SHA-256 geprüft und atomar
publiziert. Der tägliche Lauf scannt das HDD-Archiv nicht rekursiv. Sidecar-
Fehler machen den gesamten Backupstatus `partial`, damit abhängige Jobs nicht
auf einer unvollständigen Sicherung aufbauen.

Statusdateien:

```text
var/state/backup_db.json       letzter vollständiger Lauf
var/state/backup_partial.json  letzter gezielter --slug-Lauf
```

Ein gezielter Lauf darf einen vollständigen Tageslauf nicht unterdrücken. Wiederherstellungsdetails stehen in `ops/restore-config-db-checklist.md` und `ops/config-db-cifs-runbook.md`.

Der Kamera-DB-Backfill folgt derselben Trennung: `bin/migrate_camera_db.php`
validiert nur CLI-Optionen und koordiniert den Lauf.
`CameraDatabaseMigrationSource` lädt DB-first oder explizit aus dem
Legacy-Dateibaum, `CameraDatabaseMigrationPayloadBuilder` validiert Dateien und
formt Capture-/EXIF-/Wetterdaten, und `CameraDatabaseRebuildManager` übernimmt
lokales Staging sowie die atomare Aktivierung einer neu aufgebauten DB. Die
Ziel-DB bleibt ohne `--rebuild` unverändert inkrementell beschreibbar.

## 10. Tests und Qualitätsprüfung

Auf SMB-/CIFS-Mounts Programme explizit über den Interpreter starten:

```bash
php vendor/bin/phpunit
```

Das aktuelle PHPUnit-Inventar umfasst 358 Testfälle. Die Inventarzahl ist
von den historischen vollständigen Läufen samt Assertions zu unterscheiden;
deren jeweils verifizierter Stand bleibt im `CHANGELOG.md` dokumentiert.

Weitere Prüfungen:

```bash
find src -name '*.php' -print0 | xargs -0 -n1 php -l
composer audit
npm audit --omit=dev
```

UI-Änderungen werden zusätzlich mit Playwright auf Laptop- und Mobil-Viewports geprüft. Auf diesem Mount werden die Browser vorher mit `bash bin/prepare_playwright_browsers.sh` nach `/tmp` vorbereitet.

## 11. Bekannte technische Schulden

- `admin/api.php` und `api/public.php` sind inzwischen auf die zentrale
  HTTP-Grenze reduziert; ihre Fachrouten liegen in getrennten getesteten
  Handlern. Auch `CameraDataStore` ist nur noch eine kleine Fassade:
  Schema/Migration, Capture, Likes/Best-Of und Wetter/Logo liegen in getrennten
  Repositories, während eine gemeinsame Connection Locks, Retry und Staging
  bewahrt. Kameraauswahl, DB-Hydrierung, Dateisystem-Fallback,
  Timeline-Mapping, Timeline-Navigation, Variantenauflösung, Storage-Pfade,
  Legacy-Cache sowie Kamera-, Capture- und Capture-Query-Normalisierung wurden
  ebenfalls bereits getrennt. `ImageStorage`, `ArchiveBrowser`,
  `BestOfGallery`, `stream.php`, `config/camera_store.php` und die
  Übersichtsmodule, Status-Browserlogik, Kamera-Template sowie Backup- und
  Migrations-CLI delegieren ihre großen Fachblöcke nun ebenfalls an
  kommentierte Komponenten. Kamera-Storage-Pfade werden zentral durch
  `CameraStoragePathResolver` normalisiert. Früh- und Normalrender der
  Kameraansicht beziehen gemeinsame Templatewerte, Leerzustände und Assets aus
  `CameraPagePayloadBuilder`. `bin/extract_wx_ocr.php` ist bewusst zurückgestellt.
  Weitere Trennungen bleiben entlang
  reproduzierbarer Änderungen sinnvoll.
- Routing, Antwortformatierung und Fachlogik sind in einzelnen Entry-Points noch eng gekoppelt.
- HTML-Ausgaben aus administrativ gepflegtem `info_html` bleiben eine sensible Sanitizing-Fläche.
- Bestätigte tote Diagnose- und Quellcodefragmente wurden am 16.07.2026 entfernt. `ops/open-findings.md` führt nur noch bewusst aufzubewahrende Betriebswerkzeuge und separat zu entscheidende Runtime-/Legacy-Daten.

Diese Punkte werden schrittweise entlang konkreter Änderungen entkoppelt. Ein breiter Refactor ohne reproduzierbaren Nutzen würde bei der gewachsenen Oberfläche ein unnötig großes Regressionsrisiko erzeugen.
