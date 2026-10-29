# Canon EOS: bewährte Bielefeld-Aufnahmesequenz

## Anlass und Stand

Am 12.09.2026 scheiterte auf `.16` (Pi 2, Bookworm/armhf, Canon EOS 1500D,
gphoto2 2.5.28/libgphoto2 2.5.30) der allgemeine
`--capture-image-and-download`-Test mit `PTP Device Busy`. Der Betreiber
bestätigte anschließend eine erfolgreiche Aufnahme mit dem alten
`webcam_capture`. Die Ursache des allgemeinen Capture-Fehlers ist damit
nicht abschließend bewiesen; das neue Profil übernimmt den funktionierenden
Auslöseweg, statt auf diesen allgemeinen Capture-Aufruf auszuweichen.

Nach erfolgreichem Einzeltest wurde die neue Steuerung auf `.16` aktiviert.
Der Worker scheiterte anschließend wiederholt nach etwa 87 Sekunden. Der
Betreiber hatte ihn für die Diagnose gestoppt/deaktiviert; das alte Capture-Skript
funktionierte weiterhin. Nach den unten beschriebenen Korrekturen hat der
Betreiber den Worker erneut gestartet. Kein automatisches Aktivieren oder
paralleler Test!

### Unterschied Einzeltest / systemd-Worker (12.09.2026)

Das alte Skript arbeitet nach `cd /tmp`, der erfolgreiche Einzeltest ebenfalls
in einem beschreibbaren RAM-Verzeichnis. Der Worker hat kein WorkingDirectory
und erbt von systemd `/`. Bei RAM-Spool bleibt Root absichtlich read-only.
gphoto2 2.5.28 legt in `save_file_to_file()` dennoch `tmpfileXXXXXX` im CWD
an, nicht neben dem absoluten `--filename`. Bei EROFS weicht es auf einen
Speicherpuffer aus; `save_camera_file_to_file()` schreibt diesen Pfad in diesem
Quellstand nicht in die Zieldatei. Damit fehlen Datei und gültiger Download-Hook.

Der EOS-Helfer wechselt jetzt vor gphoto2 in das private Zielverzeichnis im
RAM-Spool. Auslösesequenz, Dateiname einschließlich `.jpg.part`, USB-Auswahl,
Zeitlimits und Klimasperre bleiben unverändert. Ein Regressionstest startet
aus `/proc` (auch für root nicht für temporäre Dateien beschreibbar) und bildet
die CWD-Abhängigkeit nach: vor der Korrektur Fehler, danach JPEG/Hook-Erfolg.
Dies ist ein reproduzierbarer Integrationsfehler. Der reale Worker-Capture
und Upload wurden anschließend bestätigt; die Aufnahme 22:10 wurde trotz
Uploadabschluss 22:11:20 korrekt als `2210_*.jpg`/`2210_ex.txt` archiviert.
Das ist noch keine vollständige RO-/Dauerbetriebsabnahme.

Zusätzlich bleibt der letzte Aufnahmefehler unter
`/run/camera-rpi/SERVER-ID/capture.last-error` erhalten: Zeit, Auftrag,
Backend-Exitcode und maximal 64 KiB Aufnahmedetails, root/0600, RAM-only.
Erfolgreiche Wiederholungen löschen diese historische Diagnose nicht;
Reboot löscht sie. Keine Keys oder Curl-Traces werden dort gesammelt.

### Legacy-Uploadbestätigung nach erfolgreicher Aufnahme

Nach dem CWD-Update nahm der echte Worker am 12.09.2026 von 21:23:25 bis
21:24:33 ein Bild auf. Der alte Server verarbeitete die 1.679.100 Bytes und
meldete `state=ready`, während der Worker erneut hochlud: Die Serverantwort
`webcam_isoexp 0 2` ist kein Fehler, sondern der ausgegebene Folgeauftrag zur
ISO-/Belichtungskorrektur. Der Betreiber bestätigte dieselbe Aufnahme im Archiv
unter 21:24, :25, :26 und :27. Diese Doppeluploads sind keine neuen Aufnahmen.

