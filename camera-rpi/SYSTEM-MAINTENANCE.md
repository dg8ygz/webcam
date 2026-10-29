# Taegliche Wartung mit temporaerem RW-Fenster

Nachtrag21.10.2026: Betreiber hat automatische Trixie-Paketupdates und
Mailpit-Berichte fuer .16/.17 beauftragt. Integration, Paketplan, geaenderte
Stop-/Timeout-Regeln sowie hostbezogene Installationsnachweise stehen in
unattended-mail-20261021 (lokale Betriebsdokumentation, nicht im Export).
Die unten beschriebene deaktivierte APT-Periodik und das30-Minuten-Limit
beziehen sich auf den urspruenglichen Stand vom03.10.

Betreiberauftrag 03.10.2026 fuer `.17` und anschliessend `.16`: Logrotation
und vorhandene Paketpflege auf schreibgeschuetztem System ermoeglichen,
danach wieder RO. Installation getrennt vom allgemeinen Kamera-Rollout.

`camera-rpi-system-maintenance.timer` startet taeglich **03:05 Europe/Berlin**
den gleichnamigen Oneshot. Er benutzt die bestehende globale Sperre
`/run/lock/camera-rpi.operation.lock` zusammen mit Kamera, Admin und
Sensorbackup. Nach maximal 120 s ohne Sperre erfolgt ein sichtbarer Fehler,
kein ungeschuetzter Remount. Kameraarbeit kann waehrend der Wartung warten;
der Zeitpunkt liegt zwischen den regulaeren Zehn-Minuten-Aufnahmen.

Die bisher getrennten Timer `apt-daily.timer`, `apt-daily-upgrade.timer`
und `logrotate.timer` werden deaktiviert. Ihre Dienste bleiben vorhanden.
Der gemeinsame Lauf fuehrt deren Wartungsprogramme nacheinander aus:

1. Root sowie vorhandene RO-Boot-Mounts temporaer RW.
2. `/usr/lib/apt/apt.systemd.daily update`.
3. `/usr/lib/apt/apt.systemd.daily install`.
4. Logrotate mit bestehender Konfiguration.
5. Synchronisieren, Boot-Mounts und Root wieder RO; Mountflags nachpruefen.

Auf `.16` wird der bisherige `--state /var/log/logrotate.status`-Aufruf
beibehalten (Datei im RAM). `.17` nutzt den bisherigen Distributionspfad.
Hostabweichung unter `/etc/default/camera-rpi-system-maintenance`.
Boot-Bindalias `/boot/firmware` auf `.16` wird separat nach `/boot`
geoeffnet und davor geschlossen.

Die **APT-Konfiguration wird nicht erweitert**: Bei der Bestandsaufnahme
waren auf beiden Pis keine periodischen Paketdownloads/-installationen
konfiguriert. Die APT-Jobs pruefen damit vor allem Paketkonsistenz und
sichern ihre Verwaltungsdaten. Die Loesung schaltet weder automatische
Versions-Upgrades noch Reboots ein. Eine spaetere bewusste APT-Aktivierung
kann Laufzeit und Kamerasperre deutlich verlaengern; dafuer Wartungszeit
und Aufnahmeauswirkung erneut pruefen. Boot-RW steht dafuer bereits bereit.

## Fehler und Rueckweg

Das Schreibfenster wird vor dem ersten Remount in root-exklusiven
RAM-Markern festgehalten. EXIT/TERM/HUP/INT stellen RO unter derselben
Sperre wieder her. Zusaetzlich ruft systemd `ExecStopPost=... restore` auf,
auch nach SIGKILL des Hauptprozesses, nachdem dessen Cgroup aufgeraeumt
wurde. Laufzeitlimit 30 Minuten, Stopfrist fuenf Minuten. Schlaegt RO fehl,
bleiben Marker erhalten und der Dienst meldet Fehler; kein stiller Erfolg
und kein RO-Bind-Fallback, der einen weiterhin schreibbaren Superblock
verdecken koennte. Ein schon vorher fremd RW geoeffnetes System wird nicht
uebernommen oder eigenmaechtig geschlossen.

Ein absichtliches SIGKILL gegen **alle** Unit-Prozesse kann auch den gerade
gestarteten ExecStopPost-Prozess treffen. Ebenso sind Kernelstillstand oder
Stromausfall keine durch Bash garantierbaren Abschlusswege. Nach Reboot
gelten die unveraenderten RO-Mounts aus `/etc/fstab`. Bei einem nicht
abgeschlossenen Fenster mit verbliebenem laufendem System die Marker und
Jobs pruefen, danach den `restore`-Aufruf ausfuehren.

Fehlgeschlagene Teiljobs werden protokolliert; nachfolgende Teiljobs und
RO-Rueckkehr werden trotzdem versucht. Nur erfolgreich nachgeholte
Originaljobs bekommen ihren alten systemd-Failed-Status zurueckgesetzt.
Andere failed-Dienste (Swap, Resize, Display) werden nicht kaschiert.
Manuell gestartete Originaljobs ausserhalb dieses Fensters werden nicht
automatisch RW geschaltet; fuer manuelle Wartung den gemeinsamen Dienst nutzen.

```sh
systemctl start camera-rpi-system-maintenance.service
systemctl list-timers camera-rpi-system-maintenance.timer
journalctl -u camera-rpi-system-maintenance.service
findmnt -no TARGET,OPTIONS -T /
```

