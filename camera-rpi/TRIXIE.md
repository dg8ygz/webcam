# Neuer Kamera-Pi mit Raspberry Pi OS Trixie

Stand: 10.09.2026. Softwarepruefung auf Debian 13/Trixie mit PHP 8.4;
eine V4L2-Einzelbildaufnahme ist auf dem neuen Pi 4/arm64 geprueft, der
Dienst samt bestaetigtem Legacy-Upload ist ebenfalls geprueft; Reboot und echte
GPhoto2-/DHT22-Hardwareabnahme stehen noch aus. GPhoto2 (z.B. Canon
per USB) bleibt Standard. USB-Webcams mit nativem MJPEG sind optional ueber
V4L2 nutzbar; CSI/rpicam und reine YUYV-Kameras sind nicht implementiert.
DHT22 wird getrennt ueber Linux-IIO gelesen; kein pip, Adafruit-DHT,
RPi.GPIO oder WiringPi erforderlich. Pi-Modell und Verdrahtung zuerst pruefen.

## Uebertragung ohne Root-SSH

Auf dem Entwicklungsserver `.34` als Benutzer mit Leserecht am Repository
(aktuell root), `BENUTZER` und `PI-IP` durch den neuen Pi ersetzen:

```sh
cd /var/www/html/camera-rpi
git status --short
# Nur fortfahren, wenn keine uncommitteten Aenderungen angezeigt werden.
git rev-parse HEAD
set -o pipefail
git archive --format=tar HEAD |
  ssh BENUTZER@PI-IP 'umask 077; mkdir camera-rpi-install && tar -xf - -C camera-rpi-install'
```

Der Zielordner muss neu sein; ein vorhandenes Paket wird nicht ueberschrieben.
SSH-Hostkey pruefen. Es werden nur committete Dateien, keine Live-Keys kopiert.
Der alte `deploy-to-camera-rpi.sh` bleibt bewusst fuer UID 0 und RO-Pis;
er ist nicht der Transportweg fuer diesen frischen RW-Pi.

## Auf dem neuen Pi installieren

Mit dem normalen SSH-Benutzer anmelden; dieser muss sudo verwenden duerfen.
Die Befehle **auf dem Pi**, nicht auf `.34`, ausfuehren:

```sh
cd camera-rpi-install
sudo apt-get update
sudo bash install.sh --core --web cameraadmin --packages --check
sudo bash install.sh --core --web cameraadmin --packages
```

Fuer den USB-Webcam-Test beide Installer-Aufrufe um `--with-v4l2` ergaenzen.
Dies installiert/prueft zusaetzlich `v4l-utils` und `python3` (nur Standard-
bibliothek, kein pip). gphoto2 bleibt fuer den spaeteren Wechsel enthalten.

In der Admin-Seite „Aufnahmequelle: V4L2“ waehlen. Geraet bevorzugt anhand von
`ls -l /dev/v4l/by-id/` festlegen; unterstuetzte MJPEG-Aufloesungen zeigt
`v4l2-ctl --device=/dev/video0 --list-formats-ext`. Beispiel fuer die derzeit
angeschlossene Testkamera (GENERAL WEBCAM):

```ini
capture_backend=v4l2
video_device=/dev/v4l/by-id/usb-GENERAL_GENERAL_WEBCAM_JH0319_20210727_v017-video-index0
video_width=1920
video_height=1080
video_warmup_frames=15
```

V4L2 erlaubt 16–4096 Pixel Breite, 16–2160 Pixel Hoehe und 0–120 Anlaufbilder.
Die Kamera muss exakt die gewaehlte Groesse als MJPEG liefern. Es gibt keinen
stillen Format-/Quellwechsel und kein Transcoding. Formatabfrage maximal 5 s,
Aufnahme maximal 30 s, JPEG-Pruefung maximal 5 s (jeweils 2 s Kill-Nachlauf).
Die Aufnahme ist durch Dateigroessenlimit, JPEG-Marker-/Dimensionspruefung und
die gemeinsame Kamerasperre geschuetzt. Anhangbytes hinter dem echten JPEG-Ende
werden vor Statusanzeige/Upload entfernt; Bilddaten bleiben unveraendert.
Fehler behalten den Auftrag fuer Retry. Alte Konfigurationen bleiben gphoto2.
Fuer die spaetere Fotokamera nur die Aufnahmequelle auf gphoto2 zurueckstellen;
Speichern startet einen vorhandenen aktivierten Worker mit der neuen Auswahl.

**Hardware-Einzeltest 10.09.2026:** Pi 4 Model B Rev 1.1, Debian 13.6 arm64,
Kernel 6.18.39+rpt-rpi-v8, GENERAL WEBCAM USB 1b3f:2247/uvcvideo.
1920×1080, 15 Anlaufbilder, geprueftes JPEG 88.757 Bytes, 1,255 s inklusive
Formatabfrage, Aufnahme und Pruefung. JPEG danach mit PHP/GD dekodiert.
Die erste Rohaufnahme hatte 18 Anhangbytes hinter EOI; genau deshalb wurde
die Markerpruefung ergaenzt. Nur temporaere Testdateien im Benutzerkontext,
keine Pakete/Dienste auf dem Pi installiert, kein Upload nach DEV/PROD.
Live-MJPEG-Uebertragung bleibt ausdruecklich zurueckgestellt.

