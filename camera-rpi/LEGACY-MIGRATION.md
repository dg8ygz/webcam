# Bestandskamera: reversible Vorbereitung, noch keine Umschaltung

`ops/install-legacy-camera.sh` installiert die neue Software **inaktiv neben
dem Altbestand**. Es startet weder Worker noch Sensor/Regler, aktiviert keinen
Tunnel und veraendert die vorhandene Webseite nicht. Der laufende Altbetrieb
bleibt bestehen. Das ist noch kein automatischer Wechsel zur neuen Steuerung.

## Warum zwei Schritte?

Bei der lesenden Bestandsaufnahme am 12.09.2026 wurde auf `webcam-bfld` ein
Pi 2 Model B Rev 1.1 mit Bookworm/armhf, Kernel 6.1.21-v7+ und PHP 8.2.7
festgestellt. PHP ist dort 32-Bit; die benoetigten PHP-Funktionen sowie Compiler
und GPIO-Header sind vorhanden. Rund 745 MiB RAM waren verfuegbar, ohne Swap.
Das ist eine Momentaufnahme, keine Last-/Hardwareabnahme der neuen Software.

Der Altbestand unterscheidet sich in entscheidenden Punkten:

- `webcam_check` startet den freien `webcam_wait`-Prozess aus `/etc/crontab`
  jede Minute bei Bedarf neu. Nur einen Prozess zu stoppen reicht nicht.
- Canon-USB-Kamera; der alte Capture nutzt `eosremoterelease` und Tethering.
  Nachtrag: Standard-Capture scheiterte mit Device Busy; eigenes EOS-Legacy-
  Profil vorbereitet, siehe [EOS-LEGACY.md](EOS-LEGACY.md).
- `fancontrol.service` fuehrt `fan.py` aus: DHT22 an BCM4, Heizung LOW-aktiv
  an BCM5 und 100-Hz-Luefter-PWM an BCM27. Die Pinbelegung passt zur neuen
  Steuerung, deren physische Ansteuerung ist dort aber noch nicht abgenommen.
- Der Sensor verwendet Adafruit/RPi.GPIO. Es gibt aktuell kein IIO-DHT-Geraet.
  Den neuen IIO-Leser nicht parallel zum alten GPIO-Leser aktivieren.
- `rc.local` schaltet beim Boot Heizung/Luefter kurz um. Auch diese Zugriffe
  muessen vor einer Umschaltung gezielt abgeloest werden.
- RRD-Erzeugung/Diagramme, Wetterabruf, lokale Webseite und Bilder benutzen
  `/tmp`. `/tmp`, `/var/log` und `/var/tmp` sind bereits tmpfs. Die urspruenglichen
  RRD-Daten werden nicht in das neue TSV-Format umgerechnet.
- Root und `/boot` waren waehrend der Pruefung RW, `/boot/firmware` RO. Das
  Skript aendert Boot nicht und stellt nur ein selbst geoeffnetes Root-RW-Fenster
  wieder auf RO zurueck.

Bei dieser ersten Bestandsaufnahme konnte `analyse` Konfiguration/Programme
und Dateizeitstempel lesen, aber nicht das Systemjournal (keine adm-/systemd-
journal-Gruppen). Dabei wurde keine Aufnahme, GPIO-Aktion oder Dienst-/Treiber-/
Paket-/Konfigurationsaenderung ausgefuehrt. Das damals beobachtete JPEG war vom
12.09.2026 16:50, die RRD von 16:55 CEST. Installationsnachtrag siehe unten.
Ein kompletter Log-/Lueckencheck ist damit ausdruecklich nicht erbracht.

## Installation vorbereiten

Bei der anschliessenden produktiven Umschaltung muss der gemeinsame
Aufnahme-Pool eingerichtet und abgenommen werden, auch wenn zunaechst nur
ein Upload-Ziel existiert. Die private Datei
`/etc/camera-rpi/capture-pool.conf` (root:root, 0600) legt mit `source_server`
das gepruefte Kameraprofil fest; alle Ziele benutzen denselben tmpfs-Spool.
Die inaktive Erstinstallation allein aktiviert diesen Pool nicht.
Abnahme: genau eine gemeinsame Aufnahme, Verteilung desselben JPEGs und
unabhaengige Upload-Bestaetigungen aller aktivierten Ziele. Nur aktive Worker
oder erfolgreiche Einzeluploads reichen dafuer nicht. Siehe
[CAPTURE-POOL.md](CAPTURE-POOL.md).

Auf DEV erstellt `bash ops/build-legacy-package.sh` ein kleines, geprueftes
Paket unter `/tmp/camera-rpi-legacy-<HASH>.tar.gz` und gibt SHA256 aus.
Nur explizit aufgefuehrte Quelldateien werden verpackt, keine echten Keys.
Der Installer verlangt dieses Paket mit Dateimanifest `SHA256SUMS`.

Das Paket per SCP als normaler SSH-Benutzer in einen **neuen privaten Ordner**
kopieren; Archivhash mit dem auf DEV ausgegebenen Hash vergleichen. Nicht eine
gleichnamige fremde Datei direkt unter `/tmp` ueberschreiben. Root-SSH ist
nicht erforderlich. Danach in einer vorhandenen Root-/sudo-Sitzung:

```sh
bash ops/install-legacy-camera.sh --check
bash ops/install-legacy-camera.sh --install cameraadmin
```

`--check` prueft nur Voraussetzungen. `--install` fragt das neue HTTP-Passwort
verdeckt ab. Es erzeugt keine Zielserverdatei, importiert/rotiert keinen Upload-
Key und aktiviert weder die Admin-Seite noch einen neuen Dienst. Die Software
landet an ihren echten Sollpfaden, insbesondere Worker unter
`/usr/local/libexec/camera-rpi-worker` (nicht `/usr/local/bin`).

