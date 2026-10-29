# Versionierung

Stand: 23.10.2026

## Aktueller Stand

- Offizielle Projektversion: **6.2.2**
- Aktuelle DEV-Iteration innerhalb von Version 6: **247**
- Technische Revisionen über alle sechs Entwicklungsstufen: **990**
- Basis der rückwirkenden Auswertung: Commit `bd28fca` mit Iteration 80

DEV-Iteration247: Bielefeld-Archivpilot mit privaten Originalen, gespeicherten
Bearbeitungsrezepten und bedarfsgenerierten Varianten; unveraenderliche
Slotkonflikte liefern eine an Upload-ID/Hash gebundene409-Antwort.
128Tests/938Assertions; Pi-Gegenstueck separat versioniert und auf Bielefeld
und Testcam installiert. Kein neuer PROD-Rollout und keine DB-Migration
bei dieser Commit-Abnahme. Details: `../ops/bielefeld-deferred-images/README.md`
und `../ops/bielefeld-dev-delay-20261022.md`.

DEV-Iteration246: Bildbearbeitung mit geordneter Bereichsliste und echter
Vorschau im Kamera-Admin; verpflichtender Stromberg-Beschnitt vor optionalen
Filtern. Details: `../ops/image-processing-2026-10-18.md`.
Version6.2.2 auf ausdruecklichen Betreiberauftrag; npm-Metadaten synchron.
Produktive Ausrollung wird gesondert im Betriebsprotokoll nachgewiesen.

DEV-Iteration242: neun Review-Befunde korrigiert: Archivschutz, EOS-Cleanup,
JPEG-/Pool-Fehlerbehandlung, Current-Reihenfolge und Wiederherstellung,
physische Aufnahmezeit, Poll-Zeitplanung, Testserver-Isolation und begrenzte
Dedupe-Locks. Nur DEV-/Pi-Quellen; kein PROD-/Kamera-Rollout.
Nachweise: `../ops/review-fixes-2026-10-17.md`.

DEV-Iteration241: „Aktuell“ verlaesst die Timelapse und oeffnet das aktuelle Bild.

DEV-Iteration240: Kameraanlage provisioniert Current-Verzeichnisse im bereits
freigegebenen Cache-Baum. Alte Current-URLs bleiben erreichbar; bestehende
Legacy-Verzeichnisse bleiben erhalten. Keine Root-Aktion/Apache-Neustarts
bei Neuanlage. Nur DEV; Details in ops/admin-current-provisioning-20261016.md.

DEV-Iteration239: Cache-Retention grenzt Jahres-/Monats-/Tagesbaeume vor
der Dateipruefung anhand des lokalen Ablaufdatums ein. Datei-mtime und
vollstaendige Archivpruefung bleiben Pflicht. Nur DEV; Nachtabnahme offen.

DEV-Iteration238: graublauer Seitenhintergrund wie die Startseite bei
weiterhin hellen Inhaltsflaechen auf allen vier Zielseiten. Nur DEV.

DEV-Iteration237: Stromberg als dritte Mehrkamera-Vorauswahl, nur DEV.

DEV-Iteration236: helle Seiten-/Inhaltsflaechen und einheitliche Rahmen fuer
Wetter, Status, Mehrkamera und globales Best-Of. Navigation unveraendert.
Nur DEV.

DEV-Iteration235: Log-Endpunkt bereinigt Zugangsdaten und liefert bei
Schreibfehler500. Fehlende Pi-Fehleruebertragung im Pi-Quelltext ergaenzt,
auf .16/.17 und DK0RE installiert und mit Testberichten abgenommen.
Neuer PROD-Anwendungscode unveraendert.

DEV-Iteration234: gemeinsamer Navigationskopf auch in Mehrkamera; der Titel
„Webcam-Übersicht“ ist auf allen Seiten mit diesem Kopf ein Startseiten-Link.
Nur DEV, kein PROD-Rollout. Historische Release-Staende siehe Betriebsprotokolle.

DEV-Iteration225: Zeitpfeile und Zeit-Pulldown ueber Tagesgrenzen ergaenzt.
Bereits geladene Nachbartageskanten liefern das erste/letzte vorhandene
Bild, ohne zusaetzliche Archivscans. Nachbartag im Pulldown mit Datum;
Stromberg04.10.23:50 <->05.10.00:00 im Browser per Pfeil und Liste abgenommen.
8 PHP-Tests/75Assertions,1 Browserfall mit vier echten Klickwechseln.
Details: ops/timeline-midnight-2026-10-05.md. Nur DEV.

DEV-Iteration224: ISO-Auftrag waehrend Cooldown behalten und nach Fristende
beim authentifizierten Poll einmalig zustellen. Neue Messungen ersetzen ihn;
Konfigurationswechsel und abgelaufene Messwerte verwerfen ihn weiterhin.
Strombergs zuvor verworfene23:50-Messung nach Sicherung neu bewertet;
ISO800 um23:55:47 zugestellt. Kein PROD-Rollout.

DEV-Iteration223: separate Stromberg-Bildbruecke von DEV zum alten PROD.
Altprotokoll mit Formularauthentifizierung, exakter Slotquelle, expliziter
Uploadbestaetigung und90s Long-Poll; leere Altserver-Antwort gilt als idle.
Betreiber aktivierte eigene .42-Instanz23:42, bestehende Helper unveraendert.
Erste echte Weiterleitung per explizitem --forward-slot um23:46 bestaetigt,
Originalhash und Alt-PROD-Archiv geprueft. Automatischer23:50-Slot um23:52:23
ebenfalls bestaetigt, Originalhash und Archiv stimmen;
ops/stromberg-dev-master-20261004/.
Kein Rollout der Webanwendung auf neuen PROD.

DEV-Iteration 222: allgemeine ISO-Obergrenze in Backend und Admin-Formular
auf800 angehoben. Minimum100 und Standardmodus Aus bleiben erhalten;
explizite abweichende Kameragrenzen werden vom Code weiterhin respektiert.
Kein PROD-Rollout. Iterationen219–221: automatische ISO-Regelung,
Erhoehung ab25s und Rueckregelung unter10s; Details im ISO-Runbook.
Release6.2.1/221 am04.10.auf PROD ausgerollt, dort ISO weiterhin aus.

Release **6.2.0** (03.10.2026): auf Betreiberwunsch um eine Minor-Version
erhöht. Bündelt die bisherige Entwicklung bis DEV 218 einschließlich
Mehrkamera/Diashow mit Zufall, Archivfilmen und DWD-Vorhersage/Radar.
Reine Versionsdeklaration erhöht die Iteration nicht. Anschließender
Betreiberauftrag: PROD am 03.10. um 23:13:43 CEST auf 6.2.0/218 aktualisiert.

DEV-Iteration 218: Diashow mit wählbarer Zufallsauswahl aus allen öffentlichen
Kameras und dem gesamten indexierten Archivzeitraum. Begrenzte, indizierte
DB-Auswahl statt Verzeichnisscan; keine Testkameras, Duplikate oder Zukunfts-
Slots. Einzelbild, Kameraname und Aufnahmezeit; 5/10/20 s, Pause und manuelle
Weiterschaltung. Nur DEV; neuer öffentlicher GET presentation/random.

DEV-Iteration 217: Mehrkamera-Uhrzeit als klickbare, scrollbare Liste analog
zum Tagesvideo: 144 Zehn-Minuten-Werte, Maus/Touch/Tastatur, Schließen per
Auswahl/Außenklick/Escape. Tagesregler und ±10-Minuten-Tasten synchronisieren
die Anzeige; in Diashow ist die Uhrzeitauswahl deaktiviert. Nur DEV.

DEV-Iteration 216: zusätzlicher Vorhersagezeit-Schieber entfernt. Die vier
Diagramme behalten gemeinsame Stundenwahl per Maus/Touch, ergänzt um direkte
Tastaturwahl mit Pfeilen/Home/End. Zeitstempel bleibt sichtbar. Radar unverändert.

DEV-Iteration 215: Jahresfilm-Fehler als erschöpftes Build-Kontingent (429)
belegt. Auswahländerungen starten erst mit „Video laden“; „Neu erstellen“
erzwingt Refresh. 429 nennt Wartezeit; Budget bleibt unverändert. Historische
sm/la/lm-Varianten ohne Größenmetadaten anhand der zentralen Profile gewichtet,
damit Monats-/Jahresfilme keine 114-Pixel-Thumbnails bevorzugen. Nur DEV.

DEV-Iteration 214: Tagesvideo verwendet klickbare, scrollbare Auswahllisten
im Stil der Timeline für FPS, Auflösung, Zeitraum und tägliche Uhrzeit.
Uhrzeit nur bei Monat/Jahr, 144 Zehn-Minuten-Werte. Optionen-Menü nach
Playerstart ausgeblendet; Listen werden über dem Video platziert und schließen
bei Auswahl, Außenklick oder Escape. Keine Backend-/PROD-Änderung.

DEV-Iteration 213: Zeitraum-Dropdown unter Optionen → Tagesvideo neben FPS
und Auflösung: Ganzer Tag, Sonnenaufgang, Abend, Nacht, Monatsfilm, Jahresfilm.
Monats-/Jahresfilm mit Uhrzeit; Datum aus der Kameraansicht. Filmreiter und
zugehöriger Controller aus Mehrkamera entfernt. Auswahlwechsel bevorzugt
Cache und verwirft alte Antworten/Polls; Schließen stoppt Browseranfragen.

DEV-Iteration 212: Vorhersage-/Radarstandorte auf Bielefeld, Steinhagen und
Stromberg reduziert. Bielefeld verwendet den bisherigen Standort der Kamera
`sparrenburg` (bisher Bielefeld-Sparrenburg). Andere Kameralisten und deren
Messwerte bleiben unverändert; keine Live-Config-/PROD-Änderung.

DEV-Iteration 211: Vorhersage als vier responsive SVG-Diagramme statt Tabelle.
Temperatur, Niederschlag pro Stunde, Wind/Böen und Bewölkung mit gemeinsamer
Stundenauswahl über Maus, Touch oder Tastaturregler. Fehlende Werte bleiben
Lücken, Regen beginnt bei null und Bewölkung verwendet 0–100 Prozent.
Keine zusätzliche Bibliothek, keine Backend-/PROD-Änderung.

DEV-Iteration 210: globale Navigation nennt die Vergleichsseite „Mehrkamera“.
Diashow und Filme bleiben dort; Vorhersage/Radar ist ein eigener aufklappbarer
Bereich der globalen Wetterseite mit getrenntem Controller. Beide neuen
Zusatzlinks aus Kamera-Menüs entfernt. Alte `explore.php#weather`-Links führen
zur globalen Wetterseite. Keine Änderung an Datenquellen oder PROD.

DEV-Iteration 209: neue DEV-Seite `explore.php` für Mehrkamera-Vergleich,
Snapshot-Diashow, solare Zeitraffer sowie Monats-/Jahresfilme. Archivwahl
indexbasiert und auf 300 Bilder begrenzt; für langfristige Filme nur eine
benötigte Bildvariante prüfen. MP4 übernimmt bestehende globale Build-/IP-
Limits; fehlende Bilder erzeugen vor HTTP 202 eine sichtbare Fehlerantwort.
DWD MOSMIX und RADOLAN-RY mit zentralem Cache, Quellen-/Zeitangabe und
klarer Trennung von Kameramesswerten. Keine Config-DB-/PROD-/Pi-Änderung.
Bedienung, Grenzen, Performance und Tests (lokale Betriebsdokumentation, nicht im Export).

DEV-Iteration 208: ausdrueckliche USB-Startvorbereitung per POST liefert
202/ready=false beim Relay-/Playlist-Hochlauf und 200/ready=true erst mit
valider Playlist. Der Player wartet vor dem ersten Medienabruf, maximal
60 Sekunden pro Vorbereitungsphase; Stop bricht Polling ab. Passive Probes,
Foto-/Privacy-Regeln und echte Fehler bleiben getrennt. 13 bestehende USB-
Tests plus neue Vorbereitung (22 Assertions) und Playwright-Vertrag bestanden.
Echter DEV-Start 19:31:13 HTTP 202, Folgeabruf 19:31:33 bereit/200.
Betreiberstart 19:33:27 nach acht Sekunden bereit; bis 19:34:02
18 Playlist-/zehn Segment-200, keine Browser-503 oder Sperrfehler.
Betrieb und Grenzen (lokale Betriebsdokumentation, nicht im Export).

DEV-Iteration 207: Testcam-Relay trennt Playlist- und Segment-Fetchs;
kurze Sperrkollisionen warten begrenzt und nutzen danach den gemeinsamen
Cache. Admission bleibt atomar mit 500-ms-Frist, Fetch-Locks maximal 2,5 s;
Upstream weiterhin 2 s. 13 gezielte USB-Tests/121 Assertions bestanden,
einschliesslich vorher reproduzierter Parallelfehler. Nur DEV aktiv;
nachfolgende Live-Abnahme siehe Iteration208, Dauerlauf/Fotopause offen.
Nachweis (lokale Betriebsdokumentation, nicht im Export).

DEV-Iteration 206: zeitlich begrenzte Testcam-Streamdiagnose per lokalem
RAM-Marker (maximal 30 Minuten): Aufnahme-/Privacy-Sperren, Viewer-/Locklimit,
Upstream-Status, Curl-Fehlernummer und Abrufdauer. Keine Zugangsdaten/Medien
im Log, keine Aktivierung durch Browserparameter. Neun gezielte Tests mit
89 Assertions bestanden; kein PROD-/Pi-Rollout. Betrieb (lokale Betriebsdokumentation, nicht im Export).

DEV-Iteration 205: Review-Korrekturen für Log-Maskierung, HLS-Cache und
Segmentlinks, DB-basierte Timelapse-Auswahl über vollständige Zeiträume und
aktuelle Statusdialoge. Pi-Admin-Speicherfehler separat im Camera-RPi-Checkout
korrigiert. Keine Live-DB-Änderung, kein PROD-/Pi-Rollout.
Änderungen und Abnahme (lokale Betriebsdokumentation, nicht im Export).

DEV-Iteration 204: direkter Rueckweg vom Testcam-Stream zum aktuellen Bild,
Playerfreigabe bereits beim Linkklick und DB-Auswahl des neuesten Monatsbilds
vor dem Laden seiner Tagesnavigation. Dadurch keine komplette Monatshydrierung
beim Einstieg in Livebild/Timelapse. Abnahme (lokale Betriebsdokumentation, nicht im Export).

PROD steht auf **6.2.0 / Iteration 218**, aus `77c7e77` seit 03.10.2026
23:13:43 CEST. Alle 455 Laufzeitdateien bytegleich geprueft; Live-Config
unveraendert. Rollout und Rueckweg (lokale Betriebsdokumentation, nicht im Export).
Historische DEV-only-Vermerke beschreiben ihre jeweilige damalige Abnahme;
mit diesem Rollout sind alle Webcam-Laufzeitkorrekturen bis 218 in PROD.

Die maschinenlesbare Sollversion steht in der Datei `VERSION`, die aktuelle
technische Iteration in `ITERATION`. Die Versionsangaben in `package.json` und
`package-lock.json` werden auf denselben Versionswert gesetzt.

DEV-Iteration 203: Auf expliziten HLS-Seiten mit MJPEG-faehiger Kamera
fuehrt „Livestream“ ueber einen normalen Seitenwechsel zur Kameraansicht
ohne HLS-Parameter. So wird der fehlende MJPEG-Player neu gerendert und
HLS beendet. Nur DEV; Medien-/Proxy-/DB-Konfiguration unveraendert.
Abnahme (lokale Betriebsdokumentation, nicht im Export).

Iteration 202: abgelaufene gueltige HLS-Segmente liefern 410/no-store;
der hls.js-Player erneuert Decoder/Playlist einmal pro 60 Sekunden und
stoppt bei erneutem Fehler sichtbar. Neue Runner verwenden eindeutige
Segmentnummern, alte Playlists gelten nicht mehr als startbereit.
Keine Proxy-/PROD-/Live-DB-Aenderung. Abnahme (lokale Betriebsdokumentation, nicht im Export).

Iteration 201: RTSP-HLS mit Videobedienung,
direkter Menueauswahl und sichtbaren Autoplay-/Ladefehlern. Player-Stop
entfernt Ereignishandler und verwirft spaete Probeantworten; Videoklicks
starten keinen neuen Stream. Firmennetz-Abnahme noch offen. Kein neuer
MP4-Transport, keine Live-DB-/PROD-Aenderung. Details:
`ops/stream-company-browser-2026-09-19.md`.

Iteration 200: Livestream-/Datenschutzarbeiten
193–198 zusammenhaengend versioniert, Protokoll-/Betriebsdokumentation
ergaenzt. Kein erneuter Rollout durch diesen Commit, insbesondere kein
PROD-Gesamtrollout und kein neuer Kamera-/Treiber-/GPIO-Test.

Iteration 199: stabile automatische Capture-ID
aus dem Sollslot, unabhaengig von verspaeteten Polls anderer Pool-Ziele.
Manuelle Auftraege bleiben separat. PROD erhaelt nur den gezielten Hotfix,
keinen Gesamtrollout der zwischenzeitlichen DEV-Funktionen.

Der separate Hotfix-Commit `d075f77` enthaelt keinen Livestream-/Datenschutzcode;
diese vorherigen DEV-Arbeiten werden erst mit Iteration 200 versioniert.

Iteration 198: authentifizierte Standortmetadaten
für optionale Pi-Aufnahmefenster; zeitlich begrenzte Datenschutz-Pausenmeldung
für Watchdog und HLS. Ohne Pi-Konfiguration keine Aktivierung. Keine PROD-Änderung.

Iteration 197: Startwache nur für neue Testcam-
Streams ab xx:x9. Bestehende Viewer laufen bis zur realen Foto-Unterbrechung
weiter; kein zeitgesteuerter globaler Stop im Video-Poll.

