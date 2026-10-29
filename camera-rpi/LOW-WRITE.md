# SD-schonendes Betriebsprofil (optional)

Fuer den Raspberry Pi 4 mit ca. 4 GiB RAM, Debian/Raspberry Pi OS Trixie.
Installation nur bewusst durch root: `bash ops/enable-low-write.sh --apply`
aus einem vorbereiteten Paket mit `SHA256SUMS`. Nicht automatisch Teil eines
normalen `install.sh`-Updates. Es ist **kein vollstaendig schreibfreies OS**:
Konfiguration, Programme, Paketverwaltung und Sensor-Archive bleiben persistent.
Root wird nicht generell RO gesetzt; der Backup-Helper unterstuetzt bereits
RO gestartete Systeme durch ein gesperrtes, temporaeres Schreibfenster.

| Daten | Ablage | Grenze / Lebensdauer |
| --- | --- | --- |
| Sensorstatus und Verlauf | `/run/camera-rpi-status` | 48 Stunden Verlauf |
| Reglerverlauf (Taupunkt/Abstand/PWM/Heizung) | `/run/camera-rpi-status/climate-history.tsv` | bis 48 h, max. 8192 Zeilen, unter 1 MiB; laufend RAM, taegliche SD-Sicherung |
| Kamera-Spool aller Ziele | tmpfs auf `/var/lib/camera-rpi/spool` | gemeinsam 256 MiB |
| Workerstatus, Schluessel-Arbeitsdateien, lokale PHP-Sessions | `/run` | bis Neustart |
| Apache-/Systemlogdateien | tmpfs auf `/var/log` | 64 MiB |
| Journal | `/run/log/journal` | 32 MiB |
| temporaere Daten | `/tmp` (bereits tmpfs), `/var/tmp` | `/var/tmp`: 64 MiB |
| NetworkManager-DHCP-Lease | einzelne `*.lease`-Dateien als RAM-Bind-Mount | Konfiguration und Schluessel bleiben persistent |

Beim Netzwerkstart legt `camera-rpi-networkmanager-ramlease.service` jede
vorhandene NetworkManager-Lease-Datei zunächst unter `/run` (tmpfs) ab und
bind-mountet nur diese einzelne Datei zurück auf ihren ursprünglichen Pfad.
Dadurch bleiben DHCP-Erneuerungen auch bei schreibgeschütztem Root möglich,
ohne den gesamten Ordner `/var/lib/NetworkManager` oder dessen Schlüssel zu
verdecken. Systeme ohne aktiven NetworkManager oder ohne Lease-Datei benötigen
keinen Mount; der Dienst bleibt dort trotzdem vorbereitet.
| Logrotate-Zustand | `/run/logrotate/status` | bis Neustart |
| Sensor-/Regler-Sicherungen | `/var/lib/camera-rpi/sensor-backup` | letzte 30 Tagessicherungen je Reihe (`sensor-*.tsv`, `climate-*.tsv`) |

Die tmpfs-Grenzen sind Obergrenzen, keine Vorabreservierung. Die zusaetzlichen
Mounts begrenzen den Verbrauch zusammen auf 384 MiB. Bei voller RAM-Spool
koennen neue Bilder nicht aufgenommen werden; schon wartende Bilder werden
nicht zur Platzbeschaffung geloescht. Die vorhandenen getrennten Zielspools
bleiben getrennt. Der Kernel darf RAM in reines ZRAM komprimieren; das Profil
setzt bei rpi-swap `Mechanism=zram`, damit kein SD-Writeback genutzt wird.
Andere separat konfigurierte Disk-Swap-Geraete sind vor Anwendung zu pruefen.

## Sicherung und Wiederherstellung