Der Timer ist enabled, schreibt wegen `Persistent=false` keinen
persistent timestamp auf das RO-Dateisystem und holt ausgeschaltete
Tagesfenster nicht beim Boot nach.

Rueckbau im gesperrten RW-Fenster: neuen Timer deaktivieren, nur den neuen
Helper, Service/Timer und die neue Defaultdatei entfernen, daemon-reload.
Urspruengliche Timerzustaende aus der Sicherung wiederherstellen: `.17`
alle drei enabled/active; `.16` nur logrotate, beide APT-Timer bereits
vorher disabled/inactive. Bestehende Logrotate-Drop-ins unveraendert.

## Paket und Tests

Quellen: `scripts/camera-rpi-system-maintenance`, die beiden gleichnamigen
Dateien unter `systemd/`, `ops/install-system-maintenance.sh`.
Der Installer erwartet ein flaches, per SHA256SUMS geprueftes Paket und
einen separaten systemd-Oneshot mit ExecStopPost fuer das Installationsfenster.
Er verweigert vorhandene Ziele/fremdes RW, sichert Original-Timerzustaende
und fstab unter `/root/camera-system-maintenance-before-20261003.*`.
Keine Kamera-/Sensor-/Klimaquellen oder Zugangsdaten im Payload.

`python3 -B tests/system-maintenance.py`: acht Tests mit echtem flock,
Prozessen und isolierten Mount-/Wartungsattrappen, keine GPIO/IIO-Zugriffe.
Erfolg, Teilfehler, fremdes RW, gescheiterter RO-Remount mit spaeterer
Recovery, SIGTERM, SIGKILL/Restore, Firmwarealias und RAM-Logrotate-Pfad.
Isolierter realer systemd-Test auf dem Entwicklungsserver bestaetigt
ExecStopPost/RO-Recovery nach SIGKILL des Hauptprozesses. Ein vorangegangener
Test gegen die gesamte Unit-Cgroup zeigte die oben genannte Grenze.
Die urspruenglich knappen Testlaufzeitgrenzen wurden fuer langsamere ARM-
Prozessoren erweitert; produktive Fristen davon unveraendert.

## Live-Rollout 03.10.2026 (CEST)

Sieben Tests auf beiden ARM-Pis erfolgreich (.17 ca. 50 s, .16 ca. 51 s).
Waehrend der Tests echte Mounts weiterhin RO; keine Testprozesse verbleiben.
Danach beide Pakete anhand SHA256SUMS geprueft und getrennt installiert:

| Pi | Installation | Sicherung |
| --- | --- | --- |
| .17 | 13:32:40–13:32:51 | `/root/camera-system-maintenance-before-20261003.OmT8pzZv` |
| .16 | 13:34:33–13:34:44 | `/root/camera-system-maintenance-before-20261003.2aZaG3Rd` |

Erster echter Lauf `.17` **13:33:33–13:33:44**, alle drei Jobs OK,
Root und `/boot` danach verifiziert RO. Die alten Failed-Zustaende von
apt-daily/apt-daily-upgrade/logrotate sind behoben; die anderen drei
Failed-Units (Swap/Resize/Displaytest) wurden nicht zurueckgesetzt.
Timer auf beiden Pis enabled, naechster Lauf **04.10.2026 03:05 CEST**.
Private Deploymentdateien, Hashmanifest und Rohlogs:
`/var/local/webcam/system-maintenance-20261003/` auf dem Entwicklungsserver.
Kein allgemeines `install.sh`, Kamera-/Klima-Rollout oder Betriebssystem-Reboot.

Erster `.16`-Lauf 13:35:25–13:35:34: Wartungsprogramme erfolgreich und
Root, `/boot`, `/boot/firmware` anschliessend RO, aber falscher Gesamtfehler
beim bedingungslosen `systemctl reset-failed` bereits entladener/inaktiver
Originalunits. Korrektur: nur tatsaechlich `failed` gesetzte Units nach
erfolgreichem Job zuruecksetzen. Neue Regression laesst ein unnoetiges
Reset scheitern und prueft das gezielte Ruecksetzen eines echten Fehlers.
Keine Mount-/Kameraaenderung durch diese Korrektur.

Korrigierter Helfer nach acht erfolgreichen Tests auf jedem Pi um **13:41**
installiert und gegen die Repositorydatei geprueft (SHA256
`1ed074ad842a2b121a4bc5483d629e4303fde90a4ac6e9be05e8b49a80a0ab8c`).
Zusaetzliche Helfersicherungen: `.16`
`/root/camera-maint-helper-before-20261003.rX2HQ8bJ`, `.17`
`/root/camera-maint-helper-before-20261003.lQdhlvV3`.
Finales Quellmanifest: `ops/system-maintenance-20261003.sha256`.

**Finale Live-Abnahme:** korrigierter Lauf `.16` 13:42:14–13:42:22,
`.17` 13:42:17–13:42:25, jeweils Result=success/Exit 0 und alle drei
Teiljobs OK. Nachpruefung 13:43: Root und beide vorhandenen Boot-Pfade
RO, keine offenen Recovery-Marker. Kamera-, Klima- und Sensor-PIDs
unveraendert, NRestarts=0, aktuelle DHT-Messungen und kein Klimafault.
Regulaere 13:40-Originalbilder beider Kameras auf DEV/PROD vorhanden
(nach Erstinstallation, vor Helferkorrektur). Kein ausgeloester Capture-Test
und kein physischer Reboot-Test. Timerbereitschaft/enabled unabhaengig
geprueft; erster kalendergesteuerter Folgelauf am 04.10. bleibt offen.