Iteration 196: USB-Livestream im ursprünglichen
Kameralayout mit Optionen/Timeline/Seitenkopf statt eigenständiger Seite.
Komponente nur für die Testcam; alte Playerlinks leiten auf die Kamera weiter.

Iteration 195: Tabwechsel beendet den Testcam-Stream
nicht mehr. Fünf-Minuten-Frist bleibt unverändert und wird nach Browser-Suspend
bei Rückkehr erneut geprüft; Stop/Seitenwechsel bleiben wirksam.

Iteration 194: Testcam unter Optionen → Livestream;
Startwartezeit ab xx:x8 bis zum bestätigten Foto, fünf Minuten ab Wiedergabe.
Keine Live-DB-/Pi-/PROD-Aenderung; andere Kameras unverändert.

Iteration 193: opt-in USB-Testcam-Stream mit
eigenem HTTPS-Videorelay, RAM-HLS-Cache, Foto-Vorrang auf dem Pi und
Start/Stop-Testplayer. Keine Live-DB-/PROD-Konfigurationsaenderung.
Details: `ops/usb-stream.md`. Raspi-Version bleibt separat 1.0.

Iteration 192: Die Archivpruefung verlangt fuer
exakt leere EXIF-Quelldateien keine Archiv-Sidecars. RTSP-Bilder ohne Kamera-EXIF
werden dadurch nicht faelschlich blockiert. Bildpruefungen, nichtleere EXIF-
Quellen und Wetter-Sidecars bleiben geschuetzt. Keine Datenbank-/Archiv-Aenderung
im Code-Commit. Am 13.09. um 10:20:30 CEST als einzelner Hotfix `5a28ead`
auf PROD-Basis 188 ausgerollt, nicht als vollstaendiges Release 192.
Freigegebener Cache-Lauf fuer Bielefeld-Nord erfolgreich; Archiv unveraendert.
Details: `ops/archive-sidecar-bielefeld-nord-2026-09-11.md`.

Iteration 191: Bereits angenommene exakte
Archivslots erzeugen beim verspäteten automatischen Command-Poll keinen
zweiten Capture-Auftrag. Nur RO-Abfrage der Kamera-Metadatenbank; manuelle
Aufträge bleiben vorrangig. Zwei SQLite-Tests/acht Assertions sowie echte
verspätete Legacy-/Chunk-Runde um 01:00 abgenommen. Kein PROD-Rollout und
keine Live-Konfigurationsänderung. Die Raspi-Variante hat separat Version 1.0.
Details: `ops/received-capture-slot-2026-09-13.md`.

Iteration 190: Sparrenburg-Wettervorlage mit
IPv4 und begrenzter Fehlerdiagnose statt stillem Exit 0. Bestehende
3/8-s-Zeitlimits und Wetterformat unveraendert; 9 Tests/46 Assertions.
Separat freigegebene DEV-Livekonfiguration seit 12.09. 16:43:54 CEST auf
Version 2060, Sicherung und Scope-Pruefung dokumentiert. PROD bleibt 188
mit unveraendertem Wetterkommando; keine Live-DB im Commit.
Details: `ops/sparrenburg-weather-diagnosis-2026-09-12.md`.

Iteration 189: opt-in Slot-URLs fuer HTTP-Pull,
begrenztes Warten auf noch nicht veroeffentlichte Originale ohne alte
Current-Bilder oder Watchdog-Fehler. Stromberg nach ausdruecklicher
DEV-Freigabe am 12.09. auf datierte lokale PROD-Bildquelle umgestellt;
Keys/Intervalle/PROD bleiben unveraendert. Kein PROD-Rollout von 189.
Details: `ops/slot-snapshot-source.md`.

Iteration 188: isolierter allgemeiner Browser-
Abnahmelauf mit generierten Bildern und privaten SQLite-Fixtures. CI ohne
Live-DEV-Adresse, Desktop und Mobil. PROD-Laufzeitcode am 12.09.2026 um
00:13:05 CEST aus `25bc9df` auf 188 ausgerollt; Tests/CI nicht
mitkopiert, Live-Konfiguration unveraendert. Details: `ops/ssd-production-rollout.md`.
Details: `tests/isolated/README.md`.

Iteration 187: eindeutige Boolean-Umgebungswerte
fuer Beispiel-DB-Freigabe und Kamera-DB-Schreibschutz. `0`/`false` sind aus,
ungueltige Werte werden abgelehnt. Keine Live-DB-/Environment-Aenderung,
kein PROD-Rollout. Details: `ops/boolean-environment.md`.

Iteration 186: Luftfeuchte im Kamera-Wetterdiagramm
und direkt adressierbare Wetterauswertung je Kamera. Testkameras bleiben in
globalen Listen verborgen; direkte Wetterseiten sind noindex. Keine DB-
Aenderung, kein PROD-Rollout. Details: `ops/camera-maintenance-weather.md`.

Iteration 185: aufklappbare SSH-/Browser-Anleitung
direkt im Kameraeditor bei den Wartungsbuttons. Neuer Asset-Versionsstand,
damit Browser die nachtraeglich ergaenzte Hilfe nicht aus Cache 184 laden.
Desktop-/Mobil-Playwright bestanden; kein PROD-Rollout.

Iteration 184: kamerabezogene HTTPS-Wartungsfreigabe
mit fester 30-Minuten-Grenze und optionaler DHT22-Wetteruebertragung beim Upload.
Wetterkommandos behalten Vorrang. Keine Schemaaenderung, kein PROD-Rollout.
Relay, Edge und Pi installiert; SSH, manuelles Schliessen und automatischer
30-Minuten-Ablauf am 11.09. abgenommen. DHT-Ingest ebenfalls bestaetigt.
Details: `ops/camera-maintenance-weather.md`.

Iteration 183: kamerabezogene Remote-Fokusqueue
fuer gphoto2 mit fuenf Modi, Auth/CSRF, Capability-Pruefung und 15 Minuten
Ablauf. Der Pi fuehrt beim naechsten Poll unter gemeinsamer Kamerasperre aus;
keine beliebigen Shellbefehle, keine Live-DB-Aenderung, kein PROD-Rollout.
Details: `ops/camera-focus.md`.

Iteration 182: kamerabezogener manueller
Aufnahmeauftrag im Admin, ohne globalen Ausloeser. Auth/CSRF, atomare Queue,
30-Sekunden-Pause und fuenf Minuten Ablauf. Dazu kamerabezogene Pi-Upload-
Vorgabe (Lokal/Legacy/Chunked) ueber den authentifizierten Helper-Poll.
554 Tests / 3221 Assertions.
PROD bleibt 180. Details: `ops/manual-camera-capture.md`.

Iteration 181: frei benannte Testkameras koennen
im Admin aus oeffentlichen Sammlungen ausgeblendet werden, bleiben aber per
bekanntem Slug einschliesslich eigener Galerie und Aufnahmefunktionen nutzbar.
Keine Schema-/Live-DB-Aenderung, kein PROD-Rollout. Bedienung und Abnahme:
`ops/test-camera-visibility.md`.

Iteration 180: Cache-Retention schliesst Jahres-
und Overlay-Baeume vor der Rekursion aus. Capture-Backups respektieren bei
passender Vortagssicherung den expliziten Vollbackup-Plan samt Laufzeitbudget.
546 Tests / 3072 Assertions bestanden; reale Mitternachtsabnahme steht aus.
PROD seit 09.09.2026 00:48:19 CEST ebenfalls Iteration 180 aus `7fe60de`;
keine Live-DB-/Zeitplanaenderung. Messwerte und Rueckweg:
`ops/midnight-storage-load-2026-09-09.md`.

Iteration 179: hostlokaler Boot-Schutz auf .34
wartet auf die wirkliche IPv4-Adresse/NAS-Route und wiederholt ausschliesslich
den initialen Apache-Start bei voruebergehendem Mountfehler. Keine Aenderung
am Anwendungscode oder an Datenbanken; PROD-Code bleibt Iteration 177.
Live-Aktivierung ohne Apache-/Netzwerkneustart; echter Reboot-Test am
07.09.2026 um 20:08:52 mit automatischem Apache-Start um 20:09:04 bestanden.
Details: `ops/apache-boot-recovery-2026-09-07.md`.

Iteration 178: Betriebshelfer fuer die abgenommene
Grafana-/Mailpit-Alarmierung und den reservierten Fail2Ban-Ban-/Unban-Test
sowie konsolidierte Betriebsdokumentation. Keine Aenderung am Anwendungscode
gegenueber 177; dieser Commit wird nicht automatisch nach PROD uebertragen.

Iteration 177: Review R1–R8 ergänzt vollständige
IP-Vertrauensprüfung, absolute Limiter-Ablaufzeiten, Capture-Ressourcengrenzen,
Admin-Versionskonflikte, stabile Chunk-Sperren, nichtblockierende Prozesseingabe,
kalenderfeste History und eine optionale numerische Wetterprojektion in SQLite.
R8 ist nach Betreiberfreigabe am 06.09.2026 auch auf allen fünf DEV-Live-DBs
aktiviert; jede Datenbank wurde vorher konsistent gesichert und geprüft.
Details, Messwerte und Rückweg: `ops/review-hardening-2026-09-06.md`.

Seit Iteration 176 ist Admin-JSON auf 1 MiB begrenzt,
Overlays auf 4096 Pixel je Seite / 8.388.608 Gesamtpixel. Upload-Logschreiber
verwenden denselben exklusiven Lock; der Capture-Backup-Anstoss reserviert
atomar pro Kamera und gibt bei belegtem Ausfuehrungslock die Reservierung frei.
Grenzen, Testumfang und Rueckweg: `ops/admin-resource-hardening.md`.

Seit Iteration 175 erhalten Watchdog-Speicherungen und alte
Resetfolgen erhalten denselben strikten 20-Schritte-/300s-Delay-/60s-HTTP-Vertrag;
HTTP(S)-Weiterleitungen und echte HTTP-Gesamtlaufzeit sind ebenfalls begrenzt.
Admin-Hinweise, Negativ-/Laufzeit-/Speichertests: `ops/watchdog-config-hardening.md`.
Vorheriger PROD-Stand war Version 6.1.0, Iteration 177. PROD unter
`/var/www/html/webcam2` wurde am 06.09.2026 um 22:19:53 CEST aus
`977f06a906f002cd766f3745ca138c4887bb6f86` aktualisiert; Wetterprojektion
anschliessend separat aktiviert und alter Bielefeld-Verweis nach erneuter
Freigabe repariert. Version 6.1.0/174 (`bc0ccd6`) ist der gesicherte vorherige
PROD-Stand. Code, Config-DB und Kamera-DBs
wurden vor dem Rollout gesichert; Live-Konfiguration und Bildbestand wurden
nicht aus DEV uebernommen. Abnahmegrenzen: `ops/ssd-production-rollout.md`.
Der vorherige Stand Version
6.0.4/Iteration 104 mit gezielt ergänzten Chunk-v1-Laufzeitdateien bleibt
ausschließlich als historischer Rollback-Kontext dokumentiert. Der Bielefelder Altbestand ab
14.08.2026 06:10 ist als Duplikatbestand markiert; Wetter und EXIF blieben
erhalten. Der anschliessende Variantenabgleich synchronisiert veraltete
Dateigroessen mit dem realen Bildbestand.

Der vollständige PROD-Rollout auf Iteration 164 lief am 02.09.2026 nach dem
frischen Backup `20260902_075114`. Config-DB, fünf Kamera-DB-Snapshots und
2.502 Sidecars wurden fehlerfrei gesichert; alle sechs SQLite-Snapshots
bestanden `PRAGMA quick_check`. Das vorangehende Produktionsrelease liegt als
`/var/local/webcam/releases/webcam2-code-before-iteration164-20260902_075114.tar.gz`
vor (SHA-256
`a45a74a38e1e14eb3c9c7d301e69e54ec4790a3db3ec93b53c72e6b2c68b57c2`).
Alle 417 regulären Laufzeitdateien aus dem 528-Einträge-Releasearchiv stimmen
bytegenau mit dem gewählten Commit überein. 179 PHP-Syntaxprüfungen, sämtliche
JavaScript-Syntaxprüfungen, 373 PHPUnit-Tests mit 2.233 Assertions,
Apache-Konfiguration/Reload, öffentliche Seiten und APIs sowie die
Zugriffsschutzgrenzen bestanden. Alle fünf PROD-Kameras waren nach dem
Rollout innerhalb ihres 600-Sekunden-Intervalls frisch; neue Warnungen,
Fehler oder HTTP-5xx-Antworten entstanden nicht.

Der vorherige vollständige PROD-Rollout lief am 30.08.2026 nach einem Backup
`20260830_193202`. Config-DB und fünf Kamera-DB-Snapshots bestanden jeweils
`PRAGMA quick_check`; die Sidecars meldeten `copied=0 unchanged=2788 failed=0`.
Das vorangehende Produktionsrelease liegt als
`/var/local/webcam/releases/webcam2-code-before-6.0.7-20260830_193907.tar.gz`
(SHA-256 `6640372b0c686f08c681f81dd3307a223dcbdfe3c7485e270dc4ff8d973cccfc`)
vor. Das 456-Einträge-Releasearchiv stimmte nach der Auslieferung bytegenau
mit PROD überein; PHP-Lint, Apache-Konfiguration, Reload sowie öffentliche
Routen, Public-API und die geschützte Admin-Grenze (401 ohne Token) bestanden.

Iteration 166 schließt den zweiten Pfad für veraltete Vorschaubilder auf der
Startseite. „Zuletzt angesehen“ speicherte bislang neben Kamera-Link und
Aufnahmezeit auch die Bild-URL im Browser-Local-Storage. Dadurch konnte ein
Browser nach der serverseitigen Korrektur aus Iteration 165 weiterhin etwa
`20260702_080000_sm.jpg` aus einem nicht mehr vorhandenen Cache-Verzeichnis
anfordern. Solche erwartbaren 404 werden nicht erst im Browser ersetzt, weil
sie am vorgeschalteten Webserver bereits den Fail2Ban-Zähler erhöhen würden.
Neue Verlaufseinträge speichern deshalb keine Bild-URL mehr, alte Einträge
verlieren sie bei der Normalisierung, und die Übersicht verwendet stets die
aktuell vom Server aufgelöste Vorschaubild-URL der Kamerakarte.

Der technische Commit `cc9d550287c50ba6f869fec6ad0d9efc4a25a1dd` wurde nach
dem vollständigen Backup `20260902_090245` ausgerollt. Config-DB und fünf
Kamera-DBs bestanden `PRAGMA quick_check`; die Sidecars meldeten
`copied=0 unchanged=2502 failed=0`. Das Releasearchiv liegt unter
`/var/local/webcam/releases/webcam2-code-6.0.7-cc9d550287c5-20260902_090737.tar`
(SHA-256 `12e4294b0b96c060d9aa728edbc442b9d106147f7a14225cfce1a41d4614efa6`),
der Rückweg unter
`/var/local/webcam/releases/webcam2-code-before-iteration166-20260902_090737.tar.gz`
(SHA-256 `ca79b331c33c9c9ac9aa43c94e10dd7c59c0ba6b6e87f02c54c6fcf922b47bd0`).
Alle 417 regulären Laufzeitdateien waren bytegleich. 192 PHP- und 50
JavaScript-Dateien, Apache-Konfiguration/Reload, öffentliche HTTP-Smokes sowie
die 401/403-Schutzgrenzen bestanden. Die vollständige PHPUnit-Suite bestand
mit 373 Tests und 2.237 Assertions. Ein Browser-Regressionstest legte gezielt
die alte URL im Local Storage ab und bestätigte, dass keine Anfrage auf diese
URL mehr entsteht und stattdessen das aktuelle Kartenbild erscheint.

Iteration 173 begrenzt Upload-Requests bereits im Apache vor der
PHP-Authentifizierung. Der Legacy-Endpunkt erhält 26 MiB Request-Body für den
aktuellen 25-MiB-Anwendungsdefault, Chunk-v1 nur 2 MiB für höchstens 1 MiB
Nutzlast. Im echten DEV-Apache erreichte eine kleine Anfrage weiterhin PHP und
endete ohne Schlüssel mit HTTP 403; 2 MiB plus ein Byte wurden bereits vom
Webserver mit HTTP 413 abgewiesen. Ein kommentiertes Betriebs-Runbook und eine
Edge-Vorlage beschreiben alle vier bekannten Webcam-Pfade am öffentlichen
Reverse Proxy. Der erste globale `LocationMatch`-Versuch war dort wirkungslos
und lieferte 404 statt 413; die korrigierte Regel prüft `Content-Length`
vHost-lokal vor `ProxyPass`. Die reale Abnahme bestätigte 403, 413 und 411 sowie
0 übertragene Body-Bytes bei der Übergröße. Auch über die öffentliche
DNS-/Fritzbox-Strecke antwortete der Edge-Guard mit 413. `mod_qos` wurde wegen
des nicht unterstützten MPM prefork verworfen; Rate und Parallelität waren an
diesem Zwischenstand noch ein separater betrieblicher Restpunkt und folgen in
Iteration 174. Automatische Tests halten Origin-Grenzen und Edge-Vertrag fest.
Die vollständige Regression bestand nach dieser Edge-Korrektur mit 392 Tests
und 2.351 Assertions.

