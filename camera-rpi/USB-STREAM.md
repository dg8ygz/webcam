# Video bei Bedarf (experimentell)

Seit 28.09.2026 kann die Testcam alternativ eine gemeinsame RTSP-Quelle nutzen;
Konfiguration und Rückwechsel: [CAMERA-SOURCE.md](CAMERA-SOURCE.md).

Nur USB-Testcam, keine Aktivierung auf Canon .16/.17 oder PROD. Version der
Raspi-Software ist seit 28.09.2026 1.1. Neue Steuerung in PHP/Shell, kein neues Python.

## Datenweg und Lebensdauer

Auf der Testcam am 22.10.2026 installiert: Video-Fehlerretry ohne verbleibende
Freigabe mit 60/120/240/maximal 300 Sekunden Pause. Erfolg setzt den
Fehlerzaehler zurueck; regulaer bleiben 10 Sekunden, waehrend einer noch
gueltigen Freigabe bei Transportfehlern 2 Sekunden. Keine Lease-Verlaengerung
durch Fehler. Installation, Folgebild auf beiden Zielen und ein natuerlicher
Timeout mit 60-Sekunden-Pause sind in
Abnahme und Rueckweg (lokale Betriebsdokumentation, nicht im Export) dokumentiert.
Die folgenden Angaben zum 2-Sekunden-Fehlerretry gelten nur innerhalb einer
noch gueltigen Freigabe.

Der getrennte Video-Poll fragt alle 10 Sekunden `include/video-command.php`
mit dem bestehenden privaten Command-Key ab. Er verbraucht keinen Fotoauftrag
und öffnet keinen SSH-Zugang. Nur ein zugelassener Zuschauer im Server-Player
erneuert Bedarf. Ohne Abrufe endet dieser nach 45 Sekunden, die Pi-Freigabe
spätestens weitere 30 Sekunden danach. Vorübergehende Transportfehler und HTTP-5xx
erhalten nur die Restlaufzeit einer bereits erteilten Freigabe; Wiederholung
nach zwei Sekunden, ohne Verlängerung. Auth-/TLS-Fehler, ungültige Antworten und
eine ausdrückliche Sperre schließen bei der nächsten Abfrage. Kontrollabrufe
sind auf zwei Sekunden Verbindungsaufbau und fünf Sekunden Gesamtzeit begrenzt. Der Pi nutzt monotone Zeit und begrenzt einen
durchgehenden Lauf auf 30 Minuten; anschließend muss Bedarf erst erlöschen.

FFmpeg erzeugt H.264-HLS im RAM: 1920x1080, 3 fps, CRF25, veryfast bei USB / ultrafast bei RTSP,
maxrate 1500 kbit/s, zwei Encoderthreads. CRF25 ist nicht JPEG-qscale25!
Die GENERAL WEBCAM bietet MJPEG-1080p nur bei 30 Eingangs-fps; `fps=3`
reduziert vor dem Encoding. Tatsächliche Bandbreite/CPU erst live messen.
Sechs Zweisekunden-Segmente plus Löschreserve; Überwachung bei 16 MiB.
Nicht als Aufnahme-/Archivsystem verwenden. Keine Videos im Tagesbackup.

`camera-rpi-video.php` besitzt FFmpeg, den privaten HTTP-Server und Chisel als
Kindprozesse. Abbruch: TERM, höchstens zwei Sekunden, KILL, anschließend reap.
systemd ergänzt control-group cleanup, 384 MiB, maximal drei CPU-Kerne (300%) und Nice=10.
PHPs HTTP-Router liefert nur freigegebene HLS-Namen, kein Directorylisting,
keine Konfigurations-/Schlüsseldateien. Zusätzlich Bearer-Token erforderlich.

