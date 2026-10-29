# Reproduzierbare Tests

`python3 -B tests/system-maintenance.py` prueft das gemeinsame RO/RW-
Wartungsfenster mit echten Sperren/Prozessen und isolierten Mountattrappen:
Teilfehler, fremdes RW, Remountfehler/Recovery, SIGTERM, SIGKILL und
separates Restore, Boot-Bindalias und bestehenden RAM-Logrotate-Pfad.
Keine echten Mounts, Paketjobs, Kamera-/Sensoraktionen.

`bash tests/sensor-capture-resume` prueft den echten Sensordienst mit
privaten Messdateien und echtem `flock`: keine Abfrage/Publikation bei
belegter Kamerasperre, Nachholen binnen zwei Sekunden nach Freigabe,
danach normales Messintervall. Wiederholte Aufnahmesperre erhaelt alte
Zeitstempel und Historie; Lock-Proben zaehlen nicht als Messungen.
`--once` bleibt bei belegter Sperre sofort beendet. Keine Hardware/Dienste.

`bash tests/admin-autostart` prüft die echte systemd-Aktivierungsverzweigung
mit isolierten Testdoubles: persistentes enabled statt nur laufendem Worker,
Fehlerweitergabe beim Aktivieren/Deaktivieren/Neustarten sowie freien Lock
nach Fehlschlägen. Keine tatsächlichen Dienste oder Root-Remounts.

`bash tests/admin-identifiers` prüft die Root-Brücke mit gültigen/ungültigen
Kennungen und den Längengrenzen 48/64. `node tests/admin-identifiers.cjs`
prüft echte PHP-Formulare in Chromium: native Patternprüfung, verhinderter
POST, absichtlich umgangene Browserprüfung und feldbezogene PHP-Fehler.
Nur Fixture-Konfigurationen/Keys, keine Live-Speicherung oder Aktivierung.

`node tests/focus-ui.cjs` prüft genau einen kamerabezogenen Fokusbereich auch
bei mehreren Zielservern. Die gemeinsame Quelle wird serverseitig gewählt;
manipulierte Ziel-IDs können sie nicht ändern. Fehlende/eindeutig nicht
bestimmbare Quelle, V4L2 und ungültiges CSRF bleiben gesperrt. Alle Bewegungen
gehen ausschliesslich an eine Attrappe, nie an gphoto2 oder GPIO.

`php tests/dht-frame.php` baut den tatsaechlichen DHT-C-Decoder mit kleinen
Kernel-Stubs und gemeinsamen Flankenpruefungen. Sechs reale erfolgreiche
Aufzeichnungen, synthetische Stoerungen, fehlende Flanken, falsche Pruefsummen,
Praeambel-/Pulsgrenzen und Cache-Zeitstempel bei Fehlern. Keine GPIO-/Modul-
oder Dienstaktion; Hardware-Jitter und Fehlerquote bleiben Live-Abnahmen.

`bash tests/capture-pool` prueft echte parallele Worker ohne Hardware:
ein JPEG fuer alle Ziele, unabhaengige Legacy-/Chunk-ACKs, neue Generationen
trotz langsamer Clients, Wetter-/Zeitbindung, zehn wartende Bilder und
Lesesperren gegen Loeschen waehrend eines Uploads.

`bash tests/steinhagen-cutover` prueft den separaten .17-Umschaltweg:
Konfigurations-/Statuspruefungen, dateibasierte Sicherung/Rueckstellung,
Erhalt wartender Bilder sowie Abbruch vor Unmount bei Sicherungsfehlern.
Keine echten Dienste, Aufnahmen oder GPIO-Zugriffe; Live-Abnahme separat.

`bash tests/bielefeld-cutover` prueft den gezielten .16-Umschaltweg mit
56 Dateisystem-/Konfigurations-/Statuspruefungen: Original- und neue wartende
Bilder, Keys, RRD-Restore nach geleertem RAM, Dateirechte, atomarer Rueckweg,
Abbruch vor Unmount bei Stop-/Sicherungsfehler, frische Messwerte und echte
GPIO-Statuskennzeichnung. Keine realen GPIO-, Dienst- oder Mountaktionen.
Die physische Regelwirkung und der Bootablauf bleiben Live-Abnahmen.
Erwartetes `is-enabled` mit Exit 1 und noch fehlender IIO-Bus duerfen keine
ERR-Abbruchmeldung erzeugen; echte Enumerationsfehler bleiben Fehler.

