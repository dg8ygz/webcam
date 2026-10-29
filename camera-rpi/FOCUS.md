# gphoto2-Fokus, lokal und remote

## 05.10.2026: optionale elektrische AF/MF-Freigabe für DK0RE

Betreiber erklärt die historische EOS10D-Schaltung: Das Objektiv bleibt
mechanisch eingekoppelt, wird für normale Aufnahmen aber elektrisch auf
manuell gestellt. Dadurch erfolgt insbesondere nachts keine unerwünschte
Neufokussierung vor jeder Aufnahme. Fokus wird nur ausdrücklich freigegeben.
Die Aussage zur damaligen EOS10D beschreibt die Historie, keine neu geprüfte
PTP-Eigenschaft der heute angeschlossenen Kamera.

Lesender Befund auf `webcam.webcam.example.invalid`: `webcam_focus` schaltet
wiringPi23 (BCM13, physischer Pin33) vor Auto- und manuellem Fokus HIGH und
danach LOW; `rc.local` richtet ihn als Ausgang ein. `webcam_focus2` enthält
keine GPIO-Schaltung. EXIF des DEV-Slots 05.10.2026 20:30 nennt Canon EOS2000D,
EF-S18–55mm f/3.5–5.6 III, 43mm/f5. Der vom Kamerabody gespeicherte EXIF-Zeitstempel
weicht vom Serverslot ab und wird nicht als aktuelle Kamerauhr-Abnahme gewertet.

Implementiert, **noch nicht installiert oder an der echten Kamera getestet**:

- Geräteweites optionales Feld `focus` im GPIO-Profil/Admin; Standard `null`.
  Für die geplante Stromberg-Migration BCM13, gemeinsam auf Doppelbelegung
  mit DHT4, Heizung5, Lüfter27 und Kamerastrom6 geprüft.
- Eigener nativer Pin-Eigentümer startet LOW. Root-exklusiver RAM-Socket;
  HIGH nur innerhalb des bestehenden gesperrten Fokusauftrags, auch für
  manuelle Fokusbewegungen. Rückkehr auf LOW nach Erfolg, Abfragefehler,
  gphoto-Fehler und Timeout; Fehler beim Rückstellen bedeutet Auftrag fehlgeschlagen.
- Normaler gphoto2-Aufnahmeweg fordert ausschließlich LOW an und wartet
  auf Bestätigung. Kein Autofokus, keine Fokus-Abfrage oder Liveview-Vorschau
  vor der normalen Aufnahme. Fehlender konfigurierter Eigentümer sperrt USB.
- Unabhängige native HIGH-Frist 60s, danach LOW auch bei verlorenem Worker.
  ON/STATUS verlängern keine laufende Frist. SIGTERM stellt LOW her. Ein
  SIGKILL des Eigentümers oder Stromverlust benötigt elektrisch sicheren
  Ruhezustand durch die vorhandene Beschaltung, nicht nur einen Software-Trap.
- Bestehende Konfigurationen ohne Fokusleitung erhalten keine GPIO-Freigabe.
  Speichern bleibt ohne Live-Umschaltung; verwendet wird nur der Boot-Snapshot.

Vor Migration: GPIO-Bootstack samt neuem Schema, Common-Helfer, PHP-Brücke,
neu gebautem `camera-rpi-pwm`, Fokus-Unit und Worker-Abhängigkeit zusammen
installieren. Manifest `ops/gpio-install-files.tsv` führt die Bestandteile;
der Legacy-Erstinstaller enthält den Fokushelfer/die Unit nur als inaktive
Vorbereitung, **nicht** als abgeschlossene GPIO-Einrichtung. Die bisherigen
hostgebundenen Update-/Cutover-Skripte nicht blind auf DK0RE anwenden.
Alte `webcam_wait`-/Cron-/GPIO-/fan.py-Eigentümer müssen beim späteren Cutover
koordiniert abgelöst werden. BCM13 niemals gleichzeitig mit WiringPi und
dem neuen nativen Dienst bedienen. Nachtrag06.10.: Neue ISOEXP-Verarbeitung setzt ganze EV-Werte direkt,
keine Modellindices mehr. Auf .17 bereits gezielt ausgerollt; Strombergs
alter Helfer nutzt weiterhin die korrekte Index15-Zuordnung fuer0EV.

