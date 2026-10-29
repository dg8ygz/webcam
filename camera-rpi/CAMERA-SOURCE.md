# Gemeinsame Aufnahmequelle: RTSP, USB oder gphoto2

Optional `/etc/camera-rpi/camera-source.conf`, root:root 0600. Fehlt die Datei,
gelten unverändert die bisherigen Server-/Pool- und Videoeinstellungen.
Vorhandene ungültige Profile werden abgelehnt; kein stiller USB-Fallback.
Die Quelle gilt für Fotos aller Ziele und den bedarfsgesteuerten Videodienst.

Auf der Testcam ist seit 28.09.2026 `rtsp://192.0.2.110:554/11` eingestellt.
Am Abend wurden beide Foto-Uploads, öffentliche HLS-Ausgabe und USB-Rückweg
erfolgreich geprüft. Der ursprüngliche Kontaktabbruch bleibt ungeklärt. Betriebsstand und Grenzen (lokale Betriebsdokumentation, nicht im Export).
Die Kamera liefert HEVC/1080p/15 fps ohne Authentifizierung. Der Pi liest per
RTSP/TCP und erzeugt JPEG-Fotos bzw. H.264-HLS mit 1080p/3 fps. Der Browser
benutzt weiterhin den vorhandenen Videoweg. Kein öffentliches RTSP-Relay.

## Quellenwechsel im Admin

Unter **Aufnahmequelle** den Kameratyp wählen:

- **RTSP – Netzwerkkamera:** RTSP-URL eingeben.
- **V4L2 – USB-Cam:** vorhandenes Gerät aus `/dev/v4l/by-id` auswählen oder
  den Gerätepfad darunter frei eingeben (`/dev/videoN` oder by-id-Pfad).
  Breite, Höhe, Anlaufbilder und Video-Eingangsbildrate erscheinen nur hier.
- **gphoto2 – Fotokamera:** „Standard – bewährte EOS-Sequenz“ oder
  „Alternativ – direktes gphoto2 Capture & Download“ wählen.
  Der HLS-Dienst bleibt bei gphoto2 ohne Kamera-/Encoderzugriff.

**Aufnahmequelle speichern** gilt für alle Ziele und übernimmt die Änderung
nach einer laufenden Kameraaktion. Die jeweils anderen Einstellungen bleiben
für den Rückwechsel erhalten. Zielserver-Adressen, Keys und Aktivierung werden
mit dem getrennten Serverformular gespeichert und dabei nicht geändert.
Die RTSP-URL ist ausschließlich im angemeldeten Admin sichtbar; sie kann
Zugangsdaten enthalten. Keine Ausgabe auf der öffentlichen Statusseite.

Speichern validiert zuerst vollständig, wartet auf den gemeinsamen Lock,
öffnet bei Bedarf das RW-Fenster und stoppt nur zuvor aktive Foto-/Videodienste.
Profiltausch erfolgt mit Synchronisierung und Inhaltsvergleich; bei Fehler
werden das vorherige Profil und die zuvor aktiven Dienste wiederhergestellt.
Sensor und Klima werden nicht neugestartet. Alte siebenfeldrige Profile bleiben
lesbar; `gphoto_capture_mode` ist optional und hat seit06.10.2026 den
Standardwert `eos-legacy`. Neue Ziele und Profile ohne Modus verwenden damit
die bewährte EOS-Sequenz. Die gespeicherten Kennungen bleiben kompatibel:
`eos-legacy` bezeichnet die EOS-Sequenz, ein ausdrücklich gespeichertes
`standard` weiterhin den direkten gphoto2-Aufruf. Eine ausdrückliche Auswahl
wird beim erneuten Speichern beibehalten, nicht stillschweigend umgedeutet.

## Quellenwechsel per Datei

In der privaten Datei genau eine Zeile aktiv lassen:

```ini
source=rtsp
# source=v4l2
# source=gphoto2
```