Iteration 174 liefert die beiden noch fehlenden, voneinander getrennten
Schutzschichten für den öffentlichen Proxy. Fail2Ban zählt ausschließlich
abgeschlossene POST-Anfragen an den Legacy- beziehungsweise Chunk-Endpunkt;
die getrennten Budgets liegen bei 12 und 120 Versuchen pro 60 Sekunden und
sperren eine überziehende Quell-IP für zehn Minuten auf Port 80/443. Vor
Apache begrenzt die isolierte nftables-Tabelle `inet webcam_proxy_guard` je
Quell-IP neue Frontend-Verbindungen auf 30/s nach Burst 60 und gleichzeitig
nachverfolgte Verbindungen auf 64. Der kommentierte Installer prüft seine
Nutzdateien per SHA-256, validiert Fail2Ban, nftables und systemd vor der
Aktivierung und entfernt bei einem Teilfehler nur seine eigenen neuen Namen.
Die gezielten Verträge bestanden mit drei Tests und 55 Assertions, die
vollständige Suite mit 395 Tests und 2.406 Assertions; Bash-, Filter-,
nftables- und systemd-Syntax waren fehlerfrei. Die Live-Aktivierung auf `.2`
ist seit 05.09.2026, 22:12:10 CEST bestätigt. Die betriebliche Abnahme bleibt
bis zum Testban mit reservierter Adresse und einem legitimen Chunk-Transfer
offen. Die beobachtete nächste Legacy-Runde der drei bereits zuvor sendenden
Quelladressen endete mit HTTP 200 ohne protokollierten Ban der neuen Jails.

Zum selben Iterationspaket gehoeren die Proxy-Fail2Ban-Bestandsreparaturen
(05.09., 23:01 CEST), die vHost-/WP-Erweiterung (23:36:08 CEST) und die
Nachpruefung von Backup-Datum, WP-Unterverzeichnissen und Secret-Kodierung
(06.09., 00:07:29 CEST). 1060 native Filter-/Datum-/IP-Tests und der isolierte
Reload-/Rueckweg-Test bestanden. Der Betreiber bestaetigte zwei erhaltene
WP- und 24 ENV-Bans samt nftables-Sets und referenzierenden Regeln. Apache
und PROD-Code wurden dabei nicht veraendert. Details und Abnahmegrenzen:
`ops/proxy-fail2ban-refinement-2026-09-06.md`.

Iteration 172 schließt den künstlichen-Like-Pfad über den weiterhin
erreichbaren Legacy-Endpunkt `like.php`. Ein gemeinsamer, kommentierter
Request-Guard verlangt nun an beiden Like-Endpunkten eine einmalig
verbrauchbare Captcha-Challenge und belastet anschließend dasselbe
IP-basierte SQLite-Budget. Dessen Grenzen folgen
`likes_window_seconds`/`likes_max_per_window`; rotierende Session-Cookies oder
ein Wechsel zwischen `like.php` und `bestof-toggle.php` erzeugen kein neues
Kontingent. Die bisherige Session-Duplikatprüfung bleibt zusätzlich erhalten.
Die vollständige Regression bestand mit 390 Tests und 2.333 Assertions.

Iteration 171 bereinigt historische Cache-Tage ohne Originalaufnahmen. Enthält
ein alter Cache-Tag nur noch abgeleitete Varianten und existiert der zugehörige
Archivtag, darf der Tag ohne Warnschleife entfernt werden. Fehlt der Archivtag,
bleibt der Bestand fail-closed erhalten. Ein neuer Regressionstest bestätigt
die Löschung solcher Artefakte ohne `cache retention archive verification failed`.

Iteration 170 schließt die Umgehung des öffentlichen SQLite-Rate-Limits durch
rotierende Session-IDs. Reguläre Public-API-Requests werden nun primär und
ausschließlich anhand der von `ClientIpResolver` ermittelten Client-IP
gezählt. `REMOTE_ADDR` bleibt die Vertrauenswurzel; `X-Forwarded-For` wird nur
hinter der explizit konfigurierten Proxy-Kette von rechts nach links
ausgewertet. `X-Session-ID` ist kein Bestandteil des Datenbankschlüssels mehr,
weshalb ihre Rotation weder ein neues Kontingent noch zusätzliche SQLite-
Zeilen erzeugt. Eine fehlende gültige Client-IP endet entsprechend der
bisherigen Fehlerstrategie fail-closed.

Der neue Adaptertest deckt eine mehrstufige vertrauenswürdige Proxy-Kette,
rotierende Session-Header, einen gefälschten Forwarded-Header hinter einem
unvertrauenswürdigen Direktclient sowie die fehlende Client-IP ab. Ein echter
lokaler HTTP-Durchlauf bestätigte bei Limit 1: erster History-Request HTTP 200,
zweiter Request derselben IP mit anderer Session-ID HTTP 429 und weiterhin nur
eine SQLite-Hitzeile.

Die vollständige Suite bestand mit 386 Tests und 2.288 Assertions. Ihre
HTTP-Testumgebung legt die Rate-Limit-SQLite seit diesem Stand pro Testprozess
temporär an und simuliert getrennte Clients über vertrauenswürdige
`X-Forwarded-For`-Adressen; Testläufe verändern damit keine Betriebsbudgets.

Zum selben noch uncommitteten Stand wurde ein beim Boot sichtbarer Wettlauf
zwischen Apache und dem per `x-systemd.automount` eingebundenen DEV-CIFS
geschlossen. `webcam-sync-apache-runtime-paths` schreibt nun zusätzlich
`RequiresMountsFor=<project-root>` in das systemd-Drop-in. Damit wartet der
Aufbau des Apache-Mount-Namespace auf den echten Projekt-Mount und endet nicht
mehr vorzeitig mit `status=226/NAMESPACE`.

Iteration 169 setzt den hohen Rechtebefund des Code-Reviews vom 03.09.2026
um. Der Apache-Prozess sieht den vollständigen DEV-Projektbaum nun in seinem
systemd-Mount-Namespace read-only; nur die ausdrücklich benötigten
Laufzeitpfade `var`, `cache`, `storage`, der lokale Datenbank-Root und die
konfigurierten `<slug>/current`-Verzeichnisse bleiben beschreibbar. In diesen
Ausnahmen verhindert eine zusätzliche Apache-Regel die Ausführung hochgeladener
PHP-, CGI- und Shell-Dateien. Root und Codex behalten im Host-Namespace ihren
normalen Schreibzugriff auf den Quellbaum.

Der Permission-Fixer trennt öffentliche Runtime-Daten mit `0775/0664` von
lokalen Live-Datenbanken mit `2770/0660`. Auf CIFS mit mountseitig erzwungenen
`nounix`-/Dateimodi überspringt er den wirkungslosen rekursiven chmod/chown-
Lauf; der wieder aktivierte Nachtjob endet dadurch ohne die zuvor wiederholten
tausenden Änderungen. DB-Schreiblocks werden nicht länger world-writable
angelegt.

Das Admin-Panel prüft den Current-Pfad jeder Kamera aus Sicht des laufenden
Webprozesses. Bei einer neuen oder noch nicht freigegebenen Kamera zeigt es
Pfad und den exakten Root-Befehl an. Der root-owned Helfer
`webcam-sync-apache-runtime-paths` liest die Kamera-Slugs direkt aus der
jeweiligen Config-DB, legt fehlende Current-Verzeichnisse an und erzeugt eine
separate systemd-Drop-in-Datei pro DEV-/PROD-Projektwurzel. Die Ausnahmeliste
muss deshalb nicht mehr von Hand gepflegt werden. Das Runbook
`ops/apache-webroot-hardening.md` dokumentiert Installation, Prüfung und
Rollback. Die vollständige PHPUnit-Suite bestand mit 383 Tests und 2.275
Assertions; der isolierte Playwright-Vertrag des Kameraeditors sowie
PHP-/JavaScript-/Shell-Syntax, Permission-Probelauf, Apache-Konfiguration,
Namespace-Grenzen und HTTP-Smokes waren ebenfalls erfolgreich.

Iteration 168 ergänzt in der Kamera-Lightbox den lokalen Download des gerade
sichtbaren Bildausschnitts über die Taste `D`. Zoom und Verschiebung werden in
Originalbildkoordinaten zurückgerechnet; freie Lightbox-Ränder gelangen nicht
in das mit 95 Prozent JPEG-Qualität erzeugte Bild. Die maximale Vergrößerung
steigt von 4x auf 8x, und die Tastaturhilfe beschreibt die neue Bedienung. Ein
isolierter Playwright-Test prüft Zoomgrenze, Verschiebung, Download und die
tatsächlich ausgeschnittene Canvas-Größe ohne Abhängigkeit vom Archivmount.

Iteration 167 verhindert erwartbare Fehler des nächtlichen Permission-Fixers
auf einem read-only Archivmount. Vor dem rekursiven Scan eines Kameraordners
unter `storage/archive/<slug>/db_backups` wird nun geprüft, ob das Verzeichnis
existiert und tatsächlich beschreibbar ist. Read-only und fehlende Verzeichnisse
werden übersprungen; beschreibbare Backup-Verzeichnisse bleiben unverändert im
Prüfumfang. Drei Regressionstests decken read-only, beschreibbar und fehlend
getrennt ab.

Iteration 165 korrigiert die Auflösung historischer Bildvarianten. Ein
positiver `size_bytes`-Wert in der Kamera-DB belegt nur, dass eine Datei beim
Indexieren vorhanden war; nach einer Cache-Retention kann ihr realer Ort das
Langzeitarchiv sein. `ArchiveDatabaseReader` prüft deshalb den aktuellen
Cache-Pfad und fällt bei alten kanonischen Cache-Namen auf die nachgewiesene
Archivdatei zurück. Der Regressionstest bildet ausdrücklich den Fall
„DB-Metadatum vorhanden, Cache-Datei entfernt, Archivdatei vorhanden“ ab.
Der Hotfix-Rollout vom 02.09. verwendete das 528 Einträge umfassende
Releasearchiv
`/var/local/webcam/releases/webcam2-code-6.0.7-f3a0b61d6f4c-20260902_082616.tar`
(SHA-256
`2246518aa4e14c6e927be0617718057c02b7f052ae294ad9e23af58e010c302a`).
Der unmittelbar vorher laufende Iteration-164-Code ist unter
`/var/local/webcam/releases/webcam2-code-before-iteration165-20260902_082616.tar.gz`
gesichert (SHA-256
`712ef14574847ad95820202e26c7c81609e23b316198d106ee7515ecf58d72f4`).
Alle 417 regulären Laufzeitdateien stimmten bytegenau, PHP-/JavaScript-Syntax,
Apache und die 401/403-Schutzgrenzen bestanden. Die reale PROD-History und
alle von ihr gelieferten Bild-URLs antworteten mit HTTP 200.

Iteration 164 beseitigt drei nach der großen Entzerrung verbliebene
Duplikatgruppen. `CameraStoragePathResolver` normalisiert Kamera-Storage-Pfade
nun für App, Tagesarchivierung, Bildspeicherung, Status/Wetter und die
Legacy-Migration identisch; relative Angaben beziehen sich verbindlich auf die
Projektwurzel. `CameraPagePayloadBuilder` stellt gemeinsame Templatewerte,
sichere Leerwerte und die geordnete JavaScript-Liste für Best-Of-Frühpfad und
normalen Kamera-Render bereit. Dieselbe Assetliste speist auch den
Live-HTML-Cache-Fingerprint. Der unbenutzte zweite Datumsparser im
`PublicHistoryHandler` wurde entfernt; alle öffentlichen Zeitrouten verwenden
damit ausschließlich `PublicTemporalRequest`.

Die vollständige PHPUnit-Suite bestand anschließend mit 373 Tests und 2.233
Assertions. PHP-Lint, Watchdog sowie HTTP-Smokes für Status, normale
Kameraansicht und Best-Of blieben Teil der Abschlussprüfung.

Iteration 163 zerlegt die verbliebenen großen Lese- und Darstellungsblöcke der
Kameraansicht ohne Änderung ihrer öffentlichen Schnittstellen. Tagesvideo,
Wetter- und Timeline-Datenmodell, Lightbox-Einstieg und -Bedienung sowie
Streampräferenz und HLS-Lebenszyklus liegen nun in ausführlich kommentierten
Browsermodulen. Serverseitig ist `PublicTimelineHistoryHandler` nur noch der
Dispatcher vor getrennten Timeline-, History- und Timelapse-Handlern mit
gemeinsamer Request-Normalisierung. `StatusService` koordiniert entsprechend
nur noch den Statusspeicher und die Wetterauswertung.

Im selben Stand wurde die beim Schnitt sichtbar gewordene Wetterregression
behoben: Diagramm, Messpunkt-Vorschaubild und Hover-Auswahl verwenden wieder
den lokalen Ganzzahlparser für SVG-Punktindizes. EXIF-Sidecars erscheinen im
Informationsdialog als responsive Schlüssel/Wert-Tabelle; alle Inhalte werden
weiterhin ausschließlich als Text in den DOM geschrieben. Die vollständige
PHPUnit-Suite bestand mit 359 Tests und 2.190 Assertions. Ergänzende
Playwright-Läufe bestanden vor dem letzten UI-Fix mit 13 von 13 und danach mit
8 von 8 relevanten Fällen; HTTP-, Mobil- und Desktopprüfungen auf DEV waren
ebenfalls erfolgreich.

Iteration 162 schließt den zusammenhängenden Entzerrungslauf der öffentlichen
Lese- und Darstellungswege ab. `api/public.php` delegiert Kamera-, Best-Of-
sowie Timeline-/History-Routen an drei kommentierte Handler. `ImageStorage`,
`ArchiveBrowser` und `BestOfGallery` bleiben kompatible Fassaden vor getrennten
EXIF-, Varianten-, Retention-, Kalender-, Timeline-, Dateikatalog-, Like- und
Cache-Komponenten. `stream.php` trennt HLS und MJPEG; der prozedurale
Config-Store delegiert Verbindung, Hydrierung und Persistenz. Die öffentliche
Übersicht verteilt Karten/Favoriten, Verlauf, Leaflet und Lightbox auf vier
Browsermodule, während `overview.js` nur noch koordiniert.

Im selben geprüften Stand wurden vier UI-Regressionsfälle behoben: Die
Archivkalenderbildung ordnet Jahresgrenzen in der konfigurierten Zeitzone ein,
der Modus-Badge zeigt bei aktivem Stream nicht länger die zuvor gewählte
Monatsansicht, nicht verfügbare Kameraaktionen erscheinen im Best-Of sichtbar
ausgegraut und ohne JavaScript-Hook, und die Wetterdiagramme wählen über die
gesamte horizontale Diagrammfläche den zeitlich nächsten Messpunkt samt
Tagesbild. Die vollständige PHPUnit-Suite bestand mit 358 Tests und 2.181
Assertions; ergänzende Playwright-Läufe deckten Streammodus, Best-Of-Menü,
Wetterinteraktion und Mobil-/Desktoplayout ab.

Iteration 161 trennt den zuvor 1.720 Zeilen großen `CameraDataStore` entlang
seiner fachlichen Zuständigkeiten. Die bestehende Klasse bleibt als 274 Zeilen
kleine, rückwärtskompatible Fassade erhalten. Schema und additive Migrationen,
Capture-/Varianten-/EXIF-Zugriffe, Likes/Best-Of sowie Wetter-/Logo-Daten liegen
nun in vier ausführlich kommentierten Repository-Klassen. Eine gemeinsame
`CameraDataConnection` bewahrt die bisherige PDO-Verbindung, Busy-Retries,
Schreiblocks und den kontrollierten CIFS-Staging-Fallback zentral, damit diese
Betriebslogik nicht in den Fach-Repositories dupliziert wird. Die öffentliche
Methodenschnittstelle der Fassade blieb unverändert; die vollständige Suite mit
358 Tests und 2.180 Assertions sowie der DEV-Watchdog liefen erfolgreich.

Die Betriebsdokumentation hält außerdem den erneut verwaisten CIFS-Scan vom
30.08. und die Proxmox-Hangdiagnose vom 31.08. fest. `AGENTS.md` präzisiert
deshalb die verbindlichen Suchgrenzen auch für `/var/www/html`; der
Systemcheck dokumentiert Kdump-Reserve, Panic-/Lockup-Schalter, Watchdog,
minütliche Host-Telemetrie und die weiterhin nur korrelierte USB-/Gaststart-
Beobachtung, ohne daraus eine unbewiesene Absturzursache abzuleiten.

Iteration 160 führt für `pull-snapshot` eine ausdrückliche Quellenpriorität
ein. Erfolgreiche Pull-Aufnahmen bleiben maßgeblich; externe Active-Uploads
werden standardmäßig bestätigt, aber nicht gespeichert. Über den neuen
Adminwert `capture.active_upload_fallback_failures` kann pro Kamera festgelegt
werden, nach wie vielen aufeinanderfolgenden Pull-Fehlern der externe Upload
als Fallback akzeptiert wird. Pull-Aufnahme, Command-Ausgabe und Upload teilen
sich dafür einen pro Kamera gesperrten, persistenten Fehlerzustand unter
`var/state/pull-fallback/`. Ein erfolgreicher Pull setzt den Zähler zurück und
beendet den Fallback automatisch.

Die neue Einstellung wird über Admin-Formular, Normalisierung und ConfigStore
durchgängig validiert und gespeichert. Bestehende Live-Config-Datenbanken
benötigen einmalig Migration
`database/migrations/0008_add_active_upload_fallback_failures.sql`; vor der
DEV-Migration wurde ein geprüftes SQLite-Backup unter
`/var/lib/webcam/config-backups/` erstellt. Unit-, HTTP- und Browsertests
decken Prioritätswechsel, Fehlerzähler, Command-Freigabe, Upload-Ignorierung,
Persistenz und Editor-Payload ab.

Iteration 159 verlagerte den zweiten der beiden verbliebenen
Laufzeitschnitte verlagert `GET /status` in den kommentierten
`StatusAdminHandler`. Er liest jede konfigurierte Kamera genau einmal über den
bestehenden StatusService, verwendet einen gemeinsamen Request-Zeitpunkt und
ordnet Alter und Capture-Intervall weiterhin in `unknown`, `ok`, `warn` oder
`error` ein. Zwei isolierte Verträge prüfen alle Schweregrade sowie unbekannte
Methoden und Unterpfade.

