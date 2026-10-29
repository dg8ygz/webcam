# Dateien und Pakete per SSH auf den Kamera-Pi übertragen

Fuer einen **neu installierten, schreibbaren Trixie-Pi mit sudo-Benutzer**
gilt [TRIXIE.md](TRIXIE.md). Der folgende historische SSH-Helfer verlangt
weiterhin echte UID 0 und ein anfangs schreibgeschuetztes Root-Dateisystem.

`deploy-to-camera-rpi.sh` erledigt den technischen Transport vom lokalen
Entwicklungsserver zum Kamera-Pi. Es ist kein automatischer PROD-Schalter: Nach
der Übertragung existiert noch keine Zielserver-Konfiguration und kein neuer
Kamera-Worker wird aktiviert.

## Einfacher interaktiver Aufruf

```sh
cd /var/www/html/camera-rpi
./deploy-to-camera-rpi.sh
```

Das Script fragt nacheinander:

1. IP-Adresse oder DNS-Name,
2. Bestätigung des angezeigten Git-Commits,
3. SSH-Passwort für den Root-Login.

Adresse und Benutzer können auch vorgegeben werden:

```sh
./deploy-to-camera-rpi.sh --host 192.0.2.10 --user root
```

Mit Admin- und lokaler Bild-/Klima-Webseite:

```sh
./deploy-to-camera-rpi.sh \
  --host 192.0.2.10 \
  --user root \
  --with-web cameraadmin
```

Dann wird zusätzlich zweimal verdeckt das neue HTTP-Passwort abgefragt. Es ist
nicht identisch mit dem SSH-Passwort und sollte mindestens zwölf Zeichen lang
sein.

## Warum es keine Option `--password` gibt

Ein Passwort direkt hinter einem Kommando wäre in Shell-History und zeitweise
in der Prozessliste sichtbar. Das Script liest es deshalb ohne Bildschirmausgabe
ein. Für einen beaufsichtigten Automatismus kann stattdessen eine private Datei
verwendet werden:

```sh
chmod 0600 /sicherer/pfad/pi.passwort
./deploy-to-camera-rpi.sh \
  --host 192.0.2.10 \
  --password-file /sicherer/pfad/pi.passwort \
  --yes
```

Die Datei muss genau eine Zeile enthalten und Modus `0400` oder `0600` haben.
Nach dem Deployment sollte sie sicher entfernt werden. Ein SSH-Key bleibt für
regelmäßige Administration grundsätzlich die bessere Lösung. Passwortbasierter
Root-Login sollte nur in einem geschützten Verwaltungsnetz und möglichst nur
für das Wartungsfenster erlaubt sein.

## Was das Script tatsächlich macht

1. Es verlangt ein sauberes Git-Arbeitsverzeichnis und ermittelt den exakten
   Commit. Nicht committete Dateien und echte Keys werden dadurch nicht
   versehentlich mitgenommen.
2. SSH prüft den Hostkey nach Trust-on-first-use. Ein später veränderter
   Hostkey führt zum Abbruch; die Prüfung wird nicht abgeschaltet.
3. Vor jeder Änderung muss das Konto auf dem Ziel wirklich UID `0` besitzen
   und Root muss read-only gemountet sein.
4. In einem kontrollierten rw-Fenster werden nur benötigte Pakete installiert:
   GPhoto, Curl, Coreutils, util-linux, AWK, CA-Zertifikate und Tar. Mit
   `--with-web` kommen Apache, PHP, sudo und `htpasswd` hinzu. Es wird kein
   allgemeines `apt upgrade` ausgeführt.
5. `git archive` überträgt nur Dateien des angezeigten Commits in ein
   temporäres Verzeichnis unter `/tmp`. Das `.git`-Verzeichnis und ignorierte
   Secrets werden nicht übertragen.
6. Die Projektdateien werden nach `/var/www/html/camera-rpi` kopiert und der
   Core-Installer gestartet. Vorhandene `/etc/camera-rpi`-Konfigurationen, Keys,
   Spoolbilder und ein eventuell vorhandenes Remote-`.git` bleiben erhalten.
7. Der installierte Commit wird in `.deployed-commit` notiert.
8. Abschließend prüft das Script Root-/Boot-Mounts, dpkg/APT, GPhoto-Erkennung
   und fehlgeschlagene systemd-Units.

Alle selbst geöffneten rw-Fenster besitzen Fehler- und Signal-Traps. Auch nach
einem fehlgeschlagenen APT-, Kopier- oder Installationsschritt versucht das
Script, Boot und Root in ihren vorherigen read-only-Zustand zurückzubringen.

## Erst ansehen, noch nichts tun

```sh
./deploy-to-camera-rpi.sh --host 192.0.2.10 --dry-run
```

Der Dry-run öffnet keine SSH-Verbindung und verändert weder lokal noch remote
etwas.

## Voraussetzungen auf dem lokalen Entwicklungsserver

Benötigt werden `git`, `ssh`, `sshpass` und `tar`. Fehlt eines davon, bricht das
Script vor der Passwortabfrage ab und nennt den Debian-Installationsbefehl:

```sh
apt-get install openssh-client sshpass git tar
```

`sshpass` erhält das SSH-Passwort über eine kurzlebige Umgebungsvariable, nicht
als Argument. Andere Prozesse desselben privilegierten lokalen Benutzers könnten
Prozessumgebungen grundsätzlich auslesen; deshalb sollte auf dem
Entwicklungsserver während des Deployments kein fremder Root-Zugriff bestehen.

## Bewusst nicht automatisiert

- keine Änderung oder Rotation produktiver Kamera-Keys,
- keine Erstellung eines PROD-Zielservers,
- keine Aktivierung eines neuen Kamera-Workers,
- keine Deaktivierung der alten `webcam_wait`-/`webcam_capture`-Helfer,
- keine Aktivierung des DHT22 vor Prüfung von Verdrahtung und GPIO,
- kein Löschen alter oder unbekannter Dateien auf dem Zielsystem.