Fuer eine unbeaufsichtigte **inaktive** Vorbereitung ohne Passwortwahl:

```sh
bash ops/install-legacy-camera.sh --install-locked cameraadmin
```

Dieser Weg legt einen gesperrten HTTP-Benutzer mit unbrauchbarem Passwort-Hash
an. Es gibt kein Standardpasswort und keine Uebernahme des SSH-Passworts.
Vor der spaeteren, separat freizugebenden Web-Aktivierung setzt der Betreiber
mit `sudo htpasswd -B /etc/camera-rpi/admin.htpasswd cameraadmin` sein Passwort.
Alle anderen Erstinstallations-, Sicherungs- und Inaktivitaetsregeln gelten
unveraendert; ein vorhandener Admin-Zugang wird niemals ueberschrieben.

Es muss eine **Erstinstallation** sein: vorhandene Camera-RPi-Dateien,
Konfigurationen oder Daten fuehren zum Abbruch, nicht zum Ueberschreiben.
Die normale `install.sh` nicht ersatzweise fuer diese Bestandskamera starten:
sie hat andere Aktivierungsschritte. APT, OS-Upgrade, Boot-Konfiguration,
Low-write-Mounts und Dienstumschaltung gehoeren nicht zu dieser Transaktion.
Fehlende Pakete werden nur gemeldet.

## Vorhandenes Serverziel inaktiv uebernehmen

Ein vorhandenes Ziel kann nach inaktiver Installation mit
`bash ops/prepare-legacy-target.sh --apply TARGET-ID` vorbereitet werden.
Das gepruefte Paket muss den Importer, `ops/lib/legacy-install.sh` und ein
`SHA256SUMS`-Manifest enthalten. Der Import liest genau eine literale aktive
Zuweisung fuer `wc_name`, `wc_adr` und `wc_key` aus `/etc/webcam.cfg`, ohne die
Shell-Datei auszufuehren. `/include` wird von der Basisadresse entfernt.
Bestehende Zieldateien werden nicht ueberschrieben. Legacy-Upload, EOS-Legacy
und 700 Sekunden Poll-Timeout werden mit **enabled=false** vorbereitet;
keine Abfrage der Gegenstelle oder Hardwareabnahme. Der Key landet separat
unter `keys/TARGET-ID.key`, root:root/0600. Vorherige Konfiguration und Quelle
werden unter `/var/backups/camera-rpi-target.XXXXXXXX` privat gesichert.
Vor einer Aktivierung muessen Kameraablauf und exklusiver Hardwarebesitz
separat geprueft werden; insbesondere ist EOS-Legacy keine automatische
Abnahme der abweichenden Steinhagen-Sequenz.

## Sicherung und datenerhaltender Rueckbau

Vor der ersten Veroeffentlichung entsteht ein root-only Ordner:
`/var/backups/camera-rpi-legacy.XXXXXXXX`. Er enthaelt das gepruefte neue
Payload, alte statische Konfiguration/Helper und einen unabhaengigen Rueckweg.
Das Archiv enthaelt unter anderem den alten Key und darf **nicht** in Git,
Webroot oder einen oeffentlichen Download gelangen.

Der Installer gibt den konkreten Rueckbaubefehl aus, beispielsweise:

```sh
sudo bash /var/backups/camera-rpi-legacy.XXXXXXXX/ops/install-legacy-camera.sh \
  --rollback /var/backups/camera-rpi-legacy.XXXXXXXX
```

Den ausgegebenen echten Ordnernamen verwenden. Der Rueckweg braucht das alte
Downloadverzeichnis nicht mehr und prueft den Host sowie seine eigenen
Pruefsummen. Ablauf:

1. Keine neuen aktiven/aktivierten Dienste und keine aktivierte neue Apache-
   Konfiguration zulassen. Es werden keine Aufnahmen hart abgebrochen.
2. Gemeinsame Camera-RPi-Operationssperre nehmen.
3. **Aktuelle** neue Konfiguration inklusive Keys, Standard-Spool, Sensor-
   und Reglerarchive sowie RAM-Verlauf in `rollback.XXXXXXXX/current.tar`
   sichern, byteweise mit den Quelldateien vergleichen und synchronisieren.
4. Erst danach neue Programme/Konfiguration in den privaten Rueckweg
   verschieben. Daten unter `/var/lib/camera-rpi` und `/run/camera-rpi*`
   bleiben zusaetzlich am Originalort; keine Bilder werden geloescht.
5. systemd-Konfiguration neu einlesen; Altbestand und alte Dienste bleiben
   unveraendert. Ein eigener Root-RW-Wechsel wird zurueckgenommen.

Bei Sicherungs-/Vergleichsfehlern wird vor dem Verschieben abgebrochen. Ein
Abbruch waehrend des Verschiebens ist wiederholbar; vorherige Rueckwegordner
werden nicht ueberschrieben. Auch eine fehlgeschlagene Teilinstallation wird
so gesichert. Zurueckgelassene Daten verhindern bewusst eine unbedachte erneute
Erstinstallation. Nicht durch `rm -rf` umgehen.

**Grenze des Versprechens:** Die alte Software und ihre laufenden Daten werden
nicht veraendert. Neue Standarddaten sind vor dem Rueckbau gesichert, werden
aber nicht automatisch in den alten Upload-/RRD-Betrieb importiert oder als
uebertragen markiert. Externe, spaeter selbst konfigurierte Datenpfade bleiben
unberuehrt und brauchen eine eigene Sicherung. Dies ist kein sektorweises
SD-Image und kein garantierter Schutz gegen Stromausfall/SD-Defekt waehrend
einer Schreiboperation. Fuer einen spaeteren OS-/Paketwechsel vorher ein
vollstaendiges SD-Image bzw. eine zweite unveraenderte SD-Karte bereitstellen.