Für reguläre Systemwartung auf bereits RO gestarteten Kameras gibt es den
separaten `camera-rpi-system-maintenance.timer` um **03:05 Europe/Berlin**.
APT und Logrotate laufen dabei unter dem gemeinsamen Kamera-Operationslock
in einem begrenzten Schreibfenster. Stromberg nimmt seit 11.10.2026 zusätzlich
Uhrspeicherung und Locate auf; die fünf unabhängigen Debian-Timer sind dort
deaktiviert. Details und Rückweg: DK0RE-Wartungsabnahme (lokale Betriebsdokumentation, nicht im Export).

Auf Bielefeld und Steinhagen sind seit 16.10.2026 ebenfalls Uhrspeicherung
und Locate in dieses Fenster integriert. Mit der expliziten Option
`CAMERA_RPI_MAINT_TRIXIE_JOBS=1` kommen apt-listchanges (nur lokale Daten,
kein Netzwerk, maximal 120 Sekunden) und die dpkg-Datenbanksicherung hinzu.
Alle sieben eigenstaendigen Timer sind dort deaktiviert; die zugehoerigen
Dienste ueberspringen Starts auf RO-Root. Teilfehler bleiben sichtbar,
beenden aber nicht die nachfolgenden Jobs oder die RO-Rueckstellung.
Abnahme und Rueckweg .16/.17 (lokale Betriebsdokumentation, nicht im Export).

Taeglich um **00:05 Europe/Berlin** schreibt ein systemd-Timer den Verlauf
atomar als TSV auf SD (Datei und Verzeichnis werden synchronisiert).
Dateinamen `sensor-YYYYMMDD.tsv` verwenden ebenfalls das Datum in Europe/Berlin.
Die 48h RAM-Historie liefert Ueberlappung zwischen den taeglichen Snapshots.
Mit der Regler-Backuperweiterung kommt im selben Job `climate-YYYYMMDD.tsv`
hinzu: Taupunkt/Abstand/PWM/Heizung mit Betriebsart und Zustandsmarkern. Je
unter 1 MiB, 30 erfolgreiche Tagesdateien, ohne Aenderung des Sensorformats.
Beide Reihen teilen sich das eine gesperrte Schreibfenster; ein Fehler der
einen Sicherung verhindert nicht den Versuch der anderen, meldet den Job aber
als fehlgeschlagen. Erweiterung in PHP/Shell, Sensorarchivierung unveraendert.
Ein bereits vorhandenes Tagesbackup wird nicht erneut geschrieben. Bei
fehlenden/ungueltigen Messwerten bleiben alte Sicherungen unangetastet.
Erst nach erfolgreichem Schreiben werden eigene Archive jenseits der letzten
30 Sicherungen entfernt. Andere Dateien werden nicht geloescht.

Beim Boot liest der Restore-Service den Verlauf vor Sensor- und Reglerstart von SD
ins RAM, mit maximal 30 Sekunden Wartezeit auf die Zeitsynchronisation.
Es werden nur plausible Messungen der letzten 48h uebernommen, nicht
`current.status`. Offline mit falscher Uhr ist diese Alterspruefung eingeschraenkt;
die Archive bleiben erhalten. Keine aktuellen Stellwerte, Fehlermerker oder
Regelpuffer werden restauriert. Ein bereits laufender Regler sperrt eine
manuelle Rueckschreibung seines Verlaufs, damit sein RAM-Cache nicht kollidiert.
Ein manueller gemeinsamer Restore benoetigt deshalb ein abgestimmtes Wartungs-
fenster ohne laufende Sensor-/Reglerschreiber; nicht waehrend des DHT-Langzeittests.

