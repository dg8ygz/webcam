# Aufnahmefenster und Helligkeitsprüfung

Option in der **lokalen Kamera-Verwaltung**, geräteweit für alle Zielserver.
Neuinstallation ist unverändert uneingeschränkt: Der Installer legt keine
`/etc/camera-rpi/privacy.json` an. Erst bewusstes Speichern aktiviert Optionen.
PHP/Shell, kein neuer Python-Code. Heizung, Lüfter und DHT bleiben unabhängig.

## Bedienung

- Aufnahmefenster: uneingeschränkt, feste Uhrzeiten oder Sonnenaufgang/-untergang.
- Sonnenaufgang: −30 = 30 Minuten vorher, +30 = 30 Minuten danach beginnen.
- Sonnenuntergang: −30 = 30 Minuten vorher, +30 = 30 Minuten danach beenden.
  Beide Versätze unabhängig, ganze Minuten zwischen −720 und +720; 0 = Ereignis.
- Standortquelle: vorhandenen Zielserver auswählen. Dessen hinterlegte
  `location.latitude`/`longitude` und Server-Zeitzone kommen beim authentifizierten
  Command-Poll über `X-Camera-Position` (Base64-JSON, keine ausführbaren Befehle).
  Kein IP-Geolocation, kein Beispielstandort, kein zusätzlicher Abrufdienst.
  Koordinaten auf dem **Webcam-Server** bearbeiten, nicht nochmals auf dem Pi.
- Fehlender/ungültiger Standort verhindert die Aktivierung von Sonnenzeiten.
  Der Admin zeigt den Hinweis sowie bei gültiger Konfiguration die heutigen
  tatsächlichen Freigabezeiten mit Datum/Zeitzone. Feste Uhrzeiten benötigen
  keinen Standort und verwenden die separat angegebene IANA-Zeitzone.
- Standortcache liegt in `/run/camera-rpi-privacy/position-<ziel>.json`, maximal
  24h gültig. Nur beim bewussten Speichern wird ein Snapshot in die Konfiguration
  übernommen. Er überbrückt Reboot/kurze Pollpause, verfällt ebenfalls nach 24h.
  Ein neuer fehlender Standort überschreibt die Gültigkeit des alten Snapshots.
  URL/Slug-Wechsel invalidieren alte Daten durch eine gebundene Identitäts-Hashsumme.
  Kein automatischer SD-Schreibzugriff durch Positionsaktualisierungen.

