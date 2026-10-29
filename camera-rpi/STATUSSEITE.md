# DHT22 und lokale Kamera-/Klima-Seite

Stand: 28.08.2026 – Software vorbereitet und ohne echte Hardware getestet;
GPIO-Pinbelegung am Kamera-Pi noch festzulegen.

Nachpruefung 10.09.2026: IIO-/Statussimulation unter Debian Trixie/PHP 8.4
erfolgreich; echte Trixie-/Pi-GPIO-Abnahme weiterhin offen. Auf dem neuen
RW-Pi entfallen die nachfolgenden RO-Remountschritte, siehe [TRIXIE.md](TRIXIE.md).

## Was später im Browser zu sehen ist

Die lokale Adresse lautet:

```text
http://PI-IP/
http://PI-IP/camera-status/
```

Direkt ohne Anmeldung erreichbar, kein SSH-Tunnel erforderlich.
Seit dem .16-Update vom 13.09.2026 wird `/` direkt mit HTTP 200 bedient,
ohne Umleitung auf `/camera-status/`. Der bisherige Pfad bleibt als Alias
kompatibel; das Stylesheet verwendet diesen absoluten Alias. `/camera-admin/`
bleibt separat durch HTTP-Anmeldung geschützt.
Die Seite zeigt:

- `Camera-RPi V1.0` und den Titel `Lokaler Blick auf <Webcam-Kennung>`,

- das zuletzt tatsächlich aufgenommene Bild,
- Datum und Uhrzeit der Aufnahme,
- aktuelle Temperatur und relative Luftfeuchte vom DHT22,
- den Temperatur-/Feuchteverlauf der letzten 24 Stunden.

Die Kennung stammt aus `camera_slug` der öffentlichen RAM-Datei `latest.status`,
nicht aus den privaten Zielkonfigurationen. Vor der ersten veröffentlichten
Aufnahme lautet der Titel nur `Lokaler Blick`. Kennungen werden HTML-escaped;
die Seite öffnet dafür keine zusätzlichen Serververbindungen. Nur für die
Anzeige wird der erste ASCII-Buchstabe gross geschrieben (`bielefeld` wird
`Bielefeld`); Konfiguration und gespeicherte Kennung bleiben unverändert.

Die drei separaten DHT22-/Heizungs-/Luefterkacheln wurden am 12.09.2026 auf
Betreiberwunsch entfernt. Temperatur/Feuchte bleiben im Bild, Regelwerte unten;
keine doppelten oder unkonfigurierten Rueckmeldefelder mehr in dieser Ansicht.

Mit dem [Klima-Regler](CLIMATE.md) steht darunter eine zweite Uebersicht als
Tabelle: aktueller Taupunkt, Abstand, Luefter-PWM in Prozent und Heizung An/Aus.
Die Betriebsart kennzeichnet Simulation. PWM ist kein Drehzahlmesswert; ohne
frischen Reglerstatus erscheinen unbekannte Werte statt erfundener Nullwerte.
Die Tabelle bleibt bestehen. Zusaetzlich liegt zwischen den beiden Bereichen
die Grafik **Taupunkt & Steuerung**: Taupunkt/Abstand in Grad, PWM als
Prozent-Stufenverlauf und Heizung An/Aus in eigener Spur. Bis zu 48 Stunden
RAM-Verlauf ab Installation, mit sichtbaren Luecken und gestrichelter Simulation;
keine zusaetzliche DHT-Abfrage oder laufende SD-Schreiblast. Mit der Tagesbackup-
Erweiterung werden beim Boot die letzten gesicherten historischen Reglerpunkte
wiederhergestellt; Daten seit der letzten Sicherung fehlen. Aktueller Status
und Regelpuffer werden nie restauriert. Details: [CLIMATE.md](CLIMATE.md).
Legendennamen, Markierungen und Kurven sind gemeinsam eingefärbt: Taupunkt
blau, Abstand gruen, PWM gelb, Heizung rot. CSS wird nach Inhalt versioniert.

