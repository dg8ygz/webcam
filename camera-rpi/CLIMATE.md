# Gehaeuseheizung und Luefter

## Unabhaengigkeit von Fotoauftraegen und Webcamserver

Sensor und Regler laufen als eigene, beim Boot aktivierte systemd-Dienste.
Der Sensor misst standardmaessig alle 30 Sekunden zuzueglich Lese-/Retryzeit;
der Regler wertet seinen lokalen RAM-Status alle 250 ms zuzueglich
Verarbeitungszeit aus. Weder Fotoauftrag, Seitenaufruf noch Erreichbarkeit
des Webcamservers starten diese Schleifen. Physische Regelung setzt die
Betriebsart Automatik voraus; Fehlerreaktionen gelten weiterhin.
Nur waehrend tatsaechlicher Kameraarbeit werden Heizung und Luefter nach
bestaetigtem Abschalten kurz pausiert. Upload und Server-Poll pausieren sie
nicht. Eine belegte Kamerasperre kann Sensorzyklen auslassen. Im neuen
Versuchsstand vom 20.09. prueft der Sensor die Sperre danach im Sekundentakt
ohne DHT-Zugriff und holt die Messung nach Freigabe zeitnah nach; nach einer
tatsaechlichen Messung gilt wieder das konfigurierte Intervall. Echte
Lesefehler behalten ihre bisherigen vier Versuche und Drei-Sekunden-Abstaende.
Messwertaltersgrenze und Reglerpuffer bleiben unveraendert. Installationsstand
des Versuchs im Entwicklungsprotokoll: seit 20.09. auf `.16`, nach
Betreiberauftrag seit 21.09. 08:33:33 auch auf `.17`. Erste Messungen dort
erfolgreich; weitere Nachtbeobachtung bleibt offen.

Die **lokalen Pi-Grafiken** lesen Sensor-/Reglerverlaeufe aus RAM. Die
**Wettergrafik des Webcamservers** verwendet dagegen angenommene Aufnahmen
und deren Wetterdaten; lokale DHT-Werte kommen als Snapshot mit dem Bild,
nicht als kontinuierlicher Sensorstream. Fehlende Serverbilder koennen dort
also Luecken erzeugen, obwohl der Pi weiter regelt.

Auch lokale Verlaufsluecken sind kein alleiniger Stillstandsnachweis:
Nach Verlust ungesicherter RAM-Daten bleiben gegebenenfalls nur das
Tagesbackup um 00:05 und neue Punkte nach dem Boot. Der zusaetzliche
[Shutdown-Checkpoint](REBOOT-BACKUP.md) kann bei geordnetem Herunterfahren
aktuellere Daten erhalten; ein harter Stromverlust ist damit nicht abgedeckt.
Am 20.09. lief Steinhagens Regler waehrend beider Serverstoerungen weiter;
Bielefeld bootete gegen 11:40 neu und zeigt eine Luecke ab ca. 00:05.
Vor dem Boot ist sein Regelbetrieb aus diesem Verlauf nicht rekonstruierbar.
Laut anschliessender Betreibererklaerung loeste der Server nach seiner
Rueckkehr wegen ausgebliebener Bilder den Kamera-Reset aus. Nicht anhand
von Logs verifiziert; die weitere Untersuchung dieses Neustarts ist am
20.09. ausdruecklich zurueckgestellt („ignorieren“), kein aktiver Diagnoseauftrag.
Messgrenzen und genaue Befunde: Entwicklungsprotokoll (lokale Betriebsdokumentation, nicht im Export).

## Nachtrag 19.09.2026: Fotoaufnahme unabhaengig von Sensorwerten

Im Entwicklungsstand darf ein neuer, gueltiger Fotoauftrag auch ohne gueltige
DHT-Messung und trotz eines gespeicherten `capture_timeout` fortfahren.
Der Regler schaltet fuer dessen zeitlich begrenzte Pause beide Ausgaenge aus;
erst die erfolgreiche Bestaetigung des Ausgangshelfers erlaubt das Capture-ACK.
Ein alter Aufnahme-Timeout wird dabei nicht quittiert: Nach der Pause bleibt
die Klimastoerung sichtbar und ihre Ausfallreaktion aktiv. Hardware- und
Konfigurationsfehler bleiben getrennt; fehlgeschlagenes Abschalten erzeugt
keine Freigabe. Ein gehaltener Lock ohne gueltigen, frischen Pausenauftrag
reicht ebenfalls nicht. Sensorwerte sind keine Voraussetzung fuer dieses ACK.

Anlass war der lesende Befund auf `.17`: frische Temperatur-/Feuchtewerte,
aber dauerhaft `capture_timeout` und ausbleibende Bilder. Die Verriegelung
erklaert die weitere Aufnahmesperre, nicht die urspruengliche Ursache des
Bildausfalls. Am 19.09. anschliessend auf ausdruecklichen Auftrag auf `.17`
und `.16` installiert; beide lieferten danach regulaer die 12:20-Aufnahme.
Vor dem Update waren beide bereits fehlerfrei mit frischen 12:10-Bildern;
die vorherige Erholung darf nicht dem Fix zugeschrieben werden.