**Vorbereitung der ersten Testkamera 10.09.2026:** Das gepruefte Paket liegt
in einem neuen privaten Installationsordner im Home des Pi-Administrators;
alle uebertragenen Quelldateien wurden per SHA-256 verglichen. Der passende
DEV-Detailendpunkt ist vom Pi aus per HTTPS mit Zertifikatspruefung erreichbar.
Nach ausdruecklicher Freigabe wurde ein eigener Key nach gepruefter SQLite-
Sicherung nur fuer die Testkamera erzeugt und SSH-verschluesselt in einer
privaten 0600-Eingabedatei uebertragen. Authentifizierte HTTPS-Capabilities vom
Pi: HTTP 200. Der Betreiber hat Core/Web/V4L2-Pakete installiert; die lokale
Statusseite antwortet mit HTTP 200. Zielworker-Aktivierung und erster Upload
stehen noch aus; auf DEV fehlt noch die Current-Runtime-Freigabe der neuen
Kamera. Kein Key in dieser Dokumentation oder im Repository.

Die Infoseite ist jetzt unter `http://PI-IP/` allgemein ohne Anmeldung
vorgesehen. Die Verwaltung unter `/camera-admin/` bleibt passwortgeschuetzt.
Die fruehere localhost-Beschraenkung war keine Betreiberanforderung und wurde
entfernt. Die aktualisierte Apache-Vorlage muss auf dem Pi installiert werden.

`--check` simuliert hier nur die Paketaufloesung; es ist keine Hardware- oder
Apache-Abnahme. Der zweite Aufruf installiert benoetigte Pakete, Worker,
Sensor-Dienst und lokale Webseiten und fragt nach einem HTTP-Passwort.
Kein allgemeines `apt upgrade`, keine neue Kamera-Konfiguration, keine
Aktivierung eines Kamera-Workers und keine automatische GPIO-Aktivierung.
Bestehende Keys und Serverdateien bleiben erhalten. Neue Sensor-Konfiguration
ist deaktiviert; der Dienst zeigt diesen Zustand an.

`--packages` verlangt bereits schreibbare Root-/Boot-Dateisysteme. Es setzt
weder fstab noch OverlayFS oder Mountmodi um. Auch Worker und Admin-Helfer
setzen ein anfangs schreibbares Root nicht eigenmaechtig auf read-only.
Ein spaeterer RO-Umbau ist eine separate Betriebsaufgabe, keine Voraussetzung
fuer die erste Trixie-Inbetriebnahme. Bei Desktop-Images darf kein anderer
Kamera-/Automountprozess die GPhoto-Kamera gleichzeitig belegen.

## Abnahme und Einrichtung

```sh
sudo apache2ctl configtest
sudo systemctl status camera-rpi-sensor.service --no-pager
sudo gphoto2 --auto-detect
sudo camera-rpi-healthcheck --expect-root=rw
```

Ohne konfigurierte Zielserver ist die Warnung `keine Zielserver eingerichtet`
erwartet; ein spaeteres OK ersetzt keine echte Capture-/Upload-Abnahme.
Der Healthcheck erwartet ohne Option weiterhin RO (Bestands-Pi).
Direkt `http://PI-IP/` fuer Bild/Klima bzw. `/camera-admin/` fuer die
passwortgeschuetzte Verwaltung oeffnen. Optional verschluesselt per Tunnel:

```sh
ssh -N -L 8088:127.0.0.1:80 BENUTZER@PI-IP
```

Dann `http://127.0.0.1:8088/camera-admin/` und
`http://127.0.0.1:8088/camera-status/` oeffnen. Zielkamera zuerst im
Webcam-Server anlegen, passenden eigenen Key verwenden, dann einen
DEV-Zielserver auf dem Pi konfigurieren. Echten Capture, bestaetigten Upload
und Retry pruefen, bevor PROD aktiviert wird. [Admin](ADMIN.md).

Fuer DHT22 zuerst `dtoverlay -h dht11` auf dem Pi pruefen. Erst nach
GPIO-/Verdrahtungspruefung das Overlay in `/boot/firmware/config.txt`
aktivieren, rebooten und IIO-Messwerte pruefen. Danach `device.conf`
aktivieren: [Sensoranleitung](STATUSSEITE.md). Keine automatische Pinwahl.

## Kompatibilitaetsquellen und Grenzen

- [Debian Trixie gphoto2](https://packages.debian.org/trixie/gphoto2):
  Paket auch fuer armhf/arm64. Vorhandenes Paket beweist keine Unterstuetzung
  jedes Kameramodells.
- [Debian Trixie PHP-Modul](https://packages.debian.org/trixie/libapache2-mod-php):
  PHP 8.4. Das Projekt braucht keine architekturspezifisch gebauten Module.
- [Raspberry-Pi-Kernel Overlayliste, 6.12](https://github.com/raspberrypi/linux/blob/rpi-6.12.y/arch/arm/boot/dts/overlays/README):
  `dht11` unterstuetzt DHT11/DHT21/DHT22, GPIO ueber `gpiopin`.

Die lokalen Tests simulieren GPhoto, HTTP und IIO-Messdateien. Reales
GPIO-Timing, USB-Kamera, Reboot und systemd-Sandbox auf dem konkreten Pi
muessen nach Installation noch abgenommen werden. Keine Hardwarefreigabe
allein aus gruenen Simulationstests ableiten.