Iteration 158 führte zuvor die Stream-Verwaltung in den kommentierten
`StreamAdminHandler`. Viewerübersicht, Gruppierung pro Kamera, Trennen sowie
IP-Sperren und -Freigaben verwenden weiterhin ausschließlich `StreamState`.
Der JSON-Body wird erst nach exakter POST-Routenwahl gelesen; ungültige Tokens
und IPs bleiben als HTTP 400 sichtbar. Drei isolierte Verträge prüfen reale
temporäre HLS-States, Sperrdateien, Aktionen und frühe Ablehnung.

Nach diesen beiden Schnitten enthält `admin/api.php` noch 295 Zeilen. Es gibt
dort keinen fachlichen Admin-Routenblock mehr: Bootstrap, Health, Auth, CSRF,
Pfadauflösung, verzögerter JSON-Reader, Handler-Dispatch und zentrale
Fehler-/Antwortgrenze bleiben bewusst zusammen. Der echte Admin-HTTP-Vertrag
wird weiterhin durch `AdminApiTest` abgedeckt.

Iteration 157 verlagerte im zweiten, getrennt ausgeführten API-Schnitt
Status, Aktivierung und Rebuild des optionalen Legacy-Archivcaches in
den kommentierten `ArchiveCacheAdminHandler`. Freigabeschalter, tatsächlich
gehaltener `flock`, feste Prozessargumente, eigene Prozessgruppe, Timeout und
begrenzte Diagnoseausgabe bilden dort eine geschlossene Sicherheitsgrenze.
Vier isolierte Verträge prüfen Status/Config, frühe Ablehnung, stale und
gehaltene Locks sowie 409/500/504; die echten Admin-HTTP-Tests bleiben grün.

Iteration 156 führte zuvor den ersten ausdrücklich getrennten Schnitt aus.
`GlobalConfigAdminHandler` übernimmt `/config`, Timeline, Runtime,
Wartungsskripte, Übersichtstext/-karte, Public-Rate-Limit und `/scripts`.
Validierung, ConfigStore-Persistenz und Wartungsstatus sind in kommentierte
Funktionsblöcke gegliedert; ein verzögerter JSON-Reader wird nur bei passenden
PUT-Routen aufgerufen. Drei isolierte Verträge plus bestehende HTTP- und
Rate-Limit-Tests sichern den unveränderten Vertrag.

Nach den Schnitten 156 und 157 enthielt `admin/api.php` noch 378 statt 826 Zeilen. Es
bleibt die zentrale Grenze für Bootstrap, Authentifizierung, CSRF, JSON-Ausgabe
und Dispatch; fachliche Handler werden erst für ihr jeweiliges Pfadpräfix
erzeugt. Status und Streamsteuerung waren zu diesem Stand die letzten
Laufzeitblöcke dort und wurden anschließend in Iteration 158/159 getrennt.

Iteration 155 führte den vollständig kommentierten `CameraAdminHandler` ein.
Er übernahm den zweiten Punkt der Admin-API-Entzerrung:
Kamera-CRUD, Abschnittsupdates, Jahresliste, DB-Vorschau, Capture-GET/PUT und
den administrativen Overlay-Endpunkt. `admin/api.php` führt nur noch die
zentrale Auth-/CSRF-Prüfung aus, delegiert alle `/cameras`-Routen geschlossen
und gibt deren neutrales `AdminRouteResult` aus. Die bereits getrennten
Normalizer und SQLite-Services bleiben die fachlichen Grenzen; der Handler
ordnet URL, Statuscodes, Logging, Like-Sidecar und Page-Cache-Invalidierung zu.

Der Overlay-Dateiablauf liegt zusätzlich im kommentierten
`OverlayUploadService`. Er begrenzt gemeldete und tatsächliche Dateigröße,
bestimmt PNG/JPEG aus Inhalt und Bildkopf, teilt den Overlay-Lock mit der
Bereinigung und veröffentlicht atomar unter `cache/<slug>/overlays/`;
öffentlich bleibt nur `overlay.php` sichtbar. Der globale Router sinkt damit
insgesamt von 1.209 auf 826 Zeilen. Vier isolierte Handler-Verträge, sechs
Overlay-Service-Tests und ein echter Multipart-HTTP-Test sichern CRUD,
Jahres-/DB-/Capture-Routen, Validierungsreihenfolge, Publikation, Format,
Größe, Lock sowie Kamera- und Fehlergrenzen.

Iteration 154 verlagerte die lesende Capture-Suche des Admin-Editors. Sie
liegt jetzt im ausführlich kommentierten `CameraCaptureQueryService`. Er
normalisiert die Query-Parameter, berechnet Datumsbereiche, filtert und
paginiert DB-Treffer, wählt `latest` beziehungsweise den zeitlich nächsten
Capture und hydriert das gemeinsame API-Modell. `admin/api.php` behält nur
Kameraprüfung, HTTP-Fehlerabbildung und Antwort und sinkt von 1.347 auf 1.209
Zeilen. Sechs neue SQLite-Integrationstests und ein HTTP-Negativtest sichern
Slotdetail, Bereichssuche, Filter/Pagination, `latest`, leere Treffer sowie die
Nächstes-Bild-Auswahl.

Iteration 153 trennte den serverseitigen Capture-Editor in zwei
ausführlich kommentierte Komponenten. `CapturePayloadNormalizer`
übernimmt Feld-Positivliste, relative Kamerapfade sowie die Mengen- und
Längengrenzen für Varianten, EXIF und Wetter. `CameraCaptureEditor` kapselt
Vorschau, Detailansicht, API-Serialisierung und das atomare SQLite-Update unter
dem vorhandenen Kamera-Schreiblock. `admin/api.php` behält HTTP-Status,
Logging und Like-Sidecar-Abgleich und sinkt von 1.841 auf 1.347 Zeilen. Sieben
isolierte Normalisierungs-/SQLite-Tests und ein echter HTTP-Negativtest sichern
den neuen Vertrag; ein Fehlerpfad beweist zusätzlich die Freigabe von
Transaktion und Lock.

Iteration 152 verlagerte die Kamera-Konfigurationsnormalisierung.
Kamera-Gesamtpayloads, die zehn
abschnittsweisen Editorupdates sowie Overlay- und Labelwerte werden jetzt in
der ausführlich kommentierten Klasse
`src/Service/Admin/CameraConfigNormalizer.php` normalisiert. Die Klasse kennt
weder HTTP noch ConfigStore oder Dateisystem und meldet Fachfehler als
`InvalidArgumentException`; `admin/api.php` übersetzt sie weiterhin in HTTP
400. Der API-Einstieg sinkt dadurch von 2.282 auf 1.841 Zeilen. Ein isolierter
Vertrag prüft Positivlisten, Defaults, Pfad-/Protokollgrenzen, Overlay-URLs und
Labels; zwei echte API-Tests sichern die HTTP-400-Übersetzung. Dabei wurde auch
der kollidierende Regex-Trenner der lokalen Overlay-Pfaderkennung korrigiert.

Iteration 151 verlagerte den Haupt-Renderer der Admin-Oberfläche. Der bisher
345 Zeilen lange Haupt-Renderer
der Admin-Oberfläche ist in das ausführlich kommentierte
`assets/js/admin-page.js` umgezogen. Der neue Seitencontroller gliedert den
Aufbau in sichere Flash-/Tabellenwerte, Fachbereichskomposition,
DOM-Austausch, einmalige Ereignisdelegation und Editorstart. Er besitzt weder
API noch einen zweiten Browserzustand; `admin.js` bleibt die einzige Stelle für
Laden und Speichern und sinkt von 1.198 auf 889 Zeilen. Direkt eingesetzte
Config-, Fehler- und Cachewerte werden dabei nun durchgängig HTML-maskiert.

Iteration 150 ergänzte die Schreibprüfung des Archiv-Rollovers. Der
Archiv-Rollover prüft vor Tages-Lock und
Dateiverarbeitung mit einer kurzlebigen realen Schreibprobe, ob der Archiv-Root
tatsächlich beschreibbar ist. Auf einem read-only CIFS-Mount endet er jetzt
kontrolliert mit Exit 2 und einer eindeutigen Meldung statt mit einem Fatal
Error am Lock. Die Probe wird beschrieben und muss anschließend wieder
entfernbar sein; reine Rechtebits gelten nicht als ausreichender Nachweis.

Iteration 149 machte Fehlerstatus unter Admin → Scripte aufklappbar. Sie zeigen
höchstens fünf bereinigte Logzeilen genau des
zugehörigen Wartungsjobs. Neue Läufe speichern diese kleine Ausgabe direkt in
ihrem Taskstatus. Für bereits vorhandene Statusdateien wird nur ein eindeutig
über Scriptlabel und gespeicherte Startzeit abgegrenzter Abschnitt des alten
Sammellogs verwendet; ohne sichere Zuordnung bleibt die Liste leer.

Iteration 148 verlagerte die Übersetzung des Kameraformulars in
abschnittsweise beziehungsweise vollständige API-Payloads in das
ausführlich kommentierten `assets/js/admin-camera-payload.js`. Zehn kleine
Fachbuilder teilen einen FormData-Kontext und normalisieren Basisdaten,
Standort, Capture/Stream, Speicher, Overlay, Labels, Wetter und Watchdog nur
noch an einer Stelle. Teilspeicherung und vollständiges Speichern benutzen
dieselben Builder; die zuvor doppelte Feldzuordnung ist entfernt. Das Modul
sendet keine Requests und besitzt keinen Browserzustand. `admin.js` behält
Speicherflags, API-Aufrufe und Meldungen und sinkt von 1.479 auf 1.198 Zeilen.
Ein isolierter Playwright-Vertrag vergleicht jeden Abschnitt mit dem
Gesamtpayload und prüft Defaults, Stream-Erhalt, deaktivierte RTSP-Felder,
Parserfehler sowie die Delegation an Overlay- und Label-Editor.

Iteration 147 verlagerte den zuvor 467 Zeilen langen Kameraformular-
Renderer aus `admin.js` in das ausführlich kommentierte
`assets/js/admin-camera-editor.js`. Kameraauflösung, gemeinsames
View-Model, die zehn bearbeitbaren Fachabschnitte, die lesende DB-Diagnose,
Tabzustand und Formularrahmen sind dort in kleine benannte Blöcke gegliedert.
Overlay-, Label- und Capture-Diagnose bleiben an ihre bestehenden
Spezialkomponenten delegiert; Listener, Payload-Aufbau, API und zentraler
Browserzustand verbleiben in `admin.js`. Sämtliche direkt vom Renderer
eingesetzten Configwerte werden nun auch in Zahlenfeldern, IDs und Überschriften
HTML-maskiert. `admin.js` sinkt von 1.932 auf 1.479 Zeilen. Ein isolierter
Playwright-Vertrag prüft Abschnittsmodell, Neuanlage, XSS-Maskierung, interne
Tags, RTSP-Zustand und Delegation; der echte Admin-Test prüft zusätzlich die
vollständige Assetreihenfolge sowie alle elf Tabs.

Iteration 146 verlagerte die zusätzliche Browservalidierung der
Kameraformulare geschlossen in das ausführlich kommentierte
`assets/js/admin-form-validation.js`. Slug-, Text- und Pfadregeln,
Fehlerdarstellung, verzögerte Einzelprüfung, Gesamt-/Abschnittsprüfung,
automatischer Tab-Fokus und RTSP-abhängige Eingaben teilen damit eine
zustandslose Komponente. Direkte Feldlistener und der delegierte
Transportwechsel werden auch bei wiederholter Initialisierung nur einmal
gebunden. Die Admin-API bleibt ausdrücklich die verbindliche zweite
Validierungsgrenze. `admin.js` sinkt von 2.103 auf 1.932 Zeilen. Ein isolierter
Playwright-Vertrag und ein echter Admin-Test prüfen Fehleranzeige, Tabwechsel,
Fokus, Listener-Lebenszyklus und das deaktivierte Mitsenden verborgener
RTSP-Felder.

Iteration 145 verlagerte den Tabelleneditor für öffentliche Bildlabels
geschlossen in das ausführlich kommentierte
`assets/js/admin-label-editor.js`. Auswahlwerte, maskiertes Rendering,
Pflichtfelder, Link- und Farbprüfung, Payload-Aufbau, Leerzustand sowie
Hinzufügen/Entfernen teilen damit eine kleine Komponentenschnittstelle. Die
Browserprüfung liefert direkte Rückmeldung, während `admin/api.php` jeden
Payload weiterhin unabhängig und verbindlich validiert. `admin.js` sinkt von
2.398 auf 2.103 Zeilen. Ein isolierter Playwright-Vertrag prüft XSS-Maskierung,
Payload-Normalisierung, gefährliche Links, ungültige Hexfarben, leere Tabellen
und die genau einmalige Listenerbindung nach wiederholtem Initialisieren.

Iteration 144 verlagerte den Editor für helle und dunkle Overlaylogos
geschlossen in das ausführlich kommentierte
`assets/js/admin-overlay-editor.js`. Rendering, dokumentrelative URL-Auflösung,
Bildgrößen, Multipart-Upload, Vorschau, Zurücksetzen und Payload-Normalisierung
teilen damit ein Variantenmodell. Der zentrale API-Adapter erkennt `FormData`,
setzt dabei keinen falschen JSON-Content-Type und behält CSRF- sowie
Fehlerbehandlung bei. Dynamische Formularwerte werden vollständig maskiert;
positive Maße und Deckkraftgrenzen werden vor dem Speichern geprüft, während
Dateityp, Pfad und Persistenz verbindlich im PHP-Backend bleiben. `admin.js`
sinkt von 2.669 auf 2.398 Zeilen. Ein isolierter Playwright-Vertrag prüft
Maskierung, Payload, Multipart-Upload, Vorschau und das getrennte Zurücksetzen
beider Varianten.

Iteration 143 verlagerte die Kamera-Kartenvorschau geschlossen in das
ausführlich kommentierte `assets/js/admin-map-preview.js`.
Das Modul liest die noch ungespeicherten Standort-, Blickrichtungs-,
Sichtfeld-, Reichweiten- und Versatzwerte aus dem aktiven Formular und zeigt
sie wahlweise mit Leaflet oder im netzunabhängigen Canvas-Fallback. Es schreibt
selbst keine Konfiguration. Bei Neuaufbau und Tabwechsel werden Leaflet,
Resize- sowie Eingabe-Listener vollständig abgeräumt. `admin.js` sinkt von
2.954 auf 2.669 Zeilen. Ein isolierter Playwright-Vertrag prüft Darstellung,
reaktive Eingaben und Listener-Cleanup; der echte Admin-Test sichert die
gemeinsame Assetrevision und Ladereihenfolge.

Iteration 142 verlagerte DB-Vorschau, Jahresauswahl, Slot-/Datumsuche,
Detailformular und Speichern einzelner Capture-Metadaten geschlossen in das
ausführlich kommentierte `assets/js/admin-capture-editor.js`.
Das Modul verändert keine Bilddateien, sondern delegiert ausschließlich
validierte Metadaten-Payloads an dieselbe CSRF-geschützte Admin-API. Varianten,
EXIF und Wetter müssen vor dem Request echte JSON-Arrays sein; dynamische
Vorschauwerte werden durchgehend maskiert. Preview- und Jahresrequests laufen
pro Kamera höchstens einmal gleichzeitig, wodurch schnelle Neurenders keine
doppelten Datenbankabfragen mehr auslösen. `admin.js` sinkt von 3.502 auf
2.954 Zeilen. Ein isolierter Playwright-Vertrag prüft Request-Deduplizierung,
XSS-Maskierung, Slotladen, JSON-Ablehnung und den exakten Speicher-Payload.

Iteration 141 fasste die globalen Admin-Einstellungen im ausführlich
kommentierten `assets/js/admin-settings.js` zusammen.
Das Modul rendert Übersichts-Teaser und -Karte, Wetter-Command-Timeout,
Timeline-Grenze und Public-API-Limit, bindet deren fünf Formulare und delegiert
validierte Payloads an dieselbe CSRF-geschützte Admin-API. Defaults älterer
Installationen werden lediglich sichtbar hydriert und niemals still
gespeichert. Dynamische Configwerte werden vor der HTML-Ausgabe maskiert;
auch programmgesteuert abgesendete leere Kartenfelder werden explizit
abgewiesen. `admin.js` sinkt von 3.746 auf 3.502 Zeilen. Ein isolierter
Playwright-Vertrag prüft Defaults, Maskierung, Kartenvalidierung und exakte
Payloads; der echte Admin-Test sichert die gemeinsame Assetrevision und
Ladereihenfolge.

Iteration 140 verlagerte die optionale Legacy-Archivcache-Diagnose geschlossen
in das ausführlich kommentierte `assets/js/admin-archive-cache.js`.
Status-Hydrierung, Aktivierung,
Rebuild-/Dry-Run-Payload, Formularzustand, Fehlersichtbarkeit und das nur bei
laufendem Job aktive Fünf-Sekunden-Polling sind damit aus `admin.js`
entfernt. Der Browser startet selbst keinen Cachelauf ohne die bestehende
serverseitige Admin-Freigabe; Autorisierung, Pfadauflösung und Prozesslock
bleiben im Backend. Dynamische Statuswerte werden vor der HTML-Ausgabe
maskiert. `admin.js` sinkt von 3.951 auf 3.746 Zeilen. Ein isolierter
Playwright-Vertrag prüft Maskierung, Jahresgrenze, Dry-Run-Payload und Polling;
der echte Admin-Test deckt Assetreihenfolge und den Diagnosebereich ab.