Die Legacy-Uploadauswertung akzeptiert neben bisherigen leeren 2xx-/JSON-
Erfolgsantworten jetzt bei **HTTP 200 und erfolgreichem curl** genau eine
begrenzte `webcam_isoexp`-Datenzeile. Maximal 128 Bytes; ISO `0`, `Auto` oder
positive Ganzzahl bis 102400, optional Belichtungsstufe 0–5. Keine weiteren
Zeilen, Shell-Anhängsel, HTML, Fehlermeldungen, Binärdaten oder HTTP-Fehler.
Unbekannte Antworten behalten das Bild im Spool; nur die bestätigte Kopie
dieses Zielservers wird entfernt. Lokale Vorschau/andere Server bleiben erhalten.

Die Zeile wird nur als bekanntes Feedback nach der Bildverarbeitung erkannt
und nie als Shell ausgeführt. Die getrennte ISO-/Belichtungssteuerung wird
erst über den Command-Poll ausgelöst (siehe unten).

### Alter Command-Poll und zusätzliche Leerzeile

Das vom Betreiber bereitgestellte `command.php` liest `command.txt` und gibt
`echo("command\n$cmd\n")` aus. Endet die Datei bereits mit LF, entsteht eine
zusätzliche leere Schlusszeile. Der Parser entfernt jetzt ausschließlich leere
Schlusszeilen (auch CRLF), keine inneren Zeilen oder Leerzeichen-Zeilen.
Strukturierte Command-Frames müssen danach genau drei Zeilen mit gültiger
Epoch und einem vollständig bekannten Befehl enthalten. Capture mit und ohne
`YYYYMMDD_HHMM` wird erkannt, die Server-Epoch bleibt erhalten. Zusatzbefehle,
Shell-Anhängsel und Binär-/Steuerzeichen werden abgelehnt. Der separate
Fokusvertrag bleibt unverändert strikt auf drei Zeilen begrenzt.

Ein gültiger `webcam_isoexp`-Command wird nur bei `capture_backend=gphoto2`
und `gphoto_capture_mode=eos-legacy` in die eigene ISO-/Belichtungswarteschlange
übernommen. Andere Profile melden ihn als nicht unterstützt unter
`/run/camera-rpi/SERVER-ID/legacy-isoexp.status` (0600, RAM).
Für diesen Befehl wird nie ein Capture-Auftrag angelegt.
Der Server löscht `command.txt` bereits beim Ausliefern, ohne Ausführungs-ACK.

Die vermeintlichen „120 Sekunden“ im Serverkommentar sind in diesem Quellstand
120 Schleifendurchläufe mit je 500 ms, also ungefähr **60 Sekunden** plus Arbeit.
Ein regulärer leerer HTTP-Erfolg bedeutet weiter „warten“; ein beliebiger
Transportfehler wird durch die Zeilenendekorrektur nicht zum Erfolg.

### Archivzeit: `now` ist nicht die Auftragsnummer

Der Betreiber meldete: Der Auftrag 21:50 wurde im Archiv als 21:51 gespeichert.
Direkt auf `.16` gelesenes Original `/usr/local/bin/webcam_capture` setzt
`now=$1` und sendet `--form now=$now`. Bei `webcam_capture 20260912_2150`
ist der Uploadwert folglich **`now=20260912_2150`**, nicht die Epoch-Zahl der
zweiten Command-Zeile und auch nicht der Abschlusszeitpunkt der Aufnahme.
Die bis dahin bereitgestellte Zeilenendekorrektur behielt zwar die Epoch,
verwarf aber weiterhin dieses eigentliche Archivdatum: unvollständiger Fix.

Der Worker bewahrt jetzt beide Werte getrennt: Epoch für Auftrags-ID,
Plausibilitätsprüfung und Spoolnamen; Kalenderstempel für den Legacy-Upload.
Auf zusätzliche Betreiberanweisung wird bei automatischen Legacy-Aufträgen
die Minute einmalig auf den vollen Zehnminuten-Slot abgerundet:
`20260912_2158 -> 20260912_2150`, `20260912_2209 -> 20260912_2200`.
Datum/Stunde und Serverzeitzone bleiben erhalten; keine Rundung anhand der
lokalen Pi-Zeit, keine Rundung manueller Aufträge oder moderner Epoch-Commands.
Danach bleibt dieser Uploadstempel unverändert. Er steht als zweite Zeile im
RAM-/Spool-Auftrag und vor JPEG-Veröffentlichung in `EPOCH.jpg.legacy-stamp`
(0600, neben dem Bild im jeweiligen Zielspool). Upload-Retry und Worker-Neustart
lesen ihn daraus; keine Neuberechnung anhand von Ortszeit, EXIF oder Uploadzeit.
Die Rundung erfolgt in der vom Server bereits vorgegebenen Kalenderzeit,
also nach dessen `captureOffset`, ohne weitere Zeitzonenverschiebung.
Beschädigte vorhandene Metadaten blockieren den Upload, statt stillschweigend
mit einer falschen Zeit fortzufahren. Nach Bestätigung wird nur der eigene
Bild-/Wetter-/Datums-Satz entfernt; andere Ziele und Vorschau bleiben erhalten.

