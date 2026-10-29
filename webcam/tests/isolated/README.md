# Isolierte allgemeine Browser-Abnahme

Ab DEV-Iteration 188 ist dies der kanonische E2E-Lauf. Er braucht weder
die Adresse von DEV/PROD noch Kameras, Keys, eine Beispieldatenbank oder
vorhandene Aufnahmen. Die Testkameras tragen bekannte Slugs, sind aber
vollstaendig neu erzeugte Fixtures, keine Kopien der Live-Konfiguration.

## Start

Voraussetzungen: Linux, Git-Checkout, Node >=20, PHP 8.4 mit PDO_SQLite,
SQLite3, GD, mbstring, curl und EXIF. Node-/Browser-Versionen kommen aus
`package-lock.json` und dem dazu passenden Playwright-Download.

```sh
npm ci
npx playwright install --with-deps chromium
npm run test:e2e:isolated
```

Auf `.34` liegt der bereits installierte Browser-Runtime separat:

```sh
PLAYWRIGHT_NODE_MODULES_PATH=/opt/webcam-playwright/node_modules \
PLAYWRIGHT_BROWSERS_PATH=/opt/webcam-playwright/browsers \
npm run test:e2e:isolated
```

Einzelne Faelle lassen sich mit Playwright-Argumenten auswaehlen:
`npm run test:e2e:isolated -- --project=desktop tests/isolated/core.test.js`.
`npm run test:all` fuehrt danach zusaetzlich die bestehende PHPUnit-Suite
aus (vorher `composer install`). Deren eigener Test-Bootstrap ist unveraendert;
die hier beschriebene private Webroot-Isolation gilt fuer den Browserlauf.
Alte `test:all --base-url`-/Kamera-Optionen werden bewusst abgelehnt.

## Umfang und Isolation

- Frische lokale Codekopie aus einer `git ls-files`-Allowlist; keine
  Symlinks, Live-SQLites, Cache-/Archiv-/Current-Baeume oder Secrets.
  Uncommitted Aenderungen an bereits versionierten Quelldateien werden
  mitgeprueft; neue Runtime-Dateien muessen versioniert sein.
- Drei Kameras, je zwoelf erzeugte JPEGs mit Varianten, EXIF und Wetter
  ueber drei Tage. Zwei oeffentlich, eine Testkamera. Zeitbasis und Bilder
  werden je Lauf erzeugt; `fixture.json` im Report dokumentiert die Zeitbasis.
- Eigene Config-, Kamera- und Rate-Limit-DBs, eigener State und Cache.
  Geerbte WEBCAM-/WC-Konfiguration und BASE_URL werden nicht uebernommen.
  Keine Maintenance-Ticks, keine Capture-Endpunkte, keine Streams.
- PHP bindet ausschliesslich an einen freien Loopback-Port. Ein zufaelliger
  Health-Marker verhindert das Uebernehmen eines fremden Servers. PHP
  `open_basedir` beschraenkt Dateizugriff auf die private Kopie;
  Shell-Prozessfunktionen und URL-Dateizugriffe sind deaktiviert.
- Die integrierten Seitenpruefungen blockieren externe Browseranfragen
  (z.B. Karten). Controller-Vertraege verwenden lokale DOMs und API-Mocks.
Kein allgemeiner OS-Netzwerk-Sandbox-Ersatz fuer beliebige neue Testcodes.
- Ein Worker, keine Retries, fuenf Minuten Gesamtlimit, Desktop und Mobil.
  Leere Bildlisten sind Fehler, kein erfolgreicher Skip. PHP-Fatalfehler
  lassen den Lauf unabhaengig vom Playwright-Ergebnis scheitern.
- PHP-/Browser-Prozessgruppen werden beendet und die markierte private
  Kopie entfernt. Reports bleiben im ausgegebenen `webcam-e2e-report-*`-
  Verzeichnis: PHP-Log, Fixture-Metadaten, JSON, bei Fehler Screenshots/Trace.

Die Abnahme umfasst Uebersicht, Testkamera-Sichtbarkeit/direkten Zugriff,
History-API und -UI, Timelapse-API mit echten JPEG-Abrufen und Playback-UI,
Best-Of, Wetter (Temperatur/Luftfeuchte), Admin-Authentifizierung/Versionsstand,
HTTP-Sperren und vorhandene isolierbare Admin-/EXIF-/Wetter-Controller-Tests.

Nicht behauptet: alle historischen Tests unter `tests/e2e` seien migriert.
Die alten expliziten Einzelbefehle bleiben Repro-Werkzeuge mit ihren eigenen
Fixtures/Annahmen, nicht die allgemeine Abnahme. Insbesondere Standalone-
Best-Of-Skripte mit `process.exit` sind aus der normalen Playwright-Discovery
ausgeschlossen. Kein Live-Upload, keine reale gphoto2-, Proxy-, Apache-,
Stream-/ffmpeg-, externe Karten- oder Produktions-Lasttest-Abnahme.

## CI, Rollout und Rueckweg

`.github/workflows/e2e.yml` laeuft bei Push/PR/manuellem Start mit eigenen
Fixtures und laedt Reports auch bei Fehlern hoch (sieben Tage Aufbewahrung).
Die zwei alten, auf `.34` zeigenden History-/Timelapse-Zeitplaene wurden
ersetzt. Kein Secret und kein VPN im CI erforderlich. Umsetzung folgt dem
[Playwright-CI-Ablauf](https://playwright.dev/docs/ci-intro) und
[PHP-Setup](https://github.com/shivammathur/setup-php).
Ein lokaler Erfolg belegt noch keinen ausgefuehrten GitHub-Workflow.

Keine Installation, Datenmigration, Cron-Aenderung oder PROD-Ausrollung
noetig. Rueckweg ist das Ruecknehmen der Test-/Workflow-Dateien im Git;
keine Live-Daten muessen restauriert werden. Reports enthalten nur
Testdaten und koennen nach Auswertung gezielt entfernt werden.

## Lokale Abnahme am 11.09.2026

- Gesamtlauf: 52 Browsertests in 41,4 s, keine Skips/Flakes; anschliessend
  PHPUnit **611 Tests / 3455 Assertions** in 83,0 s, 24 MiB gemeldeter Speicher.
- Wiederholung mit absichtlich falschem geerbtem BASE_URL, CAMERA_PATH,
  Config-/Kamera-DB-Pfad und ungueltiger Beispiel-DB-Freigabe: erneut
  52 Tests in 42,5 s. Die Werte wurden durch private Testkonfiguration ersetzt.
- Reports auf DEV: `/tmp/webcam-e2e-report-90p0Lq` und
  `/tmp/webcam-e2e-report-X0MW3M`. Testprozesse beendet, private Webroots
  entfernt. Kein Hosted-CI-Lauf und keine Produktions-Performancemessung.
- PHP-/Node-/Shell-Syntaxpruefungen und `git diff --check` bestanden.
