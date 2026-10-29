# Camera-RPi: eine Kamera für mehrere Server

Version **1.1** (Raspi-Software, unabhängig von der Version des Webcamservers).
Die maschinenlesbare Version steht in `VERSION`; Abnahmen und verbleibende
Hardwaregrenzen stehen in `DEVELOPMENT.md`. Updatepakete: ops/UPDATE-PAKETE.md (lokale Betriebsdokumentation, nicht im Export).

Review-Korrekturen17.10. im Quellstand: EOS-Abbruchschutz, JPEG-Pruefung mit
**PHP-GD** und getrennte physische Aufnahmezeit. Noch kein Kamera-Rollout;
Vertrag, Tests und Rueckweg: [CAPTURE-POOL.md](CAPTURE-POOL.md#review-korrekturen-17102026-quellstand-noch-kein-kamera-rollout).

Gemeinsame Aufnahmen fuer mehrere Uploadziele und zehn wartende Bilder je
Client: [CAPTURE-POOL.md](CAPTURE-POOL.md). Koordiniertes Worker-Update notwendig;
keine automatische Aenderung einer laufenden Kamera durch das Repository.

Bestandskamera vorbereiten, ohne den laufenden Altbetrieb umzuschalten:
[LEGACY-MIGRATION.md](LEGACY-MIGRATION.md) und
`ops/install-legacy-camera.sh` (inaktive Erstinstallation mit datenerhaltendem
Rueckbau; EOS-/IIO-/GPIO-Abnahme vor echtem Cutover weiterhin erforderlich).

gphoto2-Fokus lokal und per Serverauftrag: [FOCUS.md](FOCUS.md).
Canon-Zähler und gemessene Grenzen: [SHUTTERCOUNTER.md](SHUTTERCOUNTER.md).
Bewährte Canon-EOS-Aufnahmesequenz als eigenes Profil, inaktives Update für
Bielefeld und Rückweg: [EOS-LEGACY.md](EOS-LEGACY.md).

Taupunkt-Heizung und Feuchte-PWM im lokalen Admin: [CLIMATE.md](CLIMATE.md).
Feste BCM5/27-Belegung, 2-s-Luefteranlauf, Simulation und Capture-Pause.
Bei Neuinstallation Aus; keine feste Temperatur-/Heizzeitverriegelung.
Sensor und Regler laufen als eigene Boot-Dienste unabhaengig von Fotoauftraegen
und Webcamserver. Lokale RAM-Grafik und Wettergrafik beim Bildempfaenger haben
unterschiedliche Datenwege; Verlaufsluecken beim Serverausfall am 20.09. sind
in DEVELOPMENT.md (lokale Betriebsdokumentation, nicht im Export) mit Pi-Boot und Backupgrenzen eingeordnet.

HTTPS-Wartungszugang ohne Pi-Portfreigabe: [MAINTENANCE-TUNNEL.md](MAINTENANCE-TUNNEL.md).
DHT22-Werte als Wetter-Fallback beim Upload: [LOCAL-WEATHER.md](LOCAL-WEATHER.md).
Beide Erweiterungen sind opt-in bzw. optional; Installationsstand und Abnahme
stehen im Betriebsprotokoll des Webcamservers (`ops/camera-maintenance-weather.md`).

Aktuell: Version 1.1 mit editierbarer gemeinsamer Aufnahmequelle, RTSP-URL und
USB-Geräteauswahl auf der Testcam. [Bedienung](CAMERA-SOURCE.md).

Historischer Stand: 21.09.2026 – Version 1.0, geraetespezifische Teilrollouts.
Sensor-Nachholen seit 20.09. 20:54:57 auf `.16`, nach Betreiberbeobachtung
einer ruhigeren Nacht seit 21.09. 08:33:33 auch auf `.17`: Nach belegter
Kamerasperre zeitnah messen statt erneut das volle Intervall zu warten.
Klima-Altersgrenze und jeweiliger DHT-Treiber unveraendert;
Tests und Beobachtung (lokale Betriebsdokumentation, nicht im Export).
V4L2-Testcam und EOS-Legacy-Bestandskameras sind getrennte Installationen.
Auf Bielefeld wurden gemeinsame Aufnahme, Legacy- und Chunk-Upload desselben
Bildes live nachgewiesen. Am 20.09. `.16` und `.17` RO, Sensor/Klima und ihre
drei bzw. zwei Worker aktiv; `.16` Distribution-DHT nach Rueckbau,
`.17` weiterhin adaptiver Versuchsstand. Langzeitqualitaet dadurch nicht belegt.

Seit 19.09. Klima-Capture-Fix und sichtbarer Quittierweg auf beiden Pis;
seit 27.09. zusaetzlich **auf `.17` und `.16`** gemeinsame Aufnahmefehler-Pause,
Taupunktanzeige trotz gespeicherter Stoerung bei frischen Messwerten und
bestaetigtes Quittierungsergebnis. Befund und .17-Abnahme (lokale Betriebsdokumentation, nicht im Export),
anschliessender .16-Rollout (lokale Betriebsdokumentation, nicht im Export).
Kamerastrom-Ein/Aus nur auf `.17` installiert und real abgenommen. Neue
Fokus-Capability-Erkennung laut letztem Rolloutnachweis noch nicht auf `.16`.
Die neuere Privacy-Oberflaeche der DEV-Quellen ist ebenfalls kein Beleg fuer
ihre Installation auf allen Kameras. Aktueller Verlauf: DEVELOPMENT.md (lokale Betriebsdokumentation, nicht im Export).

**Neuer Pi mit Raspberry Pi OS Trixie:** [TRIXIE.md](TRIXIE.md) beschreibt
Uebertragung per normalem SSH-Benutzer, Paketpruefung und Installation mit
sudo auf einem schreibbaren System. Der alte Root-SSH-/RO-Deploymentweg
ist weiterhin ausschliesslich fuer den bestehenden schreibgeschuetzten Pi.

Dieses Verzeichnis enthält den Ersatz für die beiden alten
GPhoto-Helfer. Eine Canon-Kamera kann damit mehr als zwei Zielserver bedienen.
Jeder Server besitzt eine eigene Konfiguration, einen eigenen Schlüssel, einen
eigenen Worker und eine eigene Warteschlange.

Die kurze Antwort auf die Konfigurationsfrage lautet: **Ja, pro Zielserver gibt
es eine Konfigurationsdatei.** Der geheime Schlüssel steht jedoch absichtlich
nicht darin, sondern in einer zweiten, nur für `root` lesbaren Key-Datei. Die
Admin-Webseite legt beide passend an; man muss sie dann nicht von Hand editieren.
Gleiche Schluesselwerte auf mehreren Zielservern oder Maschinen sind erlaubt.
Die Dateien bleiben je Ziel getrennt; ein leeres Schluesselfeld beim Bearbeiten
erhaelt den vorhandenen Key unveraendert.

## So funktioniert es ohne Fachbegriffe

Ein Worker ist ein kleiner Hintergrunddienst. Er fragt nur seinen eigenen
Server, ob ein Foto gewünscht ist. Melden drei Server gleichzeitig Bedarf,
warten ihre Worker an einer gemeinsamen „Besetzt“-Sperre. Mit aktiviertem Pool
macht die erste Anforderung ein Bild für alle aktivierten Ziele. Die anderen
Worker laden dieselben Bildbytes unabhängig hoch. Ohne Poolkonfiguration bleibt
der ältere Einzelaufnahmebetrieb erhalten; siehe `CAPTURE-POOL.md`.

Ein Bild wird zuerst sicher zwischengespeichert. Erst wenn genau der zuständige
Server den Upload bestätigt, entfernt der Worker dessen Queue-Referenz. Das
Poolbild bleibt bis zur letzten Bestätigung erhalten. Bei Netzfehlern wird
erneut versucht; je Ziel gelten maximal zehn wartende Bilder mit Uploadschutz.

Zusätzlich legt jeder erfolgreiche Capture eine flüchtige Kopie des neuesten
Bildes unter `/run` ab. Ein getrennter Dienst liest den DHT22; der Klimaregler
liefert Taupunkt, Abstand, PWM-Sollwert und Heizungsansteuerung. Die lokale Seite
`/` (auch `/camera-status/`) liest diese Dateien ohne Kamera-/GPIO-Zugriff.
Ansteuerung ist keine physische Drehzahl- oder Relaisrückmeldung.

Für jeden Zielserver kann die Admin-Seite eines von zwei Upload-Verfahren
wählen. `Legacy` sendet wie bisher das vollständige JPEG in einer Anfrage.
`Chunked v1` fragt die Gegenstelle zuerst nach ihrer ausdrücklich bestätigten
Unterstützung und sendet das Bild anschließend standardmäßig in 256-KiB-Teilen.
Kennt ein älterer Server den neuen Endpunkt nicht, verwendet der Worker für
dieses Bild automatisch Legacy. Bricht dagegen eine bereits begonnene
Chunk-Übertragung ab, bleibt das JPEG erhalten und wird später erneut versucht.

## Admin-Webseite

Die lokale Oberfläche zeigt alle Zielserver als Karten. Dort kann ein
Administrator:

- einen neuen Server mit Adresse, Webcam-Kennung und eigenem Key anlegen,
- Adresse oder Webcam-Kennung ändern,
- einen Key gezielt ersetzen, aber niemals auslesen,
- einen Worker aktivieren, deaktivieren, prüfen oder neu starten,
- Legacy oder Chunked v1 pro Zielserver auswählen,
- gphoto2 (Standard) oder V4L2/MJPEG samt Gerät, Auflösung und Anlaufbildern wählen,
- Heartbeat, letzten Zustand sowie wartende Aufträge und Bilder sehen.

Die eigene Sektion „Heizung & Luefter“ konfiguriert ausserdem den gemeinsamen
Gehaeuseregler: Taupunktabstand, Hysterese, PWM-Grenzen, Sommerbetrieb und
Messwertalter. Keine feste Temperatur-/Heizzeitverriegelung, keine zusaetzliche
Aktivierungs-Checkbox. Keine getrennten, konkurrierenden Regler pro Server.

Sie besitzt bewusst keine Löschfunktion. Ein versehentliches Entfernen eines
Servers oder wartenden Bildes ist damit über die Webseite nicht möglich.
Die Infoseite ist unter `http://PI-IP/` und `/camera-status/` ohne Anmeldung
erreichbar. `/camera-admin/` bleibt getrennt und verlangt Benutzername und
Passwort. Fuer die Verwaltung in nicht vertrauenswuerdigen Netzen HTTPS oder
einen SSH-Tunnel verwenden; HTTP verschluesselt keine Zugangsdaten.
„Jetzt aufnehmen“ wirkt nur auf die gewaehlte Kamera-/Zielkonfiguration.
Details stehen in [ADMIN.md](ADMIN.md).

## Historischer Ausgangsstand des alten Pi (`webcam-bfld`, 28.08.2026)

Die folgende Liste beschreibt den Zustand vor der Migration. Seit dem
Cutover laufen die neuen Worker; die alten Poller duerfen nicht gleichzeitig
auf die Kamera zugreifen. Aktueller Stand und Abnahmen stehen oben und in
`DEVELOPMENT.md`, nicht in dieser historischen Liste.

- Raspberry Pi 2 Model B Rev 1.1 mit Raspberry Pi OS Bookworm/armhf.
- Canon-DSLR über `gphoto2`; der alte Python-PiCamera-Stack wird nicht genutzt.
- Damals produktiv: `/usr/local/bin/webcam_wait` und
  `/usr/local/bin/webcam_capture`.
- `/`, `/boot` und `/boot/firmware` sind im Normalbetrieb read-only.
- `/var/log`, `/var/tmp` und `/tmp` liegen auf tmpfs.
- APT-/dpkg-Timer sind deaktiviert; `rpi-eeprom` bleibt auf dem Pi 2 gehalten.
- `packagekit`, `dphys-swapfile`, `resize2fs_once` und `rp1-test` sind maskiert.
- Logrotate nutzt `/var/log/logrotate.status` auf dem flüchtigen Log-Dateisystem.

Diese Ausgangseinstellungen wurden bei Migration und Rueckweg beruecksichtigt.
Spaetere Installationen sind in den datierten Cutover-/Rolloutprotokollen
festgehalten; diese Liste ist keine heutige Pruefung aller OS-Einstellungen.

## Was in welchem Ordner liegt

| Datei oder Ordner | Für Nicht-Programmierer erklärt |
|---|---|
| `ARCHITEKTUR.md` | Warum mehrere Worker trotzdem sicher nur eine Kamera benutzen. |
| `ADMIN.md` | Bedienung und Schutz der Admin-Webseite. |
| `STATUSSEITE.md` | DHT22, Heizung, Lüfter und lokale Bild-/Klima-Anzeige verständlich einrichten. |
| `ROLLOUT.md` | Kontrollierte Installation, Prüfung und Rückkehr zum alten Stand. |
| `DEPLOYMENT.md` | Pakete und Projektstand sicher per Root-SSH auf den Kamera-Pi übertragen. |
| `TRIXIE.md` | Frischen Trixie-Pi ohne Root-SSH installieren; Hardwareabnahme bleibt separat. |
| `DEVELOPMENT.md` | Chronologisches Protokoll aller Entscheidungen und Zwischenschritte. |
| `config/` | Beispiel für eine Serverdatei und einen Key. Keine echten Zugangsdaten. |
| `scripts/camera-rpi-worker` | Fragt einen Server ab, fotografiert, puffert und lädt hoch. |
| `scripts/camera-rpi-healthcheck` | Prüft alle Worker, Heartbeats, Queues und den read-only-Zustand. |
| `scripts/camera-rpi-sensor` | Liest DHT22 und Gerätezustände in den flüchtigen RAM-Status. |
| `scripts/admin/camera-rpi-admin` | Einziger eng begrenzter Root-Helfer der Webseite. |
| `scripts/lib/` | Gemeinsames, striktes Lesen und Prüfen von Konfigurationen und Keys. |
| `admin/` | PHP-Oberfläche und Darstellung; sie schreibt nie selbst nach `/etc`. |
| `systemd/` | Vorlage, aus der pro Zielserver ein Hintergrunddienst entsteht. |
| `apache/` und `sudoers/` | Lokaler Webzugriff und exakt begrenzte Berechtigung. |
| `tests/` | Kamera- und Netzwerk-Simulation für drei unabhängige Server. |
| `install.sh` | Installiert nur nach bewusstem Aufruf; startet keinen Zielserver von selbst. |
| `deploy-to-camera-rpi.sh` | Fragt Ziel und Passwörter sicher ab und führt Transport plus Installation aus. |

## Sicherheitsregeln

- Bei Hinweisen auf die Dokumentation belegbare Luecken und Widersprueche
  direkt nachpflegen (Betreiberpraeferenz 20.09.2026). Historische Versuche
  datiert erhalten; aktuelle Quellen, installierte Geraete und offene
  Hardwareabnahmen auseinanderhalten. Keine implizite Live-Aenderung.
- Neue Erweiterungen in Shell/PHP, **kein neues Python**. Vorhandene Python-
  Bestandteile nur mit gesondertem Migrationsauftrag ersetzen. Fuer zeitkritische
  PWM existiert der kleine dokumentierte native Helfer; Regelung bleibt PHP.
- Funktionsbloecke direkt im Quelltext dokumentieren und gut lesbar halten;
  die Pflege muss auch ohne IDE moeglich sein.
- Keine echten Keys, Seriennummern oder privaten Endpunkte in dieses
  Entwicklungsverzeichnis eintragen.
- Keys werden weder in Prozesslisten noch Statusdateien, Logs oder HTML gezeigt.
- Curl folgt keinen Weiterleitungen, damit ein Key nicht zu einem fremden Host
  weitergereicht werden kann.
- Serverantworten sind Daten und werden niemals als Shellcode ausgeführt.
- Command- und Uploadantworten, JPEG-Größe, Zeitstempel und Laufzeiten besitzen
  harte Grenzen.
- HTTP überträgt den Key unverschlüsselt. Wo möglich ist HTTPS zu verwenden;
  HTTP ist nur in einem bewusst vertrauten, isolierten Netz vertretbar.
- Das bestehende Ziel `/webcam`, die laufenden Helfer und vorhandenen Keys
  bleiben bis zu einer ausdrücklichen Rollout-Freigabe unverändert.

## Bereits geprüfter Entwicklungsstand

`sudo ./tests/run-tests` simuliert drei gleichzeitig gestartete Worker mit drei
verschiedenen Keys. Der Test belegt, dass die Kamera niemals parallel geöffnet
wird, jeder Server sein Bild erhält, Keys nicht in Curl-Argumenten erscheinen
und Bilder erst nach Bestätigung verschwinden. Dabei bestätigt ein Server
Chunk-v1, ein alter Server löst den automatischen Legacy-Fallback aus und ein
dritter bleibt fest im Legacy-Modus. Zusätzlich werden Shell- und PHP-Syntax
geprüft. Simulierte DHT22-, Heizungs-, PWM- und Tachosignale prüfen außerdem die
lokale Statusseite samt letztem Bild und Verlaufsgrafik. Der Test benutzt weder
die echte Kamera noch das Netzwerk.
# Optional: SD-schonender RAM-Betrieb

Taegliche Sensor-Sicherung auf SD, Wiederherstellung beim Start sowie
begrenzte RAM-Spools und fluechtige Logs: siehe [LOW-WRITE.md](LOW-WRITE.md).
Dieses Profil wird bewusst separat aktiviert und benoetigt einen Neustart
zur vollstaendigen Abnahme; normale Installation und Updates aktivieren es nicht.

Zusätzliche Sicherung bei geordnetem Reboot/Shutdown: [REBOOT-BACKUP.md](REBOOT-BACKUP.md).
Sie erhält aktuelle Sensor-/Klimaverläufe, fertige gemeinsame Bildwarteschlangen
mit Zeitstempeln und einen begrenzten Journal-Auszug, ohne laufende SD-Schreibserie.

Fuer die bereits vorbereitete Bestandskamera `.16` gibt es einen eigenen,
konservativ geprueften Umschaltweg mit 128-MiB-RAM-Spool und persistentem
Rueckweg: BIELEFELD-CUTOVER.md (lokale Betriebsdokumentation, nicht im Export). Dieser ersetzt keine
allgemeine Neuinstallation und aktiviert keine anderen Kameras.
# Optionaler Aufnahmeschutz

Geräteweite Sonnenaufgang-/Sonnenuntergangsfenster mit getrennten ±Minuten,
vorhandenen Kamerapositionen und optionaler RAM-Helligkeitsprüfung:
[CAPTURE-PRIVACY.md](CAPTURE-PRIVACY.md). Ohne bewusste Aktivierung unverändert aus.

Gemeinsame USB-/RTSP-Quelle für Fotos und Video: [CAMERA-SOURCE.md](CAMERA-SOURCE.md).

Optionaler monatlicher Canon-Uhrabgleich mit NTP-Pruefung und Kamerasperre:
[CAMERA-CLOCK.md](CAMERA-CLOCK.md). Auf DK0RE, Bielefeld und Steinhagen
seit09.10.2026 aktiv; letztere mit RO-kompatiblem Timer.