Iteration 139 ergänzte in der linken Orientierungsschiene des Admin-Panels
neben dem in der Config-DB gespeicherten Stand auch die laufende
Code-Iteration, beispielsweise `Stand … · Iteration 139`.
`admin/index.php` liest dafür dieselbe maschinenlesbare `ITERATION`, die auch
den gemeinsamen Cachebuster der Admin-Assets bestimmt, und übergibt den Wert
als reines Data-Attribut. Dadurch ist der tatsächlich geladene DEV-/Release-
Stand im Panel ohne Zugriff auf das Dateisystem erkennbar. Der echte
Playwright-Admin-Test prüft die sichtbare Iteration zusammen mit Reihenfolge
und Revision der Admin-JavaScript-Dateien.

Iteration 138 verlagerte die Wartungsscript-/Scheduler-Arbeitsfläche
in das ausführlich kommentierte `assets/js/admin-maintenance.js`.
Defaults, Server-Hydrierung, Laufstatus, Kartenrendering, Formularauswertung,
Pfad-/Argumentprüfung, fachliche Backup-/Wetter-Abhängigkeiten und das
Hinzufügen beziehungsweise Entfernen eigener Scripte bilden dort einen
geschlossenen Block. Die Komponente führt selbst keine Wartungsprogramme aus;
der interne Scheduler bleibt unverändert serverseitig an die Kamera-Polls
gekoppelt. API, Meldungen und Browserzustand werden weiterhin aus `admin.js`
injiziert. Ein Payload-Objekt ohne Prototyp schützt zusätzlich vor besonderen
Tasknamen, bevor das Backend unabhängig erneut validiert. `admin.js` sinkt von
4.203 auf 3.950 Zeilen. Der isolierte Playwright-Vertrag prüft Defaults,
Hinzufügen, das clientseitige Ablehnen eines Traversal-Pfads und den gültigen
Payload; die bestehende echte Admin-Suite deckt Darstellung, Speichern und
mobiles Layout ab.

Iteration 137 verlagerte als ersten Schnitt der Admin-Entzerrung
Laden, Fallback, Darstellung und Bedienung des Kamera-/Livestreamstatus aus
`assets/js/admin.js` in das ausführlich kommentierte
`assets/js/admin-status.js`. `admin.js` behält den gemeinsamen
Browserzustand und reicht nur seine vorhandenen API-, CSRF-, Formatierungs-
und Renderfunktionen als Adapter weiter; die Hauptdatei sinkt dadurch von
4.487 auf 4.203 Zeilen. Dadurch gibt es weder einen zweiten
Zustand noch eine zweite Authentifizierungslogik. Stream-Trennen, IP-Sperren
und Freigaben laufen weiterhin über den einmalig gebundenen delegierten
Click-Handler. Beide Admin-Assets tragen denselben Iterations-Cachebuster, um
gemischte Browsercache-Stände nach einem Rollout auszuschließen. Ein eigener
Playwright-Vertrag prüft Statusladen, HTML-Maskierung und Aktionsdelegation;
der echte Admin-Test prüft zusätzlich Ladereihenfolge und sichtbaren
Status-/Streambereich.

Iteration 136 verlagerte die rund 725 Zeilen ausführbares Inline-JavaScript
der öffentlichen Übersicht in das ausführlich
kommentierte Asset `assets/js/overview.js`. Das Modul übernimmt Kartenstatus,
Favoriten, zuletzt betrachtete Kameras, minütliche Statusaktualisierung,
Timeline-Lightbox und Leaflet-Karte. `templates/overview.php` enthält nur noch
serverseitiges HTML sowie einen mit `JSON_HEX_*` gehärteten, nicht ausführbaren
Konfigurationsblock; es sinkt von 945 auf 238 Zeilen. Auch die bisher in
`index.php` aus PHP-Strings zusammengesetzte Kartenlogik ist in dasselbe Modul
verschoben. Das gemeinsame Layout lädt das Asset dokumentrelativ mit seiner
automatischen Iterations-/MTime-/Größenrevision. Der fokussierte Playwright-
Lauf bestand für Desktop und Mobil und prüft zusätzlich externes Asset,
fehlendes ausführbares Inline-JS, JSON-Konfiguration und Leaflet-Start.

Iteration 109 bereinigt
verschachtelte unbekannte HTML-Wrapper rekursiv, bevor deren sichtbare Kinder
in administrativ gepflegtem `info_html` erhalten bleiben. Dadurch können
`script`, SVG/MathML, Event-Handler und gefährliche URL-Schemata nicht mehr über
einen entfernten Wrapper in die öffentliche Kameraansicht gelangen. Sieben
gezielte Sanitizer-Tests mit 59 Assertions und der vollständige Lauf mit 249
Tests und 1.639 Assertions sichern den Pfad ab.

Mit Iteration 110 wurden Uploads dateibasiert ingestiert,
auf absolut 64 MiB begrenzt und brechen bei einem nicht verfügbaren
Deduplizierungs-Lock mit 503 ab. Explizite Timeline-Bereiche werden bereits in
SQLite vor der Relationshydration begrenzt; Metadatenmaps leben nur blockweise.
Der gemeinsame `BoundedProcessRunner` schützt die kurzlebigen Wetter-, Reset-,
OCR-, Dayvideo-, Capture-, Wartungs- und Admin-Prozesse mit Outputlimit,
Timeout sowie Prozessgruppen-TERM/KILL. Ein unbrauchbarer Rate-Limiter-Soll-
und Fallbackpfad endet mit einer eindeutigen fail-closed Exception. Der
vollständige Lauf bestand mit 257 Tests und 1.673 Assertions. Iteration 109 und
110 waren zu diesem Zeitpunkt noch nicht als vollständige PROD-Codebasis
ausgerollt. Beide Iterationen sind im technischen Commit `df1ea70`
zusammengefasst. Die
anschließende Dokumentation der am 29.08.2026 bestätigten Docroot-Bereinigung
und des durchgeführten APT-Upgrades ist reine Betriebsdokumentation und erhöht
die Iteration nicht.

DEV/Code steht auf Iteration 111. Die großen Frontend-, OCR-, API-, Archiv-,
Kamera-DB-, Bildspeicher-, Upload- und Prozessdateien enthalten nun eine
deutschsprachige Leseführung und fachliche Abschnittskommentare. Sie erklären
Datenfluss, Zustandsänderungen, Locks, Fallbacks und Fehlerpfade, ohne triviale
Einzelzeilen zu wiederholen. Die Laufzeitlogik bleibt unverändert.

DEV/Code steht auf Iteration 112. `TimelineNavigator` übernimmt die
zustandslosen Auswahl-, Zeit- und Nächstsuchregeln aus `ArchiveBrowser`;
`ArchiveEntryImageResolver` kapselt die Auswahl einer tatsächlich
auslieferbaren Bildvariante. Die öffentliche Fassade bleibt kompatibel und ist
um rund 340 Zeilen private Hilfslogik kleiner. Neue isolierte Tests sichern die
ausgelagerten Regeln ohne Kamera-DB oder Archivscan ab. Der vollständige Lauf
bestand mit 265 Tests und 1.692 Assertions.

DEV/Code steht auf Iteration 113. Der optionale Legacy-Archive-Cache ist aus
`ArchiveBrowser` in `LegacyArchiveCache` verschoben. Das kompakte JSON-Format
und seine Rückübersetzung liegen nochmals getrennt in
`LegacyArchiveCacheCodec`; `ArchiveStoragePaths` vereinheitlicht die
historischen Root-/Slug-Pfadformen. Die öffentlichen Methoden von
`ArchiveBrowser` bleiben kompatibel, die Datei sinkt von rund 1.720 auf 1.085
Zeilen. Ausführliche Abschnittskommentare erklären Cache-Grenze, Migration,
Frischeprüfung, binäre Suche, URL-Aufbau und Sidecar-Auflösung. Der vollständige
Lauf bestand mit 268 Tests und 1.714 Assertions.

DEV/Code steht auf Iteration 114. Die bisher direkt am Anfang von `camera.php`
liegenden Regeln für Tag-/Nacht-Erkennung und zeitabhängige Bildlabels sind in
`CameraOverlayMetadata` gekapselt. `CameraWeatherMetadata` bereinigt
Wettertexte und prüft Wetter-Qualitätsmarker read-only; die Navigation durch
bereits geladene Jahres-/Monats-/Tageslisten liegt in
`CameraTimelineCalendar`. Damit enthält der Seiteneinstieg keine globalen
Hilfsfunktionen mehr und sinkt von 2.187 auf 1.885 Zeilen. Die neuen Klassen
erklären Eingaben, Fallbacks und fachliche Grenzen direkt im Quellcode. Fünf
neue Komponententests prüfen Wetterfehler, Qualitätsstrecken, inklusive
Label-Zeiträume, Sonnenzeiten sowie Kalenderwechsel. Der vollständige Lauf
bestand mit 273 Tests und 1.727 Assertions.

DEV/Code steht auf Iteration 115. Der rund 305-zeilige serverseitige
Timeline-Navigationsblock ist aus `camera.php` in
`CameraTimelineNavigationBuilder` verschoben. Der Builder lädt nur
Tages-/Kalendermetadaten für die aktuelle Auswahl und direkte Nachbarn und
liefert `timelineNav`, Zeit-, Stunden- und Jahresoptionen templatefertig zurück. Reine
Regeln für Jahres-/Monatsnachbarn, Tageswahl und das Zusammenführen der
Kalender-Maps liegen in `CameraTimelineCalendar`. Die dokumentrelativen
History-/Playback-Link-Builder bleiben als Callbacks beim Route. `camera.php`
sinkt dadurch weiter von 1.885 auf 1.572 Zeilen. Drei neue Builder-Tests prüfen
den leeren Zustand sowie Zeit-, Stunden-, Monats- und Jahresnavigation. Ein
Playwright-Test deckt das Stunden-Pulldown am Bielefelder Archivtag 31.05.2026
ab. Der vollständige PHP-Lauf bestand mit 276 Tests und 1.752 Assertions.

DEV/Code steht auf Iteration 116. Die bisher direkt in `camera.php` als lange
PHP-Stringverkettung aufgebaute Leaflet-Standortkarte liegt nun im ausführlich
kommentierten Asset `assets/js/camera-map.js`. PHP übergibt lediglich ein
HTML-kodiertes JSON-Paket mit Koordinaten, Blickrichtung, Sichtweite, Titel und
Kartenoffset. Das Modul wird ausschließlich in Ansichten mit sichtbarer Karte
geladen und fließt in die Live-Page-Cache-Version ein. `camera.php` sinkt von
1.572 auf 1.426 Zeilen. Drei neue Karten-Browsertests sowie der erweiterte
Slash-/Non-Slash-Assettest bestanden; die vollständige PHP-Suite blieb bei 276
Tests und 1.752 Assertions.

DEV/Code steht auf Iteration 117. Die zunächst als nächster Builder-Kandidat
bewertete Downloadoptionen-Berechnung war nach genauer Verwendungsprüfung
vollständig ungenutzt: Der zugehörige Download-Select war bereits entfernt,
während Route, Template und `lazy-meta.js` noch tote Vorbereitungsschritte
enthielten. Diese 155 Zeilen wurden ersatzlos entfernt, statt sie in eine neue
Abstraktion zu verschieben. `camera.php` sinkt dadurch von 1.426 auf 1.306
Zeilen. Sechs relevante Playwright-Prüfungen und die vollständige PHP-Suite mit
276 Tests und 1.752 Assertions bestanden.

DEV/Code steht auf Iteration 118. Der zusammenhängende Block für EXIF, Wetter,
Likes, Logo-Erkennung, Overlay, Modal-Fallbacks, zeitabhängige Labels und
Best-Of-Zustand wurde aus `camera.php` in den ausführlich kommentierten
`CameraSnapshotPresentationBuilder` verlagert. Die Route wählt nur noch
Snapshot und Ansicht und reicht das fertige Präsentationsmodell weiter; sie
sinkt von 1.306 auf 1.045 Zeilen. Vier neue Builder-Tests decken DB-,
Legacy-Sidecar-, Live-Fallback-, Overlay-, Label- und Best-Of-Pfade ab. Die
betroffenen Browserprüfungen bestanden, ebenso die vollständige PHP-Suite mit
280 Tests und 1.781 Assertions.

DEV/Code steht auf Iteration 119. Die Timeline-Menüs und der Kameraname sind
im HTML wieder korrekt als Geschwister gruppiert; dadurch wächst der starre
Menüblock auf schmalen Viewports nicht mehr über die Seite hinaus. Die
Tagesnavigation springt rückwärts zum letzten Bild des vorherigen Tages und
vorwärts zum ersten Bild des nächsten Tages, statt die aktuelle Uhrzeit
beizubehalten. Das Wettermodal nutzt auf großen Bildschirmen bis zu 960 px und
bleibt mobil auf 95 Prozent der Viewportbreite begrenzt. Die aktuellen Werte
der Temperatur- und Luftdruckdiagramme zeigen Uhrzeit und Messwert jeweils in
einer eigenen Zeile und bleiben beim Erkunden älterer Messpunkte fest; Hover
und Tap aktualisieren nur Vorschau und Tooltip. Das zweispaltige
`.weather-chart`-Layout der separaten
Wetterseite ist auf `.weather-page` begrenzt und kann deshalb die
Modaldiagramme nicht mehr überlagern. Die schwebende Messpunktvorschau beginnt
links erst hinter den Skalenwerten und verdeckt dadurch insbesondere den
Maximalwert nicht mehr. Ein neuer Builder-Test
sichert den Tageskantensprung 12:00 zu 23:50 beziehungsweise 00:10; drei neue
Playwright-Prüfungen decken die 390-px-Timeline sowie das Wettermodal mobil und
am Desktop ab.
Der vollständige PHP-Lauf bestand mit 281 Tests und 1.783 Assertions.

DEV/Code steht auf Iteration 120. Der zuvor rund 900 Zeilen umfassende
Wetterblock ist aus `assets/js/camera.js` in das ausführlich kommentierte
`assets/js/camera-weather.js` ausgelagert. Die Komponente übernimmt
Normalisierung der Timeline-Wettertexte, kurzlebigen Tagescache,
Temperatur-/Luftdruck-SVGs, Windkompass, Tooltip, Messpunktvorschau und den
fertigen Modalinhalt. `camera.js` stellt nur noch Adapter für aktuellen
Archivtag, vorhandene Timeline-Daten und dokumentrelative Vorschaubilder bereit
und sinkt von 8.919 auf 8.052 Zeilen. Das neue Asset wird vor `camera.js`
geladen und ist Bestandteil der Live-Page-Cache-Version. Die drei fokussierten
Playwright-Prüfungen für Timeline und Wettermodal bestanden.
Der vollständige PHP-Lauf blieb mit 281 Tests und nun 1.788 Assertions
fehlerfrei.

DEV/Code steht auf Iteration 121. Die zeitbezogene Umgebungskarte wurde aus
`assets/js/camera.js` in das ausführlich kommentierte
`assets/js/camera-surroundings.js` verschoben. Das Modul validiert
Standortdaten, ordnet jeder Kamera das zeitlich nächste zulässige Archivbild
zu, baut sichere Popups, berechnet Blickfeldpolygone und verwaltet den
Leaflet-Lebenszyklus. `camera.js` behält nur Modalsteuerung und schmale Adapter
zum bereits vorhandenen Timeline-Tagescache, zur Kamera-API sowie zu den
dokumentrelativen URL-Helfern; dadurch entsteht weder eine neue Datenquelle
noch ein weiterer serverseitiger Cache. Die Hauptdatei sinkt von 8.052 auf
7.552 Zeilen. Das neue Asset wird vor `camera.js` geladen, ist Bestandteil der
Live-Page-Cache-Version und besitzt einen eigenen Browsertest für Modal,
Leaflet und Kameramarker. Sechs fokussierte Browserprüfungen für beide Karten,
Slash-/Non-Slash-Assets und Modal-Nebenwirkungen bestanden. Die vollständige
PHP-Suite blieb mit 281 Tests und 1.788 Assertions fehlerfrei.

DEV/Code steht auf Iteration 122. Speicherung und Validierung des gemerkten
Snapshots sowie die vollständige Split-/Zoom-/Drag-Oberfläche des
Bildvergleichs liegen nun im ausführlich kommentierten
`assets/js/camera-image-compare.js`. Das Modul erzeugt fremde Texte
ausschließlich über `textContent`, behält den kompatiblen
`webcam:image-compare:v1`-Sitzungsschlüssel und fragt nach partieller
Navigation immer den aktuellen Kamera-Root ab. Die weiße Trennlinie samt Kreis
ist nun selbst der breite Maus-/Touch-Regler; Pfeiltasten sowie Home/End bieten
dieselbe Funktion per Tastatur. Die entfallene Leiste unter dem Bild schafft
mehr Platz, während `−`, `100%` und `+` als kompakte Zoomsteuerung oben rechts
im Bild liegen. `camera.js` enthält nur noch
die dokumentrelative URL-Reparatur, „Zuletzt angesehen“ und schmale Adapter
zum gemeinsam genutzten EXIF-/Wetter-/Kartenmodal; es sinkt dadurch von 7.552
auf 7.113 Zeilen. Das neue Asset wird vor `camera.js` geladen und ist Teil der
Live-Page-Cache-Version 19. Die fokussierten Browserprüfungen bestanden am
Desktop und bei `390x844`; sie öffnen nun auch die echte Vergleichsansicht,
ziehen den Trenner per Pointer und prüfen weiterhin Modal-/Timeline-Lock,
Beschriftungen sowie Slash-/Non-Slash-
Assetpfade. Die vollständige PHP-Suite bestand mit 281 Tests und 1.788
Assertions; der DEV-Watchdog lief mit den expliziten Live-DB-Pfaden
fehlerfrei.