Neue Serveraufträge ohne Kalenderargument und manuelle Aufträge behalten das
bisherige Epoch-Format; Chunk-v1 bleibt unverändert numerisch. Bei bereits vor
diesem Fix aufgenommenen Spoolbildern fehlt das Originalargument und wird nicht
erraten. Bereits falsch abgelegte Archivbilder werden hier nicht umbenannt.
Der Datenpfad ist mit simulierten Gegenstellen inklusive Prozessneustart und
abweichender Pi-Zeitzone geprüft; der echte Legacy-Archivslot wurde danach durch
den Betreiber für den 22:10-Auftrag bestätigt (`2210_*`, Uploadende 22:11:20).

### ISO-/Belichtungsauftrag aus dem Command-Poll

Grundlage ist das vom Betreiber bereitgestellte `webcam_isoexp`, nicht eine
allgemeine Annahme über Kameramodelle. Nur im EOS-Legacy-Profil:

| Eingabe | gphoto2-Argument |
| --- | --- |
| ISO `0` | kein ISO-Argument, Wert bleibt unverändert |
| ISO `Auto` oder positive ISO-Zahl bis 102400 | `--set-config-value iso=WERT` |
| Stufe fehlt | keine Belichtungsänderung |
| Stufe `0 / 1 / 2 / 3 / 4 / 5` | `--set-config-index exposurecompensation=14 / 17 / 20 / 23 / 26 / 29` |

Die auskommentierte alternative EOS1100D-Tabelle wird nicht verwendet.
`/usr/local/bin/gphoto2` aus der Vorlage existiert auf `.16` nicht; verwendet
wird das dort vorhandene `/usr/bin/gphoto2`, wie bei der Aufnahme.
Es werden feste Argumentarrays gebaut, niemals der gelieferte Programmtext
ausgeführt. Beispiel `webcam_isoexp 0 2` setzt nur Belichtungsindex 20.
Beide Werte unverändert (`webcam_isoexp 0`) ist ein No-op ohne USB-Aufruf.

Ein frischer Command mit gültiger Epoch wird atomar im privaten RAM unter
`/run/camera-rpi/SERVER-ID/isoexp.request` vorgemerkt. Ein Konflikt auf der
globalen Capture-/Fokussperre erhält ihn. Vor USB wird die Epoch in `isoexp.last`
als versucht markiert; derselbe oder ein älterer Auftrag wird nach einem
Worker-Neustart nicht wiederholt. Reboot leert diese RAM-Merkdaten; alte
`legacy-isoexp.status`-Hinweise werden nicht nachträglich ausgeführt.

Der gphoto-Aufruf hat 15 Sekunden plus 2 Sekunden Kill-Frist. Nach dem Setzen
werden ISO und Belichtung in derselben Verbindung ausgelesen; die Ausgabe
bleibt mit Auftragsnummer begrenzt auf 64 KiB in `isoexp.output` (0600, RAM).
`isoexp.status` enthält Auftrag, ISO, Stufe, Exitcode und applying/completed/failed.
Completed bedeutet erfolgreichen gphoto-Aufruf, noch keine photometrische
Abnahme der nächsten Aufnahme. Ein bei Abbruch verbliebenes applying bedeutet
unbekannten Ausgang, nicht Erfolg. Bei Fehler ist auch eine teilweise erfolgte
Konfigurationsänderung möglich; keine automatische Endloswiederholung.
Nur ein neuer Serverauftrag löst einen weiteren Versuch aus.

Kein Root-Remount, kein Shutter, keine GPIO-/Heizungs-/Lüfteraktion dabei.
Die ursprüngliche Uploadantwort ist weiterhin nur ACK; erst der separat
abgefragte Command darf die Einstellung ändern.