Chisel verbindet ausgehend per geprüftem HTTPS und gepinntem SSH-Fingerprint
zu `/camera-maintenance/video/`. Das ist ein Unterpfad am vorhandenen Edge,
aber ein **eigener Relayprozess**, eigener Schlüssel und eigene Credentials.
Serverlistener 127.0.0.1:9087, einzig erlaubter Reverse-Port 127.0.0.1:23001,
Pi-Ziel 127.0.0.1:18087. Kein SSH-Port, SOCKS oder freies Proxyziel. Wie bei
Chisel üblich beschränkt die Reverse-ACL den Listener, nicht die Zieladresse
auf einem kompromittierten Pi; der Pi bleibt eine vertrauenswürdige Quelle.

## Foto-Vorrang

`cr_capture_to_file` kündigt reale V4L2- und RTSP-Aufnahmen über den
SH-Lock `camera-rpi.operation.lock.video.priority` an. Der Videodienst erkennt
dies, beendet alle Videokinder und gibt `.video` frei. Foto wartet höchstens
acht Sekunden. Deskriptoren gehören dem Capture-Subprozess bis zu dessen Ende.
Die bestehende globale Kamera-/Pool-Sperre bleibt unabhängig: Uploads und
Poolverwaltung unterbrechen Video nicht. Nach dem Foto startet ein neuer
Live-Ausschnitt mit neuer Generation und fortschreitender HLS-Sequenz.
Kein GPIO-, Fokus-, Stromschalt- oder Sensorbefehl im Videodienst.

## Installation und Rückweg

Testcam benötigt `ffmpeg`, PHP CLI mit curl/pcntl/posix und Chisel1.11.5.
Auf dem derzeitigen schlanken Test-Pi bringen FFmpeg-Abhängigkeiten zusätzlich
146 Pakete/ca.381 MB; kein X-Server installiert. Kein Systemupgrade/Reboot.

`ops/update-video.sh --apply` prüft das Paket, bekannte Common-Baselines und
gestoppte Kamera-Worker; sichert ersetzte Dateien samt alten Inodes. Es startet
keinen Dienst. Private Registrierung separat als root:root/0600 unter
`/etc/camera-rpi/video.json` installieren. Beispiel ist absichtlich ungültig
ohne individuelle Credentials. **Vor Videostart müssen beide Kamera-Worker
mit der neuen Common-Bibliothek neu gestartet sein.**

Erstprobe mit `systemctl start camera-rpi-video.service`, nicht gleich enable.
Start/Stop unter DEV `usb-live.php?camera=test`; vor Start keine Kameranutzung.
`journalctl -u camera-rpi-video.service` und `/run/camera-rpi-video/status`
zeigen Prozess-/Leasezustand, nicht behauptete Bildqualität.

Sofort stoppen: `systemctl stop camera-rpi-video.service`. Das beendet nur
Video, Foto-/DHT-/Klimadienste bleiben aktiv. Rückweg: Video stoppen, Worker
nach Fotoabschluss stoppen, Dateien aus der ausgegebenen privaten Sicherung
an die in `targets` notierten Pfade zurückstellen, daemon-reload und nur die
vorher aktiven Worker starten. Neue Dateien sind durch `files/N.absent`
markiert; nicht vorhandene Altdateien nicht aus einem anderen Paket kopieren.
Keine Sensorwerte, Spoolbilder, Schlüssel oder Kameraeinstellungen löschen.

Serverseite: `../webcam/ops/usb-stream.md`. Hardware-/Langzeitabnahme ist
getrennt von den lokalen Vertrags- und synthetischen FFmpeg-Tests.

Erste echte Abnahme13.09.: Full-HD/3fps im Browser, Foto18:50 an beide Ziele
bestätigt, Stream danach wiedergegeben und ohne Zuschauer18:51:35 geschlossen.
Testcam-Dienst nun bootaktiviert, aber ohne Zuschauer weiterhin geschlossen.
Keine DHT-/Sensorneustarts. Grob ein CPU-Kern und265MiB für FFmpeg;
weitere Last-/Langzeitoptimierung steht aus. Details im Server-Runbook.