`bash tests/legacy-target-config` prueft den generischen inaktiven Import von
Kameraname, Include-Adresse und Key: literale Werte, CRLF, vierstellige
Legacy-Keys, Ablehnung von Expansion/Duplikaten und korrekte deaktivierte
Worker-Konfiguration. Keine echte Quelle, Netzwerk- oder Hardwareaktion.

`bash tests/bielefeld-legacy-config` prueft den reinen Legacy-Key-Import mit
Testtokens: Kommentare, Quotes, CRLF, doppelte/fehlende Zuweisungen und
abgewiesene Shell-Ausdruecke. Der gemeinsame Worker-Parser bestaetigt das
deaktivierte Ziel `bielefeld-legacy`, Slug `bielefeld`, Legacy/700s und korrekte
Endpoint-Pfade ohne doppeltes `/include`. Kein echter Key und keine Dienste.

Klima-Erweiterung: `php tests/climate.php` aus dem Projektroot prueft
reine Regelung (auch 48h Heizbedarf und warmer Sommer ohne Verriegelung),
Anlaufboost, Alt-Konfigurationsmigration, Admin/CAS/Sicherung und echte
Supervisor-/Capture-Locks gegen temporaere Dateien. Im Hauptlauf enthalten.
`php tests/climate-capture-race.php` erzwingt einen atomaren Austausch der
Pausenanfrage zwischen lstat und open: frische Anfrage wird erneut gelesen,
ungueltige/abgelaufene Anfragen und Symlinks bleiben abgesichert. Elf Checks,
keine GPIO-Aktion; optional installierte PHP-Quelldatei als Argument.
`php tests/climate-sensor-grace.php` prueft kurze Lesefehler mit gehaltenem
PWM-Sollwert/Heizung aus, Messwertablauf, Puffererhalt/-neubau, beide Uhren,
Capture-Pausen und Anlaufboost; simulierte Zeiten, kein GPIO/IIO. Ebenfalls
im Hauptlauf enthalten; der Supervisor-Test prueft die Statusintegration.
Seit 19.09. pruefen beide Tests ausserdem Aufnahmefreigaben ohne Sensorwerte
trotz eines alten Capture-Timeouts. `climate.php` startet den echten
Shell-Capturehelper gegen eine Kameraattrappe, prueft Lease-Ablauf und den
Auto-Modus mit einem harmlosen Ausgangsprozess: bestaetigtes OFF erlaubt
die Aufnahme, verweigertes OFF verhindert sie. Der Klimafehler bleibt erhalten.
`bash tests/climate-native` prueft den nativen PWM-Helfer nur mit kompilierten
Testausgaengen: Pipe/ACK, 100-Hz-Schleife, EOF/TERM und unabhaengiger Watchdog.
Der Wrapper baut ausserdem das Kamerastrom-Testbinary und ruft `power.php`
mit Binary-/Stagingargument auf: USB-Erkennung, Socket-ACK und Operationslock.
`php tests/power.php` ohne diese Argumente ist kein gueltiger Testaufruf.
`node tests/climate-ui.cjs` prueft die echte PHP-/Admin-Bruecke im Testmodus
einschliesslich sichtbarer Fehler, frischem/veraltetem Status und
CSRF-geschuetzter Quittierung ohne Konfigurationsaenderung
(390/1280 px, Save/Reload, CSRF, CAS, entfernte Grenzfelder und Automatik ohne
zusaetzliche Checkbox). Ohne Fehler bleibt der Quittierbutton verborgen. Benoetigt das vorhandene
Playwright-Modul; keine reale GPIO-, Sensor-, Mount- oder Dienstaenderung.
`node tests/status-refresh-ui.cjs` prueft mit Browser-Uhr die echte
60-Sekunden-Hintergrundaktualisierung: keine Navigation/unnötigen Bilddownloads,
dekodierter Austausch, langsamer/fehlgeschlagener Download, entfernte Vorschau,
erneute Hover-Bindung, Erscheinen/Verschwinden der Stoerungsanzeige und
HTTP-Fehler. Nur lokale RAM-Fixtures; keine Hardware.

