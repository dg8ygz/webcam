# GPIO-Belegung im Kamera-Admin

Unter **GPIO-Belegung** gibt es fünf Auswahlfelder: DHT22, Heizung,
Lüfter-PWM, Kamerastrom und optionale Fokusfreigabe. Angezeigt werden BCM-Nummer und physischer Pin
des erkannten Headers. Beispielsweise ist GPIO 4 der physische Pin 7.
Kamerastrom und Fokusfreigabe können „Nicht angeschlossen“ bleiben.

Bereits ausgewählte Pins sind in den anderen Feldern ausgeblendet.
Auch ohne JavaScript lehnt die privilegierte Speicherfunktion doppelte Pins
ab. Eine veraltete Formularversion kann neuere Änderungen nicht überschreiben.
GPIO 0/1 sowie Masse und Versorgung stehen nicht zur Auswahl.
Die Auswahl prüft die fünf Software-Rollen, nicht jede mögliche externe
Verdrahtung oder alternative I²C-/SPI-/UART-Funktion. Diese separat prüfen.

## Modell- und Header-Erkennung

Die Admin-Seite zeigt Modell, Revision (falls vorhanden) und Headergröße.
Quelle ist `/sys/firmware/devicetree/base`: `model`, `compatible` und
`system/linux,revision`. Das oft irreführende cpuinfo-Feld „Hardware“ wird
nicht zur Modellbestimmung verwendet. Exakte `raspberrypi,...`-Kennungen
bestimmen die Belegung; unbekannte oder mehrdeutige Kennungen sperren das
Formular und serverseitiges Speichern. Der Modelltext allein gibt nichts frei.

- A/B mit altem P1-Header: 26 Pins; beim frühen B ist Pin 13 GPIO 21,
  bei Rev2 GPIO 27. GPIO 2/3 fehlen beim frühen B auf P1. GPIO 0/1 bleiben
  auch dort bewusst von der Auswahl ausgeschlossen.
- A+/B+, Pi 2/3/4, 400 sowie Zero/Zero W/Zero 2 W: 40-Pin-Belegung.
- Pi 5 und 500: Modell/40-Pin-Header erkannt, aber gesperrt, weil der
  native PWM-Helfer RP1 derzeit nicht unterstützt.
- Compute Modules: keine geratene Headerbelegung; diese hängt vom Carrier ab.

Die Erkennung garantiert weder angelötete Stiftleisten noch freien Zugriff
auf alternativ belegte Pins. Ebenso muss der Kernel GPIO-v2 unterstützen.
Gespeicherte Pins, die auf einem anderen Board fehlen, werden als ungültig
angezeigt und nicht automatisch ersetzt. Der Boot-/Hardwarepfad prüft ebenfalls
die erkannte Belegung, bevor er den DHT lädt oder Ausgänge anfordert.