## Nachtrag 27.09.2026: Retry-Pause und unabhaengige Messwertanzeige

Gemeinsame Pool-Aufnahmefehler erhalten eine geraeteweite
Retry-Pause von 30/60/120/240/maximal 300 Sekunden im RAM. Beide Worker
beachten dieselbe monotone Deadline und geben die Kamerasperre sofort frei;
es wird nie unter der Sperre auf den naechsten Versuch gewartet. Eine
erfolgreiche Aufnahme setzt den Backoff zurueck, offene Auftraege bleiben
erhalten. So entstehen auch bei PTP-Timeouts Zeitfenster fuer Sensor und
Strombedienung. Eine tatsaechlich laufende Aufnahme bleibt weiterhin gegen
gleichzeitiges Ausschalten geschuetzt. Der Backoff repariert keine defekte
USB-Verbindung. Anlass, gezielter `.17`-Rollout und Abnahme:
Steinhagen 27.09. (lokale Betriebsdokumentation, nicht im Export).

## Bisheriger Implementierungsstand

Stand 12.09.2026. Neue Implementierung nach `fan.py` v0.4 von dd5cc als
Funktionsvorlage: Taupunktabstand und Feuchte-PWM bleiben erhalten. Kein
kopierter Daemon und kein Austausch der DHT-Bibliothek. Erstinstallation auf dem
Test-Pi am 12.09. um 13:13 CEST; **keine reale Relais-/Luefter-Abnahme**.
Der DHT-24h-Test bleibt unangetastet. Frische Installation startet im Modus Aus.
Die nachfolgende Betreiberkorrektur entfernt die festen Temperatur-/Heizzeit-
Grenzen und die Aktivierungs-Checkbox; ihr Installationsstand steht in DEVELOPMENT.md.

**Sprachvorgabe:** Kein Python fuer diese Erweiterung. Der Betreiber erlaubt PHP
ausdruecklich wegen der Wartbarkeit ohne IDE. Die Regelung und Dateischnittstellen
sind PHP-CLI-Klassen mit Funktionskommentaren; Shell verbindet vorhandene Worker
und Installation. Ein enger nativer C-Helfer erzeugt nur die PWM-Flanken.
Keine neue Python-Bibliothek und keine Migration bestehender Python-Helfer.

## Feste, vom Betreiber bestaetigte Belegung

| Funktion | BCM-GPIO | Physischer Pin | Verhalten |
| --- | --- | --- | --- |
| DHT22 Daten | 4 | 7 | Nur der vorhandene IIO-Treiber besitzt die Leitung |
| Luefter-Treiber | 27 | 13 | 100 Hz PWM; HIGH = volle Leistung |
| Heizungsrelais | 5 | 29 | LOW = Heizung an; HIGH = aus |

DHT22-Modul weiterhin 3,3 V an Pin 1 und GND an Pin 9. GPIOs treiben
**keinen Luefter und keine Heizung direkt**. Vor Aktivierung 3,3-V-taugliche
Treiberstufen, gemeinsame Masse, Relaispolaritaet, Ausfallzustand und separate
Thermosicherung pruefen. Ein externer Pull-up am LOW-aktiven Relais muss es bei
Boot, Prozessabsturz oder freigegebener GPIO-Leitung ausgeschaltet halten.
Die konkrete Ausfuehrung muss zur bestehenden Schaltung passen.

BCM27 wird wie beim Original mit Software-PWM betrieben. Die neue Grenze
nutzt das Linux-GPIO-Character-Device v2 direkt, kein RPi.GPIO-Kompatibilitaets-
Shim, kein `/dev/mem` und keinen `pigpiod`-Netzwerkdienst. GPIO-Bank wird anhand
des BCM-Labels erkannt, fremde Chips oder belegte Leitungen werden abgelehnt.
Kein neuer Thread liest DHT22. PWM-Einfluss auf die DHT-Fehlerquote ist trotzdem
erst mit der real angeschlossenen Hardware zu messen.