## Vor der eigentlichen Umschaltung noch offen

- Neue EOS-Aufnahme/Fokus auf dieser Canon und 32-Bit-Reglerbetrieb abnehmen.
- Key/Serverziel privat in eine noch deaktivierte neue Konfiguration uebernehmen;
  Legacy-Protokoll beibehalten. Keine Ausfuehrung von Shell-Config; der unten
  beschriebene Import liest ausschliesslich eine eindeutige literale Zuweisung.
- Alten Cron-Poller, Boot-GPIO-Zugriffe und fan.py koordiniert stilllegen;
  gerade laufende Aufnahme/Upload abschliessen lassen und RAM-/RRD-Daten sichern.
- IIO-Sensor und Regler exklusiv uebernehmen, tatsaechliche Aktoren pruefen.
- Erst dann neue Worker/Webseite und ggf. Tagesbackup aktivieren. Fuer Pi 2
  RAM-Budgets passend dimensionieren; nicht blind das Pi-4-Low-write-Profil laden.
- Fuer diesen echten Cutover einen eigenen, ebenfalls geprueften Rueckweg
  inklusive Dienst-/Cron-/GPIO- und Altformat-Uebernahme vorbereiten.

## Installationsstand auf .16 am 12.09.2026

Die **inaktive Erstinstallation ist abgeschlossen**, die Umschaltung bleibt
offen. Der Betreiber hat ca. 17:22 CEST das gepruefte Paket
`camera-rpi-legacy-8df5b2b0fc04.tar.gz` aus
`/tmp/camera-rpi-legacy-fixed.XMsoWN32/` erfolgreich als root installiert.
Die Sicherung liegt privat unter `/var/backups/camera-rpi-legacy.bQy25Pv0`.
Der konkrete Rueckweg lautet bei Bedarf, nicht als naechster Installationsschritt:

```sh
sudo bash /var/backups/camera-rpi-legacy.bQy25Pv0/ops/install-legacy-camera.sh \
  --rollback /var/backups/camera-rpi-legacy.bQy25Pv0
```

Lesende Nachkontrolle ab 17:24 CEST als analyse: neue Dienste nicht aktiv,
aktivierbare Units/Timer disabled, Schutzpruefung fuer den inaktiven Bestand OK.
39 lesbare Manifestdateien stimmen byteweise ueberein; drei private Dateien
konnten nicht unabhaengig verglichen werden. Deren erfolgreiche Pruefung sowie
die des privaten Gesamt-Payloads sind durch das Betreiberprotokoll belegt.
Alt-Fan/Apache/Cron laufen mit unveraenderten PIDs 565/695/326, `webcam_wait`
mit PID 724. JPEG-Zeit 17:20:12, RRD und Wetterdatei 17:24:36 CEST bestaetigen
frische Altdaten; kein vollstaendiger Journal-/Lueckencheck moeglich.

92 isolierte Installerpruefungen lokal und auf dem Pi sowie die zuvor gelaufene
allgemeine Camera-RPi-Regression sind bestanden. Das ersetzt keine EOS-/IIO-/
GPIO-Abnahme der neuen Steuerung auf dem alten 32-Bit-Pi. OS und Pakete wurden
nicht aktualisiert; der Rueckweg umfasst keinen Betriebssystemwechsel.

## Bielefeld-Ziel mit vorhandenem Key vorbereiten

Auf ausdruecklichen Betreiberwunsch bereitet
`ops/prepare-bielefeld-legacy.sh --apply` genau diesen neuen Zielserver vor:

```ini
server_id=bielefeld-legacy
server_base_url=http://192.0.2.2/webcam
camera_slug=bielefeld
key_file=/etc/camera-rpi/keys/bielefeld-legacy.key
enabled=false
upload_mode=legacy
capture_backend=gphoto2
gphoto_capture_mode=eos-legacy
command_timeout_seconds=700
```

Die Basisadresse endet bewusst vor `/include`: der Worker ergaenzt
`/include/command.php` bzw. `/include/upload.php`. HTTP entspricht dem
gewuenschten alten HAMNET-Ziel; es bietet selbst keine TLS-Verschluesselung.
Das ist noch kein Verbindungstest oder Nachweis der EOS-Kompatibilitaet.

Das Skript aus einem geprueften Paket als root starten, optional zuerst mit
`--check`. Es liest genau eine aktive literale `wc_key`-Zuweisung aus
`/etc/webcam.cfg`, ohne `source`/`eval`, Schluesselausgabe oder Netzwerkabruf.
Der Key bleibt auf der Kamera. Vor dem Anlegen wird die vorhandene neue
Konfiguration samt alter Quelldatei unter
`/var/backups/camera-rpi-target.XXXXXXXX` privat gesichert. Zielkonfiguration
und Key werden root:root/0600 angelegt, vorhandene Ziele niemals ueberschrieben.
Ein selbst geoeffnetes Root-RW-Fenster wird wieder geschlossen.

Keine Dienstaktivierung, kein Poll, Upload, Capture oder GPIO-Zugriff; auch die
alte `/etc/webcam.cfg` bleibt unveraendert. Die Admin-Seite wird dadurch noch
nicht freigeschaltet. Der bestehende Migrations-Rueckweg sichert die neu
angelegten Dateien innerhalb `/etc/camera-rpi` mit. Bei einem Teilfehler bleiben
neue Dateien und Sicherung erhalten; nicht blind erneut importieren/loeschen.
Vor Aktivierung weiterhin EOS, Sensor, Cron-/Boot-Konkurrenz und Rueckweg pruefen.