Für USB diese beiden Zeilen umkommentieren, dann:

```sh
sudo camera-rpi-source --check
sudo camera-rpi-source --apply
```

Der Helfer wartet bis zu 120 Sekunden auf die globale Kamerasperre und startet
nur die zuvor aktiven Foto-Worker und den Videodienst neu. Sensor und Klima
laufen weiter. Eine laufende Aufnahme darf vorher fertig werden. Kein Neustart
des Pi nötig. Die Dienste lesen die Quelle beim Start; bloßes Editieren reicht
nicht. Auf schreibgeschütztem Root zuvor das übliche Wartungsfenster nutzen.

Die USB-Alternative mit stabilem by-id-Gerätepfad, 1920x1080, 15 Warmup-Frames
und 30 Video-Eingangs-fps bleibt vollständig in der Datei stehen. Auch die
ursprünglichen Serverprofile und `video.json` bleiben erhalten. Die Verwaltung
zeigt und bearbeitet die effektive Quelle im eigenen Formular; Speichern
einer Serverkarte verändert das gemeinsame Quellenprofil nicht.

[Kommentiertes Beispiel](config/camera-source.example.conf).

## Ruhigeres Einzelbild aus RTSP (18.10.2026)

RTSP-Fotos warten jetzt zwei Videosekunden seit dem ersten beziehungsweise
letzten I-Bild und verwenden dann ein Folgebild. Ein neues I-Bild startet
diese Beruhigungsphase neu. Nach vier Videosekunden wird das naechste Bild
zugelassen, damit kurze GOPs oder All-I-Streams weiter Aufnahmen liefern.
Ohne Zeitstempel gilt die bisherige sofortige Auswahl. Der gesamte Abruf
bleibt auf 30 Sekunden plus zwei Sekunden Abbruchfrist begrenzt.

Die Auswahl entspricht dem serverseitigen DEV-RTSP-Fix. Sie ist keine
optische Schaerfemessung und garantiert bei einer anderen Kamera nicht
denselben Rauschgewinn. Kein Mitteln, Entrauschen oder Nachschaerfen.
Der gemeinsame Pool nimmt weiterhin nur einmal auf und verteilt das JPEG
an die aktiven Ziele. Foto-/Video-Sperren und Privacy bleiben erhalten;
HLS sowie USB- und DSLR-Aufnahmen verwenden diese Auswahl nicht.

Tests: `php tests/rtsp-settling.php` mit echten kleinen H.264-/HEVC-Videos;
Quelle/Datenschutz mit `php tests/camera-source.php`, harte Prozessfrist
mit `php tests/camera-source-timeout.php`.
Die Installation auf der Testcam ist gesondert in
RTSP-Beruhigungsphase (lokale Betriebsdokumentation, nicht im Export) belegt.

## Grenzen und Datenschutz

RTSP-Adresse nur lokal in root-geschützter Datei; Übergabe an FFmpeg über
stdin/ffconcat, nicht argv oder exportierte Umgebungsvariablen. Keine Ausgabe
der RTSP-Diagnostik mit möglichen Zugangsdaten. TCP-Lesezeitlimit 5 Sekunden,
Foto-Gesamtlimit 30 Sekunden plus 2 Sekunden zum Beenden; Teildateien werden
verworfen. Pool, Uploadbestätigungen, Privacy und Foto-Vorrang bleiben bestehen.
IP-Kamera-Aufnahmen benötigen keine USB-/GPIO-Klimapause. Video behält die
bestehenden Bedarfs-, Laufzeit- und Speichergrenzen.

Die Konfiguration ist optional und wird vom Installer nicht automatisch aktiviert.
FFmpeg wird für RTSP benötigt. Der Video-Updater liefert dessen neuen gemeinsamen
PHP-Helfer mit. Testcam-Deployment erfolgt als Patch gegen den gesicherten
Live-Stand, damit andere noch nicht installierte Änderungen nicht mitkommen.