Nachtrag 06.10., erster DK0RE-Cutover: Fokus-Socket beim sofortigen ersten
Aufruf nicht erreichbar, Rueckweg ausgefuehrt. Fokus-/Power-Units erhalten
ExecStartPost mit bounded STATUS-Abfrage; erst bestaetigtes OFF/ON gibt
abhaengige Dienste frei. `tests/gpio-readiness.py` prueft verzoegerten Start,
fehlende/falsche Antworten und optionale Pins ohne Hardware. Reale Aktivierung
dieser Korrektur noch ausstehend, kein Autofokuslauf zur Abnahme.

Prüfungen ohne echte Hardware: `python3 tests/focus-gpio.py` kompiliert einen
expliziten Test-Build mit 1s statt 60s Frist und prüft Socket-Protokoll,
hängenden Client, fehlende Verlängerung, SIGTERM, den echten Shell-Fokusweg
mit Kameraattrappe, Fehlerrückstellung und LOW vor Aufnahme. Ergänzend:
`bash tests/focus-capabilities`, `bash tests/focus`, `bash tests/eos-legacy`,
`bash tests/climate-native`, GPIO-Konfigurations-/Speicher-/Boardtests und
`node tests/gpio-ui.cjs`. Keine reale Fokusbewegung, Aufnahme oder Umstellung.

## Lokale Bedienung: einmal pro Kamera

Der lokale Kamera-Admin zeigt einen eigenen Bereich **Kamerafokus** ausserhalb
der Serverkarten, mit genau einmal `--`, `-`, `auto`, `+`, `++`. Es gibt keine
separate Fokusstellung je Uploadziel: Alle Ziele sehen dasselbe Objektiv.
Bei einer V4L2-Quelle wird der gesamte Fokusbereich ausgeblendet, nicht nur
deaktiviert. Direkte Fokus-POSTs bleiben auch dann serverseitig abgewiesen.

Die Oberfläche sendet keine Ziel-ID. Beim POST bestimmt PHP das gemeinsame
Quellprofil aus der aktuellen Serverliste; ohne Pool ist nur ein einzelnes
Ziel eindeutig. Bei widersprüchlichen/fehlenden Quellen oder ohne aktiven
gphoto2-Worker bleiben die Buttons gesperrt und direkte POSTs werden ebenfalls
abgelehnt. Ein nachträglich eingeschleustes `server_id` wird nicht verwendet.
Auch beim Bearbeiten eines anderen Servers bleibt die Kameraquelle dieselbe.

Intern bleibt die bestehende asynchrone `focus.request`-Queue des Quellworkers
erhalten. Die Root-Brücke prüft Profil, Modus, Worker und Warteschlange erneut.
Keine Fokusbewegung im HTTP-Request; Kamerasperre und einmalige Ausführung
bleiben unverändert. Remote-Fokusaufträge des Webcamservers sind davon
unberührt. UI-Tests verwenden nur eine Attrappe. Die Umstellung dieses
Bedienbereichs ist **keine optische Abnahme** und ändert keinen gphoto2-Ablauf.

## Kameraeigenschaften abfragen, ohne den Fokus zu veraendern