Es gibt keinen SD-Schreibvorgang bei jeder Messung. Das Basisprofil sichert
nur täglich; die separat installierte [Reboot-Sicherung](REBOOT-BACKUP.md)
schreibt zusätzlich bei jedem geordneten Shutdown einen frischen Checkpoint
mit Verläufen, fertigen Bildwarteschlangen/Poolmetadaten und begrenztem Journal.
Sie funktioniert auch, wenn das Tagesbackup bereits geschrieben wurde.
Der Timer speichert selbst keinen persistenten Zeitstempel und holt bei
ausgeschaltetem Pi keine verpassten Termine nach. Ist der Pi zum Termin aus,
oder schlaegt das Backup fehl, ist die letzte Sicherung entsprechend aelter.
Bei Stromausfall gehen ungesicherte RAM-Daten weiterhin verloren. Ohne
Reboot-Erweiterung sind auch geordnete Neustarts für RAM-Bilder/Logs verlustreich.
Mit der Erweiterung werden fertige Warteschlangen beim Boot wiederhergestellt;
ein älterer Checkpoint kann nach hartem Stromverlust bereits bestätigte Uploads
erneut enthalten. Für Schutz auch zwischen Checkpoints wäre ein persistenter
Spool oder eine gesicherte Stromversorgung nötig.

## Installation / Abnahme

Der Updater prueft Standardpfade, freien RAM, bestehende Mountdefinitionen
und vorhandene Profil-Dateien. Er sichert Worker, Sensorconfig und alten
Spool in einem privaten `/root/camera-rpi-low-write-before.*`-Ordner.
Aktive Kamera-Worker werden fuer die RAM-Spool-Migration kurz gestoppt.
Der Inhalt wird nach dem Einhaengen byteweise verglichen und die zuvor
aktiven Worker werden wieder gestartet. Keys bleiben unveraendert.
Ein erstes Sensorbackup wird sofort angelegt.

**Danach einmal kontrolliert neu starten:** `/var/log`, `/var/tmp`, die neue
ZRAM-Konfiguration und die 48h-Historie gelten vollstaendig erst nach Reboot.
Bis dahin keine vollstaendige RAM-only-Abnahme behaupten. Die alten
SD-Dateien unter den neuen Mounts bleiben verdeckt erhalten (kein automatisches
Loeschen, keine erneute Uebernahme alter Bildauftraege bei jedem Boot).

```sh
findmnt -T /var/lib/camera-rpi/spool
findmnt -T /var/log
findmnt -T /var/tmp
cat /sys/block/zram0/backing_dev   # erwartet: none
cat /proc/swaps                   # kein separater Disk-Swap
systemctl list-timers camera-rpi-sensor-backup.timer --no-pager
systemctl status camera-rpi-sensor-restore.service --no-pager
ls -l /var/lib/camera-rpi/sensor-backup
cat /run/camera-rpi-status/current.status
free -m
```

Sensor-Backupfehler stehen im Journal von `camera-rpi-sensor-backup.service`.
Dieselbe Unit meldet auch Regler-Backupfehler. `ops/update-climate-backup.sh`
erweitert bestehende Installationen mit vorheriger Code-Sicherung und erstem
Reglerarchiv ohne Dienstneustarts. Der naechste Tageslauf/Boot-Restore muss
anschliessend im realen Betrieb nachkontrolliert werden.
Die Sicherung liegt auf derselben SD: sie schuetzt gegen RAM-Verlust, nicht
gegen einen Defekt der SD-Karte. Updates, SSH-Login-/OS-Zustaende ausserhalb
der genannten Pfade koennen weiterhin SD-Schreibzugriffe verursachen.

## Ruecknahme nach erfolgreicher Installation

Nur im Wartungsfenster als root: Worker stoppen; noch wartende RAM-Bilder
separat sichern. Sensor stoppen. Backup-Timer stoppen/deaktivieren. Die in
`ops/enable-low-write.sh` aufgefuehrten Profil-Units/Drop-ins entfernen,
Mount-Units vorher deaktivieren. Gesicherte Sensorconfig und Worker
wiederherstellen. Neu starten (Logs nicht im laufenden Apache aushaengen).
Die verdeckten alten SD-Spooldateien werden wieder sichtbar: vor Start der
Worker alte Auftraege gegen die gesicherten aktuellen RAM-Auftraege abgleichen,
damit keine alten Bilder erneut versendet werden. Nicht blind alles loeschen.