Grundlagen: [offizielle Raspberry-Pi-Modellkennungen](https://github.com/raspberrypi/documentation/blob/master/documentation/asciidoc/computers/raspberry-pi/revision-codes.adoc)
und [GPIO-Hardwarebeschreibung](https://www.raspberrypi.com/documentation/computers/raspberry-pi.html#gpio),
ergänzt durch die offiziellen Kernel-Gerätebäume
[Model B Rev1](https://github.com/raspberrypi/linux/blob/rpi-6.12.y/arch/arm/boot/dts/broadcom/bcm2835-rpi-b.dts)
und [Model B Rev2](https://github.com/raspberrypi/linux/blob/rpi-6.12.y/arch/arm/boot/dts/broadcom/bcm2835-rpi-b-rev2.dts).

## Übernahme und Verdrahtung

Speichern schreibt ausschließlich `/etc/camera-rpi/gpio.json` und bewahrt
die vorherige Fassung als `gpio.json.previous`. Es schaltet keine Pins und
startet keine Dienste neu. Die Seite zeigt die aktive Belegung getrennt an.
Bei Änderungen: Gerät ausschalten, Verdrahtung ändern, anschließend starten.
Ein Software-Reboot allein ersetzt das sichere Umverdrahten nicht.

`camera-rpi-gpio.service` findet oder lädt beim Booten den DHT-Overlay und
erstellt `/run/camera-rpi-gpio/active.json`. Alle Verbraucher verwenden
ausschließlich diesen RAM-Snapshot. Ein erneuter Start des Bootdienstes im
gleichen Boot übernimmt keine wartenden Änderungen. Fehlt bei eingerichtetem
Profil der Snapshot, wird nicht stillschweigend auf andere Pins ausgewichen.
Die Sensorauswahl erfolgt über die GPIO-Zelle des Gerätebaums, nicht über die
wechselnde Nummer `iio:deviceN`. Der DHT-Treiber wird nicht ersetzt.

Heizung: LOW-aktives Relais. Lüfter: 100-Hz-PWM mit unverändertem
2-Sekunden-Anlaufimpuls. Die native GPIO-v2-Komponente fordert die beiden
Ausgänge gemeinsam an und initialisiert Heizung und Lüfter ausgeschaltet.
Bereits von einem Kernel-Verbraucher belegte Pins können nicht übernommen
werden. Ein Defekt oder fehlender Hardwaretreiber wird dadurch nicht behoben.

Der optionale Dienst `camera-rpi-camera-power.service` hält den ausgewählten
Versorgungspin beim Start LOW (an). Seit dem gezielten Update vom 19.09.
erlaubt sein root-exklusiver RAM-Socket explizites ON (LOW) und OFF (HIGH).
Die Admin-Brücke schaltet nur den aktiven Boot-Pin, unter der gemeinsamen
Aufnahmesperre und nach bestätigtem GPIO-Schreibvorgang. Der Dienst ist
vom Klima-Watchdog getrennt.
Seit 16.10.2026 kann der Worker nach drei echten gphoto-Aufnahmefehlern einen
begrenzten `RESET` anfordern: 45 Sekunden AUS, 15 Sekunden Anlauf, maximal
einmal je 30 Minuten und dreimal ohne erfolgreiche Aufnahme. Der GPIO-Owner
schaltet selbst wieder ein, auch wenn der Worker beendet wird. Manuelles AUS
wird nicht aufgehoben. Details und Grenzen stehen in `CAPTURE-POOL.md`.
Beim Beenden wird kein ausdrücklicher AUS-Impuls
gesendet; der elektrische Zustand nach Freigabe hängt von der Treiberstufe
und deren Pull-Widerständen ab. Nicht direkt eine Kamera aus einem GPIO speisen.

Im Admin steht oben **Kamera ein-/ausschalten**. Die Einschalt-Schaltfläche
ist grün, wenn `lsusb` den Text `Canon` enthält; andernfalls ist die
Ausschalt-Schaltfläche rot. Bus, Device und USB-ID werden nicht ausgewertet.
Das jeweils andere Feld bleibt neutral. Bei fehlgeschlagener USB-Abfrage
sind beide neutral und der Status unbekannt. Die Anzeige wird alle drei
Sekunden nach der vorherigen Antwort erneuert. GPIO-Sollzustand und
USB-Erkennung werden nicht verwechselt: bis zur USB-Anmeldung kann die
Anzeige nach ON noch rot bleiben. Fehlender Kamerastrom-Pin oder fehlender
Dienst sperren die Buttons. Login und CSRF sind erforderlich; eine laufende
Aufnahme führt zur Meldung „Kamera beschäftigt“, nicht zum Abschalten.

OFF gilt bis zum nächsten bewussten ON oder Start des Kamerastrom-Dienstes
(auch Reboot: wieder ON). Es werden keine Worker deaktiviert oder
Konfigurationsdateien geschrieben. Das Upgrade startet ausschließlich den
Kamerastrom-Dienst neu; Klimaregler/Sensor bleiben aktiv. Installation und
Rückweg für `.17`: `ops/camera-power-rollout-2026-09-19.md`.

## Geprüfte Kamera-Updates und Rückweg

**Fokus-Erweiterung vom 05.10.2026, noch nicht ausgerollt:** `focus` ist
standardmäßig `null`; ältere Vier-Felder-Konfigurationen und Boot-Snapshots
werden unverändert als Fokus deaktiviert gelesen. Ein konfigurierter Pin wird
vom separaten `camera-rpi-focus-gpio.service` beim Start LOW angefordert.
Nur explizite Fokusaufträge schalten HIGH; danach wieder LOW, auch bei Fehlern.
Vor jeder gphoto2-Aufnahme muss der Dienst LOW bestätigen. Ohne Bestätigung
findet keine Aufnahme statt. GPIO-Schreibbestätigung ist keine elektrische
Messung am Objektiv.

Ein eigener nativer 60-Sekunden-Timer beendet HIGH auch dann, wenn der Worker
abstürzt; wiederholte ON-/STATUS-Anfragen verlängern ihn nicht. SIGTERM setzt
LOW vor der Freigabe. Bei SIGKILL/Stromausfall des GPIO-Eigentümers bestimmt
die externe Beschaltung den Pegel; Software ersetzt keinen Pull-down.
Details, Stromberg-Historie und Stand der Abnahme: [FOCUS.md](FOCUS.md).
Die historischen, hostgebundenen Rolloutskripte unten sind keine DK0RE-Freigabe.

`bash ops/update-gpio.sh --apply` ist eine gezielte Erstinstallation für die
geprüfte Testcam und die Kameras .16/.17: DHT GPIO 4, Heizung 5, Lüfter 27.
Testcam und .16 haben keinen aktivierten Versorgungspin; .17 übernimmt
BCM6 (bisher wiringPi22 in rc.local). Andere Ausgangslagen werden abgelehnt.
Auf .17 wird nur diese alte Bootzeile auskommentiert, nicht ausgeführt.
Ein eigener Kamerastrom-Pin wird erst beim nächsten Boot angefordert.
Das Update sichert Dateien und die
Bootkonfiguration, übernimmt den bestehenden GPIO-Snapshot und aktiviert
die Bootdienste **ohne sie zu starten**. Laufende Kamera, Sensor, Regler und
DHT-Langzeittest werden nicht neugestartet. Keine Fokusaktion, kein Reboot.
Nur der bisherige DHT-Overlay GPIO 4 wird in der Bootdatei auskommentiert;
künftig übernimmt ihn der Bootdienst. Andere Overlays bleiben erhalten.
Die Bootpartition kann `/boot` oder `/boot/firmware` sein; ein ursprüngliches
RO-Mount wird nach dem Update wiederhergestellt. Reglerparameter, alte
Sensor-Fehlerbehandlung, Zielserver, Keys und Aufnahmesequenzen bleiben erhalten.

**RO-sicherer Dateiaustausch:** GPIO-Installation, Modell-Update und GPIO-
Rollback erhalten vor jedem Rename/Unlink einen zusätzlichen Hardlink der
bisherigen Root-Datei im privaten Sicherungsverzeichnis (`inodes/` bzw.
`rollback-inodes/`). Die separaten Dateikopien bleiben der unveränderte Rückweg.
Laufende Prozesse können dadurch ihre alte Version weiter lesen, ohne offene
gelöschte Inodes zu erzeugen, die den RO-Remount blockieren. Paketbauer müssen
`ops/lib/update-inodes.sh` mit aufnehmen; der Erstinstaller kopiert den Helfer
auch zum eigenständig ausführbaren Rollback.

Diese Hardlinks nicht entfernen, solange alte Prozesse laufen; am einfachsten
die gesamte Sicherung bis nach einem geprüften Neustart behalten. Bereits
durch ältere Pakete oder APT erzeugte offene gelöschte Dateien repariert der
Helfer nicht rückwirkend. Dafür ist weiterhin ein abgestimmter Dienstneustart
oder Reboot nötig. Keine erzwungenen Remounts. Ursprung und Nachweis:
Rollout-Nachkontrolle (lokale Betriebsdokumentation, nicht im Export).

Der ausgegebene Rückrollbefehl stellt die gesicherten Dateien wieder her.
Nach einer zwischenzeitlichen Umverdrahtung zuerst die ursprüngliche
Verdrahtung wiederherstellen; ein Rollback startet keine Dienste neu.
Das Paket enthält keine Änderung der Kamera-Worker oder Fokusbefehle.

## Prüfungen

`php tests/gpio-config.php`, `php tests/gpio-board.php`, `php tests/gpio-store.php`,
`bash tests/climate-native` und `node tests/gpio-ui.cjs` prüfen Schema,
Doppelbelegung, CAS, Boot-Snapshot, native Argumente und das Formular.
Diese Prüfungen verwenden keine physische GPIO-Hardware. Eine tatsächliche
Umbelegung und ein Kaltstart sind gesonderte Hardwareprüfungen.

`bash tests/update-inodes` prüft echte offene Deskriptoren und erhaltene
Inodes beim Update und Rückweg. `sudo bash tests/update-inodes --ext4` prüft
zusätzlich auf einem frisch angelegten, temporären ext4-Image: Ohne Hardlink
scheitert RO bei offenem gelöschtem Inode, mit Hardlink funktioniert es. Nur
dieses Image wird gemountet/remountet; niemals das Root-Dateisystem des Hosts.