**Abgeschlossen auf .16:** Der Betreiber hat den Import am 12.09.2026 um
17:54 CEST erfolgreich ausgefuehrt. Die Root-Ausgabe bestaetigt beide
Konfigurations-/Key-Pruefungen, unveraenderte Quelldaten und `enabled=false`.
Die konkrete private Sicherung ist `/var/backups/camera-rpi-target.WsAkJzgV`.
Kein erneuter Import erforderlich. Dieser Stand ist durch das
Betreiberprotokoll belegt, nicht durch eine neue SSH-Nachkontrolle oder einen
Uploadtest. Die echte Kamera-Umschaltung ist weiterhin nicht erfolgt.

## Einzelaufnahme vor der Umschaltung

`ops/test-legacy-capture.sh --check` prueft den angehaltenen Cron/Poller,
inaktive neue Dienste, freien RAM-Dateispeicher und genau die inventarisierte
Canon 04a9:32e1. `--capture` nimmt anschliessend unter der gemeinsamen
Operationssperre einmal mit dem neuen EOS-Legacy-Helfer auf. Die zuvor
verwendete allgemeine Capture-Funktion ist durch die bewaehrte
Release3/Wait5s/Immediate5/Tethered80s-Sequenz ersetzt; Details in EOS-LEGACY.md.
120 Sekunden Zeitlimit plus 15 Sekunden Rueckstellfrist, keine automatische
Wiederholung, keine Power-/GPIO-Aktion und kein Workerstart.

Das Ergebnis liegt in einem neuen `/tmp/camera-rpi-eos-result.XXXXXXXX`.
`--keep` vermeidet das Loeschen auf der Kamera; bei Capture Target Internal RAM
ist dies keine persistente Kamerakartenkopie. Lokales `/tmp/raw.jpg` wird
vor/nach dem Test per SHA256 verglichen, nicht ersetzt. Fehlt es nach Reboot,
wird stattdessen die unveraenderte Abwesenheit geprueft. JPEG-Dateigroesse, Start-/Endmarker
und Dimensionen werden geprueft, keine vollstaendige Pixeldekodierung behauptet.
Fehlerprotokolle bleiben zur Analyse erhalten; fuer dieses Profil muss JPEG
eingestellt sein, RAW/RAW+JPEG/Serienaufnahme sind nicht abgenommen.
Die neu erzeugten, keyfreien Testergebnisse sind fuer Gruppe analyse lesbar;
bestehende USB-/Key-/Bildrechte bleiben unveraendert. Kein Upload, kein Zugriff
auf den Serverkey und keine Dienst-, Mount- oder GPIO-Aenderung.

Dieser physische Test erfordert root: analyse hat auf .16 weder USB-Schreibrecht
noch Zugriff auf den root-eigenen Operationslock. Ein erfolgreicher Einmaltest
waere noch keine Abnahme des Workers samt Klimapause, Sensor oder Dauerbetrieb.

Nachtrag 12.09.2026: EOS-Legacy auf `.16` installiert (Sicherung
`/var/backups/camera-rpi-eos-before.toukvDSh`). Betreiber-Einmaltest danach
erfolgreich, 16s und JPEG 6000x4000 / 1.995.132 Bytes, Altbildhash unveraendert.
RAM-Ergebnis `/tmp/camera-rpi-eos-result.PWtsMXAX`. Die auffaellige `Killed`-
Zeile gehoert zum gezielten eigenen Download-Hook, nicht zu einem Fremdprozess.
Aktivierungsfreigabe anschliessend erteilt. Root-Umschaltung und Rueckweg:
BIELEFELD-CUTOVER.md (lokale Betriebsdokumentation, nicht im Export); noch keine abgeschlossene
Regler-/Sensor-/Upload-/Boot-Abnahme behauptet.
# Gezielte Umschaltung Steinhagen (.17)

`ops/activate-steinhagen.sh` ist **kein universeller Installer**. Er erwartet
den inventarisierten, bereits inaktiv installierten Stand auf `webcam-sth`
und ein Paketmanifest mit den geprueften installierten Laufzeitdateien.
Vorher muessen Alt-Kamera-/RRD-Cronjobs und der alte Poller gestoppt sowie
EOS-Aufnahme, Legacy-Upload und Folgeaufnahme nach ISO-Befehl abgenommen sein.
Der alte `fancontrol` darf im Ausgangszustand weiterlaufen: Er wird erst nach
dauerhafter Sicherung gestoppt und disabled, bevor DHT-IIO und neuer Regler
uebernehmen. Der separate .16-Umschaltweg bleibt unveraendert.

Umfang: 128-MiB-tmpfs-Spool und Poolprofil `steinhagen-legacy`, 48h Sensor-RAM-
Verlauf, DHT22/BCM4, bestehende Reglerparameter im Automatikmodus, taegliches
Sensor-/Klimabackup um 00:05 Europe/Berlin, Restore beim Boot, Webuebersicht
unter `/` und gesperrter Admin unter `/camera-admin`. Passwort lokal setzen:
`htpasswd -B /etc/camera-rpi/admin.htpasswd cameraadmin`.
Alte Webseiten/Bilder/Keys/RRD bleiben erhalten. Kamera-GPIO6 und Fokus werden
bei der Umschaltung nicht geschaltet; es wird kein automatischer Power-Recovery-
Mechanismus hinzugefuegt. Der alte spaete Heater-/Fan-/Focus-GPIO-Setup in
rc.local wird auskommentiert, damit er nach Boot keine neuen Besitzer stoert.

`--check` ist ohne Aenderung; `--apply` startet Sensor/Regler nur mit frischen
plausiblen Messwerten, danach den Kamera-Worker. Bei Fehler wird zurueckgestellt.
`--rollback /var/backups/camera-rpi-cutover.XXXXXXXX` sichert zuvor neue Daten,
stoppt neue Besitzer und stellt Dateien/Bootkonfiguration wieder her. Der alte
Regler wird wieder aktiviert; die vom Betreiber ausgeschalteten Kamera-Cronjobs
bleiben aus. Bei fehlerhafter Sicherung/Stop/Unmount bleibt der Rueckweg stehen,
anstatt Daten zu verwerfen oder zwei GPIO-Besitzer zu starten. Der Backup-Pfad
ist privat und enthaelt Keys. Root-/Boot-RO wird wiederhergestellt, sofern der
Umschalter das jeweilige Dateisystem selbst schreibbar gemacht hat.