`node tests/status-climate-ui.cjs` prueft die neue aktuelle Regelwert-Tabelle
unter der vorhandenen Verlaufsgrafik, PWM/Anlaufimpuls statt RPM, An/Aus,
Simulation und fehlende/veraltete/ungueltige Werte auf 390/1280 px.
Zusaetzlich: zweite Vier-Serien-Grafik zwischen Sensorverlauf und beibehaltener
Tabelle, PWM-/Heizungsstufen, Luecken, Simulationslinien und fehlender Verlauf.
Die vier Legendentext-/Markierungs-/Linienfarben muessen uebereinstimmen und
untereinander verschieden sein; Stylesheet-URL enthaelt den aktuellen CSS-Hash.
Drei redundante Geraetekacheln fehlen, Bild-Overlay und Tabelle bleiben erhalten.
`php tests/climate-history.php` prueft den begrenzten RAM-Verlauf, 30-s-/Ereignis-
Aufzeichnung, Neustart/Uhrkorrektur, echte Zeitachse, Luecken, 48h-/Zeilen-/
Dateigroessenlimits und misst einen voll belegten Verlauf. Im Hauptlauf enthalten.
`php tests/dht-restore-timer.php` prueft feste monotone Frist, Reload-Invarianz,
Ablehnung falscher/abgelaufener Tests und D-Bus-Soll/Ist-Verifikation ohne
Systemdienst-/GPIO-Aktionen. Der aktive Pi-Termin wird vor Installation mit
`php ops/fix-dht-restore-timer.php --check 2niRB78n` ausschliesslich gelesen.

`php tests/climate-archive.php` prueft taegliche PHP-Sicherung/Boot-Restore,
Berlin-Tageswechsel, 30 eigene Archive, alte Sensor-only-Sicherungen, Dateirechte,
Groessen-/Altersgrenzen, aktive Reglersperre und den Vorrang vorhandener RAM-Daten.
Echte Shellwrapper laufen nur gegen private Mount-/Archiv-Stubs: beide Archive
werden bei Fehlern versucht, RO wird wiederhergestellt, keine Stellwerte geladen.

`./run-tests` verwendet keine echte Kamera, kein Netzwerk und keine echten
Keys. Es baut unter `/tmp` eine abgeschlossene Testwelt auf und entfernt sie
danach wieder.

Der Ablauf ist bewusst nah am späteren Betrieb:

1. Die Admin-Brücke legt drei Server und drei getrennte Key-Dateien an.
   `admin-key-reuse` prueft zusaetzlich identische Keywerte fuer mehrere Ziele,
   getrennte Inodes/Rechte, unveraendertes Beibehalten und gezieltes Ersetzen.
   Verwaiste fremde Key-Dateien blockieren kein Speichern; Formatpruefung und
   Pflichtschluessel fuer neue Ziele bleiben erhalten.
2. Identische Keywerte sind erlaubt; die Dateien bleiben je Ziel getrennt.
   `admin-key-reuse` prueft diesen Vertrag ohne fremde Keys auszulesen.
3. Die Statusliste wird darauf geprüft, dass kein Key erscheint.
4. Ein lokaler PHP-Testserver prüft Schutzheader, HTML-Escaping, Sitzung,
   CSRF-Token und einen echten Formular-POST gegen eine harmlose Testbrücke.
5. Eine eingeschleuste unbekannte Konfigurationszeile muss abgelehnt werden.
6. Drei Worker werden gleichzeitig gestartet.
7. `fixtures/fake-gphoto2` erzeugt Test-JPEGs und erkennt parallelen Zugriff.
8. `fixtures/fake-curl` prüft pro URL den richtigen Key, bestätigt für Server
   eins Chunk-v1 und simuliert für Server zwei einen alten Endpunkt.
9. Der Test belegt Chunk-Übertragung, automatischen Legacy-Fallback und einen
   fest konfigurierten Legacy-Server und zählt weiterhin drei Uploads.
10. Curl-Argumentlisten werden auf alle drei geheimen Testwerte geprüft.
11. Nach bestätigten Uploads muss der Spool leer sein; ein abgelehnter Upload
    muss sein fertiges JPEG dagegen behalten.
12. Die echte `wait/0/wait`-Antwort darf kein Foto auslösen.
13. Während eines absichtlich langsamen Uploads muss ein anderer Server die
    freie Kamera benutzen können.
14. Ein GPhoto-Fehler muss den Request behalten und alle Sperren für den
    erfolgreichen Folgelauf freigeben.
15. Simulierte DHT22-Dateien müssen korrekt in Temperatur und Luftfeuchte
    umgerechnet werden; Heizung, PWM-Prozent und echte Tachodrehzahl bleiben
    getrennte Aussagen.
16. Jeder Capture muss das jüngste Bild samt tatsächlicher Aufnahmezeit im RAM
    veröffentlichen. Die lokale PHP-Seite muss Bild, Zustände und SVG-Verlauf
    sicher ausliefern.