Die Seite aktualisiert sich alle 60 Sekunden im Hintergrund, ohne kompletten
Seitenreload. Ein unveraendertes Bild behält seinen DOM-Knoten und wird nicht
erneut geladen; neue JPEGs werden zuerst unsichtbar geladen und dekodiert und
erst danach zusammen mit dem Aufnahmezeitpunkt ausgetauscht. Messwerte und
Grafiken werden ebenfalls erneuert, die Scrollposition bleibt erhalten.
Bei Download-/Verbindungsfehlern bleibt der letzte Stand mit Hinweis sichtbar;
eine vom Server gemeldete entfernte Vorschau wird dagegen sofort entfernt.
Versteckte Tabs pollen nicht; nach Rueckkehr wird ein ueberfaelliger Stand
einmal aktualisiert. Keine ueberlappenden Requests, 15 Sekunden Zeitlimit.
Ohne JavaScript bleibt ein normaler 60-Sekunden-Reload als Rueckfall erhalten.
Sie kann nichts schalten und nichts konfigurieren. Sensorintervalle und
RAM-/Archivaufzeichnung sind von dieser Anzeigeaenderung unabhaengig.

## Werte beim Zeigen auf die Grafiken

In beiden Verlaufsgrafiken reicht jede vertikale Position innerhalb des SVG:
die horizontale Mausposition waehlt den naechsten gespeicherten Zeitpunkt.
Eine senkrechte Hilfslinie markiert den gewaehlten Datensatz; der Tooltip zeigt
Datum/Uhrzeit (Europe/Berlin) sowie Temperatur/Feuchte beziehungsweise Taupunkt,
Abstand, PWM und Heizung. Auch die Sensorkurve verwendet echte Zeitabstaende
statt gleichmaessiger Abstaende zwischen Datensaetzen.

Keine interpolierten Werte: Bei einer Luecke ueber 45 Sekunden wird der
naechste gespeicherte Messpunkt ausdruecklich als solcher mit Messluecken-Hinweis
angezeigt. Unbekannte Werte bleiben unbekannt, Simulation ist gekennzeichnet.
Touch: Grafik antippen. Tastatur: Grafik per Tab fokussieren, Pfeile links/rechts
fuer Messpunkte, Pos1/Ende fuer Anfang/Ende, Escape zum Ausblenden.
Hover erzeugt weder HTTP-Anfragen noch Dateischreibzugriffe oder Sensorabfragen.
Der kleine eingebettete Code wird durch einen exakten CSP-SHA256-Hash erlaubt;
kein unsafe-inline und keine neue Chartbibliothek/zusatzliche Installationsdatei.

Abnahme: `NODE_PATH=/var/www/html/webcam/node_modules node tests/status-climate-ui.cjs`
(falls Playwright nicht im normalen Node-Suchpfad liegt). Lokale Fixtures,
390/1280 Pixel, X-Auswahl oben/unten, Zeitluecken, Simulation, unbekannte Werte,
Tastatur/Touch und CSP; keine Live-Sensoren. Reines PHP-/CSS-Update erfordert
keinen Dienstneustart und darf den aktiven DHT-Langtest nicht beeinflussen.

Am 13.09.2026 auf Testcam und .16 als reines Zwei-Dateien-Update abgenommen.
`ops/update-status-hover.sh` prueft bekannte Ausgangshashes, sichert beide
Dateien und publiziert atomar unter der Kamerasperre. Initiales RO wird nur
fuer das Update aufgehoben und danach restauriert; initiales RW bleibt RW.
Unbekannte Ausgangsstaende werden nicht ueberschrieben. Sicherungspfade und
Live-Nachkontrollen stehen in DEVELOPMENT.md (lokale Betriebsdokumentation, nicht im Export).

## Warum die Pinbelegung noch nicht fest im Programm steht

Der DHT22 benötigt einen freien BCM-GPIO. Heizung, PWM-Ausgang und vor allem
ein Tachosignal hängen von der vorhandenen Elektronik ab. Ein falscher fest
einprogrammierter Pin könnte mit anderer Hardware kollidieren. Deshalb sind
alle Quellen in `/etc/camera-rpi/device.conf` benannt und werden erst nach
Prüfung der realen Verdrahtung aktiviert.