DEV/Code steht auf Iteration 123. Navigationsmodus, Modus-Badge und der
Lebenszyklus der Jahr-/Monat-/Tag-/Stunden-/Zeit-Picker wurden aus
`assets/js/camera.js` in das ausführlich kommentierte
`assets/js/camera-timeline.js` verschoben. Eine deklarative Konfiguration
beschreibt nur noch die Unterschiede der fünf Picker; Öffnen, Schließen,
Escape-Behandlung, Fokus auf den aktiven Eintrag und die vorübergehende
Sperrung der Bild-Hotspots werden gemeinsam ausgeführt. Damit kann immer nur
eine Timeline-Auswahlliste offen sein, und nach partieller Navigation bleiben
keine Referenzen auf ersetzte DOM-Elemente zurück. Die servergerenderte
Timeline, ihre API-/SQLite-Datenquelle und der vorhandene kurzlebige
Browsercache bleiben unverändert; insbesondere wurde kein zusätzlicher
Timeline-Cache eingeführt. `camera.js` sinkt von 7.113 auf 6.611 Zeilen. Das
neue Modul wird vor `camera.js` geladen, gehört zur Live-Page-Cache-Version 20
und wird bei Slash-/Non-Slash-Routen als eigenes Asset geprüft. Zehn
fokussierte Playwright-Prüfungen bestanden für den bekannten Stundenfall,
Wechsel und Escape-Aufräumen der Picker, Monatsnavigation, mobile Breite,
Hotspot-Modi, Modal-Lock und beide Pretty-Route-Formen. Die vollständige
PHP-Suite blieb mit 281 Tests und 1.788 Assertions fehlerfrei; der
DEV-Watchdog lief mit expliziten Live-DB-Pfaden ebenfalls erfolgreich.

DEV/Code steht auf Iteration 125. Die zusammenhängende MJPEG-/HLS-
Zustandsmaschine wurde aus `assets/js/camera.js` in das ausführlich
kommentierte Modul `assets/js/camera-stream.js` ausgelagert. Das Modul kapselt
Endpunktaufbereitung und belegungsfreie Probes, erstes-Frame-Überwachung,
Clientlimit-Wiederholung, geplante Reconnects, native beziehungsweise
hls.js-basierte HLS-Wiedergabe, die festgelegte Fallback-Reihenfolge und die
pro Kamera geführte Session-Auswahl zwischen Stream und Snapshot. Kontrollierte
Admin-Trennung (`403`) und ein belegtes Viewer-Limit (`503`) bleiben sichtbar
und lösen weiterhin keinen verdeckten HLS-Fallback aus. `camera.js` verbindet
den Controller nur noch über Adapter mit Timeline-Badge, Ladeanzeige und dem
vorhandenen Snapshot-Ladevorgang und sinkt von 5.579 auf 4.848 Zeilen. Es wurde
keine neue Serverquelle und kein persistenter Cache eingeführt. Das neue Asset
wird in allen Kamera-Renderpfaden vor `camera.js` geladen, gehört zur
Live-Page-Cache-Version 22 und wird bei beiden Pretty-Route-Formen separat auf
Erreichbarkeit geprüft. Neun fokussierte Playwright-Prüfungen bestanden für
kontrollierte `403`-Stops, sichtbare `503`-Clientlimits samt `Retry-After`,
die hls.js-Auswahl, beide expliziten HLS-Queryformen,
Slash-/Non-Slash-Assets sowie Lightbox und partielle Archivnavigation. Die
isolierten Streamtests fangen alle Endpunkte ab und belegen deshalb weder echte
MJPEG-Slots noch starten sie einen HLS-Runner. Die vollständige PHP-Suite blieb
mit 281 Tests und 1.788 Assertions fehlerfrei; der DEV-Watchdog lief mit
expliziten Live-DB-Pfaden ebenfalls erfolgreich. Beim manuellen HLS-Test zeigte
Edge nach mehreren
normalen Reloads zunächst einen schwarzen Player, während Runner, Playlist und
Segmente frisch waren. Ein harter Reload lud den Stream sofort korrekt. Die
Apache-Zugriffe belegten dazu, dass Edge bei den normalen Reloads die
Kamera-JavaScripts nicht erneut angefordert hatte. Lokale CSS-/JS-Assets tragen
deshalb nicht mehr nur die sekundengenaue `filemtime`, sondern eine Kennung aus
Projektiteration, Änderungszeit und Dateigröße. Ein normaler Reload kann damit
nach schnellen Auslagerungen keine alte Stream-Factory mit einer neuen
`camera.js` kombinieren; ein Playwright-Test prüft dieses dreiteilige Format.
Unabhängig davon meldete das aktuelle Chromium/Edge für HLS eine nur teilweise
brauchbare native Wiedergabe. Dieser Pfad erzeugte im Gegenversuch etwa 700
Playlist-Anfragen in zwölf Sekunden und konnte als schwarzer Player enden;
derselbe Stream lief über hls.js mit sieben Playlist-Anfragen stabil. Der
Controller bevorzugt deshalb auf MediaSource-Browsern hls.js und verwendet
native HLS-Wiedergabe nur noch als Fallback, insbesondere für Safari. Ein
isolierter Browsertest simuliert ausdrücklich gleichzeitig vorhandene native
HLS- und hls.js-Unterstützung und sichert die Auswahl von hls.js ab.

DEV/Code steht auf Iteration 124. Die Archiv-Lightbox wurde aus
`assets/js/camera.js` in das ausführlich kommentierte Modul
`assets/js/camera-lightbox.js` ausgelagert. Das Modul bündelt den Lebenszyklus
des Bilddialogs, Zoom und Verschieben, Tastatursteuerung, EXIF-/Wetter- und
Overlay-Anzeige, den kontrollierten Wechsel über Tagesgrenzen sowie die
vorhandene Best-of-Seitennavigation. `camera.js` behält dafür nur eine träge
Factory-Anbindung und schmale Adapter für Timeline, Wettermodal und Likes.
Die Tastatursteuerung folgt nun der Bildbearbeitungslogik: Pfeiltasten
verschieben den vergrößerten Ausschnitt, `Bild auf`/`Bild ab` wechseln das
Einzelbild, `Shift` zusammen mit diesen Tasten springt zum vorherigen oder
nächsten vorhandenen Tag und behält dabei nach Möglichkeit die Uhrzeit bei.
`Pos1`/`Ende`, `+`/`-`, `0`, `Esc` und die mit `H` einblendbare Kurzhilfe
ergänzen die Bedienung. Die Nulltaste passt das Bild tatsächlich mit Faktor
1 ein; Mausziehen und Pfeiltasten verwenden dieselben begrenzten
Verschiebekoordinaten.
Die bereits vorhandenen, nur während einer geöffneten Browserseite lebenden
Maps für EXIF, Best-of-Seiten und Tagesdaten wurden unverändert mit dem
zugehörigen Zustandsblock verschoben; es wurde kein neuer Server-, Datei- oder
Timeline-Cache eingeführt. `camera.js` sinkt von 6.611 auf 5.579 Zeilen. Das
neue Asset wird in allen Kamera-Renderpfaden vor `camera.js` geladen, gehört
zur Live-Page-Cache-Version 21 und wird für Slash-/Non-Slash-Routen separat
auf Erreichbarkeit geprüft. Elf fokussierte Playwright-Prüfungen bestanden für
die neue Tastatursteuerung und Hilfe, Tagesgrenzen, Zoom nach partieller
Archivnavigation, Best-of, die drei Hotspot-Modi, beide Pretty-Route-Formen
sowie die vorhandenen Layoutvarianten. Die Kurzhilfe und die mobile
Kameraansicht wurden dabei auch bei `390x844` geprüft. Die vollständige
PHP-Suite blieb mit 281 Tests und 1.788 Assertions fehlerfrei; der
DEV-Watchdog lief mit expliziten Live-DB-Pfaden ebenfalls erfolgreich.

DEV/Code steht auf Iteration 126. API-Abruf, Request-Abbruch, Race-Schutz,
kurzlebiger Tagescache, Normalisierung der Timeline-Zeilen und das komplette
Navigations-View-Model wurden aus `assets/js/camera.js` in das ausführlich
kommentierte Modul `assets/js/camera-timeline-data.js` verschoben. Der
Controller berechnet Bild-, Stunden-, Tages-, Monats- und Jahresnachbarn und
liefert ein HTML-unabhängiges Präsentationsmodell zurück; `camera.js` übernimmt
nur noch dessen Darstellung in den vorhandenen Controls, Hotspots, Labels und
Overlays. Wetterfenster und Umgebungskarte greifen über die schmale
`loadDay`-/`getCachedDay`-Schnittstelle auf denselben Browserzustand zu, sodass
parallele Anfragen desselben Archivtags geteilt werden. Es wurde kein
persistenter, dateibasierter oder serverseitiger Cache ergänzt. Fehlgeschlagene
Refreshes überschreiben die servergerenderte Timeline nicht mehr mit Daten
einer anderen Auswahl; abgebrochene Promises bleiben nicht als dauerhaft
blockierender Cacheeintrag zurück. `camera.js` sinkt von 4.848 auf 4.144 Zeilen.
Das neue Asset wird in allen Kamera-Renderpfaden vor `camera.js` geladen und ist
Teil der Live-Page-Cache-Version 23. Ein isolierter Browsertest prüft
View-Model, dokumentrelative Links und das Teilen eines Tagesrequests; zusammen
mit Stundenpicker, Monats-/Tagesnavigation, mobilem Layout, Wettermodal und
beiden Pretty-Route-Formen bestanden zehn fokussierte Playwright-Prüfungen.
Die vollständige PHP-Suite blieb mit 281 Tests und 1.788 Assertions
fehlerfrei; der DEV-Watchdog lief mit den expliziten Live-DB-Pfaden ebenfalls
erfolgreich.

DEV/Code steht auf Iteration 127. Der bisher zusammenhängende Dialogblock in
`assets/js/camera.js` wurde nach Verantwortung getrennt. Das ausführlich
kommentierte `assets/js/camera-captcha.js` kapselt Aufbau, Promise-Lebenszyklus,
Neuladen und Fehleranzeige des Like-Captchas; die verbindliche Like-, CSRF- und
Rate-Limit-Prüfung bleibt unverändert im Backend. `assets/js/camera-history.js`
bündelt den eingebetteten Tageshistorie-Dialog sowie das per Public-API
geladene Tages-, Monats- und Jahrespanel. Sein kleiner Map-Cache lebt nur bis
zum Schließen der Browserseite und erzeugt keinen zusätzlichen Datei- oder
Servercache. Wiederholte Initialisierung nach partieller Navigation bindet
keine doppelten Trigger oder globalen Hash-Listener. `camera.js` behält nur
zwei träge Factory-Adapter und sinkt von 4.144 auf 3.633 Zeilen. Beide Assets
werden in allen Kamera-Renderpfaden vor `camera.js` geladen und sind Teil der
Live-Page-Cache-Version 24. Neun fokussierte Playwright-Prüfungen bestanden für
Like-Captcha, Tages-/Monats-/Jahreshistorie, Hotspot-Navigation und beide
Pretty-Route-Tiefen. Der Like-Test verwendet nun kontrollierte HTTP-Antworten
und schreibt keine Live-Likes. Die vollständige PHP-Suite blieb mit 281 Tests
und 1.788 Assertions fehlerfrei; der DEV-Watchdog sowie Kamera- und Statusroute
liefen mit den expliziten Live-DB-Pfaden ebenfalls erfolgreich.

DEV/Code steht auf Iteration 128. Timelapse-Wiedergabe und Tagesvideo wurden
aus `assets/js/camera.js` in das ausführlich kommentierte Modul
`assets/js/camera-playback.js` verschoben. Der Controller kapselt
Dayvideo-Build/Polling, Timelapse-Frames, Vorladen, Scrubber,
Wiedergabegeschwindigkeit, Tastatur und Vollbild sowie das kontrollierte
Ausblenden und Wiederherstellen von Livebild, Stream und Overlays. Der
dokumentweite Dayvideo-Klickhandler wird weiterhin nur einmal registriert;
Timelapse bindet sich nach partieller Navigation jeweils an den aktuell
gerenderten Playback-Block. Timer und globale Tastatur-/Vollbild-Listener der
vorherigen Instanz werden dabei explizit entfernt; der frühere wirkungslose
`remove`-Event-Listener entfällt. Es entsteht kein zusätzlicher persistenter
Cache, und `refresh=1` bleibt auf den ersten manuellen Dayvideo-Neuaufbau
begrenzt.
`camera.js` sinkt von 3.633 auf 2.944 Zeilen. Das neue Asset wird in allen
Kamera-Renderpfaden vor `camera.js` geladen und gehört zur
Live-Page-Cache-Version 25. Sieben fokussierte Playwright-Prüfungen bestanden
für Timelapse-Laden, Scrubber, Framezahl, Wiedergabe, Tastatur, Menüanordnung,
Dayvideo öffnen/neu laden/schließen, temporär ausgeblendete Overlays und beide
Pretty-Route-Tiefen. Die vollständige PHP-Suite blieb mit 281 Tests und 1.788
Assertions fehlerfrei; DEV-Watchdog, Playback- und Statusroute liefen mit den
expliziten Live-DB-Pfaden ebenfalls erfolgreich.

DEV/Code steht auf Iteration 129. Der gemeinsame Lebenszyklus des EXIF-,
Wetter-, Umgebungskarten- und Bildvergleichsdialogs liegt nun im ausführlich
kommentierten `assets/js/camera-meta-modal.js`. Das Modul verwaltet Fokus,
Seitensperre, Darstellungsmodi, asynchronen Race-Schutz, Kartenbereinigung und
die dokumentweit delegierten Menüaktionen. Die bestehenden Fachmodule für
Wetter, Karte und Bildvergleich bleiben unverändert; `camera.js` stellt ihnen
nur dynamische Adapter zum aktuellen Kamera-Root, zu Timeline-Pickern und
Beschriftungen bereit. Dadurch funktionieren die Trigger weiterhin nach einer
partiellen Archivnavigation, ohne globale Listener mehrfach zu registrieren.
`camera.js` sinkt von 2.944 auf 2.633 Zeilen. Das neue Asset wird in allen
Kamera-Renderpfaden vor `camera.js` geladen und gehört zur Live-Page-Cache-
Version 26. Neun fokussierte Playwright-Prüfungen bestanden für EXIF, Wetter,
Umgebungskarte, Bildvergleich, Timeline-Lock, mobile und breite Darstellung
sowie beide Pretty-Route-Tiefen. Die vollständige PHP-Suite blieb mit 281
Tests und 1.788 Assertions fehlerfrei; DEV-Watchdog, Kamera- und Statusroute
liefen mit den expliziten Live-DB-Pfaden ebenfalls erfolgreich.

DEV/Code steht auf Iteration 130. Normalisierung, sichere DOM-Erzeugung,
Farbblasen, Browserzustand und die Unterdrückung bereits eingebrannter
Bildbeschriftungen wurden aus `assets/js/camera.js` in das ausführlich
kommentierte Modul `assets/js/camera-labels.js` verschoben. Der Controller
liest seinen Kamera-Root bei jedem Arbeitsschritt neu und bleibt dadurch nach
partieller Timeline-Navigation am aktuellen DOM. Die zuvor doppelte
Klickbehandlung aus direktem Schalter-Listener und Root-Delegation wurde durch
genau einen dokumentweit delegierten Listener ersetzt. Labeltexte werden
weiterhin ausschließlich über `textContent` ausgegeben; Links und Farben
durchlaufen vor dem DOM-Aufbau begrenzte Normalisierungen.
`camera.js` sinkt von 2.633 auf 2.292 Zeilen. Das neue Asset wird in allen
Kamera-Renderpfaden vor `camera.js` geladen und gehört zur
Live-Page-Cache-Version 27. Sechs fokussierte Playwright-Prüfungen bestanden
für Labelumschaltung, Bildvergleich, eingebrannte Overlays, partiellen
Root-Austausch, modale Seiteneffekte und beide Pretty-Route-Tiefen. Die
vollständige PHP-Suite blieb mit 281 Tests und 1.788 Assertions fehlerfrei;
DEV-Watchdog, Kamera- und Statusroute liefen mit den expliziten Live-DB-Pfaden
ebenfalls erfolgreich.

DEV/Code steht auf Iteration 131. Der verbliebene Timeline-Darstellungsblock
wurde aus `assets/js/camera.js` in das ausführlich kommentierte Modul
`assets/js/camera-timeline-view.js` verschoben. Das Modul baut
Navigationsgruppen, Uhrzeitauswahl und seitliche Hotspots mit DOM-Methoden,
übernimmt das fertige Snapshot-Präsentationsmodell und verwaltet den
Lade-/Timeoutzustand des Hauptbildes. Datenabruf, Tagescache, Normalisierung
und Nachbarsuche verbleiben getrennt in `camera-timeline-data.js`; Bedienung
und Lebenszyklus der Picker bleiben in `camera-timeline.js`. Nach partieller
Navigation wird der aktuelle Kamera-Root jeweils neu aufgelöst.
`camera.js` sinkt von 2.292 auf 2.041 Zeilen. Das neue Asset wird in allen
Kamera-Renderpfaden vor `camera.js` geladen und gehört zur
Live-Page-Cache-Version 28. Einundzwanzig fokussierte Playwright-Prüfungen
bestanden für Timeline-Modell, Zeit-/Stunden-/Monatsnavigation, Hotspots in
Zeit-, Tages-, Monats- und Jahresmodi, partielle Navigation, Labels, Likes,
mobile Breite und beide Pretty-Route-Tiefen. Die vollständige PHP-Suite blieb
mit 281 Tests und 1.788 Assertions fehlerfrei; DEV-Watchdog, Kamera- und
Statusroute liefen mit den expliziten Live-DB-Pfaden ebenfalls erfolgreich.