Start im Entwicklungsverzeichnis:

```sh
sudo ./tests/run-tests
```

Zusätzlich vor einem Rollout ausführen:

```sh
bash -n deploy-to-camera-rpi.sh install.sh scripts/* scripts/admin/* scripts/lib/* tests/run-tests tests/fixtures/*
php -l admin/src/bootstrap.php
php -l admin/public/index.php
php -l status/public/index.php
sudo ./tests/run-tests
```

ShellCheck soll genutzt werden, sobald das Paket auf dem Entwicklungsrechner
installiert ist. Der echte Kamera-, Server- und ro/rw-Test bleibt ein eigener,
ausdrücklich freizugebender Rollout-Schritt.

## Trixie-Nachpruefung

`bash tests/sensor-read-errors` prueft einen echten EIO-Lesefehler und die
anschliessende Erholung im selben Sensor-Prozess. `bash tests/sensor-retry-sequence`
prueft mit kontrollierten Lesern, dass nach einem Temperaturfehler keine
Feuchteabfrage erfolgt, Wiederholungen drei Sekunden auseinanderliegen und
nach maximal vier Versuchen keine unvollstaendigen Werte veroeffentlicht werden.
Der vierte Versuch und `ok_fourth` wurden am 15.09.2026 ergaenzt (`282f85b`);
historische Dreiversuchs-Messreihen behalten ihre damalige Aussage.
Beide Tests verwenden keine Sensorhardware.

`bash tests/install-preflight` prueft die neue Paket-Simulation und die
Ablehnung schreibgeschuetzter Mounts mit APT-/findmnt-Stubs. Keine Installation
oder echten Remounts. Fuer den Installer-Test root verwenden; ohne root wird
dieser Teil ausdruecklich uebersprungen. RW-/RO-Healthcheck-Erwartungen werden
ebenfalls isoliert geprueft. Der Gesamtlauf `bash tests/run-tests` bindet
diesen Test ein. Hardware-/ARM-/systemd-Abnahme bleibt separat.
# Fokus

`bash tests/focus` prueft die fuenf Argumentlisten, authentifizierten
Protokollparser, V4L2-Ablehnung, Scope, Replay, Fehler und Timeout ohne Kamera.
Jeder Fake-gphoto2-Aufruf muss die gemeinsame Capture-Sperre nachweisen.
`bash tests/focus-capabilities` prueft den echten Fokus-Helfer mit simulierten
Kameraeigenschaften (PC/Off, viewfinder, eosviewfinder, verschobene Indizes),
Abbruch vor Schreibbefehlen bei unklaren Eigenschaften und Cleanup nach
Fehler/Timeout. Kein unangeforderter Autofokus oder echter Kamerazugriff;
beide Shelltests sind im Hauptlauf enthalten.
`tests/focus-ui.cjs` startet eine isolierte PHP-Admin-Seite mit harmloser
Testbruecke und prueft mit Playwright alle Buttons, CSRF und 390/1280 Pixel.
Aufruf mit `NODE_PATH=/tmp/pw-node-modules PLAYWRIGHT_BROWSERS_PATH=/tmp/pw-browsers node tests/focus-ui.cjs`.

# Low-write / Sensor-Sicherungen

`python3 -B tests/test-sensor-archive.py` prueft atomare Sicherung und Restore,
Berliner Tagesgrenzen, einen Schreibvorgang pro Tag, 30-Dateien-Retention,
fehlende/defekte Daten, unvollstaendige Zeilen, Groessenlimit, Symlinks und
Erhalt der vorherigen Datei bei fehlgeschlagenem Austausch.
`bash tests/low-write-worker` prueft ohne echte Mounts, dass nur bei zwei
tmpfs-Schreibpfaden das RO/RW-Schreibfenster entfaellt.
Die Installation der Mounts und der Reboot sind separate Hardware-Abnahmen.
# Reversible Bestandskamera-Vorbereitung

`bash tests/legacy-isoexp` prueft absolute EV-Werte0..5 ueber
--set-config-value (seit06.10.; alte Index14/17/...-Tabelle war auf EOS2000D
um -1/3EV versetzt), ISO=0 ohne ISO-Aenderung, optionale Stufe und No-op.
Echte lokale Kamerasperre mit simulierter Gegenstelle, Replay-Schutz,
ungueltige/veraltete Commands, USB-Fehler und Prozess-Timeout werden geprueft.
`capture-backends` prueft zusaetzlich den kompletten Worker-Poll und einen
zweiten Worker-Prozess ohne Wiederholung des ISO-Auftrags. Kein echtes USB.

