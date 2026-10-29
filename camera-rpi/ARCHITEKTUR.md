# Architektur für mehrere Zielserver

Aktuelle Korrektur: [CAPTURE-POOL.md](CAPTURE-POOL.md) beschreibt den gemeinsamen
RAM-Pool. Die erste automatische Anforderung macht ein Bild fuer alle aktiven
Ziele; getrennte Uploadqueues referenzieren dieselben JPEG-Bytes. Maximal zehn
wartende Bilder je Ziel, aelteste zuerst verwerfen, laufende Uploads schuetzen.
Ohne Poolkonfiguration bleibt der Einzelaufnahmebetrieb kompatibel erhalten.

Ergaenzung 12.09.2026: Ein separater, standardmaessig ausgeschalteter
`camera-rpi-climate.service` kann die vorhandenen RAM-Sensorwerte fuer
Taupunkt-Heizung und Feuchte-PWM verwenden. Nur sein GPIO-Adapter besitzt
BCM5/27, nie der Webrequest oder Sensorprozess. Eine bestaetigte geraeteweite
Capture-Sperre verbindet ihn mit dem gemeinsamen Kamerahelper. Die folgenden
aelteren Architekturabschnitte beschreiben die Basis; der neue Reglervertrag
mit Fehler-/RO-/RAM-Grenzen steht in [CLIMATE.md](CLIMATE.md).

Stand: 20.09.2026, Raspi-Version 1.0. Live-Abnahmen und offene Punkte:
`DEVELOPMENT.md`; ein erfolgreicher Einzelzyklus ist kein Langzeittest.

## Das Gesamtbild

```text
Server DEV  ─ eigener Worker + eigener Key ─┐
Server PROD ─ eigener Worker + eigener Key ─┼─ globale Sperre ─ Canon/GPhoto
Server 3    ─ eigener Worker + eigener Key ─┤
Server 4    ─ eigener Worker + eigener Key ─┘
```

Die Zahl der Server ist nicht im Programm fest verdrahtet. Jede zusätzliche
Datei unter `/etc/camera-rpi/servers/` erzeugt zusammen mit einer systemd-
Instanz einen weiteren Worker. Alle dürfen Server unabhängig abfragen. Nur für
Kamera, persistenten Spool und read-write-Fenster gilt dieselbe globale Sperre.

## Ablauf eines Auftrags

1. Der Worker fragt `include/command.php` seines Servers mit einem privaten
   `X-Command-Key`-Header ab.
2. Er validiert moderne Drei-Zeilen-Antworten und den dokumentierten
   Legacy-Adapter. Nur bekannte Capture-/Fokus-/ISO-Aufträge gelangen in feste
   Handler; Serverantworten werden niemals als freie Shell ausgeführt.
3. Ein plausibler Capture-Auftrag wird in der Warteschlange dieses Servers
   zuerst flüchtig vorgemerkt und anschließend in den konfigurierten Spool
   übertragen. Scheitert das Warten auf die Sperre, wird er im nächsten
   Durchlauf weiterverarbeitet und nicht erneut beim Server angefordert.
4. Der Worker wartet auf die globale Kamera-Sperre.
5. Mit Pool wird unter dieser Sperre geprüft, ob ein anderer Worker bereits
   eine passende Generation erzeugt hat. Nur andernfalls wird aufgenommen.
   Der Pool verlangt tmpfs. Im älteren persistenten Betrieb öffnet der Worker
   nur bei Bedarf ein eigenes read-write-Fenster.
6. GPhoto schreibt zunächst `ZEIT.jpg.part`. Zeitlimit, Mindest-/Maximalgröße
   sowie JPEG-Anfang und -Ende werden geprüft.
7. Erst das vollständige Bild wird atomar veröffentlicht. Im Pool erhalten
   alle aktivierten Ziele Hardlinks und unveränderliche Zeit-/Wettersidecars.
8. Der Worker setzt Root wieder ro und gibt die Kamera-Sperre frei. Curl lädt
   das Bild danach unabhängig mit dem zugehörigen `X-Upload-Key` hoch. Im
   Modus `chunked` fragt er zuerst authentifiziert nach
   `camera-rpi-chunk-v1`. Nur diese genaue Antwort schaltet Teiluploads frei;
   andernfalls sendet er dasselbe JPEG über den alten Legacy-Endpunkt.