Die Paket- und Live-Abnahme dieser Installation wird in
`ops/steinhagen-inactive-install-2026-09-13.md` nachgefuehrt.


## DK0RE/Stromberg, 06.10.2026: Erstinstallation vorbereitet

Nach abgeschlossenem Trixie-/WiringPi-Upgrade und GUI-Bereinigung fordert
Betreiber den Befehl fuer die neue Kamerasoftware. Hostgebundener Wrapper
`ops/dk0re-camera-20261006/stage.py`, publizierte Fassung mit eingebettetem
quelltextbasiertem Paket: `http://192.0.2.34/dk0re-camera-stage.py`.
Nur DK0RE/Pi4/Trixie; Altidentitaet Stromberg und DEV-Ziel exakt geprueft.
`--apply` verwendet den vorhandenen **inaktiven** Erstinstaller mit neuer
HTTP-Passwortabfrage fuer cameraadmin, danach den literalen Ziel-/Key-Importer
als stromberg-dev/enabled=false. Keine Ausfuehrung von webcam.cfg, keine
Ausgabe des Keys. Altdateien vorher/nachher gehasht, Sicherungen unter
/var/backups/camera-rpi-legacy.* und /var/backups/camera-rpi-target.*.
Keine neuen Dienststarts, GPIO-Zugriffe, Cron-/rc.local-Aenderungen, Apache-
Aktivierung oder Reboot. Eine Installation ist noch keine Umschaltung.

Paketbauer enthaelt nun auch den bestehenden prepare-legacy-target-Importer
und dessen17Tests. Exaktes Paket lokal und auf DK0RE bestanden:116 Install-
/Rueckwegpruefungen plus17 Importpruefungen, nativer ARM-Build mit -Werror,
PHP-/Bash-Syntax. 67 Paketdateien und SHA256 nach Entpacken geprueft;
Wrapper verweigert anderen Host vor Installation. HTTP-Download bytegleich.
Paket-SHA256:785cba4b1c05912001c055447070e7379e30f783f6dd5c46b10751c9199eabca.
Wrapper-SHA256:a72aa9763c167a5f82da3324668382a20845f05d8cf97fb95af444158efffcdf.
ARM-Testverzeichnis:/tmp/dk0re-camera-check.rrqsOMsN (nur Testdaten).
Private DEV-Transport-/Pruefdaten:/var/local/webcam/dk0re-camera-20261006/.

Noch offen nach Betreiberinstallation: koordinierte GPIO-Uebernahme einschl.
BCM13-Fokusfreigabe, Kamera-Power/Heizung/Luefter, alte Poller-/Cron-/Boot-
Eigentuemer abloesen, Admin aktivieren, neue Aufnahme-/Upload-Abnahme und
Rebootpruefung. Bestehende Fremdhost-Cutover-Skripte weiterhin nicht blind
anwenden. Neue ISOEXP-Stufen benutzen bereits ganze EV-Werte; Nachtregelung
arbeitet bis zur Umschaltung ueber den bisherigen Stromberg-Helfer.

### Nachtrag 06.10.2026, 20:24: Apache-Voraussetzung fehlt

Betreiberlauf bricht im unveraendernden Core-Vorcheck ab: kein PHP-Handler.
Die vorherige Paket-/ARM-Pruefung deckte diesen rootpflichtigen Host-Vorcheck
nicht ab; die Aussage zur Installierbarkeit war daher zu weitgehend.
Read-only-Nachpruefung: Apache aktiv mit mpm_prefork, kein php/proxy_fcgi;
nur alte php8.0-Konfigurationsreste, kein libapache2-mod-php installiert.
PHP8.4-common vorhanden. APT-Simulation fuer libapache2-mod-php8.4 ergibt
genau eine Neuinstallation, keine Updates/Entfernungen. /etc/camera-rpi fehlt.

Separater Reparaturhelfer `ops/dk0re-camera-20261006/php-apache-fix.sh`,
publiziert als `http://192.0.2.34/dk0re-php-apache-fix.sh`: Host-/Trixie-/
ARMHF-/RW-Pruefung, exakte Versionsbindung an php8.4-common, eng begrenzter
Paketplan, Apache-Konfigurationssicherung unter /root, Handlerinstallation,
Syntax-/Modulpruefung und Apache-Reload. Paket-Maintainerskripte koennen
Apache ebenfalls neu laden/starten. Kein Kamera-/GPIO-/Cron-Eingriff.
Root-Ausfuehrung und anschliessende erneute Erstinstallation noch offen.

### Betreiberabschluss 06.10.2026, 20:27

PHP-Apache-Reparatur erfolgreich; Sicherung /root/dk0re-php-apache-before-28vpUWkG.
Inaktive Erstinstallation und Passwortsetzung erfolgreich, danach DEV-Import
stromberg-dev/enabled=false bestaetigt. Sicherungen:
/var/backups/camera-rpi-legacy.AEHr5Y3z und
/var/backups/camera-rpi-target.yLFDGK0Z. STAGED und unveraenderte Altdateien
laut Betreiberabschluss. Dies ist weiterhin keine aktive Umschaltung.