`bash tests/legacy-capture-stamp` prueft den originalen Uploadvertrag
`now=$1`: Kalenderargument getrennt von Epoch-Auftragsnummer, Erhalt bei
Kamerasperre/Spool/Retry, keine Zeitzonenumrechnung, Metadatenpruefung und
Entfernung erst mit Bildbestaetigung. `capture-backends` startet fuer den Retry
einen neuen Worker-Prozess und prueft das tatsaechlich gesendete `now`-Formfeld.

`bash tests/legacy-command-frames` reproduziert den vom Betreiber gelieferten
Command-Vertrag inklusive doppeltem Schluss-LF: Capture mit Server-Epoch,
CRLF, bekannte ISO-Auftraege ausserhalb des EOS-Legacy-Profils, strikter Fokusvertrag,
Abweisung zusaetzlicher Befehle und Steuerzeichen. `capture-backends` prueft
im echten Worker mit simulierten Gegenstellen, dass die ISO-Konfiguration weder
Aufnahme noch Upload ausloest und die naechste Capture-Anfrage funktioniert.

`bash tests/legacy-upload-ack` prueft HTTP/Transport, bekannte ISO-Rueckmeldung,
JSON/Leerantwort sowie Abweisung von Fehlertext, Shell-Anhaengseln, Binaerdaten
und Mehrzeilern. Die Daten werden nicht ausgefuehrt. `capture-backends` prueft
zusaetzlich Spool-Erhalt bei unbekannter Antwort und spaetere Bestaetigung ohne
neue Aufnahme oder Verlust der Vorschau. Keine Hardware oder Netzwerkzugriffe.

`bash tests/eos-workdir-update` prueft den gezielten Nachbesserungsweg bei bereits
aktiver Regelung: Worker muss inactive/disabled bleiben, atomare Dateien und
Rollback, Rechte sowie unveraenderte Konfiguration/wartende Bilder. Nur Fixtures.
`eos-legacy` und `capture-backends` starten die EOS-Aufnahme auch aus `/proc`:
der simulierte gphoto-Download braucht wie 2.5.28 ein beschreibbares CWD, selbst
bei absolutem `.jpg.part`-Ziel. Fehlerdiagnosen bleiben privat/begrenzt im RAM.

`bash tests/eos-legacy` prueft die echte neue Helfer-/Hook-Prozesskette mit einem
Fake-gphoto2: benanntes Release Half/5s/Immediate/Release Full/Tethered80s,
Freigabe vor dem Download, Fehler beim Ausloesen/Freigeben, 2s Download-Hook, eigener PID,
Release nach Erfolg/Fehler/Timeout, keine Erfolgsmeldung ohne JPEG/Marker,
unveraenderte Altbilder und fehlendes raw.jpg nach Reboot. Keine Hardware.
`bash tests/eos-update` prueft Backup/atomare Dateiuebernahme/Rueckbau,
Dateirechte, Quarantaene statt Loeschen, Symlink-Abbruch und Erhalt von
Keys/Bildern/anderen Konfigurationswerten. `capture-backends` deckt auch
Profil-Default, Validierung und Erhalt beim Speichern alter Admin-Clients ab.

`bash tests/legacy-install` prueft das additive Installationsmanifest, sichere
Pfadgrenzen, Abbruch bei Altdateien/Symlinks, inaktive Defaults sowie Rueckbau
mit erst nach Installation entstandenen Keys, wartenden Bildern und RAM-/SD-
Verlaeufen. Sicherungsfehler duerfen keine Dateien verschieben. Tests verwenden
nur private Dateien, keine echten Dienste, GPIO, Kamera oder Remote-Hosts.
Dies ersetzt keine Abnahme der EOS-/Sensor-/Aktor-Umschaltung auf einem Pi.
# GPIO allocation

`php tests/gpio-config.php` prüft die vier Rollen, BCM-/Header-Zuordnung und
alle paarweisen Pin-Konflikte. `php tests/gpio-store.php` prüft echtes Speichern,
Revisionsschutz und die Trennung zwischen Wunschbelegung und Boot-Snapshot.
`php tests/gpio-board.php` prüft die offiziellen Gerätebaum-Kennungen,
26-Pin-Rev1/Rev2, 40-Pin-Modelle und Sperren für RP1/Carrier/unbekannte Boards.
Das UI-Testprogramm wechselt ebenfalls die Gerätebaum-Fixture und prüft
Headerfilter sowie Ablehnung eines nicht vorhandenen GPIO trotz direktem POST.
`node tests/gpio-ui.cjs` prüft vier Dropdowns, Ausblenden belegter Pins,
serverseitige Duplikatsperre bei manipuliertem POST, CSRF, CAS und Mobilansicht.
`bash tests/climate-native` prüft auch explizite Pinargumente. Keine dieser
Prüfungen schaltet Hardware; Kaltstart und tatsächliches Umverdrahten bleiben
gesonderte Hardwareabnahmen.