API-Referenz: [Linux GPIO Character Device v2](https://docs.kernel.org/userspace-api/gpio/chardev.html),
vorhanden ab Kernel 5.10. Die bestaetigte BCM27-Belegung hat keinen passenden
Hardware-PWM-Kanal. Deshalb Software-PWM im nativen Helfer, nicht in PHP/Shell.
Dies ist unter Linux-Last keine harte Echtzeitgarantie. Nach verpassten Perioden
springt er zur aktuellen Phase, statt Flanken nachzuholen.

## Admin-Interface

Lokal `http://PI-IP/camera-admin/#climate`, mit der bestehenden Anmeldung.
Die gleiche Seite ist ueber den vorhandenen HTTPS-Wartungstunnel erreichbar.
Die neue Sektion **Heizung & Luefter** gilt fuer das eine physische Gehaeuse,
nicht getrennt pro DEV-/PROD-Ziel. Der zentrale Webcam-Admin erhaelt in diesem
Schritt kein neues Remote-GPIO-Protokoll.

- **Aus** (Installationsstandard): keine Regelung/keine neuen GPIO-Claims.
  Wurde zuvor Automatik verwendet, werden die Ausgaenge abgeschaltet/freigegeben.
- **Simulation:** echte vorhandene RAM-Messwerte auswerten und Sollwerte anzeigen,
  aber niemals den nativen PWM-Prozess starten oder Ausgaenge beanspruchen.
- **Automatik:** physische Ansteuerung nach Auswahl der Betriebsart und Speichern.
  Keine zusaetzliche Bestaetigungs-Checkbox; der native PWM-Helfer muss installiert
  sein. Anmeldung, CSRF-Pruefung und Revisionstoken gelten unveraendert.

Seit 19.09. zeigt ein gespeicherter technischer Fehler einen hervorgehobenen
Hinweis und bei frischem Reglerstatus den Button **Klima-Stoerung quittieren**.
Der frueher entfernte Button ist damit gezielt im Fehlerfall wieder erreichbar;
ohne Fehler bleibt er verborgen. Ein alter Status wird als letzter bekannter
Fehler gekennzeichnet, ohne eine aktuelle Dienstverbindung vorzutäuschen.
Auch waehrend einer erlaubten Aufnahmepause bleibt der gespeicherte Fehler
sichtbar. Die oeffentliche Statusseite verweist zur angemeldeten Adminseite.

Die Quittierung benutzt die bestehende authentifizierte/CSRF-geschuetzte
Root-Bruecke. Die CLI (`php /usr/local/libexec/camera-rpi-climate.php reset`)
bleibt verfuegbar. Frische gueltige Messwerte sind erforderlich; warme Sommerluft
verhindert die Quittierung nicht. Danach wird der Messpuffer neu aufgebaut.
Seit dem Fix vom 27.09. wartet die CLI maximal drei Sekunden auf eine
tokengebundene Antwort nach einem vollstaendigen Reglerzyklus. Erst bei
bestaetigter Quittierung und weiterhin leerem Fehlermerker meldet die Webseite
Erfolg. Fehlende, alte oder ungueltige Messwerte, ein nicht laufender Dienst oder
eine im selben Zyklus erneut aktive Stoerung liefern eine Fehlermeldung.
Gleichzeitige Quittierungen werden serialisiert; abgelaufene Anfragen wirken
nicht verspaetet. Zunaechst auf `.17`, nach gesondertem Betreiberauftrag
am 27.09. um 22:38 auch auf `.16` installiert:
.16-Abnahme (lokale Betriebsdokumentation, nicht im Export).

Gespeicherte Werte werden beim Oeffnen geladen. Ein Revisionstoken verhindert,
dass ein altes Browserformular neuere Werte ueberschreibt. Unbekannte/doppelte
Felder, falsche Typen, ungueltige Zahlen und widerspruechliche Grenzen werden an
der Root-Bruecke abgelehnt. Keine frei waehlbaren Pins, Pfade oder Kommandos.
POST benutzt dieselbe Authentifizierung/CSRF-Grenze wie der Kameraeditor.

Die oeffentliche Infoseite zeigt unter der Temperatur-/Feuchtegrafik eine
Tabelle der **aktuellen** Regelwerte: Taupunkt, Abstand in Grad, Luefter-PWM
in Prozent und Heizung An/Aus. Die Betriebsart kennzeichnet auch Simulation.
PWM ist der aktuelle Ansteuerwert einschliesslich Anlaufimpuls, kein U/min-Wert
und nicht nur das nach dem Impuls angestrebte PWM-Ziel. Fehlende oder ueber zehn
Sekunden alte Reglerdaten erscheinen als unbekannt.

Zwischen Temperatur-/Feuchtegrafik und dieser weiterhin vorhandenen Tabelle
steht zusaetzlich **Taupunkt & Steuerung**: Taupunkt und Abstand als Kurven
auf der linken Celsius-Achse, PWM als Stufenverlauf auf der rechten Prozent-
Achse und Heizung An/Aus in einer eigenen Spur darunter. Simulation wird
gestrichelt dargestellt. Zeitabstaende sind massstabsgetreu; fehlende Werte,
Dienstunterbrechungen ueber 45 Sekunden und Betriebsartwechsel werden nicht
ueberbrueckt. Keine erfundene Rueckrechnung aus alten DHT-Messwerten: der
Reglerverlauf beginnt nach diesem Update. Seitenaufrufe lesen nur RAM-Dateien,
loesen weder Messungen noch Schaltaktionen aus und schreiben nicht auf SD.
Die drei bisherigen DHT-/Heizungs-/Luefterkacheln entfallen auf Betreiberwunsch:
Temperatur und Luftfeuchte stehen bereits im Bild, die Reglerwerte unten in der
Tabelle. Legendennamen, Markierungen und Linien haben dieselben vier Farben:
Taupunkt blau, Abstand gruen, PWM gelb und Heizung rot. Ein CSS-Inhaltshash in
der Stylesheet-URL verhindert alte Browser-Caches nach einem Anzeigeupdate.
`ops/update-status-display.sh` aktualisiert nur die beiden Anzeigedateien mit
Sicherung und Pruefung des installierten Graph-Pakets; kein Dienstneustart,
keine Aenderung von Automatik, Messwerten oder RAM-Verlauf.

## Regelung und Standards

| Einstellung | Default | Einstellbereich |
| --- | --- | --- |
| Taupunktabstand | 5 °C | 1–10 °C |
| Hysterese | 1 °C | 0,5–5 °C |
| Ausschaltverzoegerung | 60 s | 0–300 s |
| Sommergrenze / PWM | 30 °C / 75 % | 15–45 °C / 30–100 % |
| PWM-Unter-/Obergrenze | 30 / 100 % | jeweils 10–100 % |
| Maximales Messwertalter | 120 s | 30–180 s |
| Anzahl Messpaare im Mittelwert | 7 | 1–15 |

Es gibt **keine feste Temperaturabschaltung und keine maximale Heizdauer**.
Der Betreiber setzt eine schwache Kondensatschutzheizung ein: sie darf bei
Taupunktbedarf dauerhaft heizen. Temperaturen ueber 35 °C sind kein Fehler und
erzeugen keine Verriegelung. Die Sommergrenze spart Heizenergie und aktiviert
die normale Sommerlueftung; nach Abkuehlung geht die Regelung automatisch weiter.
Die Defaults sind Ausgangswerte, keine thermische Hardware-Abnahme.
Der Regler berechnet den Taupunkt mit der Magnus-Formel ueber Wasser wie im
Original (auch unter 0 °C). Er verwendet diesmal ausschliesslich das aktuelle
gemittelte Messpaar fuer die Regelung. Seit 27.09. zeigt er bei Aus, Warmup,
gespeichertem technischen Fehler oder Aufnahmepause zusaetzlich Taupunkt und
Abstand aus einem frischen gueltigen Einzelmesspaar. Die Anzeige benennt
Einzelmessung bzw. Regler-Mittelwert; `measurement_source` und
`measurement_epoch` kennzeichnen den Bezug im RAM-Status. Die rein
diagnostische Berechnung erteilt keine Aktorfreigabe und fuellt nicht den
Reglerpuffer. Alte/ungueltige Messwerte werden nicht weitergerechnet.
Bei 0 % Luftfeuchte gibt es keinen endlichen Taupunkt und
keinen Heizbedarf; keine log(0)-Fehlerschleife.

Heizen beginnt bei `Temperatur - Taupunkt <= Abstand`. Im Heizbetrieb ist die
PWM gleich der gemittelten Luftfeuchte, geklemmt auf die eingestellten Grenzen.
Ausschalten erfolgt erst, wenn der Abstand **plus Hysterese** fuer die gesamte
Ausschaltverzoegerung erreicht ist. Bei erneuter Unterschreitung beginnt die
Verzoegerung von vorn. Schutzabschaltungen umgehen diese Verzoegerung.

**Luefteranlauf:** Bei jedem Wechsel 0 % → positiver Sollwert fuer zwei Sekunden
100 %, dann auf den inzwischen aktuellen Sollwert. Neue Sollwerte waehrend des
Laufens werden direkt uebernommen; kein erneuter Boost. Aenderungen innerhalb
der ersten zwei Sekunden verlaengern diese nicht. Ausschalten/Capture-Pause
bricht den Boost sofort ab; beim naechsten Anlauf beginnt ein neuer Boost.
Die Supervisor-Taktung betraegt 250 ms plus Verarbeitungszeit, keine blockierende
Zwei-Sekunden-Pause. Auch Speichern einer neuen PWM-Grenze startet den Luefter
nicht erneut. Aenderung der Messpuffergroesse verlangt dagegen einen neuen Puffer.
Der 100-%-Boost darf die konfigurierte normale PWM-Obergrenze ueberschreiten.

Die Sommerschwelle prueft die **ungemittelte** Temperatur,
damit der Mittelwert einen schnellen Temperaturanstieg nicht verdeckt. Sommer:
Heizung aus, Sommer-PWM; zurueck erst unter Sommergrenze minus Hysterese.
Taupunkt aus Gehaeuse-Luft ist nur ein Naeherungswert fuer Beschlaggefahr am
Glas. Eine Glasscheiben-Temperaturmessung waere eine spaetere Verbesserung.

## Fehler und Capture-Pause

- Fehlerhafte/fehlende Sensorwerte: Heizung sofort aus. Solange die letzte
  gueltige Messung nicht aelter als `sensor_max_age_seconds` ist (Standard
  **120 Sekunden**), bleibt der letzte normale Luefter-Sollwert erhalten,
  einschliesslich 0 % bei zuvor stehendem Luefter. Der Zwei-Sekunden-Anlaufboost
  wird nicht als Sollwert gespeichert und durch Lesefehler nicht verlaengert.
  Der Mittelwertpuffer bleibt bei kurzen Aussetzern erhalten; fehlerhafte oder
  mehrfach gelesene Messpaare fuellen ihn nicht. Der Status bleibt sichtbar
  `sensor_error`, ohne alte Temperatur-/Taupunktwerte als neue Messung auszugeben.
- Erst bei Ueberschreitung dieses Messwertalters: Luefter 100 %, Puffer leeren;
  danach mit frischen Messungen neu einmitteln. Fehlerzeitstempel verlaengern
  die Frist nicht. Wanduhr und monotone Uhr begrenzen die Frist gemeinsam.
  Ohne gueltige Ausgangsmessung (auch nach Reglerneustart/Aus/Quittierung)
  gibt es keine Haltefrist. Eine laufende Capture-Pause haelt bei Sensorfehlern
  weiterhin beide Ausgaenge aus, auch nach Ablauf der Frist.
- Technische Stoerungen (GPIO-/Konfigurationsfehler, abgelaufene Capture-Pause)
  bleiben verriegelt. Dienstneustart oder Konfigurationswechsel loescht diese
  RAM-Fehlermerker nicht. Die alten `overtemperature`-/`heat_timeout`-Merker
  werden beim Start der neuen Software verworfen, damit sie nicht weiter sperren.
  Ein neuer gueltiger Fotoauftrag darf einen alten `capture_timeout` fuer
  die bestaetigte Ausgaenge-aus-Pause ueberlagern, ohne ihn zu loeschen.
- Vor Kameraarbeit haelt der Worker neben der vorhandenen Kamerasperre einen
  gemeinsamen Klima-Capture-Lock. Ein neuer Zufallsmarker muss binnen acht
  Sekunden vom Regler **nach** Abschalten beider Ausgaenge bestaetigt werden.
  Ohne Bestaetigung keine Aufnahme; vorhandener Auftrag bleibt fuer Retry stehen.
- Die Pausenanfrage wird atomar ausgetauscht. Wenn der Austausch genau
  zwischen Dateipruefung und Oeffnen liegt, liest der Regler erneut
  (maximal drei Versuche), statt einen falschen `capture_timeout` zu speichern.
  Ungueltige Dateien, Symlinks und abgelaufene aktive Pausen bleiben Fehler;
  ein fehlender oder nicht stabil lesbarer Marker bekommt keine Bestaetigung.
- Pause endet automatisch mit Freigabe des Locks, auch nach einem Workerabsturz.
  Ein Reglerneustart erkennt die weiterhin gehaltene Pause. Sie ist zusaetzlich
  zeitlich begrenzt durch Kamera-Timeout plus Reserve, maximal 1840 Sekunden.
  Abgelaufene Pause verriegelt den Regler, verhindert aber keinen neuen
  gueltigen Fotoauftrag mit bestaetigtem Abschalten. Fehlerhafte/fehlende
  Sensorwerte und warme Sommerluft sperren keine Aufnahme. Ein nicht
  bestaetigtes Abschalten oder eine ungueltige Konfiguration bleiben Sperrgruende.
- Keine Pause waehrend Upload/Long-Poll. Kein globaler Capture-Auftrag und keine
  Aenderung von Fokusprotokoll, Keys, Zeitplaenen oder Bildzuordnung.

**Grenzen:** Sollzustand ist kein Relais-Rueckmeldekontakt, PWM ist kein
Drehzahlnachweis. Ein stehender Luefter oder festgeklebtes Relais kann ohne
zusaetzliche Sensorik unentdeckt bleiben. Der native Helfer erwartet mindestens
alle drei Sekunden einen gueltigen Stellbefehl, auch bei gleichem Sollwert.
Fehlende Befehle, EOF, ungueltige Eingaben oder SIGTERM fuehren zur Abschaltung.
AUS wird erst nach erfolgreichem GPIO-Aufruf bestaetigt. Ein unabhaengiger
systemd-Watchdog ueberwacht den PHP-Hauptprozess. Diese Softwareabschaltungen
ersetzen keinen unabhaengigen Temperaturbegrenzer. Bei Kernel-/Stromausfall kann
das Programm keine garantierte Abschaltaktion mehr ausfuehren.

## Programme, Daten und Schreibzugriffe

| Datei | Aufgabe / Grenzen |
| --- | --- |
| `scripts/lib/climate-controller.php` | Reiner Validator, Magnus, Regelzustand, Anlaufimpuls; keine I/O |
| `scripts/lib/climate-gpio.php` | Fester Prozessaufruf ohne Shell, begrenzte Stellbefehle und ACK-Pruefung |
| `scripts/lib/climate-history.php` | Begrenzter TSV-RAM-Verlauf, 30-s-/Ereignisaufzeichnung und reine SVG-Geometrie ohne Sensor-/GPIO-Zugriff |
| `scripts/native/camera-rpi-pwm.c` | Einziger GPIO-Schreiber; Initialpegel, BCM-Pruefung, PWM, 3-s-Watchdog und Cleanup |
| `scripts/camera-rpi-climate.php` | Supervisor, Konfig-CAS, RAM-Status/Fehler, Capture-ACK und Healthcheck |
| `scripts/camera-rpi-climate-archive.php` | PHP-Tagesarchiv/Boot-Restore fuer historischen Reglerverlauf, keine Stellwerte zurueckladen |
| `scripts/camera-rpi-sensor-backup`, `scripts/camera-rpi-history-restore` | Gemeinsames Backup-Schreibfenster und Boot-Reihenfolge beider Datenreihen |
| `scripts/lib/camera-rpi-common.sh` | Capture-Pause unter geerbtem flock, vor gphoto2/V4L2 |
| `scripts/admin/camera-rpi-admin` | Feste climate_get/save/reset-Aktionen hinter sudo, vorhandenes RO-Schreibfenster |
| `admin/src/climate.php`, `climate-panel.php` | Gepruefte Formulare, Status und eingebettete Bedienhilfe |
| `systemd/camera-rpi-climate.service` | Ein Dienst fuer das Geraet; 10-s-Watchdog, kein Netzwerklistener |

`/etc/camera-rpi/climate.json` (0600) wird nur beim bewussten Speichern atomar
geschrieben; vorherige Konfiguration bleibt als `climate.json.previous` erhalten.
Admin nutzt den vorhandenen globalen Lock fuer das kurze RO→RW→RO-Fenster.
Keine SD-Schreibvorgaenge pro Messung oder PWM-Aenderung, keine Python-Bytecode-Dateien.

Beim Update werden die bisherigen Felder `max_temperature_c` und
`max_heat_seconds` mit Sicherung entfernt. Alle uebrigen Werte und die Betriebsart
bleiben erhalten. Alte Dateien lassen sich auch vor dieser einmaligen Migration
lesen: ausschliesslich die beiden bekannten Alt-Felder werden validiert und
ignoriert. Neue Speicheranfragen akzeptieren sie nicht mehr. Ein normaler
Lesezugriff schreibt keine Konfiguration und erzeugt keine SD-Schreiblast.

Interner PWM-Vertrag: `SET HEATER DUTY` ueber private stdin-Pipe, Antwort
`OK HEATER DUTY` nach Anwendung. HEATER ist 0/1, DUTY 0..1000 in Zehntelprozent.
Keine beliebigen GPIO-Nummern, Pfade oder Netzwerkverbindungen. Heizung AN bei
Luefter 0 wird auch hier abgelehnt. Beide Ausgaenge werden gemeinsam angefordert,
initial Heizung HIGH / Luefter LOW. Der Testmodus existiert nur im separat mit
`CLIMATE_TEST` gebauten Testbinary, nicht im installierten Programm.

`/run/camera-rpi-climate/` (0700) enthaelt Locks, Quittierungen und Fehlermerker.
`/run/camera-rpi-status/climate.status` (0640 root:www-data) liefert alle zwei
Sekunden bzw. bei Zustandswechsel Sollzustand, PWM-Ziel/Anlaufwert und Taupunkt.
Webseiten markieren Reglerstatus nach zehn Sekunden als veraltet. Logs nur bei
Zustandswechsel; auf dem Low-write-Pi bleibt journald wie bisher fluechtig.
`/run/camera-rpi-status/climate-history.tsv` (0640 root:www-data) haelt bis zu
48 Stunden, maximal 8192 Zeilen und unter 1 MiB. Der Regler zeichnet alle
30 Sekunden sowie bei Betriebsart-, Zustands-, PWM- oder Heizungswechseln auf;
dadurch werden auch der Zwei-Sekunden-Anlaufimpuls und Capture-Pausen gespeichert.
Nur die Grafik samt Hover blendet `capture_pause` fuer PWM/Heizung aus.
Kurze 100-%-Anlaufimpulse werden ausschliesslich fuer PWM maskiert: unmittelbar
nach einer protokollierten `capture_pause` mit 0 %, binnen maximal drei Sekunden gefolgt von einem positiven
Wert unter 100 % bei gleichem Normalzustand und gleicher Betriebs-/Ausgabeart.
Das alte TSV-Format hat kein eigenes Boost-Merkmal; nicht eindeutig erkennbare
oder noch laufende Impulse bleiben deshalb sichtbar. Andere kurze Spitzen,
normale Luefterstarts und echte laengere 100-%-Phasen,
Sensorfehler und regulaere Heizungswechsel werden nicht versteckt.
Maskierte Punkte bilden Luecken, keine erfundenen Zwischenwerte; der Hover
kennzeichnet sie als „Ausgeblendet“. Rohdatei, Tagesbackup und aktuelle
Wertetabelle bleiben unveraendert. Kein Reglerneustart notwendig.
Eine atomare RAM-Datei ohne fsync, kein eigener Timer oder zusaetzlicher Prozess.
Ein Fehler der Verlaufsaufzeichnung stoppt weder Regelung noch Capture-ACK.
Dienstneustarts erhalten den Verlauf. Mit der Backuperweiterung wird er auch
taeglich um **00:05 Europe/Berlin** im bestehenden Sensor-Backupjob gesichert:
`/var/lib/camera-rpi/sensor-backup/climate-YYYYMMDD.tsv`, 0600 root:root,
letzte 30 erfolgreiche Tagessicherungen (je unter 1 MiB). Ein vorhandenes
Tagesarchiv wird nicht erneut geschrieben. Die `sensor-*.tsv` bleiben unveraendert.
Beide Sicherungen teilen sich den bestehenden Lock und das eine RO/RW-Fenster;
Datei/Verzeichnis werden nur bei diesem SD-Backup synchronisiert.

Beim Boot laeuft der gemeinsame Restore vor Sensor und Regler. Nur plausible
historische Punkte der letzten 48h kommen in die Grafik zurueck, maximal 8192.
Keine Wiederherstellung von aktuellem Status, GPIO-/Heizungszustand, Fehlern
oder Regelpuffer. Auch ein manueller Restore darf den Verlauf eines laufenden
Reglers nicht ueberschreiben: dessen daemon.lock verhindert das. Aeltere
Sensor-only-Backups bleiben kompatibel. Nach Reboot/Stromausfall fehlen weiterhin
die seit der letzten Sicherung neu entstandenen Punkte. Es gibt keine zweite
IIO-/DHT-Abfrage und keinen zusaetzlichen permanenten Schreiber.

Bestehende Low-write-Pis erhalten diese Erweiterung separat mit dem geprueften
Paket `ops/update-climate-backup.sh --apply`: Vorher-Sicherung der Dateien,
erstes Regler-Tagesarchiv, daemon-reload ohne Dienstneustart oder Reboot.
Automatik und DHT-Langzeittest werden nicht unterbrochen. Die reale Boot-
Wiederherstellung wird erst beim naechsten ohnehin geplanten Neustart abgenommen.

## Installation und Rueckweg

Frische Installation: `install.sh --core --web NAME` installiert das Modul im
Modus Aus. Fuer bestehende Pis den separat geprueften, inhaltsadressierten
Klima-Update-Tarball aus `ops/build-climate-package.sh` verwenden:

```sh
# Im frisch entpackten, checksum-geprueften Paket auf dem Pi, als root:
bash ops/update-climate.sh --apply
```

Updater verlangt bewusst freigegebenes RW-Root, sichert die exakten Zieldateien
unter `/root/camera-rpi-climate-before.XXXXXXXX`, lehnt aktive Automatik ab und
startet nur vorher aktive Kamera-Worker neu. DHT-Dienst/-Treiber werden nicht
beruehrt. Kein automatisches apt, keine neue GPIO-Testaufnahme, kein Reboot.
Voraussetzungen: PHP-CLI >=8.2 mit pcntl/posix/sockets (Debian-Pakete), zum
einmaligen nativen Build `gcc libc6-dev linux-libc-dev`. Fresh-Install `--packages`
beruecksichtigt sie; der Updater fuehrt kein apt aus. Der Compiler wird im Betrieb
nicht benutzt. Binary wird auf dem Pi gebaut, kein x86-Download fuer ARM.
Danach zuerst Simulation pruefen und die echte Hardware abnehmen.

```sh
systemctl status camera-rpi-climate.service --no-pager
php /usr/local/libexec/camera-rpi-climate.php health
cat /run/camera-rpi-status/climate.status
journalctl -u camera-rpi-climate.service -n 30 --no-pager
```

Rueckweg im Betrieb: im Admin **Aus** speichern, frischen Status `off` und
keine Ansteuerung pruefen. Dienst installiert/aktiv lassen: auch bei Aus
bestaetigt er die Capture-Sperre. Fuer vollstaendigen Code-Rollback aus der
benannten Sicherung zuerst Worker stoppen, Klima deaktivieren/stoppen,
gesicherte Dateien laut `targets.tsv` zurueckspielen und nur beim Erstinstall
neu angelegte Dateien inklusive `climate.json` entfernen. Dann daemon-reload,
Apache reload, nur zuvor aktive Worker starten. Nicht einfach den Klima-Dienst
stoppen, solange Worker eine vorhandene Klima-Konfiguration verwenden: ohne ACK
bleiben deren Aufnahmen absichtlich vorgemerkt. Automatischer Fehlerrueckweg
im Updater nutzt dieselbe exakte Zieldateiliste, nie Spool/Keys/Sensoren.

## Isolierte Abnahme

`php tests/climate.php`: Konfigurationsgrenzen, Magnus/Minusgrade/0 %,
gepaarter Puffer, Messwertalter, Hysterese/Verzoegerung, Sommerbetrieb bis 80 °C
ohne Verriegelung, 48 Stunden durchgehender Heizbedarf mit simulierten Zeiten,
technische Fehlermerker, Alt-Konfigurationsmigration und deren Sicherung,
Zwei-Sekunden-Boost/Abbruch/laufende PWM-Aenderung,
CAS/Sicherung, reale Admin-Bruecke im Testmodus und echter
Supervisor/Capture-Handshake einschliesslich Dienstneustart unter gehaltenem Lock.

`php tests/climate-sensor-grace.php`: kurze/lange DHT-Aussetzer fuer Stillstand,
Heizen und Sommer, exakte Altersgrenze, bereits gealterte Messungen,
Uhrspruenge/eingefrorene Zeitstempel, Puffererhalt/-neubau, Capture-Pause und
Anlaufboost. Simulierte Zeiten, keine GPIO-/IIO-Aufrufe. Der echte Supervisor
in `tests/climate.php` prueft zusaetzlich Heizung aus/PWM halten/Wiederanlauf
gegen temporaere Statusdateien ohne Hardware.

Das enge Paket `ops/update-climate-sensor-grace.sh --apply` ersetzt nur
`climate-controller.php` und diese Dokumentation, mit gepruefter Ausgangsversion,
Sicherung und Fehlerrueckweg. Es startet nur den Klima-Dienst neu (erneuter
Pufferaufbau), nicht Sensor, DHT-Treibertest oder Kamera-Worker. Konfiguration,
Verlaeufe und Treiber bleiben unveraendert. Eine neue 24h-Hardwareabnahme ist
durch die isolierten Tests nicht ersetzt.

`bash tests/climate-native`: Builds mit strengen Compilerwarnungen, echter
PWM-Prozess gegen Testausgaenge, sichere Start-/Endpegel, gueltige/ungueltige
Befehle, EOF, SIGTERM und unabhaengiger 3-s-Watchdog. Kein echter GPIO-Aufruf.

`node tests/climate-ui.cjs` mit dem vorhandenen Playwright: echte PHP-Oberflaeche,
echte Bruecke nur gegen private temporaere Testdateien; 390/1280 px, Vorbelegung,
Speichern, CSRF, veraltetes Formular, entfernte Grenzfelder/Checkbox und direkte
Auswahl von Automatik (ohne laufenden Regler/GPIO in Tests). Vollstaendige
Regression: `bash tests/run-tests`. **Alles ohne physische GPIO-/DHT-Zugriffe.**

`php tests/climate-history.php`: TSV-Validierung, 30-s-Drosselung und
Anlauf-/Capture-Ereignisse, Dienstneustart/Uhrkorrektur, echte Zeitachse,
Stufen/Luecken/Betriebsartwechsel, 48h-/8192-Zeilen-/1-MiB-Grenzen und begrenzte
Maximalverlaufs-Messung (Schreiben/Lesen/vier SVG-Serien). Nur Testdateien.

`php tests/climate-archive.php`: Tagesgrenze Berlin, nur eine Sicherung pro Tag,
30-Snapshot-Retention ohne Aenderung von Sensorarchiven, 48h-/8192-Punkte-Restore,
Vorrang vorhandener RAM-Punkte, aktive Reglersperre, keine aktuellen Stellwerte,
ungueltige/grosse/Symlink-Dateien und beide Shellwrapper mit nur simuliertem
RO/RW-Fenster/Fehlerpfaden. Keine echten Dienste, GPIOs oder Remount-Aufrufe.

`node tests/status-climate-ui.cjs`: reale PHP-Statusseite auf 390/1280 px;
zusaetzliche Vier-Serien-Grafik zwischen bestehender Grafik und beibehaltener
Tabelle, PWM-/Heizungsstufen, Simulationslinien, Luecken, aktuelle PWM statt
Drehzahl/Zielwert, An/Aus und fehlende/veraltete/ungueltige Daten/HTML-Escaping.

Noch offen vor Aktivierung: echte Relaispolaritaet/Ausfallpegel, Luefteranlauf bei
100 Hz und zwei Sekunden Boost, reale Grenztemperaturen/Heizleistung, externe
Thermosicherung, DHT-Fehlerquote mit laufender PWM und Capture-Stromversorgung.
# Manueller Festbetrieb (06.10.2026)

Auf ausdruecklichen Betreiberauftrag fuer DK0RE/Stromberg unterstuetzt die
Konfiguration `mode=manual80`: Heizung dauerhaft AN und Luefter80%, ohne
Abhaengigkeit von DHT22-Messungen. Keine erfundenen Sensorwerte. Sensorabfrage
kann in device.conf deaktiviert bleiben. Admin und Status bezeichnen den
Modus als Festbetrieb; die Automatikparameter wirken erst wieder in auto.

Vorhandener Anlaufimpuls:2s100%, dann80%. Vor Aufnahme bestaetigtes AUS fuer
Heizung/Luefter, danach wieder Festwert. Aus und technische Fehler haben
Vorrang; native und systemd-Watchdogs bleiben erhalten. In diesem Modus
braucht eine ausdrueckliche Fehlerquittierung kein DHT-Messpaar. Bestehende
Automatikinstallationen wechseln nicht selbsttaetig in den Festbetrieb.
Tests: tests/climate-manual.php und tests/climate-manual-daemon.py (Attrappen).