## DHT22 anschließen und für Linux sichtbar machen

Vorher den tatsächlich freien BCM-GPIO feststellen. Der häufige Beispielwert
GPIO 4 ist keine Zusage, dass er an diesem Gerät frei ist.

Der Raspberry-Pi-Kernel bringt ein Device-Tree-Overlay für DHT11, DHT21 und
DHT22 mit. In `/boot/firmware/config.txt` wird im kontrollierten
Wartungsfenster beispielsweise ergänzt:

```ini
dtoverlay=dht11,gpiopin=4
```

Danach sind Boot und Root wieder read-only zu setzen und der Pi neu zu starten.
Der Sensor sollte anschließend als IIO-Gerät erscheinen:

```sh
for device in /sys/bus/iio/devices/iio:device*; do
  echo "===== $device ====="
  cat "$device/name" 2>/dev/null
  cat "$device/in_temp_input" 2>/dev/null
  cat "$device/in_humidityrelative_input" 2>/dev/null
done
```

Der Worker sucht automatisch nach dem IIO-Namen `dht11`; so heißt der
Kernel-Treiber auch bei einem DHT22. Das offizielle Raspberry-Pi-Overlay nennt
GPIO 4 als Standard, lässt den Pin aber ausdrücklich über `gpiopin` ändern.

## Abfrage einschalten

In `/etc/camera-rpi/device.conf`:

```ini
enabled=true
dht_iio_device=auto
```

Anschließend:

```sh
/usr/local/libexec/camera-rpi-sensor --check-config /etc/camera-rpi/device.conf
systemctl restart camera-rpi-sensor.service
systemctl status camera-rpi-sensor.service --no-pager
```

Die Messung läuft standardmäßig alle 30 Sekunden. Aktueller Zustand und Verlauf
liegen nur unter `/run/camera-rpi-status` im RAM. Das schont die SD-Karte und
funktioniert mit dem read-only Root-Dateisystem. Nach einem Neustart beginnt die
Verlaufsgrafik neu; das ist beabsichtigt.

## Heizung und Lüfter anschließen

Das Programm steuert beide Geräte nicht. Es liest vorhandene Rückmeldungen als
kleine Dateien, wie sie GPIO-, PWM-, HWMON- oder vorhandene Regelscripte
bereitstellen können:

```ini
heater_state_file=/PFAD/ZU/HEIZUNG/VALUE
heater_active_value=1

fan_percent_file=/PFAD/ZU/PWM/VALUE
fan_percent_max=255
fan_rpm_file=/PFAD/ZU/FAN1_INPUT
```

Enthält die PWM-Datei `128`, werden bei einem Maximalwert von `255` ungefähr
50 Prozent angezeigt. Eine PWM-Ansteuerung beweist jedoch nicht, dass der
Lüfter mechanisch dreht. Erst `fan_rpm_file` oder alternativ ein digitaler
`fan_running_file` erlaubt diese Aussage. Ohne Rückmeldung steht auf der Seite
deshalb ausdrücklich „Drehung nicht prüfbar“.

Die konkreten Pfade können erst eingetragen werden, wenn Pinbelegung,
Lüfterelektronik und Heizungsrückmeldung am Gerät geprüft sind.

## Was bei Fehlern passiert

- Ein nicht erreichbarer DHT22 wird als Fehler angezeigt; Kamera und Upload
  laufen weiter.
- Unplausible Temperatur- oder Feuchtewerte gelangen nicht in die Grafik.
- Eine defekte Statusseite kann kein Bild und keinen Upload verhindern.
- Das letzte Bild ist eine zusätzliche Kopie im RAM. Die sichere
  serverbezogene Spooldatei behält ihre bisherigen Regeln.
- Apache erlaubt `/camera-status/` ohne Anmeldung. Die Verwaltung unter
  `/camera-admin/` bleibt passwortgeschuetzt; die Infoseite hat keine Aktionen.
