# Admin-Webseite bedienen

Neu: [Heizung & Luefter](CLIMATE.md) unter `/camera-admin/#climate`.
Gespeicherte Regelparameter werden geladen; Aus/Simulation/Automatik sind
getrennt. Automatik wird ueber die Betriebsart gespeichert, ohne zusaetzliche
Aktivierungs-Checkbox. Keine feste Temperaturabschaltung und keine maximale
Heizdauer; der Zwei-Sekunden-Anlaufimpuls ist direkt in der Bedienhilfe erklaert.
Gehaeuseweite Regelung, keine frei ausfuehrbaren GPIO-Befehle und keine
automatische Aktivierung bei Installation. Details, Sicherheitsgrenzen und
Rueckweg stehen im verlinkten Runbook.

Die Oberfläche ist für einen Geräteadministrator gedacht, nicht für öffentliche
Webcam-Besucher. Sie wird bei der Installation unter `/camera-admin/`
bereitgestellt.

Die getrennte, nur lesende Bild-/Klima-Anzeige liegt unter `/camera-status/`.
Sie kann von der Admin-Seite aus geöffnet werden. Ihre Sensor- und
Verdrahtungsanleitung steht in [STATUSSEITE.md](STATUSSEITE.md).

## Sicher zugreifen

Die Verwaltung ist direkt unter `http://PI-IP/camera-admin/` mit Benutzername
und Passwort erreichbar. Die allgemeine Bild-/Wetterseite unter `http://PI-IP/`
braucht keine Anmeldung. Fuer einen verschluesselten Verwaltungszugriff ohne
eingerichtetes HTTPS kann optional ein SSH-Tunnel verwendet werden:

```sh
ssh -L 8080:127.0.0.1:80 pi@webcam-bfld
```

Danach im lokalen Browser `http://127.0.0.1:8080/camera-admin/` öffnen und den
bei der Installation angelegten HTTP-Benutzer verwenden. Der HTTP-Verkehr
läuft dabei innerhalb des verschlüsselten SSH-Tunnels.

Es gibt keine localhost-/Subnetzbeschraenkung. Bei Internetfreigabe HTTPS
einrichten; Basic-Auth allein verschluesselt das Passwort nicht. Eine
Portweiterleitung wird vom Installer nicht eingerichtet.

## Einen Server anlegen

1. Unter „Server hinzufügen“ einen kurzen internen Namen eintragen, etwa
   `prod`, `dev` oder `archiv`. Er kann später nicht umbenannt werden.
2. Die Basisadresse bis einschließlich Webcam-Verzeichnis eintragen, aber ohne
   `include/command.php` oder `include/upload.php`.
3. Die Webcam-Kennung so eintragen, wie sie auf diesem Zielserver heißt.
4. Den ausschließlich für diesen Server vorgesehenen Key einsetzen.
5. Das Upload-Verfahren wählen. `Legacy` funktioniert mit jeder bisherigen
   Gegenstelle. `Chunked v1` ist für aktualisierte Server gedacht und fällt
   automatisch auf Legacy zurück, falls der Server es noch nicht kennt.
6. „Worker aktivieren“ markiert lassen und sicher speichern.
7. Im getrennten Bereich **Aufnahmequelle** RTSP, USB oder gphoto2 wählen.
   Darunter erscheinen nur die passenden Felder. Bei USB werden vorhandene
   by-id-Geräte vorgeschlagen; ein eigener Pfad ist ebenfalls möglich.
   **Aufnahmequelle speichern** übernimmt die Kameraeinstellung für alle Ziele.
   Details und Rückwechsel: [CAMERA-SOURCE.md](CAMERA-SOURCE.md).

Die Webseite erzeugt daraus beispielsweise:

```text
/etc/camera-rpi/servers/prod.conf
/etc/camera-rpi/keys/prod.key
camera-rpi-worker@prod.service
```

Bei drei Servern existieren drei solcher Paare. Keys dürfen nicht zwischen den
Servern wiederverwendet werden. Die Admin-Brücke vergleicht einen neuen Key
verdeckt mit allen vorhandenen Key-Dateien und lehnt eine Wiederverwendung ab.

## Einen Server bearbeiten

Beim Oeffnen wird das erste vorhandene Ziel direkt mit seinen gespeicherten
Werten geladen. Weitere Ziele ueber „Bearbeiten“ an der jeweiligen Karte
auswaehlen; „Weiteren Server hinzufuegen“ oeffnet bewusst ein neues Formular.
Nur neue Ziele benoetigen einen Schluessel. Leer speichern aendert bei einem
bestehenden Ziel weder Inhalt noch Datei des Schluessels.