Nachtrag 13.09.2026: auf ausdrueckliche Betreiberanweisung **keine Bewegung**,
keine Liveview-Vorschau und keine Testaufnahme. Auf `.16` ausschliesslich
`--list-config` sowie `--get-config` unter operation.lock und Zeitlimit gelesen.
Die Kamera meldet `output` als schreibbares RADIO mit `PC`/`Off`, `evfmode`
als RADIO mit Wert `1`, `viewfinder` als TOGGLE und **kein** `eosviewfinder`.
`manualfocusdrive` bietet Near 1/2/3 und Far 1/2/3; `autofocusdrive` ist ein
TOGGLE. Beobachtet: output Off, evfmode 1, viewfinder 0, Fokusmodus One Shot.
Diese Abfrage bestaetigt angebotene Eigenschaften, nicht optische Schaerfe.

Der neue lokale Code fragt diese Eigenschaften erst beim Verarbeiten eines
ausdruecklichen Fokusauftrags ab. Aufnahmen, Uploads, Workerstart und reine
Konfigurationspruefungen fuehren **keine** Fokus-Erkennung oder Bewegung aus.
Stand dieser Korrektur: isoliert getestet, **noch nicht auf `.16` ausgerollt**;
die reale Abnahme wird bis zu guter Sicht und erneuter Freigabe zurueckgestellt.
Die bestehende gute Nachtfokussierung darf dafuer nicht versuchsweise geaendert
werden. Auch `--capture-preview` gehoert nicht zu einer rein lesenden Pruefung.

