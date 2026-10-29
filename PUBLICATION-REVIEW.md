# Pruefbericht zum GitHub-Export

Stand: 29.10.2026. Export der aktuellen Arbeitsdateien von Webcam und CamPi,
keine Uebernahme ihrer bisherigen Git-Historien. Die vorhandene GitHub-Lizenz
und der bestehende Initial-Commit bleiben erhalten.

## Enthalten und ausgeschlossen

Enthalten: Serveranwendung, CamPi-Software, Frontend-Assets, SQL-Schemata und
Migrationen, Tests, Fachanleitungen und ausgewaehlte Installations-/Betriebswerkzeuge.
Die beiden Projekte liegen unter `webcam/` und `camera-rpi/`.

Ausgeschlossen: Live-/Sample-Datenbanken, Bilderarchive/Current-Bilder, Logs,
Sicherungen, Schluesseldateien, lokale Git-Konfiguration und alte Git-Historien,
historische Betreiberberichte, datierte hostgebundene Rollout-Installer,
fremde Infrastrukturprojekte, private vorbereitete Mountkonfigurationen und
historische Bildinventare. Installierte Composer-/npm-Abhaengigkeiten werden
ueber Lockdateien wiederhergestellt. Kleine synthetische Bild-Testfixtures und
Frontend-Icons/Schriften bleiben enthalten.

## Zugangsdaten und Adressen

- Secret-Scan mit Gitleaks 8.30.1 (Release-Download gegen dessen SHA256-Liste
  geprueft), ergaenzt durch Suche nach Passwort-/Token-Zuweisungen, privaten
  Schluesselbloecken, SSH-Schluesseln, Mailadressen, URLs und IP-Literalen.
- Feste Betreiber-IP-Adressen wurden konsistent durch das Dokumentationsnetz
  192.0.2.0/24 ersetzt; Betreiber-Webadressen durch `webcam.example.invalid`.
  Eine private RTSP-Beispieladresse wurde ebenfalls anonymisiert.
- Eine standortspezifische Passwort-Zeichenfolge in einem Redaktions-Test
  wurde durch einen kuenstlichen Testwert ersetzt; keine Annahme, dass ein
  Testverzeichnis automatisch frei von sensiblen Daten ist.
- Vier Scanner-Treffer sind ausschliesslich zwei kuenstliche Testschluessel
  in isolierten CamPi-Tests. `.gitleaks.toml` erlaubt exakt diese Werte nur
  in den vier betroffenen Testdateien. Ein Kommentar-Fehlalarm wurde durch
  klarere Formulierung beseitigt. Keine pauschale Ausnahme fuer Tests/Quellcode.
- Kein produktiver Admin-Token ist als Standard konfiguriert. Die neue lokale
  Sample-Datenbank wird aus dem Schema erzeugt und erhaelt einen Zufallskey;
  sie wird nicht committet. Der Generator verweigert bestehende Zieldateien.
- Bewusst erhalten: oeffentliche Upstream-/Paket-/Dokumentations- und
  Lizenz-URLs, DWD/MET.no/OpenStreetMap-Dienste sowie notwendige
  Drittanbieter-Autorenangaben, einschliesslich ihrer veroeffentlichten
  Mailadressen. Diese sind keine Betreiber-Zugangsdaten.
- Ein Scanner kann Geheimnisfreiheit nicht beweisen. Neue Konfigurationen,
  Testdaten, Installer und eingebettete Payloads muessen erneut geprueft werden.

## Validierung

- PHP-Lint: 469 Dateien; Python-AST: 45 Dateien; Bash-Syntax: 139 Dateien,
  jeweils ohne Syntaxfehler. Der neue Sample-Generator wurde zusaetzlich
  ausgefuehrt; Neuanlage und Verweigerung des Ueberschreibens bestaetigt.
- Composer-/npm-Installation aus Lockdateien in der isolierten Exportkopie.
- PHPUnit: 788 Tests, 4580 Assertions, **1 Failure, 1 Skip**.
  `ChunkUploadHttpTest::testLegacyMultipartIsAcknowledgedButIgnoredWhilePullIsPrimary`
  erhaelt mit seinem Test-JPEG HTTP422 `invalid_image` statt des erwarteten200.
  Keine pauschale Aussage, dass die gesamte Testsuite bestanden sei.
- Chromium, Desktop und Mobil: **112 bestanden, 4 fehlgeschlagen**.
  Zwei Controller-Testfaelle scheitern jeweils in beiden Profilen:
  Kameraeditor-Fixture laedt `WebcamAdminImageProcessing` nicht;
  Payload-Test erwartet14 Synchronisierungsaufrufe, tatsaechlich15.
  Die isolierten Bild-/Archiv-/Admin-/Upload-Browserfaelle sind im Lauf enthalten.
- CamPi: sechs Uhrabgleich-Tests, 64 Datenschutzpruefungen,
  Admin-Kennungspruefungen und20 Legacy-Konfigurationspruefungen bestanden.
  Keine echten Kamera-, GPIO-, Dienst- oder Mountaktionen durch diese Tests.

Dieser Export ist keine neue Hardware-, Produktions- oder Sicherheitsabnahme.
Keine laufende Server-/Kamerainstallation wurde durch die Bereinigung geaendert.