## RO-sichere Updates

`bash tests/update-inodes` testet Rename/Unlink mit echten offenen
Dateideskriptoren und erhaltenen Hardlinks, einschließlich Rückweg. Der
optionale Root-Test `bash tests/update-inodes --ext4` reproduziert den
RO-Remount-Fehler auf einem eigenen temporären ext4-Image und bestätigt die
Korrektur. Er verändert keine Host-Mounts oder laufenden Dienste. Der
Gesamtlauf verwendet nur den unprivilegierten Dateitest.

`php tests/reboot-data.php` prüft die separate Reboot-Sicherung mit echten
Dateien: mehrfach täglich neue Verläufe, Pool-Hardlinks für zwei Ziele,
Upload-Zeitstempel, leere Folge-Queue, keine alten Steuerbefehle, Prüfsummen,
Symlink-/Pfad-/Größenabwehr und zwei Generationen Aufbewahrung. Kein Reboot
oder Zugriff auf laufende Kamera-Dienste. Reale Bootabnahme separat.
# Aufnahmeschutz

`php tests/capture-privacy.php`, `bash tests/capture-privacy-worker` und
`node tests/privacy-ui.cjs` prüfen die Zeit-/Standort-/JPEG-/Admin-Grenzen isoliert.
Keine echte Kamera oder Live-Konfiguration; siehe [CAPTURE-PRIVACY.md](../CAPTURE-PRIVACY.md).
# DHT counter diagnostics

`php tests/dht-counters.php` compiles the actual driver decoder/read_raw against
fake GPIO/IRQ calls: no live module or device. It checks exclusive final error
buckets, offset fallback, cache hits and successful 82-edge deadline reads.
`bash tests/sensor-retry-sequence` also checks process-local retry counters.
The webpage now refreshes every 60s independently of the sensor interval.

## Gemeinsame USB-/RTSP-Quelle

`php tests/camera-source.php` prüft Parser, Dateirechte, URL-/stdin-Grenzen,
USB-Auswahl und echten FFmpeg-Verbindungsfehler. `php tests/camera-source-timeout.php`
prüft die echte 30-Sekunden-Prozessgrenze samt Teildatei-Cleanup.
`bash tests/camera-source-apply` prüft den koordinierten Dienstwechsel isoliert.
`bash tests/capture-backends` enthält RTSP-Aufnahme/Upload/Fehler-Retry und
Schutz der ursprünglichen USB-Konfiguration bei Admin-Speichern.
`SOURCE_TEST_RTSP=1 bash tests/capture-pool` wiederholt die Pool-/ACK-/
Nebenläufigkeitsprüfung durch denselben RTSP-Aufnahmepfad mit simuliertem FFmpeg.
Reale Kamera und HLS-Wiedergabe bleiben gesonderte Live-Abnahmen.

`python3 -B tests/test-source-repair.py` prueft als root ausschliesslich
isolierte Dateien unter /tmp und simulierte Servicebefehle: Wiederherstellung
leerer Quellenhelfer/Config, echte Worker-Konfigurationspruefung und Rollback
bei ungueltigem Zielprofil. Kein Zugriff auf laufende Dienste/Kameras.

## Admin-Auswahl der gemeinsamen Aufnahmequelle

`python3 -B tests/test-source-admin.py` prüft als root nur temporäre Dateien
und simulierte Dienste: ungültige Quellen ohne Seiteneffekt, privater atomarer
Profiltausch, koordinierte Dienste, Rückstellung nach teilweisem Startfehler,
Entfernen eines zuvor nicht vorhandenen Profils sowie gphoto2-Sequenzübernahme.
`node tests/privacy-ui.cjs` prüft zusätzlich auf Desktop/Mobil alle drei
Quelltypen, bedingte Felder, reale PHP-/Bridge-Speicherung, Geräte-Dropdown mit
isoliertem Verzeichnis, Freitext, Erhalt anderer Quellenwerte und CSRF.