DEV/Code steht auf Iteration 132. Same-Origin-Seitenprefetch,
Request-Deduplizierung, begrenzter Dokument-/Bild-Blob-Cache und die Hydrierung
des Hauptbildes nach partieller Navigation wurden aus `assets/js/camera.js` in
das ausführlich kommentierte Modul `assets/js/camera-snapshot-prefetch.js`
verschoben. Das Modul hält höchstens sechs geparste Nachbardokumente und zehn
Bild-Blobs im aktuellen Browserdokument, gibt verdrängte Blob-URLs frei und
prüft erwartete Snapshot-Schlüssel vor der Wiederverwendung. Prefetch-Fehler
bleiben eine wirkungslose Optimierungsabweichung; die reguläre Navigation
bleibt der sichtbare Fallback. Es entsteht kein persistenter Servercache.
`camera.js` sinkt von 2.041 auf 1.870 Zeilen. Das neue Asset wird in allen
Kamera-Renderpfaden vor `camera.js` geladen und gehört zur
Live-Page-Cache-Version 29. Fünfzehn fokussierte Playwright-Prüfungen
bestanden für Request-Deduplizierung, Snapshot-Key-Schutz, einmaligen
Dokumentverbrauch, Blob-Hydrierung, Zeit-/Monatsnavigation, Hotspots,
partielle Navigation, Labels, Likes und beide Pretty-Route-Tiefen. Die
vollständige PHP-Suite blieb mit 281 Tests und 1.788 Assertions fehlerfrei;
DEV-Watchdog, Kamera- und Statusroute liefen mit den expliziten Live-DB-Pfaden
ebenfalls erfolgreich.

DEV/Code steht auf Iteration 133. Status-/Telemetriedialog sowie die öffentliche
Like-/Best-of-Aktion wurden aus `assets/js/camera.js` in die ausführlich
kommentierten Module `assets/js/camera-status.js` und
`assets/js/camera-bestof-like.js` verschoben. Der Statuscontroller erklärt
Formatierung, zusammengeführte API-Anfragen, den ausschließlich seitenlokalen
Detailcache und den Modal-Lebenszyklus. Nach einem partiellen DOM-Austausch
löst er Modal, Fallbackinhalt und Trigger neu auf, statt auf entfernte Knoten
zu zeigen. Der Like-Controller dokumentiert Pfadnormalisierung, CSRF-/Captcha-
Request, sichtbaren Herzzustand, Fehlerfälle und die Umschaltung in die
Best-of-Navigation; Rate-Limit, Captcha-Prüfung und atomarer Bild-/DB-Schreibweg
bleiben im Backend. `camera.js` enthält nur noch schmale Factory-Adapter und
sinkt von 1.870 auf 1.530 Zeilen. Beide Assets werden in allen
Kamera-Renderpfaden vor `camera.js` geladen und gehören zur
Live-Page-Cache-Version 30. Zwei isolierte Playwright-Tests sichern den
Statusdialog nach DOM-Austausch sowie Legacy-Pfadnormalisierung und bestätigten
Like-Zustand; die vorhandenen Like-, Hotspot- und Route-Asset-Tests blieben
erfolgreich.

DEV/Code steht auf Iteration 134. Die partielle Same-Origin-Navigation der
Kameraansicht wurde aus `assets/js/camera.js` in das ausführlich kommentierte
Modul `assets/js/camera-partial-navigation.js` verschoben. Seine Leseführung
gliedert URL-Prüfung, Request-Generation und -Abbruch, Übernahme vorgeholter
Dokumente, kontrollierten Root-Austausch, History-/Popstate-Verhalten sowie
Maus- und Tastaturbedienung der Timeline-Hotspots in getrennte Abschnitte.
Kamerawechsel bleiben bewusst vollständige Seitennavigationen, damit Karte und
Inline-Konfiguration neu aufgebaut werden. Eine neuere, bereits vorgeholte
Navigation entwertet nun auch einen älteren Netzrequest; dessen späterer
Abbruch kann nicht mehr auf das veraltete Ziel umleiten. Nach erfolgreichem
DOM-Austausch startet ein schmaler Lifecycle-Adapter die bereits getrennten
Fachcontroller am aktuellen Kamera-Root neu. `camera.js` sinkt von 1.530 auf
1.329 Zeilen. Das Asset wird in allen Kamera-Renderpfaden vor `camera.js`
geladen und gehört zur Live-Page-Cache-Version 31. Zwei isolierte
Playwright-Tests sichern Root-Austausch, erneutes Event-Binding und die
Request-Race-Condition. Insgesamt 14 fokussierte Browserprüfungen bestätigten
außerdem Timeline-Picker, Hotspot-Modi, Likes, Label-Neubindung und beide
Pretty-Route-Tiefen; ein echter Archivlauf bestätigte Zeitnavigation sowie
Browser-Zurück ohne JavaScript-Fehler. Die vollständige PHP-Suite blieb mit
281 Tests und 1.789 Assertions fehlerfrei; DEV-Watchdog, Kamera- und
Statusroute liefen mit den expliziten Live-DB-Pfaden ebenfalls erfolgreich.

DEV/Code steht auf Iteration 135. Der letzte zusammenhängende
Livebild-Lebenszyklus wurde aus `assets/js/camera.js` in das ausführlich
kommentierte Modul `assets/js/camera-live-image.js` verschoben. Seine
Leseführung trennt Reload-Timer und Fokus, Vorschaubild, vorab geladenen
Vollbildtausch, Ladeabschluss und Prefetch, gefilterte Lightbox-Klicks,
Snapshot-/Streamzustände sowie die Rückkehr aus der Informationsansicht. Das
Modul entscheidet weder über MJPEG/HLS noch über Timeline- oder
Lightbox-Fachlogik; diese Controller werden weiterhin nur über schmale Adapter
angesprochen. Event-Bindings für Bild und Info-Rückweg sind idempotent.
`camera.js` sinkt von 1.329 auf 1.142 Zeilen und dient damit überwiegend als
Orchestrator gemeinsamer URL-/DOM-Adapter und Modul-Lebenszyklen. Das neue
Asset wird in allen Kamera-Renderpfaden vor `camera.js` geladen und gehört zur
Live-Page-Cache-Version 32. Zwei isolierte Playwright-Tests sichern
Placeholder-/Vollbildtausch, Ladeabschluss, Prefetch, gefilterten
Lightbox-Klick und HLS-Bereitschaft ohne Snapshot-Ladevorgang. Insgesamt 15
fokussierte Browserprüfungen bestätigten zusätzlich Streamzustände, explizite
HLS-Auswahl, partielle Navigation, beide Pretty-Route-Tiefen, Archiv-Lightbox,
Hotspots und Likes.

Der vollständige Release-Stand 6.0.6/Iteration 135 wurde am 30.08.2026 mit
Commit `a8c7304299b0ae1f55289412351c744190ead84b` kontrolliert nach PROD
ausgerollt. Zuvor liefen alle 281 PHPUnit-Tests mit 1.789 Assertions sowie die
JavaScript-/PHP-Syntaxprüfungen fehlerfrei. Nach dem Rollout stimmten alle 322
Laufzeitdateien bytegenau mit dem Release überein; öffentliche Routen,
Zugriffsschutz, reale Browserfälle und der vollständige 01:30-Capture-Zyklus
aller vier aktiven Kameras wurden erfolgreich geprüft.

## Zählweise

Die Git-Historie enthielt am Referenzstand 967 Commits. Davon änderten 135
ausschließlich Dokumentationsdateien und werden nicht als technische Iteration
gezählt. 832 Commits enthalten eine technische Änderung.

Ein Commit zählt als technische Iteration, sobald er mindestens eine
betriebswirksame Datei außerhalb der reinen Dokumentation ändert. Gemischte
Commits aus Implementierung und Dokumentation zählen deshalb mit. Reine
Dokumentations-, Versionsdeklarations- oder Release-Metadaten-Commits erhöhen
die Iterationsnummer nicht.

Version 6 beginnt für diese rückwirkende Einordnung mit dem Entwicklungsblock
ab Mai 2026. Bis einschließlich `bd28fca` umfasst dieser Zeitraum 109 Commits:
80 technische Iterationen und 29 reine Dokumentations-Commits. Die anschließende
Festlegung der Versionsnummer war reine Release-Metadatenpflege und erhöhte die
Iteration nicht. Die Trennung des Kamera-Page-Caches nach Request-Route ist
Iteration 81. Die anschließende Cache-/Query-Härtung und die explizite
HLS-Auswahl bilden Iteration 82 und das Patch-Release **Version 6.0.1**. Der
gleichwertige Kamera-Seiten-Alias `?format=hls` ist Iteration 83. Die gemeinsame,
inhaltlich getrennte Orientierungskopfzeile fuer Übersicht, globales Best Of,
Status und Wetter ist Iteration 84. Die fail-closed Archivvollständigkeitsprüfung
für Cache-Retention, physische Wetter-/EXIF-Sidecar-Backups und die
`/webcam2`-Präfixkorrektur bilden Iteration 85. Die getrennte, datensparsame
Produktionsinstallation mit positiver Release-Dateiliste, lokalem SQLite-
Backup-Staging unter `/var/local/webcam`, frei konfigurierbaren
Wartungsscripten und der bereinigten Admin-Navigation ist Iteration 86. Der alte
Timeline-Hauptreiter entfällt; das wirksame DB-Limit liegt unter Meta und der
Legacy-Cache nur noch als eingeklappte Diagnose unter Status. Dieser Stand ist
das Patch-Release **Version 6.0.2**. Der eigenständige, schlanke Alt-System-
Bridge-Helper mit vier isolierbaren Kamera-Instanzen, persistentem Upload-Spool,
Heartbeat und fail-closed HTTP-/JPEG-Prüfung sowie die Korrekturen für
Admin-CSRF-Pfadcookies und die Persistenz von Capture-Endpunkt und Upload-Key
bilden Iteration 87. Der korrigierte Altquellpfad `/<slug>/current/raw.jpg`, der
konfigurierbare 210-Sekunden-Quellverzug und Cache-Bypass beim Abruf verhindern,
dass ein noch nicht aktualisiertes Altbild dem folgenden PROD-Slot zugeordnet
wird; diese im Livebetrieb verifizierte Frischekorrektur bildet Iteration 88.
Die atomare Veröffentlichung von Capture-/Current-/Archivbildern, EXIF-,
Wetter-, Like- und OCR-Sidecars, Overlay-Uploads und neu gebauten Tagesvideos
über im Zielverzeichnis gestagte Dateien bildet Iteration 89.
Der verpflichtende Mount-Sentinel für reale Installationen verhindert, dass
Archiv-Rollover oder Cache-Retention bei einem ausgefallenen gemeinsamen
HDD-Unter-Mount gegen das leere Mountverzeichnis arbeiten; dieser fail-closed
Archivschutz bildet Iteration 90.
Die inhaltliche Verifikation und atomare Reparatur vorhandener Archiv-Zielsets,
der gemeinsame Tages-Lock auf dem Archiv-Mount sowie der standardmaessig
deaktivierte, fest eingebaute Archivjob im internen Wartungs-Scheduler bilden
Iteration 91.
Der ueberwachte HLS-Runner beendet ffmpeg standardmaessig nach 45 Sekunden ohne
frischen Viewer-Heartbeat, behaelt den maximalen Laufzeitstopp bei und raeumt
PID sowie Prozess-Lock kontrolliert auf; diese Leerlaufsteuerung bildet
Iteration 92.
Der gemeinsame Overlay-Datei-Lock fuer Admin-Upload und Orphan-Cleanup sowie
die 24-stuendige Schutzfrist fuer noch nicht gespeicherte neue Uploads
schliessen die verbleibende Cleanup-Race-Condition; dieser Schutz bildet
Iteration 93.
Die zusaetzliche CSS-/ARIA-Absicherung entfernt bei erkannter eingebrannter
Legacy-Grafik auch die komplette interaktive Beschriftungsebene; diese
Anzeigehaertung bildet Iteration 94.
Die DB-basierte Stundennavigation loest ihre Zielzeit nun als absoluten
Zeitpunkt auf dem jeweils richtigen Kalendertag auf. Dadurch bleibt die Minute
beim Wechsel ueber Mitternacht erhalten, insbesondere `00:50` zu `23:50` am
Vortag; diese Timeline-Korrektur bildet Iteration 95.
Die zentrale rekursive Redaction an Log- und Statusgrenzen sowie die
stdin-basierte RTSP-Uebergabe fuer Snapshot, MJPEG und HLS verhindern, dass
Zugangsdaten in Prozessargumenten, Runner-State oder ausgegebenen Laufzeitdaten
erscheinen; diese Credential-Haertung bildet Iteration 96.
Die explizite Weitergabe eines unmittelbar verpassten Pull-Sollslots bis in
`ImageStorage`, bei weiterhin getrennter realer Aufnahmezeit, verhindert
Zwischenslot-Luecken nach langen globalen Capture-Runden. Ein begrenztes
Nachholfenster verhindert zugleich das Auffuellen langer Ausfallzeiten mit
aktuellen Bildern; diese Scheduler-Korrektur bildet Iteration 97.
Die bytegenaue Eingangspruefung gegen das letzte verwendbare Bild verwirft
eingefrorene Folgeframes vor der Dateipublikation, behaelt ihren Slot samt
Wetterdaten als `is_duplicate`-Zeile in der Kamera-DB und setzt den sichtbaren
Kamerastatus bis zum naechsten echten Bild auf Rot. Timeline, Bildsuche,
Dayvideo und Best-Of ignorieren markierte Captures auch im Dateisystem-Fallback;
der begrenzte, standardmaessig schreibgeschuetzte Bestandsabgleich
`bin/reconcile_capture_images.php` markiert Altzeilen nach einem konsistenten
SQLite-Backup. Die gemeinsame Fusszeile zeigt Version und Iteration aus den
maschinenlesbaren Projektdateien. Dieser Stand bildet Iteration 98.
Der historische Duplikatabgleich erzeugt sein Pflichtbackup nun wie der
Tagesbackupjob zunaechst auf dem lokalen SQLite-Stagingpfad, prueft den
geschlossenen Snapshot und kopiert ihn erst danach ins Backupziel. Damit
scheitert der Apply-Lauf auf einem CIFS-Deploy nicht mehr an einem direkt auf
dem Netz-Mount geoeffneten SQLite-Ziel; diese Backupkorrektur bildet
Iteration 99.
Der Bestandsabgleich gibt vor seinem Schreibpfad alle Scan-Cursor frei und
setzt die ermittelten Duplikatflags in einer einzigen Kamera-DB-Transaktion.
Damit kann weder die eigene Leserverbindung den Apply-Lauf blockieren noch ein
Fehler einen nur teilweise markierten Bestand hinterlassen; diese atomare
Reconcile-Korrektur bildet Iteration 100.

Der Bestandsabgleich prueft nun jede einzelne Variantenzeile gegen die reale
Datei. Fehlende, leere oder ungueltige JPEG-Varianten erhalten atomar
`size_bytes=0` und koennen dadurch nicht mehr wegen veralteter DB-Metadaten als
bevorzugtes Timeline-Bild ausgewaehlt werden. Gueltige Varianten bekommen ihre
tatsaechliche Groesse und MTime; Wetter, EXIF und Bildzeilen bleiben erhalten.
Diese variantengenaue Bestandskorrektur bildet Iteration 101.

Leere oder ausschließlich als Duplikat markierte Tage lösen in der
Kameraansicht keinen vollständigen Jahresaufbau mehr aus. Eine indexierte
SQLite-Suche bestimmt höchstens das nächste verwendbare Bild; anschließend
wird nur dessen Tag für die Navigation geladen. Unbeschränkte Timeline-Leser
begrenzen bereits die Capture-Abfrage vor dem Laden von Varianten, EXIF und
Wetter, wobei historische `max_entries=0` sicher auf 300 Einträge fällt.
Dateisystem-Fallbacks respektieren auch an gemischten Tagen jedes
`is_duplicate`-Flag. Die Bereichs- und Fallbacklogik liegt nun im separaten
`CameraTimelineResolver` statt im gewachsenen `camera.php`. Diese Speicher-
und Strukturkorrektur bildet Iteration 102.

Die Archive-Timeline ist intern entlang ihrer Datenquellen getrennt:
`ArchiveDatabaseReader` kapselt SQLite-Abfragen, Duplikat-/Wetterfilter und die
Hydrierung von Capture- und Variantenzeilen, `ArchiveFilesystemReader` den eng
begrenzten Legacy-Dateisystem-Fallback und `TimelineMapper` die rein
speicherinterne Sortierung sowie Kalender-/Timeline-Abbildung. `ArchiveBrowser`
koordiniert diese Komponenten weiterhin hinter seiner unveränderten
öffentlichen Schnittstelle. Diese getestete Entkopplung bildet Iteration 103.

Iteration 104 härtet den optionalen öffentlichen Dayvideo-Build: feste
FPS-/Breitenprofile und höchstens 300 Frames, ein gehashtes IP-Budget hinter
einer rechts nach links geprüften Trusted-Proxy-Kette sowie ein hostweiter
Slot-Pool mit hart maximal zwei parallelen Builds. HTTP-Adapter, Frame-Auswahl,
ffmpeg-Runner, Locks und SQLite-Limiter sind aus `api/public.php` in getrennte
Einheiten ausgelagert; pro Build wird die Archiv-Timeline genau einmal geladen.
Der kontrollierte PROD-Rollout erfolgte am 28.08.2026 mit dem exakten
TLS-Proxy `192.0.2.2` in `WEBCAM_TRUSTED_PROXIES`.

Iteration 105 ergänzt neben dem unveränderten Legacy-Upload das authentifiziert
ausgehandelte Protokoll `camera-rpi-chunk-v1`. Ein eigener Endpunkt speichert
begrenzte Teile wiederholbar und atomar, vergleicht Manifest, Einzelhashes und
Gesamt-Hash und übergibt erst das vollständige Bild an den gemeinsamen
`UploadIngestor`. Damit verwenden Legacy und Chunk-v1 dieselbe Slot-
Deduplikation, Bildablage, Wetter-, Status- und Logikette. Alte Gegenstellen
bleiben kompatibel; der Camera-RPi-Worker fällt ohne ausdrückliche
Protokollbestätigung automatisch auf Legacy zurück.