9. Bei Chunk-v1 erhält jedes Teil einen SHA-256-Prüfwert. Die Gegenstelle setzt
   die Teile erst nach Vollständigkeits- und Gesamtprüfung zusammen. Ein
   Fehler während dieser Phase führt nicht mitten im Bild zu Legacy, sondern
   zu einem späteren, wiederholbaren Versuch mit stabiler Upload-ID.
10. Moderne Uploads verlangen ihre Protokollbestätigung. Der Legacy-Adapter
    erkennt zusätzlich die dokumentierten leeren/ISO-Hinweis-Antworten nach
    erfolgreichem HTTP-Transfer; Antworttext wird dabei nicht ausgeführt.
    Nur die eigene Zustellung wird quittiert. Erst das letzte Pool-ACK gibt
    den Master frei; ein fehlendes ACK lässt das Bild für Retry stehen.
11. Kann ein vom Worker geöffnetes rw-Fenster nicht wieder geschlossen werden,
    meldet er einen kritischen Fehler und beendet sich.

Pro Server liegt höchstens ein nicht abgeschlossener Arbeitsgang vorn. Solange
ein Upload scheitert, holt dieser Worker keinen weiteren Auftrag. Dadurch kann
ein einzelner defekter Server keine eigenen neuen Aufnahmen anfordern.
Andere Pool-Clients können weiterhin Bilder liefern; die Queue dieses Ziels
wird dann auf zehn wartende Bilder begrenzt, laufende Uploads bleiben geschützt.

## Konfiguration und getrennte Keys

Eine echte Serverdatei sieht verkürzt so aus:

```ini
server_id=prod
server_base_url=https://beispiel.invalid/webcam
camera_slug=bielefeld
key_file=/etc/camera-rpi/keys/prod.key
enabled=true
upload_mode=chunked
upload_chunk_bytes=262144
```

`prod.key` enthält genau eine Key-Zeile. Dateien für `test`, `archiv` oder
weitere Ziele zeigen jeweils auf eine andere Key-Datei. Die Admin-Seite sorgt
automatisch für Modus `0600` und Besitzer `root`.

Die Konfiguration wird Zeile für Zeile als Datenformat gelesen. Sie wird nicht
mit Shell-`source` geladen. Unbekannte, doppelte oder fehlerhafte Felder führen
zu einer Ablehnung, nicht zur Ausführung eines Befehls.

Der Key wird nur kurz in eine private Headerdatei unter `/run/camera-rpi/`
geschrieben. Curl erhält `--header @DATEIPFAD`; eine Prozessliste sieht den
Pfad, aber nicht den Key. Command und Upload verwenden beim derzeitigen
Serverprotokoll denselben servereigenen Wert mit unterschiedlichen Headernamen.
Der Betreiber darf denselben Keywert fuer mehrere Zielserver oder Maschinen
verwenden. Die Admin-Seite erzwingt keine unterschiedlichen Inhalte: getrennte
Dateien sorgen weiterhin dafuer, dass Ersetzen fuer ein Ziel andere Ziele nicht
veraendert. Ein leeres Schluesselfeld beim Bearbeiten behaelt den bisherigen Key
unveraendert; neue Ziele brauchen weiterhin einen gueltigen Key.

`upload_mode=legacy` ist der kompatible Standard. `upload_mode=chunked` ist
eine Erlaubnis zur Aushandlung, kein blindes Umschalten: Fehlt der neue
`include/upload-chunk.php`-Endpunkt oder bestätigt er nicht exakt das bekannte
Protokoll, benutzt der Worker automatisch `include/upload.php`. Die Teilgröße
darf zwischen 64 KiB und 1 MiB liegen; 256 KiB ist der dokumentierte Standard.

## Speicherorte