Lesende Nachkontrolle: alter webcam_wait PID1210 aktiv, keine geladenen neuen
Units, fancontrol disabled, rrd-create enabled. Kein IIO-Geraet; Bootkonfiguration
ohne DHT-Overlay. fan.py bestaetigt DHT22 BCM4, Luefter27, Heizung5; alter
Fokushelfer BCM13 HIGH nur fuer Fokus/LOW danach, Kamera BCM6 aus rc.local.
Rootgeschuetzte Pinzustaende und weitere Crontabs sind fuer analyse nicht
zugaenglich. Rein lesender Helper `ops/dk0re-camera-20261006/preflight.py`
publiziert als dk0re-camera-preflight.py; liest Pinpegel, Treiberverfuegbarkeit,
Altjobpositionen ohne Argumente/Keys, Konfigurationschecks und Originalhashes.
Laedt keinen Treiber, startet keinen Dienst und benutzt kein gphoto2.
Naechster Schritt benoetigt diese Root-Ausgabe fuer den konkreten Cutover.

### Root-Vorabpruefung und konkreter Umschalter, 06.10.2026 ab 20:30

Betreibercheck bestaetigt DHT11-Kernelmodul vorhanden, kein Laufzeitoverlay,
GPIO5/6/13/27 jeweils LOW. Nach altem webcam_heater bedeutet GPIO5 LOW
Heizung AN; fancontrol disabled/inactive und Luefter LOW. Eine Aufnahme
lief zum Checkzeitpunkt. Ausschliesslich bekannte Altjobs in /etc/crontab,
keine weiteren gefundenen Kamera-/RRD-Jobs in Root-/User-Crontabs/cron.d.

Vorbereitet, **noch nicht ausgefuehrt**: `ops/dk0re-camera-20261006/cutover.py`;
Bauer `build-cutover.py`, publiziert als dk0re-camera-cutover.py. 29 GPIO-/
Admin-/Unitdateien plus konfigurierte Inbetriebnahme, 59 Dateien aus dem
urspruenglichen Installationspaket als exakte Bestandspruefung. Urspruengliche
Transportfassung wird nur zur Ableitung der Schluessel-freien Codehashes gelesen.
Kein Import von Keys ins Transportpaket. Neues Passwort bleibt erhalten.

`--apply` prueft Host/Board/Trixie/RW/Bestand, kopiert den Umschalter privat
nach /root/dk0re-camera-cutover-before-* und startet einen eigenen systemd-
Oneshot. run.log und Sicherungen bleiben dort. Keine SSH-Abhaengigkeit.
Altcron fuer Poller und drei RRD-Renderer wird ausgesetzt; Wetterjob bleibt.
Alt-Poller wird STOP-pausiert, laufende Aufnahme/USB-Aktion bis 300s abgewartet,
erst danach idle Poller/Kindprozesse beendet. Keine fremden gphoto2-Prozesse
werden beendet. Kein Autofokus, Testfoto, Stromzyklus oder Pi-Reboot.

Neue GPIO-Owner: Kamera BCM6 LOW, Fokus BCM13 LOW; HIGH nur im ausdruecklichen
Fokusauftrag mit Rueckstellung/Frist. DHT BCM4 ueber Laufzeitoverlay im neuen
Bootdienst, keine Aenderung an config.txt. Alle acht alten GPIO-Anweisungen in
rc.local werden kommentiert. Heizung5/Luefter27 vor Sensortest AUS; neue
Klimaautomatik erst nach frischem gueltigem Messpaar. 256MiB RAM-Spool mit
Mountpflicht im Worker (kein SD-Fallback), Admin-Authentifizierung HTTP401
und Status HTTP200 geprueft. Neue Dienste/Timer erst nach Pruefung fuer Boot
aktiviert; rrd-create deaktiviert, fancontrol bleibt deaktiviert.

Fehlerrueckweg: vor Hardwareuebergabe nur Cron/Poller fortsetzen; nach Uebergabe
alle neuen Schreiber/Owner stoppen, ausstehende RAM-Bilder sichern, Mount und
eigenes Laufzeitoverlay entfernen, alte Dateien wiederherstellen und Alt-Poller
starten. **Heizung bleibt dabei AUS** statt der vorherigen ungeregelten AN-
Stellung; Luefter AUS, Fokus LOW und Kamera AN. Fehler beim Stoppen/Datenretten
bricht Rueckweg ab, damit keine konkurrierenden Owner starten. Manueller
Rueckweg `python3 <Sicherungsordner>/cutover.py --rollback` nur im selben Boot;
nach Reboot gesonderte Pruefung. Root bleibt im eingehenden Wartungsmodus RW.

Validierung: sieben migrationsspezifische Tests (Altcron/Bootbewahrung,
Konfigurationswechsel, PID-Reuse, Capture-Timeout ohne GPIO-Eingriff,
fehlgeschlagener Dienststop sperrt Rueckweg, korrupte Sicherung), 140 GPIO-
Schema- und 50 Boardtests, nativer Fokus-/Frist-/Fehlerpfadtest bestanden.
Exaktes Paket auf DK0RE: PHP-Syntax, ARM-Build mit -Werror und --version;
62 lesbare Baseline-/Altdateien stimmen, drei rootgeschuetzte Pruefungen
folgen im Rootlauf. systemd-analyze verify fuer GPIO/Fokus/Power/Spool bestanden.
Offen bleiben Live-DHT/GPIO, naechste Aufnahme samt DEV-Upload und Rebootabnahme.

### Erster Cutover 21:12 und Bereitschaftskorrektur

Betreiberlauf /root/dk0re-camera-cutover-before-hn9px1yc scheitert beim
ersten Fokus-OFF-Aufruf: Socket nicht erreichbar. Laut run.log erfolgte der
Rueckweg inklusive Bildsicherung nach recovery-gwj2a70z/spool. Alt-Poller
wieder beobachtet; crontab/rc.local stimmen mit Ausgangshashes ueberein.
Heizung im Rueckweg bewusst AUS, fancontrol weiter deaktiviert.