Iteration 106 ergänzt ein idempotentes Einrichtungs- und Prüfskript für den
unprivilegierten Raspberry-Pi-Benutzer `analyse`. Es beschränkt Zusatzgruppen
auf die reinen Log-Lesegruppen `adm` und `systemd-journal`, weitet geschützte
Leserechte unter `/etc` nicht pauschal auf und erzwingt die fehlenden
Schreibrechte unter `/etc`, `/sys` und `/var`. Die notwendige
Ausnahmebehandlung für das als tmpfs eingehängte `/var/tmp` wird als ACL über
`systemd-tmpfiles` nach jedem Neustart erneut angewendet und anschließend
zusammen mit den übrigen Bäumen geprüft. Virtuelle `securityfs`-Knoten werden
dabei separat von persistenten Dateien bewertet, weil deren offene Modusbits
die nachgelagerte Kernel-/Capability-Autorisierung nicht abbilden.

Iteration 107 erweitert den systemd-basierten Legacy-Webcam-Helper um die
authentifizierte Aushandlung und Nutzung von `camera-rpi-chunk-v1`. Der Helper
übernimmt die vom Server bevorzugte Teilgröße, erzeugt stabile Upload-IDs und
SHA-256-Prüfsummen für Gesamtbild und Einzelteile und wiederholt fehlgeschlagene
Teile mit harten Timeouts. Das persistente Original bleibt bis zum bestätigten
`finalize` im Spool. Nur eine ausdrücklich fehlende beziehungsweise abweichende
Capability erlaubt im Standardmodus den Legacy-Fallback; Netzwerk-, Auth- und
Serverfehler bleiben fail-closed sichtbar. Ein dokumentierter Canary-Rollout
und ein erzwungener Chunk- beziehungsweise Legacy-Modus erlauben kontrollierte
Einführung und Rücknahme.

Iteration 108 trennt die flüchtigen systemd-Runtime-Verzeichnisse des Legacy-
Webcam-Helpers pro Kamera-Instanz. Damit kann der Stopp oder Neustart einer
Instanz nicht mehr das private Headerfile, den Lock oder den Heartbeat einer
weiterlaufenden Kamera entfernen und deren curl-Aufrufe mit Fehler 26
unterbrechen.

Die Iterationen 109 bis 135 bilden zusammen das Patch-Release **Version
6.0.6**. Die reine Versionsdeklaration erhoeht die Iteration nicht.

Die Iterationen 136 bis 159 bilden zusammen das Patch-Release **Version
6.0.7**. Die reine Versionsdeklaration erhoeht die Iteration nicht.

Die Iterationen 160 bis 168 bilden zusammen das Minor-Release **Version
6.1.0**. Die reine Versionsdeklaration erhoeht die Iteration nicht. Mit diesem
Stand ist die geplante Entzerrung aller großen Laufzeitkomponenten
abgeschlossen; das historische OCR-Werkzeug `bin/extract_wx_ocr.php` bleibt
bewusst unverändert und zurückgestellt.

## Technische Änderungen von 6.0.7 zu 6.1.0

- Öffentliche API, Kamera-Request, Storage, Streams, Status, Konfiguration und
  Wartungswerkzeuge sind in kommentierte Handler, Reader, Writer, Resolver und
  Präsentationskomponenten getrennt. Die früher großen Einstiegsdateien dienen
  nur noch als überschaubare Koordinatoren beziehungsweise Fassaden.
- Wiederholte Pfad-, Payload-, Zeit-, Status-, Best-Of- und
  Konfigurationslogik wurde zusammengeführt. Der ausführlich dokumentierte
  Quellcode bleibt auch für betriebliche Pflege ohne tiefes Entwicklerwissen
  nachvollziehbar.
- Archiv-, Timeline-, Wetter-, EXIF-, Stream- und Lightbox-Funktionen erhielten
  zusätzliche Regressionstests. Die Kamera-Lightbox unterstützt zum Abschluss
  einen lokalen Download des sichtbaren Bildausschnitts und maximal 8x Zoom.
- Die nach den Entzerrungen gefundenen Laufzeitfehler wurden separat behoben:
  Archivbildauflösung und Verlaufsvorschaubilder erzeugen keine erwartbaren
  404 mehr, read-only DB-Backup-Verzeichnisse werden nicht sinnlos korrigiert,
  und Pull-Snapshot kann Active-Upload kontrolliert als Fehlerfallback nutzen.

## Technische Änderungen von 6.0.6 zu 6.0.7

- Übersicht, Kameraansicht und Admin-Oberfläche wurden in nachvollziehbare,
  kommentierte Browsermodule aufgeteilt; die zugehörigen PHP-Render- und
  Präsentationsblöcke sind in klar benannte Builder und Controller getrennt.
- Die Admin-API ist von einem gemischten 2.282-Zeilen-Endpunkt auf einen
  295-Zeilen-HTTP-Einstieg reduziert. Kamera-, globale Config-,
  Archivcache-, Stream- und Statusrouten liegen in eigenständigen, getesteten
  Handlern; Authentifizierung, CSRF und zentrale Antwortausgabe bleiben dort.
- Overlay-Uploads, Capture-Payloads, Kamera-Konfiguration und Capture-Suchen
  besitzen eigene Validierungs- und Dateisystem-/SQLite-Grenzen. Die neuen
  Komponenten sind im Code schrittweise kommentiert und mit isolierten sowie
  echten HTTP- und Browserfällen abgesichert.
- Die Archiv-Mount-Schreibprobe beendet Rollover bei read-only-Mounts sichtbar
  und ohne Cache-Löschung. Admin- und Browserregressionen prüfen nun auch
  reproduzierbar persistierte Wartungspläne statt zufälliger Live-Defaults.

## Technische Änderungen von 6.0.5 zu 6.0.6

- Der rekursive HTML-Sanitizer prüft aus unbekannten Wrappern herausgehobene
  Kindknoten erneut und entfernt Script-/SVG-/MathML-Inhalte, Event-Handler
  sowie gefährliche URL-Schemata vor der öffentlichen Ausgabe.
- Upload-, Timeline-, externe Prozess-, Rate-Limit- und Archivpfade arbeiten
  mit harten Größen-, Zeit-, Output- und Fehlergrenzen; speicherintensive
  Archivabfragen werden bereits in SQLite begrenzt und blockweise hydriert.
- Große PHP-Verantwortlichkeiten wurden aus Archivbrowser, Kamera-Route und
  Präsentationslogik in klar benannte, getestete Services getrennt.
- Die Kameraoberfläche ist in dokumentierte Fachcontroller für Timeline,
  Prefetch, Navigation, Wetter, Modals, Playback, Stream, Lightbox, Labels,
  Status, Likes, Bildvergleich und Hauptbild-Lebenszyklus aufgeteilt;
  `camera.js` dient überwiegend als Orchestrator.
- Timeline-Picker und Hotspots, mobile Breite, Wetterdiagramme,
  Bildvergleichs-Schieber, Lightbox-Tastatursteuerung und HLS-Neustart wurden
  anhand reproduzierbarer Browserfälle korrigiert beziehungsweise verbessert.
- Performance-Baselines enthalten Warm- und Cold-Archivzugriffe nach den
  DB-first- und Frontend-Entzerrungen.

Die Iterationen 105 bis 108 bilden zusammen das Patch-Release **Version
6.0.5**. Die reine Versionsdeklaration erhoeht die Iteration nicht.

## Technische Änderungen von 6.0.4 zu 6.0.5

- Der authentifizierte Endpunkt `include/upload-chunk.php` nimmt begrenzte,
  wiederholbare JPEG-Teile entgegen und übergibt erst das vollständig
  verifizierte Bild an denselben Ingest-Weg wie der Legacy-Upload.
- Das idempotente Betriebswerkzeug für den unprivilegierten Benutzer `analyse`
  gewährt Log-Lesezugriff, verhindert aber Schreibzugriffe unter `/etc`,
  `/sys`, `/var` und dem flüchtigen `/var/tmp`.
- Der Legacy-Webcam-Helper handelt Chunk-v1 explizit aus, behält unbestätigte
  Bilder im persistenten Spool und fällt nur bei tatsächlich fehlender
  Capability auf den alten Upload zurück.
- Flüchtige Header-, Lock- und Heartbeat-Dateien liegen pro Kamera in einem
  eigenen systemd-Runtime-Verzeichnis. Der Neustart einer Instanz beeinflusst
  dadurch keine parallel laufende Kamera mehr.

Iteration 102 bildet aufgrund der neuen Kamera-DB-Spalte und der sichtbaren
Duplikatbehandlung das Patch-Release **Version 6.0.4**. Die reine
Versionsdeklaration erhoeht die Iteration nicht.

## Technische Änderungen von 6.0.3 zu 6.0.4

- Byte-identische Folgeframes werden vor der Bildpublikation verworfen und als
  `captures.is_duplicate=1` gespeichert; Slot, Wetter und EXIF bleiben erhalten.
- Timeline, Bildsuche, Dayvideo, Likes und Best-Of ignorieren markierte Captures
  auch im Legacy-Dateisystem-Fallback.
- Leere Duplikat-Tage verwenden eine begrenzte, indexierte Nächstsuche statt
  einen kompletten Jahrgang samt Varianten, EXIF und Wetter in PHP zu laden;
  die Auswahlroutine ist aus `camera.php` in einen eigenen Service ausgelagert.
- Ein aktiver Duplikatfehler bleibt im Status rot, bis wieder ein echtes neues
  Bild gespeichert wurde.
- `bin/reconcile_capture_images.php` gleicht einen begrenzten historischen
  Zeitraum mit DB-Hashes und real vorhandenen JPEGs ab; Schreiblaeufe erzeugen
  vorher ein lokal gestagtes und geprueftes SQLite-Backup und kopieren erst den
  geschlossenen Snapshot ins eigentliche Backupziel. Alle ermittelten Flags
  und die realen Groessen-/MTime-Zustaende jeder Variante werden danach atomar
  in einer gemeinsamen DB-Transaktion gesetzt. Fehlende oder ungueltige
  Varianten bleiben als Nachweiszeile erhalten, werden aber mit `size_bytes=0`
  aus der Bildauswahl ausgeschlossen.
- Die gemeinsame Fusszeile zeigt die maschinenlesbare Version und Iteration.
- Version 6.0.4 ergaenzt `captures.is_duplicate`; schreibende Kamera-DB-Zugriffe
  migrieren das Schema automatisch. Der historische Abgleich wird vor dem
  Rollout kontrolliert per `bin/reconcile_capture_images.php` ausgefuehrt.

Iteration 95 schliesst zusammen mit den seit Version 6.0.2 hinzugekommenen
Iterationen 92 bis 94 das Patch-Release **Version 6.0.3** ab. Die reine
Versionsdeklaration erhoeht die Iteration nicht.

## Technische Änderungen von 6.0.2 zu 6.0.3

- Der HLS-Runner beendet ffmpeg nach 45 Sekunden ohne frischen Viewer-
  Heartbeat und behaelt den maximalen Laufzeitstopp als harte Obergrenze bei.
- Overlay-Upload und Orphan-Cleanup teilen sich einen exklusiven Datei-Lock;
  neue, noch nicht gespeicherte Uploads besitzen eine 24-stuendige Schutzfrist.
- Erkannte eingebrannte Legacy-Grafiken unterdruecken neben Wetteroverlay und
  Logo auch die komplette interaktive Beschriftungsebene.
- Die DB-basierte Stundennavigation behaelt beim Wechsel ueber Mitternacht die
  Zielminute und wechselt beispielsweise von `00:50` auf `23:50` am Vortag.
- RTSP-Zugangsdaten werden nur ueber ein stdin-Manifest an ffmpeg uebergeben;
  Prozessargumente, Runner-Konfiguration, Logs und Status bleiben bereinigt.
- Pull-Captures koennen den unmittelbar vorherigen Sollslot nach einer langen
  Runde gezielt schreiben, ohne `captured_ts` auf die Sollzeit umzudatieren.
- Fuer Version 6.0.3 ist keine Datenbankmigration erforderlich.

## Technische Änderungen von 6.0.1 zu 6.0.2

### Livestream und öffentliche Oberfläche

- `?format=hls` ist in der Kameraansicht ein vollwertiger Alias für
  `?stream=hls`: Es wird direkt HLS gewählt, kein MJPEG-Slot geöffnet und der
  Modus als „Livestream HLS“ gekennzeichnet.
- Startseite, globales Best Of, Status und Wetter verwenden denselben kompakten,
  vom Seiteninhalt getrennten Orientierungskopf. Die Wetterseite ist aus diesem
  Kopf direkt erreichbar; gemeinsame CSS-Regeln verhindern Größen-, Rundungs-
  und Breitenunterschiede zwischen den Seiten.

### Backup und Wiederherstellbarkeit

- `bin/backup_daily.php` sichert zusätzlich zu Config- und Kamera-SQLite die
  Wetter- und EXIF-Textdateien des abgeschlossenen Vortags aus Cache und Archiv.
- Sidecar-Ziele werden temporär geschrieben, über Dateigröße und SHA-256 geprüft
  und atomar veröffentlicht. Leere, abweichende oder nicht kopierbare Dateien
  setzen den Gesamtbackupstatus auf `partial`; betroffene Kameras gelten nicht
  als vollständig gesichert und die abhängige Wetterbereinigung bleibt gesperrt.
- `bin/backup_sidecars.php` ergänzt kontrollierte historische Basisläufe nach
  Datum, Jahr, Zeitraum und Kamera. Original-JPEGs und Videos werden dabei nicht
  verändert oder kopiert.

### Cache-Retention und Archivschutz

- Vor dem Löschen eines Cache-Tages prüft der neue
  `ArchiveCompletenessVerifier` jede Originalaufnahme auf alle fünf gültigen
  Archivvarianten sowie auf die im Cache vorhandenen EXIF-/Wetter-Sidecars.
- Schlägt eine Prüfung fehl, bleibt der gesamte Tag erhalten und der Grund wird
  als `cache retention archive verification failed` protokolliert.
- Der frühere, davon unabhängige Jahres-Prune im `DailyArchiver` ist entfernt.
  Damit gilt das konfigurierte 180-Tage-Fenster auch über Jahreswechsel und es
  gibt nur noch einen automatischen Löschpfad für Capture-Tage.

### Parallele Installation

- Die `.htaccess`-Präfixerkennung unterscheidet `/webcam` nun sauber von
  `/webcam2`. Dadurch übernimmt die zweite Installation nicht mehr irrtümlich
  die Rewrite-Basis der ersten Installation.

### Alt-System-Bridge und Admin-Persistenz

- Der eigenständige Bash-/curl-Helper unter `ops/legacy-webcam-helper/`
  simuliert auf einer separaten Linux-Maschine pro systemd-Instanz eine externe
  Kamera. Er pollt das PROD-Command-Protokoll, lädt das konfigurierte
  `/<slug>/current/raw.jpg` vom Alt-System und überträgt es mit dem von PROD
  gelieferten Slot-Zeitstempel.
- Nicht bestätigte Bilder bleiben im persistenten Spool; fehlende Quellbilder,
  ungültige JPEGs und HTTP-Fehler werden sichtbar protokolliert. Schlüssel
  erscheinen nicht in der Prozessliste, alle Netzwerkaufrufe besitzen harte
  Timeouts.
- `SOURCE_DELAY_SECONDS` hält den von PROD vorgegebenen Slot fest, verzögert den
  Abruf von `current/raw.jpg` aber standardmäßig um 210 Sekunden. Damit erhält
  das Alt-System Zeit für seine bis zu drei Minuten spätere Veröffentlichung;
  `Cache-Control: no-cache` verhindert dabei die Übernahme einer gecachten
  Vorgängerversion. Der produktive 00:20-Test speicherte vier neue Bildhashes
  zwischen 00:23:38 und 00:23:53 weiterhin unter dem korrekten 00:20-Slot.
- Die Admin-CSRF-Prüfung berücksichtigt mehrere gleichnamige Cookies aus alten
  Alias-/Pfadständen, ohne die Double-Submit-Prüfung aufzuweichen. Dadurch
  springen Formularwerte nach einem fälschlich abgewiesenen Speichervorgang
  nicht mehr scheinbar auf den alten Stand zurück.
- `capture_rules.upload_key` wird bei Insert und Update gemeinsam mit Endpoint,
  Transport und Intervall persistiert. Regressionstests decken den Endpoint-
  und Key-Roundtrip sowie doppelte CSRF-Pfadcookies ab.

Für Version 6.0.2 ist keine neue Datenbankmigration erforderlich. Die
Originalbilder bleiben durch die neuen Backup- und Retention-Abläufe
unverändert.

## Entwicklungsstufen

| Version | Schwerpunkt |
|---|---|
| 1 | Ursprünglicher Projektstand |
| 2 | Umstieg von FastAPI auf PHP, neues Storage- und SQLite-Fundament |
| 3 | Public-/Admin-API, per-Kamera-Datenbanken, Sicherheit und serverseitige Timeline |
| 4 | Heutiges Speicherlayout, Timelapse, History, Best-Of, OCR, Overlays und Admin-Ausbau |
| 5 | Lokale Live-Datenbanken, DB-first-Archiv, CIFS-Härtung, Wetteransichten und Performance-Refactoring |
| 6 | Streaming/HLS, Viewer-Verwaltung, Embed, Wetterqualität, Runtime-Härtung, interner Scheduler und globales Best-Of |

Diese Stufen wurden rückwirkend aus größeren Architektur- und Funktionsblöcken
gebildet. Sie sind bewusst keine Gleichsetzung „ein Commit = eine
Hauptversion“. Neue technische Änderungen innerhalb des heutigen
Architekturstands erhöhen zunächst die Iteration von Version 6. Eine neue
Hauptversion wird erst bei einem weiteren grundlegenden Architektur- oder
Betriebswechsel festgelegt.