```text
/etc/camera-rpi/servers/   Konfigurationen, root:root 0600
/etc/camera-rpi/keys/      je Server ein Key, root:root 0600
/var/lib/camera-rpi/spool/ Requests/JPEGs; persistent oder explizites tmpfs-Profil
/run/camera-rpi/           flüchtige Header, Antworten, Status, Heartbeats
/run/lock/                 globale Kamera-/Schreibsperre
/run/camera-rpi-status/    letztes Bild, Messwerte und kurzer Verlauf im RAM
```

`/run` verschwindet beim Neustart. Der Spool unter `/var/lib` ist nur ohne
Low-write-Profil persistent; im Pool-/Low-write-Betrieb liegt er auf tmpfs.
Die separat installierte Reboot-Sicherung kann fertige Queues bei geordnetem
Shutdown sichern und beim Boot restaurieren. Harter Stromverlust erhaelt
nicht automatisch den letzten RAM-Stand. Siehe `LOW-WRITE.md` und
`REBOOT-BACKUP.md`. Temporaere `.part`-Dateien werden niemals hochgeladen.

## Lokale Statusanzeige und Hardwaregrenze

Nach jeder gültigen Aufnahme kopiert der Worker das jüngste JPEG atomar nach
`/run/camera-rpi-status`. Der serverbezogene Spool bleibt davon unabhängig.
Schlägt nur diese Anzeigekopie fehl, wird der sichere Upload nicht verworfen.

Ein eigener `camera-rpi-sensor` liest den DHT22 über das Linux-IIO-Dateisystem
und optional feste Statusdateien für Heizung, PWM und Lüftertacho. Er schreibt
keine Steuerwerte. Die PHP-Seite liest ausschließlich diese vorbereiteten
Dateien mit festen Namen und läuft weiter unprivilegiert. Damit führt kein
Webrequest zu einem GPIO-, GPhoto- oder Root-Aufruf.

Messverlauf und Bildkopie liegen absichtlich im RAM: Das verhindert häufige
SD-Karten-Schreibzugriffe auf dem read-only System. Das Tagesbackup sichert
Sensor- und Reglerverlauf auf SD; beim Boot wird nur Historie restauriert,
niemals ein alter Stellwert. Ungesicherte RAM-Bilder/Logs/Messwerte gehen beim
Reboot verloren; der optionale geordnete Shutdown-Checkpoint erhaelt nur
seinen tatsaechlich gesicherten Stand. Nur Installationen ohne Low-write
behalten den SD-Spool direkt persistent.

## Admin-Sicherheitsgrenze

PHP läuft unprivilegiert als `www-data` und hat keine Schreibrechte auf die
Konfiguration. Für eine Änderung darf es über sudo genau
`/usr/local/sbin/camera-rpi-admin` ohne frei wählbare Kommandoargumente starten.
Dieser Helfer kennt ausschließlich erlaubte Aktionen, feste Verzeichnisse und streng geprüfte
Felder. Der Key gelangt per Standardeingabe dorthin, nicht per URL oder argv.

Die Oberflaeche benoetigt HTTP-Authentifizierung und fuer Aktionen einen
CSRF-Token. Die mitgelieferte Apache-Konfiguration verlangt `Require
valid-user`, keine localhost-/Subnetzbeschraenkung; die Statusseite ist
oeffentlich. In unvertrauten Netzen HTTPS oder SSH-Tunnel verwenden. Eine
gueltige Admin-Anmeldung kann Zieladressen aendern und ist wie ein
Geraete-Administratorzugang zu schuetzen.

## Bewusste Grenzen

- Im ausdrücklich aktivierten Pool erhalten alle aktiven Ziele jede automatische
  Aufnahme. Unterschiedliche Zeitpläne können zusätzliche Generationen auslösen;
  es gibt keinen pauschalen Zehn-Minuten-Cache. Manuelle Aufträge bleiben privat.
- HTTP wird für bestehende, isolierte Netze unterstützt, schützt den Key aber
  nicht kryptografisch. HTTPS ist die bevorzugte Zieladresse.
- Es gibt keine Web-Löschfunktion für Server, Keys oder Spoolbilder.
- Migration und Rollback sind gerätegebunden. Alte Poller/Überwachungs-Cronjobs
  dürfen nicht gleichzeitig mit neuen Workern auf dieselbe Kamera zugreifen.