Konkreter Codefehler: beide GPIO-Dienste Type=simple bestaetigten ihren Start
ohne Socketbereitschaft. Unmittelbarer Fokusaufruf konnte davor erfolgen.
Der urspruengliche Dienstfehler ist aus dem nach Rollback zurueckgesetzten
systemd-Zustand nicht beweisbar; keine Behauptung einer Hardwareursache.
Neu: ExecStartPost ueber ready-focus/ready-power wartet maximal ca.10s auf
STATUS=OFF bzw.STATUS=ON; TimeoutStartSec15. Ausschliesslich STATUS, kein
Fokus-/Stromschalten in der Bereitschaftspruefung. Ein defekter Dienst bleibt
ein Fehler und wird nicht durch feste Sleeps als gesund angenommen.

Cutover sichert GPIO-/Fokus-/Power-Journale vor Rollback. systemd-run nutzt
--no-block; Log-/Rueckwegpfad werden vor Start ausgegeben. Rueckweg startet
Alt-Poller in eigener transienter Unit: setsid/Popen allein verliesen die
Cutover-cgroup nicht, sodass deren Beendigung den Ersatzpoller beenden konnte
(Altcron haette erst im naechsten Minutentakt wieder gestartet).

Tests: acht Bereitschaftsfaelle (verzoegerte Sockets, falscher Zustand,
Fehlerantwort, fehlender Socket, deaktivierte optionale Pins) sowie sieben
Cutovertests bestanden. PHP-Syntax und systemd-Unitpruefung bestanden.
Erneute Live-Umschaltung und Bildabnahme weiterhin offen.

### Zweiter Lauf und Betreiberentscheidung zum Festbetrieb

Lauf b4ebq6c8 vom 06.10.21:17: Fokus-/Power-Bereitschaft bestanden, DHT-
Treiber und Sensor gestartet. Keine gueltigen Messpaare innerhalb150s.
Betreiberstatus: sensor_state=error, vier Fehlversuche. Lesbares Kernelprotokoll
mehrfach `dht11 dht11@4: Only 1 signal edges detected`. DHT-Telegramm fehlt;
keine Diagnose eines sicher defekten Sensors. Rueckweg beendet, Alt-Poller
und Folgeaufnahme beobachtet; crontab/rc.local wieder Originalhashes.
Ursache fuer unveraenderte Webseite: Admin wird erst nach Klimaabnahme aktiviert.

Separater DHT-Probehelfer vorbereitet, aber **nicht ausgefuehrt**. Betreiber
fordert stattdessen ausdruecklich Heizung dauerhaft AN, Luefter80%, DHT-Werte
ignorieren und neue Software starten. Das ersetzt fuer diese Migration die
Voraussetzung eines gesunden Sensors; weitere Sensorversuche zurueckgestellt.

Neuer expliziter Modus manual80 in Validator/Regler/Daemon/Admin/Status/Verlauf:
keine erfundenen DHT-Werte und keine Sensorentscheidung. Festwert HeizungAN/
PWM80, vorhandener2s-Anlauf100%, bestaetigte Aufnahmepause AUS und danach
Rueckkehr zum Festwert. Aus und technische Fehler haben weiter Vorrang;
Watchdogs bleiben. Quittierung braucht in diesem bewusst sensorlosen Modus
keine DHT-Werte. Automatikverhalten fuer andere Kameras unveraendert.

DK0RE-Paket ergaenzt Controller/Verlauf/Status, setzt manual80 und laesst
device.conf/enabled=false: keine weiteren DHT-Leseversuche. Sensorprozess
meldet deaktiviert. Umschalter prueft echten GPIO-Modus, Heizungon/PWM80
statt frischer DHT-Messungen. Kein weiterer Kamerastromzyklus oder Fokuslauf.
Manueller Modus in der Weboberflaeche ausdruecklich bezeichnet; Automatik-
Parameter wirken dort nicht. Root-Anwendung und erste Aufnahme noch offen.

Tests:15 Festwert-Modellpruefungen;234 vorhandene Klima-/IPC-/Capturepruefungen;
55 Verlaufpruefungen;7 Cutoverpruefungen. Zusaetzlicher echter PHP-Supervisor
mit GPIO-Attrappe prueft fehlenden Sensor, 80%-Ziel, bestaetigtes AUS fuer
Capture und erneute80%. Kein echter GPIO in diesen Tests.

### Erfolgreiche Umschaltung am 06.10.2026 ab 21:45

Betreiber startet den aktualisierten Umschalter um21:45:40. Sicherung und
Rueckweg: `/root/dk0re-camera-cutover-before-j07xf8ie`; `run.log` liegt dort.
Lesende Fernabnahme: transiente Cutover-Unit beendet mit Result=success und
ExecMainStatus=0. Worker stromberg-dev, Klima, Sensorstatus, GPIO, Fokus,
Kamerastrom und Sicherungstimer active/enabled; keine Prozessneustarts.
Alt-Poller/Altaufnahme bei Abschluss nicht mehr vorhanden. Spool liegt auf
256MiB tmpfs. Root-URL und camera-status antworten200; camera-admin401.
Statusseite bestaetigt manual80, HeizungAn und80,0% PWM. DHT-Abfrage bewusst
deaktiviert. Kein Reboot ausgefuehrt; physische Neustartabnahme bleibt offen.
Erste regulaere Aufnahme erfolgreich: Slot21:50:00 am06.10.2026 wurde
um21:50:57 in der DEV-Kamera-DB gespeichert, is_duplicate=0. App-Auswertung
21:50:58 bestaetigt ISO800,15s,EV+2,Luminanz9,2%; Regelentscheidung hold.
Echte Aufnahmepause per Status-HTTP beobachtet21:50:05–21:50:43: HeizungAus/
PWM0. Rueckkehr21:50:48 mit vorgesehenem kurzen100%-Anlauf;21:50:53 wieder
HeizungAn/PWM80. GPIO-Pegel hier durch Steuerungsstatus belegt, nicht durch
unabhaengige elektrische Messung. Alte-PROD-Weiterleitung durch .42 in dieser
Abnahme nicht erneut geprueft. Rebootabnahme und DHT-Ursache bleiben offen.

