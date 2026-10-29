# Webcam-Plattform und CamPi

Bereinigter Quellstand vom 29. Oktober 2026.

- **webcam/**: PHP-Webanwendung mit Adminpanel, Uploads, Bildbearbeitung,
  Archiv, Vergleich, Timelapse, Wetter und Streams; JavaScript/CSS und lokale
  Frontend-Bibliotheken, SQL-Schemata/Migrationen und Tests.
- **camera-rpi/**: CamPi-Aufnahme- und Uploadsoftware, gphoto2/V4L2/RTSP,
  Capture-Pool, GPIO/Stromsteuerung, Klima/Sensoren, lokales Adminpanel,
  systemd-Units, Wartung/Tunnel, Installationswerkzeuge und Tests.

Server und Pi sind eigenstaendige Projekte. Ihre Verzeichnisse bleiben hier
nebeneinander, damit vorhandene relative Verweise weiterhin aufloesbar sind.
Der Export enthaelt auch lokale, noch nicht separat committete Quellkorrekturen.
Er ist kein neuer Rollout auf laufende Kameras oder Server.

## Einstieg

Server: [Architektur](webcam/docs/ARCHITEKTUR.md),
[Betriebshandbuch](webcam/docs/BETRIEBSHANDBUCH.md),
[Server-README](webcam/README.md).
CamPi: [README](camera-rpi/README.md), [Installation](camera-rpi/DEPLOYMENT.md),
[Architektur](camera-rpi/ARCHITEKTUR.md).

Fuer den Server werden PHP mit PDO-SQLite/SQLite3, GD, cURL, mbstring und EXIF
sowie je nach Funktion FFmpeg/ImageMagick und weitere externe Werkzeuge benoetigt.
Composer installiert die Entwicklungsabhaengigkeiten, npm die Browser-Testwerkzeuge.

```sh
cd webcam
composer install --no-interaction
npm ci
php bin/init_sample_config.php
php vendor/bin/phpunit
npm run test:e2e:isolated
```

Der Sample-Generator erstellt nur eine neue lokale Datenbank und verweigert das
Ueberschreiben. Er liest keine Live-Konfiguration. Der Upload-Key wird zufaellig
erzeugt; Admin-Zugang und Kameraziele sind selbst einzurichten. Die Sample-DB
ist nicht versioniert. Fuer Browser-Tests gegebenenfalls vorher
`npx playwright install chromium` ausfuehren.

Produktive Installationen brauchen eine eigene, geschuetzte Konfiguration
ueber `WEBCAM_CONFIG_DB`. Keine Beispieladresse und keinen Testschluessel in
Produktion verwenden. CamPi-Installationswerkzeuge aendern systemd, Mounts und
gegebenenfalls GPIO; zuerst Hardware/Pinbelegung und Installationsanleitung pruefen.
Die mitgelieferten Hilfsskripte unter `ops/` sind nicht pauschal auszufuehren.

## Datenschutz und Exportumfang

Siehe [Pruefbericht](PUBLICATION-REVIEW.md). Ausgelassen sind Bilderarchive,
Live-/Sample-Datenbanken, Logs, Sicherungen, Zugangsdaten, die lokale Git-Historie,
historische Betriebsberichte und datierte hostgebundene Rollout-Pakete.
Die aktuellen integrierten Korrekturen bleiben im Anwendungscode enthalten.
`vendor/` und `node_modules/` werden aus Lockdateien installiert.
Frontend-Bibliotheken und deren Lizenzhinweise bleiben enthalten.

Betreiberadressen wurden durch reservierte Beispieladressen ersetzt. Externe
Dokumentations-, Paket-, Lizenz- und benoetigte Wetter-/Kartendienst-URLs sind
bewusst erhalten; die Anwendung kann diese Dienste bei aktivierter Funktion nutzen.
Skripte mit ehemaligen Standortnamen sind Beispiele und benoetigen Anpassung.

Die vorhandene Repository-Lizenz bleibt erhalten. Fuer beigefuegte
Drittanbieterkomponenten gelten deren jeweilige Lizenz- und Urheberhinweise.
