# RAM-Sicherung bei geordnetem Reboot und Shutdown

Zusätzlich zum einmal täglichen Backup wird vor einem geordneten Reboot oder
Herunterfahren ein eigener Checkpoint erstellt – auch wenn das Tagesbackup
bereits existiert. Kein zusätzlicher periodischer SD-Schreibzugriff.

Gesichert werden:

- Sensor- und Klimaverlauf (`history.tsv`, `climate-history.tsv`).
- Fertige JPEGs mit Legacy-Zeitstempel und Wettermetadaten, gemeinsame
  Poolgenerationen und deren noch offene Upload-Ziele. Hardlinks bleiben
  erhalten; dasselbe Bild benötigt weiterhin nur einmal Platz.
- Bis zu 5000 Journalzeilen des laufenden Boots, begrenzt auf 8 MiB. Das ist
  der RAM-Log der neuen Kamera-Dienste, kein Abbild des gesamten `/tmp`.

Nicht übernommen werden unfertige Aufnahmen (`.part`/`.build`), alte
Capture-/Fokusbefehle, Keys, GPIO-Snapshots, Prozesssperren oder aktuelle
Sensor-/Reglerzustände. Die Heizung startet nicht aus einem alten Sollwert.

## Ablauf und Grenzen

`camera-rpi-reboot-save.service` wird beim Start mit einem No-op scharfgestellt.
Beim Shutdown sorgt systemd dafür, dass Worker, Sensor und Klima zuerst
beendet werden. Danach sichert ExecStop die ruhenden Daten unter der gemeinsamen
Operationssperre; RAM-Mounts und Journal sind noch verfügbar. Root wird nur
für dieses Schreiben RW gesetzt und anschließend wieder RO, wenn es zuvor RO
war. Ein manueller Stop der Sicherungsunit bei laufenden Verbrauchern wird
ausdrücklich abgelehnt, nicht als gültige Sicherung ausgegeben.

Unter `/var/lib/camera-rpi/reboot-backup/` liegen zwei erfolgreiche
Checkpoint-Generationen. Ein neuer Stand wird vollständig geschrieben und
geprüft, bevor der Zeiger `current` atomar umgestellt wird. Frühere zusätzliche
Generationen werden danach entfernt; die unmittelbar vorherige bleibt als
Rückweg. Obergrenze pro Stand: 192 MiB und 4096 Dateien, zusätzlich wird freier
Speicher geprüft. Ein fehlgeschlagener Schreibvorgang ersetzt den alten Zeiger
nicht; abgebrochene `.checkpoint-*`-Verzeichnisse bleiben zur Diagnose erhalten.

Beim Boot läuft `camera-rpi-reboot-restore.service` vor Tagesarchiv-Restore und
Kamera-Workern. Vor dem Kopieren werden alle Hashes und Pfade geprüft. Ziel ist
ausschließlich der leere RAM-Spool; vorhandene Bilder/Verläufe werden nicht
überschrieben. Ein ungültiger Checkpoint verhindert den Workerstart, damit
eine beschädigte Warteschlange nicht unbemerkt benutzt wird. Der vorhandene
Tagesarchiv-Restore führt danach die Verläufe zusammen und filtert alte Daten.
Logs bleiben privat auf SD und werden nicht ins aktuelle Journal eingespielt.

**Kein Schutz vor hartem Stromausfall, Reset oder erzwungenem Shutdown.**
systemd kann trotz eines fehlgeschlagenen ExecStop weiter herunterfahren;
Fehler werden protokolliert, sind aber kein garantierter Reboot-Abbruch.
Bei Wiederherstellung eines älteren Checkpoints nach Stromverlust können
Uploads wiederholt werden: bestätigte Uploads werden nicht einzeln auf SD
protokolliert. Die Bildzeit bleibt dabei die ursprüngliche Zeit.

## Installation und Prüfung

Das geprüfte Paket wird mit `bash ops/update-reboot-backup.sh --apply`
installiert. Erstinstallation neben laufenden Verbrauchern; keine Änderung
an GPIO, Kamera, DHT-Treiber oder Tagesbackup. Es startet nur die No-op-
Sicherungsunit, nicht den Restore und nicht die bestehenden Dienste.

```sh
systemctl is-active camera-rpi-reboot-save.service
journalctl -b -u camera-rpi-reboot-restore.service --no-pager
sudo cat /var/lib/camera-rpi/reboot-backup/current
```

Zum Deinstallieren erst ein Wartungsfenster mit beendeten Verbrauchern wählen:
Sicherungsunit stoppen, beide neuen Units deaktivieren, die im ausgegebenen
Installationsverzeichnis unter `new-files` aufgeführten neuen Dateien prüfen
und entfernen, anschließend daemon-reload. Keine bestehenden Dateien wurden
überschrieben. Checkpoints separat behalten; keine automatische Datenlöschung
bei Deinstallation. Alte Updates/Pakete werden nicht nachträglich verändert.

`php tests/reboot-data.php` prüft echte Dateien, Hardlinks, mehrfaches Sichern
am selben Tag, leere Folge-Warteschlange, Korruption und Aufbewahrung. Keine
Hardwarezugriffe. Ein realer Shutdown-/Bootlauf ist eine gesonderte Abnahme.