### DHT-Pinsuche nach erfolgreicher Migration vorbereitet

Betreiber wuenscht einzelne GPIO-Pruefung, Aktoren ausdruecklich ausgenommen.
Altes `/usr/local/bin/fan.py` Zeile50 bestaetigt `DHT22(board.D4,
use_pulseio=False)` mit Kommentar Pin7. Das belegt die alte Konfiguration,
nicht die tatsaechliche Verdrahtung. Neues IIO ebenfalls dht11@4.

`ops/dk0re-camera-20261006/dht-pin-scan.py` / Download
`http://192.0.2.34/dk0re-dht-pin-scan.py`: Root-only `--scan`, Host/Board/
Maschinen-ID und exakte aktive/gespeicherte GPIO-Belegung gepinnt. BCM0/1,
5/6/13/27 und alle Nicht-Header-Pins ausgeschlossen. Weitere Pins nur bei
Eingangsmodus und ohne Kernel-Verbraucher; GPIO4 ausschliesslich ueber das
bestehende, verifizierte IIO-Geraet. Keine Entfernung des produktiven Overlays.
Freie Eingangs-Pins erhalten einzeln einen eindeutig benannten temporaeren
DHT-Knoten. Zwei frische Leseversuche mit Pull-up; GPIO4 vorher zusaetzlich
zwei mit originalem Pull. Vorheriger Eingangsmodus/Pull wird bei Erfolg,
Fehler und behandelbarem Terminalabbruch wiederhergestellt. Nach zwei
gueltigen Antworten Suche beenden; keine automatische Konfigurationsaenderung.
Kernelbelegung wird per GPIO-Info-ioctl gelesen, nicht als Ausgabe angefordert.
Sensorzugriff respektiert Kamera-Operationslock; laufende Aufnahme hat Vorrang.
manual80 und deaktivierte automatische Sensorabfrage bleiben Voraussetzung.
Protokoll unter `/root/dk0re-dht-scan-*/results.jsonl`.

Acht isolierte Ausschluss-/Erfolgs-/Abbruchtests bestanden. Keine Live-Suche
durch analyse mangels Root-/GPIO-Rechten. Kernelprotokoll-Grundlage:
https://raw.githubusercontent.com/torvalds/linux/master/drivers/iio/humidity/dht11.c
(kurzer LOW-Start, danach Eingang/Flanken und Pruefsumme). Keine Antwort
schliesst andere Verdrahtung nicht sicher aus und beweist keinen Sensordefekt.

Betreiberlauf22:31:28–22:36:31:22GPIOs geprueft,46Leseversuche ohne gueltige
Antwort. GPIO4 zweimal original und zweimal mit Pull-up, uebrige21freie
Eingaenge je zweimal mit Pull-up. Alle Meldungen TimeoutError110; saemtliche
Testpins als restored bestaetigt, regulaeres Testende. Protokoll auf Pi:
`/root/dk0re-dht-scan-dzdcve9h/results.jsonl`. Kein alternativer Datenpin
gefunden; Hardwaredefekt oder konkrete Ursache damit nicht bewiesen.

### Standardbezeichnung der EOS-Sequenz korrigiert

Nach erfolgreichem21:50-Bild scheitert Stromberg ab22:00 beim direkten
`--capture-image-and-download` mit PTP0x2019/DeviceBusy und backend_exit1.
Worker wiederholt denselben Auftrag; weitere Polls bleiben deshalb aus.
Betreiber bestaetigt Auswahl „Standard“ statt EOS. „Zeitfenster ausgeschaltet“
ist nur die erlaubende Privacy-Meldung, nicht die Fehlerursache. Warum die
Auswahl vorher wechselte, ist nicht belegt. Neu eingetroffenes Bild fuer22:40
um22:45:20 bestaetigt spaeter wieder Upload, unabhaengig vom hier vorbereiteten
Softwareupdate.

Auf Betreiberauftrag zeigt die Auswahl jetzt „Standard – bewaehrte
EOS-Sequenz“ und „Alternativ – direktes gphoto2 Capture & Download“.
Neue Server, fehlende Modusfelder in Server-/Quellenprofilen und UI-Fallbacks
verwenden eos-legacy. Explizites standard bleibt kompatibel die direkte
Alternative; gespeicherte Auswahl bleibt beim Roundtrip erhalten.
Beispiele/CAMERA-SOURCE.md angepasst. Bestehender Backend-Test erwartete noch
den alten EV-Index20; auf bereits implementierten absoluten EV-Wert2 korrigiert.

Gezieltes Fuenfdateien-Update fuer DK0RE: install-eos-default.py mit Builder
build-eos-default.py. Root-Pruefung der installierten Hashes, Syntax, Sicherung,
Operationslock und koordinierter Worker-Neustart; keine Config-/Key-Aenderung,
keine Strom-/GPIO-/Klimaaktion. Root-Anwendung separat erforderlich.
Validierung:29 Quellenvertraege, reale isolierte Bridge-Transaktionen mit
Service-Rollback, Backend-/EOS-Aufnahme bis Upload und Wiederholung mit
Attrappen sowie Browserformulare Desktop/Mobil bestanden. Paket auf dem Pi
heruntergeladen: alle5Dateien Bash/PHP-Syntax OK,4lesbare Ausgangshashes
identisch; privater Admin-Helfer wird erst im Rootlauf auf den gepinnten
Ausgangshash geprueft. Keine Live-Installation durch diese Abnahme.