„Bearbeiten“ füllt Name, URL und Webcam-Kennung aus. Das Key-Feld bleibt immer
leer, weil die Anwendung den bestehenden Key nicht lesen und anzeigen soll.
Leer speichern behält ihn. Nur ein bewusst eingetragener neuer Wert ersetzt
ihn. Eine Rotation muss mit der Gegenseite koordiniert werden, sonst kann der
Server vorübergehend keine Aufträge authentifizieren.

Aufnahmequelle und Videowerte werden ebenfalls geladen und erhalten.
Ein bereits vorhandener aktivierter Worker wird nach dem Speichern neu
gestartet, damit die geaenderte Kameraauswahl sofort gilt. Alte Clients ohne
diese Felder behalten die bestehende Auswahl. Der Wechsel zu gphoto2
entfernt weder Keys noch Warteschlangen; V4L2-Werte bleiben fuer spaeter stehen.

## Karten und Schaltflächen

Oben unter **Kamera ein-/ausschalten** steuern zwei Buttons den bereits
eingerichteten Kamerastrom-GPIO. Grün markiert „Kamera einschalten“, solange
`lsusb` eine `Canon` erkennt. Fehlt der Text, markiert Rot „Kamera ausschalten“.
Bus- und Gerätenummer sind unerheblich. Die Anzeige folgt der USB-Erkennung,
nicht dem zuletzt gedrückten Button; sie aktualisiert sich automatisch.
Bei Abfragefehlern erscheint „unbekannt“. Während einer Aufnahme wird der
Schaltauftrag abgewiesen. Details zu Pin, Bootverhalten und Rückweg: [GPIO.md](GPIO.md).

- „läuft“ bedeutet: aktiviert, systemd-Prozess aktiv und kein kritischer Status.
- „prüfen“ bedeutet: aktiviert, aber Prozess oder letzter Zustand braucht
  Aufmerksamkeit.
- „aus“ bedeutet: bewusst deaktiviert.
- „Wartende Aufträge“ sind noch nicht erfolgreich fotografiert.
- „Wartende Bilder“ sind aufgenommen, aber vom Zielserver noch nicht bestätigt.
- „Upload“ zeigt `legacy` oder `chunked` für genau diesen Zielserver.
- „Wirksame Vorgabe“ zeigt die zuletzt vom Worker angewendete lokale/zentrale
  Auswahl; Chunked kann bei alten Gegenstellen weiterhin Legacy benutzen.
- „Prüfen“ validiert Datei und Key, löst aber kein Foto aus.
- „Jetzt aufnehmen“ merkt eine Aufnahme nur fuer diese Kamera-/Zielkonfiguration
  vor. Der laufende Worker nutzt seine eigene Quelle, seinen Key und Uploadpfad;
  andere Ziele erhalten weder Auftrag noch Bild. Nur aktive Worker, ein offener
  Auftrag und mindestens 30 Sekunden Abstand; vorhandener Upload-Rueckstau muss
  zuerst abgearbeitet werden. Der Auftrag wartet bei beschaeftigter Hardware
  auf die bestehende Kamerasperre. Nicht mit einer globalen Ausloesung verwechseln.
- „Neu starten“ startet nur diesen Worker neu.
- „Deaktivieren“ stoppt nur diesen Zielserver. Andere Server laufen weiter.

## Schutzmaßnahmen der Webseite

Der zentrale Webcam-Admin kann unter Aufnahme → „Pi-Upload-Verfahren“ nur fuer
diese Kamera `local`, `legacy` oder `chunked` vorgeben. Der aktualisierte Worker
liest `X-Camera-Upload-Mode` nach erfolgreicher authentifizierter Abfrage;
fehlender Header bedeutet lokale Auswahl (alte Server). Zentrale Legacy-/Chunk-
Vorgaben haben Vorrang, ohne die lokale Konfiguration oder den Key zu aendern.
Zum Freigeben der lokalen Auswahl zentral „Lokal am Pi einstellen“ speichern.
Laufende Transfers werden nicht umgestellt; Rueckstau wird zuerst abgearbeitet.
Der private Runtime-State je Ziel behaelt die letzte Vorgabe bis zum naechsten
Poll, bei Reboot gilt bis dahin die lokale Auswahl. Alte Worker ignorieren den
Header; das Drei-Zeilen-Befehlsformat bleibt kompatibel.

- Apache-Benutzer/Passwort fuer die getrennte Verwaltung,
- Sitzungscookie nur für JavaScript unlesbar und mit `SameSite=Strict`,
- Sitzungsdateien unter `/run/camera-rpi-admin` statt auf dem read-only Root,
- zufälliger CSRF-Token in jeder schreibenden Anfrage,
- keine fremden Scripts, Fonts oder sonstigen Webressourcen,
- HTML-Escaping aller Status- und Servertexte,
- Keys nie in Antworten, URLs, Prozessargumenten oder Statusdateien,
- PHP ruft eine feste Programmliste ohne Shell auf,
- keine Löschfunktion und keine frei angebbaren Dateipfade.