Grundlage neben der tatsaechlichen Abfrage: [gphoto-Dokumentation zu Canon
Liveview, PC-Ausgabe und Near/Far-Werten](https://gphoto.sourceforge.io/doc/remote/).
Die Verfuegbarkeit dieser Funktionen ist dort ausdruecklich modellabhaengig.

## Reale Bielefeld-Korrektur am 13.09.2026

Auf `.16` (Canon EOS 1500D/2000D, EF-S18-55, libgphoto2 2.5.30) hat
`autofocusdrive=1` nachts zwar Exit 0 geliefert, das 00:50-Kontrollbild war
aber staerker defokussiert. Exit 0 ist kein optischer Schaerfenachweis.
Die Kamera bietet `output` mit `PC`/`Off`, **kein** `eosviewfinder`.
Fokuskorrektur unter der gemeinsamen operation.lock: `evfmode=1`, `output=PC`,
Liveview-Vorschau initialisieren, manuell in Richtung Far bewegen und jeweils
an der Vorschau pruefen; zum Abschluss `output=Off`. Vier bestaetigte Far-3-
Schritte nach den kleinen Versuchen stellten die Schaerfe wieder her.
Das ist ein Diagnoseergebnis, keine feste Schrittzahl fuer andere Situationen.

Manuell ausgeloestes Vollbild 1789253705, Aufnahme 00:55:09–00:56:17,
ist sichtbar deutlich schaerfer; Legacy-Upload um 00:56:30 bestaetigt.
Auch das regulaere Poolbild 1789254003 um 01:00 behaelt die Schaerfe.
Keine Autofokuswiederholung beim Upload oder
naechsten regulaeren Bild. Die allgemeinen Admin-Buttons unten verwenden noch
auf der Kamera noch den alten `eosviewfinder`-Pfad. Die spaetere lokale
Codekorrektur ist oben beschrieben und noch nicht installiert. Nicht behaupten,
diese Live-Korrektur habe den Button-Code bereits geaendert. Kein Reboot fuer
die Fokuskorrektur erforderlich.

Stand 11.09.2026. Buttons `--`, `-`, `auto`, `+`, `++` im lokalen
`/camera-admin/` und im Kameraeditor des aktualisierten Webcamservers.
Die aktuelle USB/V4L2-Testkamera kann damit nicht fokussiert werden.
Kein automatischer Wechsel zu gphoto2 und keine Aenderung von Ziel/Key/Timeout.

Betreiberentscheidung 12.09.2026: Die reale gphoto2-/Objektiv-Abnahme ist
bis zur Verfuegbarkeit der endgueltigen Kamera **zurueckgestellt**. Die
Heizungsregelung wurde anschliessend separat anhand des bereitgestellten
`fan.py` beauftragt und im Quellstand integriert: [CLIMATE.md](CLIMATE.md).
Belegung BCM5/27 bestaetigt; Installation standardmaessig Aus. Die reale
Relais-/Luefter-/Thermoschutz-Abnahme bleibt vor Aktivierung erforderlich und
ersetzt nicht die weiter zurueckgestellte gphoto2-/Objektiv-Abnahme.

Der lokale Admin merkt fuer den automatisch bestimmten Quellworker einen Auftrag unter
`/run/camera-rpi/<server-id>/focus.request` vor. Der zentrale Admin sendet
`POST /api/admin/cameras/<slug>/focus` mit JSON `{"mode":"++"}`,
bestehender Admin-Authentifizierung und CSRF-Schutz. Der aktualisierte Pi
meldet beim authentifizierten Poll `X-Camera-Focus-Protocol: camera-rpi-focus-v1`
nur fuer gphoto2, fuer V4L2 `none`. Ohne aktuelle Anmeldung bleibt der zentrale
Button gesperrt; Webseite nach dem ersten Poll neu laden.

Der Server liefert beim naechsten Poll exakt drei Zeilen:

```text
focus
1789128000
++
```

Die letzte Zeile enthaelt ausschliesslich den Modus (im Beispiel `++`).
Zeitstempel ist die Unixzeit der Zustellung. Der Pi
interpretiert keine Shellbefehle. Fokus verbraucht keinen Capture-Slot;
die Aufnahme kann im folgenden Poll weiterhin angefordert werden.

Ein Auftrag pro Ziel, zwei Sekunden Eingabepause, 15 Minuten Server-Ablauf,
30 Minuten Gueltigkeit der Helper-Anmeldung. Lokale/empfangene Auftraege
unterliegen zusaetzlich dem konfigurierten Alterslimit (Standard 900s).
Bei laufendem Long-Poll kann eine lokale Aktion bis zu dessen Timeout warten
(hier 700 Sekunden). Alter PROD-Server `.14` implementiert die neue Remote-
Queue nicht; sein Legacy-Capture-Vertrag bleibt erhalten.

## Ausfuehrung und Grenzen

Beide Bedienwege verwenden dieselbe private Pi-Queue und dieselbe physische
Kamerasperre wie Captures. Fokus schreibt nur nach `/run`, ohne Root-Remount.
Gphoto2 bekommt feste Argumente, je Abfrage maximal 5 Sekunden (hoechstens
vier), fuer Initialisierung/Bewegung maximal 15 Sekunden und fuer das Verlassen
von Liveview weitere 5 Sekunden. Jeder Prozess bekommt hoechstens
zwei Sekunden Kill-Nachfrist. Bei belegter Kamerasperre bleibt der Auftrag
bis zur naechsten Runde vorgemerkt. Keine parallele Aufnahme/Fokusbewegung.

| Modus | gphoto2 |
|---|---|
| auto | `autofocusdrive=1`, `--wait-event=2s` |
| - | `--set-config-value manualfocusdrive="Near 1"` |
| -- | `--set-config-value manualfocusdrive="Near 2"` |
| + | `--set-config-value manualfocusdrive="Far 1"` |
| ++ | `--set-config-value manualfocusdrive="Far 2"` |

Die benannten Werte entsprechen auf `.16` den bisherigen Indizes 0/1/4/5,
haengen aber nicht mehr von deren Reihenfolge ab. Jeder benoetigte Wert und die
Schreibbarkeit werden vorher geprueft. Bei PC/Off-Unterstuetzung:
`evfmode=1`, `output=PC`, Liveview-Vorschau initialisieren, genau eine angeforderte
Fokusaktion. Vorschau nur nach `/dev/null`, keine Datei, kein Ausloesen des
Verschlusses und kein Poolbild. Anschliessend separat `output=Off`, auch nach
Fehler/Timeout, solange Prozess und Kamera noch reagieren. SIGKILL/Stromverlust
koennen keine erfolgreiche Rueckstellung garantieren.

Ohne `output` wird nur ein tatsaechlich gemeldetes TOGGLE `viewfinder`, ersatzweise
`eosviewfinder`, verwendet und anschliessend wieder ausgeschaltet. Unbekanntes
`output`-Format, fehlender Fokuswert oder fehlerhafte Abfrage brechen **vor**
Schreibbefehlen ab. Kein spekulativer Backend-Wechsel nach einem Fehler und
kein Autofokus als Ersatz fuer einen fehlgeschlagenen manuellen Schritt.
Auch `auto` wird nur bei ausdruecklicher Auswahl ausgefuehrt; Erfolgsmeldung
bedeutet weiterhin keinen optischen Schaerfenachweis.

Ein Bewegungsversuch wird vor dem Start aus der Queue genommen. Bei Fehler,
Timeout oder Absturz wird er nicht automatisch wiederholt: Eine teilweise
ausgefuehrte relative Bewegung darf nicht verdoppelt werden. Die zentrale
Meldung **vorgemerkt** ist keine Ausfuehrungsbestaetigung; Transportverlust
nach Zustellung kann den Auftrag verlieren. Im Pi-Journal stehen Beginn und
Ergebnis, ausserdem `/run/camera-rpi/<server-id>/focus.status` mit Modus,
Zeit und Exitcode. Diese Daten sind fluechtig. Zwei verschiedene Zielserver
bedienen zwar getrennte Queues, aber dasselbe Objektiv: dessen Fokus gilt
anschliessend fuer alle Aufnahmen dieses Pi.

## Update

Am 11.09.2026 gegen 17:00 CEST (16:00 BST) durch den Betreiber auf dem neuen
Pi erfolgreich aktiviert. Sicherung: `/root/camera-rpi-focus-before.9wQKibZv`.
Konfigurationen `dev-test` und `test` sowie Apache-Syntax geprueft; keine
Fokusbewegung oder Testaufnahme ausgeloest. AH00558 bleibt eine ServerName-
Warnung. Die echte gphoto2-/Objektiv-Abnahme ist weiterhin offen.

`ops/update-focus.sh` ist ein gezieltes Root-Update fuer den am 11.09. geprueften
Pi-Stand. Paketmanifest und alte Worker-/Common-/Web-Hashes werden geprueft,
die fuenf ersetzten Dateien vorher unter `/root/camera-rpi-focus-before.*`
gesichert. Nur zuvor aktive Worker werden gestoppt/gestartet, Apache nur
neu geladen. Root muss fuer die Installation schreibbar sein. Keine Keys,
Zielkonfiguration, Logs, Sensoren oder Upload-Zeitlimits werden veraendert.
Automatischer Rueckweg bei Installationsfehlern; manueller Rueckweg: Worker
stoppen, genau diese fuenf Dateien aus der Sicherung an ihre im Script
genannten Pfade zurueckkopieren, vorher aktive Worker wieder starten.

Verifikation: `bash tests/focus`, `node tests/focus-ui.cjs` mit vorbereitetem
Playwright. Keine realen Fokusbefehle im Test. Der moderne Capture-Zeitstempel
wird jetzt vor dem historischen Textadapter geprueft und beibehalten;
der bestehende Capture-Retry-Test deckt den zuvor verlorenen Auftrag ab.

`bash tests/focus-capabilities` prueft den echten Shell-Helfer ausschliesslich
mit einer Kameraattrappe: PC/Off, viewfinder, historischer Alias, verschobene
Radio-Indizes, Abfragefehler, fehlende Werte, Schutz vor unangefordertem Autofokus,
Initialisierungsreihenfolge und Rueckstellung nach Fehler/Timeout. Der bestehende
Worker-Test deckt lokale/remote Auftraege, Replay und V4L2-Ablehnung ab.
Keiner dieser Tests ist ein Hardware- oder optischer Funktionstest.