Die Grenzen sind `[Beginn, Ende)`: exakt am Ende ist geschlossen. Feste
Nachtfenster (22:00–06:00), Zeitzonen und Sommerzeit verwenden lokale Kalendertage.
Versätze dürfen über Mitternacht reichen. Ein umgekehrtes Sonnenfenster oder
Polartag/-nacht ohne numerisches Auf-/Untergangsereignis bleibt geschlossen,
statt unbemerkt 24h freizugeben. Die Berechnung verwendet
[PHP date_sun_info](https://www.php.net/manual/en/function.date-sun-info.php),
nicht bürgerliche Dämmerung. Sichtbarer Horizont/Berge/Gebäude werden nicht modelliert.

## Dunkle Fotos

Optionaler Filter, Minimum 0–100 %, zusätzliche Hysterese 0–100 Prozentpunkte
(Summe höchstens 100). Nach einem verworfenen dunklen Bild ist für erneute
Freigabe Minimum + Hysterese erforderlich; 0 deaktiviert die Hysterese.
Letzter Messwert, Zeit, tatsächlich verwendete Schwelle und Entscheidung stehen
im Admin. Kein Abfragen zusätzlicher Fotos beim Seitenaufruf.

Messregion in ganzen Bildprozenten: links/oben/Breite/Höhe. Standard ganzes Bild.
Die Region wird im RAM auf 64×64 verkleinert; Mittelwert der gewichteten
RGB-Helligkeit (0,2126 R + 0,7152 G + 0,0722 B), normiert auf 0–100.
Das ist **keine Lux-Messung**, keine Erkennung von Personen oder beleuchteten
Wohnungen. Messregionen maskieren nichts; Belichtungsautomatik beeinflusst den Wert.
Die Zeitsperre hat stets Vorrang: helle Fenster dürfen sie niemals aufheben.
Der Filter gilt für Fotos, nicht als frameweiser Videofilter. Video wird über
das Zeitfenster gesperrt; für Wohnfenster daher unbedingt dieses verwenden.

PHP-GD ist erforderlich. Vor vollständiger JPEG-Dekodierung: JPEG-Typ,
höchstens 8192 Pixel je Achse/40 Megapixel/25 MiB prüfen. Der begrenzte Prüfer
hat 256 MiB PHP-Speicher und 20 Sekunden Laufzeit. Fehler = nicht veröffentlichen.

## Datenweg und Sperren

`cr_capture_to_file` kapselt beide bestehenden Hardwarepfade:

1. Zeitfenster prüfen, bevor USB/GPIO/Capture angefasst werden.
2. Aufnahme in privates tmpfs-Staging `/run/camera-rpi-privacy/capture.*/image.jpg`.
3. Ende des Zeitfensters nochmals prüfen, dann Helligkeit auswerten.
4. Nur freigegebene Bytes in den bisherigen Upload-/Poolpfad übergeben.

Eine über die Schließzeit hinauslaufende Aufnahme wird verworfen. Ein bereits
begonnener Verschlussvorgang wird nicht gewaltsam abgebrochen. Die vorhandene
Canon-Sequenz einschließlich ihres internen Kamera-Speicherverhaltens (`--keep`)
bleibt unverändert; der Filter verhindert Speicherung/Veröffentlichung auf dem
Pi und den Zielservern, ist aber kein Löschversprechen für internen DSLR-Speicher.

Exit 3 bedeutet absichtlich ausgelassen: Auftrag konsumieren, keine technische
Retry-Schleife. Bei gemeinsamer Aufnahme wird auch die Poolgeneration abgeschlossen,
damit wartende Peers nicht für denselben Vorgang nochmals auslösen. Kein JPEG
in Warteschlange, Preview, Klima-/Sensordatenbackup oder Reboot-Spoolsicherung.
Nur skalare letzte Messung und begrenzter Entscheidungstext bleiben im RAM.
Bei hartem Prozessabbruch kann privates RAM-Staging bis Reboot liegenbleiben;
es wird nicht durch den Admin ausgeliefert. Keine Löschung früherer Archivbilder.

Bereits freigegebene Tagesbilder behalten Zeitstempel und dürfen auch nachts
hochgeladen werden. Aktivierung verändert keine vorhandenen Warteschlangen.
Der aktuelle gemeinsame Aufnahme-/Admin-Lock verhindert Konfigurationswechsel
mitten in einer Worker-Aufnahme. Ein direkter CLI-Aufruf soll ebenfalls diesen
Lock verwenden; die normalen Worker-/Admin-Pfade tun dies bereits.

Der USB-Videodienst und sein privater HTTP-Origin prüfen dasselbe Zeitfenster.
Auch laufende Streams werden bei Datenschutz-Schließzeit beendet (anders als
die xx:x9-Regel, die nur neue Starts betrifft). Der neue Server sperrt auch
zwischengespeicherte Segmente anhand einer authentifizierten Frist; bereits zum
Browser übertragene Puffer können noch kurz auslaufen und nicht zurückgerufen werden.

## Watchdog / Server-Kompatibilität

Der aktualisierte Server übermittelt Standorte ohne Änderung der Drei-Zeilen-
Commandantwort. Neue Pi-Helper melden geplante Pausen mit `X-Camera-Privacy`.
Ein frischer Report unterdrückt automatische Resets, aber **maximal 900s ohne
neuen Poll**. Ein verschwundener Pi darf nicht dauerhaft seinen Ausfall verstecken.
Video-Reports verfallen nach 35s: Bei zuvor aktivem Datenschutz wird dann gesperrt.
Reports liegen pro Installation getrennt im RAM, nicht in der Config-/Kamera-DB.

**Der alte Legacy-Server kennt diese Meldungen nicht!** Vor Aktivierung seines
Aufnahmefensters dessen „kein Bild → Stromreset“-Watchdog separat anpassen.
Diese Software verändert weder dessen Watchdog noch Standort/Live-DB-Einträge.
HTTP-Ziele übernehmen Metadaten über denselben LAN-Vertrauensweg wie bisherige
Commands; für Schutz gegen Manipulation auf dem Transport ist HTTPS erforderlich.

## Installation und Rückweg

Paket mit SHA256SUMS und PRIVACY-BASELINES prüfen. Das Update verweigert
unbekannte lokal geänderte Programme. Zunächst `bash ops/update-privacy.sh --check`.
Voraussetzung PHP-GD (separat als Betreiber installieren, kein automatisches APT).
Nach abgeschlossener Aufnahme alle Foto-Worker und ggf. Videodienst stoppen;
Sensor, Klima und DHT-Langtest **nicht** stoppen. Dann:

```sh
bash ops/update-privacy.sh --apply
```

Die vorher gestoppten Worker/Video anschließend wieder starten. Vor Speichern
im Admin einen frischen Standort-Poll abwarten. Die neue lokale Option bleibt
bis zum Speichern AUS. Installation löst kein Testbild aus.

Sicherung: `/var/backups/camera-rpi-privacy.<kennung>/files/<index>` und
`targets` (eine feste Zielzeile pro Index). Zusätzlich alte Inodes als Hardlinks,
damit RO-Remount nicht durch offene ersetzte Programme blockiert wird.
Bei Installationsfehlern werden die gesicherten Programme automatisch zurückgestellt.
Für bewussten Rückbau zuerst Fenster **uneingeschränkt** und Helligkeitsfilter
**aus** speichern, alle Worker/Video stoppen und die aufgeführten Programmdateien
aus dieser Sicherung wiederherstellen. Neu hinzugekommene Dateien mit
`files/<index>.absent` können in die Sicherung verschoben werden. Keine Bilder,
Keys, Zielkonfigurationen oder privacy.json löschen. Nicht auf alte Programme
zurückrollen und dabei behaupten, dass eine aktive Datenschutzoption weiterwirkt.

DEV-Server: Metadaten-/Report-Unterstützung in Iteration198. Das vorhandene
USB-RAM-Bootprogramm erzeugt zusätzlich den privaten `camera-privacy`-Ordner
im installationsgetrennten `/run/webcam-usb-stream/<hash>/`-Namespace, Eigentümer
www-data/0700. Für Installationen ohne dieses Bootprogramm muss der gleiche
RAM-Ordner durch eine passende Root-Boot-/tmpfiles-Regel vorbereitet werden.
Kein PROD-Rollout und keine Kamera-Aktivierung durch diese Codeänderung.

## Prüfung

- `php tests/capture-privacy.php`: Solargrenzen, beide Vorzeichen, DST,
  Polartag/-nacht, fehlende/abgelaufene/ausgetauschte Standortquelle, CAS,
  echte JPEG-Pixel, Region und Hysterese.
- `bash tests/capture-privacy-worker`: echte Worker-/Pool-/Guardpfade,
  synthetische Kamera/HTTP; geschlossene Zeit ohne Hardwareaufruf, Dunkelbild
  ohne Upload/Preview, helles Bild genau einmal für beide Ziele.
- `node tests/privacy-ui.cjs`: reale PHP-Seite/Root-Brücke im isolierten
  Testmodus, Desktop/Mobil, Standorthinweis, Plus-/Minuswerte, Speichern, CSRF.
- Webcam-Server: `CameraPositionHeaderTest`, `CameraPrivacyStateTest`,
  `ResetServiceTest` und USB-Stream-Verträge. Keine echten Resets aus Tests.

Reale Nacht-/Dämmerungsabnahme und Wahl einer brauchbaren Helligkeitsschwelle
stehen nach bewusstem Testcam-Rollout und Aktivierung noch aus.