Echter Lauf am 12.09.2026 nach Betreiberinstallation um 22:31:33 bestätigt:
Auftrag 22:40:00, JPEG fertig 22:41:08, Upload bestätigt 22:41:21.
Anschließend meldet das Betreiberjournal den ISO-Auftrag `1789245669`
um 22:41:28 als erfolgreich ausgeführt: ISO unverändert (`0`), Stufe `2`,
Index `20`. Der konkrete Rücklesewert aus `isoexp.output`, die photometrische
Wirkung auf das folgende Bild und längerer Nacht-/Tagbetrieb sind damit noch
nicht bestätigt. Boot-Autostart bleibt bis zur Betreiberfreigabe deaktiviert;
`systemctl start` allein aktiviert ihn nicht.

## Profil und Ablauf

```ini
capture_backend=gphoto2
gphoto_capture_mode=eos-legacy
gphoto_timeout_seconds=120
```

Ohne Feld bleibt `standard` aktiv. Im lokalen Kamera-Admin gibt es die Auswahl
„gphoto2-Aufnahmesequenz“. Andere Einstellungen, Fokus und Uploadverfahren
bleiben unabhängig davon. Ein alter Admin-Client ohne das Feld erhält das
gespeicherte Profil; ungültige Werte werden abgelehnt. V4L2 ignoriert es.

`scripts/lib/camera-rpi-eos-legacy` führt ausschließlich die Aufnahme aus:

1. `--set-config-value "eosremoterelease=Release Half"`, fünf Sekunden Kameraereignisse abwarten.
2. Benanntes `Immediate`, unmittelbar danach `Release Full`, erst dann
   `--capture-tethered=80s` mit Download-Hook. Diese Freigabe entspricht dem
   [libgphoto2-Beispiel best-iso.c](https://github.com/gphoto/libgphoto2/blob/v2.5.31/examples/best-iso.c).
   Kein zusätzlicher Autofokus-Befehl.
3. Nach vollständig gespeicherter JPEG-Datei zwei Sekunden warten und **nur
   den eigenen gphoto-Prozess** beenden. Kein globales `killall`, kein fremder
   Kameraprozess, keine alten `cap*.jpg`-Dateien werden angefasst.
4. Auch bei Fehler oder TERM/Timeout: eigenen Prozess beenden/abwarten und
   benanntes `Release Full` unter einem separaten 10+2-Sekunden-Limit senden.

Ein Exit 137 allein bedeutet ausdrücklich **keinen** Erfolg: passender
Download-Hook, private Bestätigung, Dateigröße und JPEG-Rahmen müssen stimmen.
Fehlende/ungültige Datei, Timeout oder gescheiterte Rückstellung bleiben Fehler.
Der Worker behält dann seinen Auftrag für einen Wiederholungsversuch.
Die vorhandenen gemeinsamen Kamera- und Klimapausensperren bleiben erhalten.
Das äußere Aufnahme-Limit erhält 15 Sekunden Rückstellfrist (bei 120 also
höchstens regulär 135 Sekunden; ein ununterbrechbarer Kernel-I/O kann auch
durch SIGKILL nicht sofort beendet werden).

Das Profil setzt wie der Altbetrieb **JPEG-Ausgabe** der Kamera voraus.
RAW/RAW+JPEG und Serienaufnahme sind nicht abgenommen. Die Kamerakonfiguration
wird dafür nicht automatisch umgestellt. `--keep` vermeidet das Löschen auf der
Kamera; beim aktuellen Capture Target „Internal RAM“ ist dies **keine**
stromausfallsichere Kopie auf einer Kamerakarte.

Die Zuordnung der Release-Indices und der synchrone Download-Hook wurden im
passenden Upstream-Quellstand geprüft:
[libgphoto2 2.5.30](https://github.com/gphoto/libgphoto2/blob/libgphoto2-2_5_30-release/camlibs/ptp2/config.c),
[gphoto2 2.5.28 Dateiabschluss/Hook](https://github.com/gphoto/gphoto2/blob/gphoto2-2_5_28-release/gphoto2/main.c),
[Hook-Prozess](https://github.com/gphoto/gphoto2/blob/gphoto2-2_5_28-release/gphoto2/spawnve.c).
Der einzelne Hardwaretest und die oben dokumentierten Worker-Durchläufe
sind erfolgt; die vollständige Dauerbetriebsabnahme bleibt ein eigener Schritt.

## Nachbesserung nach Aktivierung auf .16

`bash ops/build-eos-workdir-package.sh` baut das separate, hashbenannte
Reparaturpaket. Auf `.16` als root `bash ops/update-eos-workdir.sh --apply`
aus dem verifizierten Paket ausführen. Voraussetzung: neuer Worker bereits
gestoppt und disabled. Sensor/Regler/Apache dürfen weiterlaufen.

Nur Worker, EOS-Helfer und diese Dokumentation werden mit privatem persistentem
Backup atomar ersetzt. Kein Dienst wird gestartet, aktiviert oder gestoppt;
keine Aufnahme, kein Upload, kein GPIO-Zugriff. Schlüssel, Zielkonfiguration
und vorhandene/wartende Bilder werden nicht geändert. Ein nötiges RW-Fenster
wird danach wieder RO geschlossen. Das Update nennt einen eigenständigen,
persistenten `--rollback`-Aufruf. Dieser prüft Host und Dateistand, bewahrt den
ersetzten Zwischenstand und lässt den Worker ebenfalls aus.

## Ursprüngliches inaktives Update auf .16

DEV: `bash ops/build-eos-package.sh` erstellt ein quelloffenes, hashbenanntes
Paket in `/tmp`, ohne Keys, Kamerabilder oder Laufzeitdaten.
Auf der Kamera in einen **frischen privaten** Ordner laden/entpacken, äußeren
SHA256 vergleichen, anschließend als root:

```sh
bash ops/update-eos-legacy.sh --check
bash ops/update-eos-legacy.sh --apply
```

Das Update verlangt den bereits installierten inaktiven neuen Bestand und die
vorbereitete `bielefeld-legacy.conf` mit `enabled=false`. Es sichert unter
`/var/backups/camera-rpi-eos-before.XXXXXXXX`, aktualisiert Bibliothek/Helfer,
Admin-Brücke/Formular und Dokumentation sowie ausschließlich das Aufnahmeprofil
in der neuen Bielefeld-Datei. **Kein Dienststart/-stopp, kein Apache-Reload, kein
Poll/Upload, kein GPIO, kein APT, kein alter Cron-/Scriptwechsel.** Der Key
bleibt unverändert auf der Kamera. Root wird nur bei Bedarf RW und danach RO.

Den ausgegebenen Rückweg verwenden; er liegt persistent im Backup und braucht
den Downloadordner nicht mehr. Er prüft Host/Prüfsummen, verlangt weiterhin
inaktive neue Dienste und verweigert stilles Überschreiben späterer Änderungen.
Beim Rückbau bleiben Zwischenstände zusätzlich gesichert, neu hinzugefügte
Dateien werden in den Backupordner verschoben. Keine Bilder/Keys werden gelöscht.
Vor dem Rückbau der ursprünglichen Erstinstallation zunächst dieses EOS-Update
zurücknehmen: deren altes Manifest kennt den neuen EOS-Helfer noch nicht.

## Späterer physischer Aufnahmetest

Erst nach bewusstem Anhalten von altem Cron/Poller und laufenden Kameraaktionen:

```sh
bash ops/test-legacy-capture.sh --check
bash ops/test-legacy-capture.sh --capture
```

Dieser Test verwendet den gleichen neuen EOS-Helfer, entdeckt den aktuellen
USB-Port der einen Canon 04a9:32e1 neu und schreibt nur in einen frischen
RAM-Ergebnisordner. Ein nach Reboot fehlendes `/tmp/raw.jpg` ist erlaubt;
ein vorhandenes Altbild wird unverändert geprüft. Kein Upload, keine Keys,
keine GPIO- oder Dienstaktion. Der Test umgeht nicht die Klimaprüfung eines
Workers, sondern ist ausdrücklich nur ein isolierter Aufnahme-/Downloadtest.

Der komplette neue Worker braucht bei vorhandener `climate.json` weiterhin
einen bestätigenden Klimadienst. IIO, Regler, alte Boot-GPIO-Zugriffe, Cron,
Webseite und Cutover-Rückweg sind **noch nicht** damit abgenommen oder aktiviert.

## Ausloeserfreigabe vom 14.10.2026

Die bisherige Folge wartete nach Immediate ohne Release Full auf das Bild und
sendete erst beim Aufraeumen Release Half. Der Helper gibt den vollen Ausloeser
nun vor dem Warten und nochmals beim Aufraeumen frei. Benannte Werte werden
unter LC_ALL=C verwendet; die numerische Menueauswahl ist kein PTP-Parameter.
Ausrollung und echte Aufnahme-/Upload-Abnahme fuer Steinhagen und Bielefeld:
Betriebsprotokoll (lokale Betriebsdokumentation, nicht im Export).
