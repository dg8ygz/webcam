# HTTPS-Wartungszugang (opt-in)

Stand 11.09.2026. Neue Software, KEIN automatischer Tunnel und kein Root-SSH.
Chisel 1.11.5 ist fuer Linux amd64/arm64 mit festen SHA256-Werten hinterlegt.
Quellen: https://github.com/jpillora/chisel/tree/v1.11.5 und
https://httpd.apache.org/docs/2.4/mod/mod_proxy.html (WebSocket upgrade).
Context7 enthielt nur das gleichnamige HDL-Projekt; deshalb Originaldokumentation.

## Architektur und Grenzen

Pi -> ausgehend HTTPS:443 -> bestehender TLS-Proxy -> Origin-Apache ->
Chisel 127.0.0.1:9086. Kein Portforwarding am Pi oder Benutzer-PC.
Der Origin-Apache akzeptiert den Pfad `/camera-maintenance/` nur von der
expliziten TCP-Gegenstelle des Edge (CONN_REMOTE_ADDR, nicht X-Forwarded-For).
Der Edge akzeptiert den Pfad nur ueber HTTPS. WebSocket-Upgrade muss entlang
der Strecke erlaubt sein. Keine globale ProxyTimeout-Aenderung.

Je registriertem Pi/Ziel eigene zufaellige Tunnel-Credentials und eigener
Loopback-Port 22000..22999 am Relay. Chisel-authfile erlaubt exakt dessen
`^R:127\.0\.0\.1:PORT$`, keine normalen Forwardings und keine oeffentlichen
Listener. Der Client legt das Ziel unveraenderlich auf 127.0.0.1:22 fest.
Wichtig: Chisels Reverse-ACL kontrolliert den Listener, nicht das entfernte
Ziel eines manipulierten Clients. Ein kompromittierter Pi/root koennte mit
seinem Credential ein anderes Ziel auf SEINEN Port legen. SSH-Hostkey daher
weiterhin strikt pruefen, niemals StrictHostKeyChecking=no einsetzen.

Der separate DynamicUser-Dienst bekommt Credentials durch systemd
LoadCredential. AUTH muss wegen Chisels Schnittstelle in die Umgebung seines
Kindprozesses; nicht in argv, Webausgaben oder Logs. Andere Pi-Benutzer und
andere DynamicUser-Instanzen erhalten keinen Zugriff. Root kann Secrets lesen.
TLS-Zertifikatspruefung UND gepinnter Chisel-SSH-Fingerprint bleiben aktiv.
Keine beliebigen Ziele, URLs, Shellbefehle oder Secrets aus dem Kamera-Command.

## Bedienung und Ablauf

Kameraeditor im neuen Webcamserver: „Fuer 30 Minuten oeffnen“ und „Schliessen“.
Admin-Auth/CSRF bleiben Pflicht. Oeffnen erst nach frischer Capability des
registrierten Pi-Dienstes. Der alte Legacy-Webcamserver unterstuetzt dies nicht.
Die Freigabe gilt ab Admin-Klick maximal 1800 Sekunden, nicht ab Verbindung.
Ein Poll liefert nur dieselbe Lease-ID und absolute Ablaufzeit; keine
Verlaengerung. Eine offene Freigabe kann nicht per Doppelklick erneuert werden.
Schliessen loescht den Sollzustand, naechster Poll uebernimmt `closed`.
Der Dienst kontrolliert die Grenze jede Sekunde, zusaetzlich monoton gegen
Uhr-Rueckspruenge, und beendet Chisel samt SSH-Verbindungen. Terminierungs-
reserve bis zu 3 Sekunden. Ohne Worker/Server/Internet laeuft eine Freigabe
trotzdem lokal ab. Nach Reboot RAM-Freigabe verloren: geschlossen.

Wartung wird in einem separaten HTTP-Antwortheader uebermittelt, nie statt
eines Capture-Auftrags. Ein Fehler der Wartung darf keine Aufnahme verwerfen.
Bei langem Poll kann auch Schliessen bis zur Poll-Antwort warten (bis 700s).
Sofort lokal: `sudo systemctl stop camera-rpi-tunnel@SERVER-ID.service`.
Neustart des Relay-Dienstes beendet sofort ALLE dortigen Wartungstunnel.
`running` bedeutet Chisel-Prozess lebt, NICHT erfolgreiche SSH-Anmeldung.
Die Anzeige ist eine datierte letzte Pi-Meldung, kein Echtzeit-Verbindungsbeweis.

## Erstinstallation (Operator, jeweils private Staging-Verzeichnisse)

1. Releasepaket/Manifest auf Origin, Edge und Pi uebertragen und pruefen.
2. Origin: `bash ops/install-chisel.sh --apply` (root). Erzeugt keinen Dienst.
3. Origin: Registrierung erzeugen, z.B. als root:

   ```sh
   python3 -B ops/prepare-tunnel-registration.py \
     --binary /usr/local/bin/chisel --target dev-test \
     --url https://camera.example.invalid/camera-maintenance/ \
     --port 22101 --output /root/camera-tunnel-registration
   ```

   Den Platzhalter-Host durch den echten oeffentlichen HTTPS-Host ersetzen.
   Ausgabeordner muss NEU sein, ausserhalb jedes Webroots. Er enthaelt den
   privaten Relay-Key, users.json und dev-test.json. Nur letztere Datei
   geschuetzt zum Pi bringen; Relay-Key nie auf den Pi oder ins Repo kopieren.
4. Origin: `bash ops/install-tunnel-server.sh --apply PRIVATE-ORDNER EDGE-IP`.
   Apache proxy/proxy_http muessen bereits geladen sein. Erstinstallations-
   guard, Sicherung, Syntaxpruefung und Reload; fremde Regeln unveraendert.
5. Edge: `bash ops/install-tunnel-edge.sh --apply ORIGIN-IP` als Operator root.
   Dies ist auf den vorhandenen Include `/etc/apache2/db0bi-proxy.conf`
   zugeschnitten. Auf diesem Host bleibt der Analysezugang strikt read-only.
6. Pi: `bash ops/update-maintenance-weather.sh --apply` als root. Erwartet den
   geprueften Fokus-Ausgangsstand; sonst Abbruch statt fremde Aenderungen zu
   ueberschreiben. Installiert `/usr/local/libexec/camera-rpi-worker`, NICHT
   `/usr/local/bin/camera-rpi-worker`. Startet zuvor aktive Worker wieder.
7. Pi: `bash ops/install-chisel.sh --apply`, danach
   `bash ops/enroll-tunnel-pi.sh --apply dev-test PRIVATE-JSON` als root.
   Ziel muss schon existieren und HTTPS fuer Command verwenden. Keine
   Upload-Key-/Ziel-/Intervall-Aenderung. Der Tunnel bleibt geschlossen.
8. Nach frischem Poll im zentralen Admin gezielt oeffnen. Am Origin
   `ssh -p 22101 admin@127.0.0.1` mit vorhandenem Pi-SSH-Key und vorher
   verifiziertem Pi-Hostkey. Kein Root-Login erforderlich.

## Abnahme und Rueckweg

### Browseransicht des Tunnelpfads

Seit 12.09.2026 zeigt ein normaler Browseraufruf von
`https://webcam.example.invalid/camera-maintenance/` eine neutrale HTML-Seite
mit der Aufschrift **404 / Seite nicht gefunden**. Absichtlich nur optisch:
die HTTP-Antwort ist **200 OK**, ohne Redirect oder ErrorDocument-404.
Das ist keine zusaetzliche Authentifizierung oder Sicherheitsgarantie.

Am Origin setzt SetEnvIfExpr fuer diesen Pfad ohne WebSocket-Upgrade frueh
`no-proxy`; AliasMatch liefert dann
`/usr/local/share/camera-rpi-tunnel-landing/index.html` aus
`apache/tunnel-landing.html`. WebSocket-Upgrades bleiben beim bisherigen
ProxyPass/Chisel. Die bestehende Beschraenkung auf die Edge-Verbindungs-IP
gilt weiterhin auch fuer die Browseransicht. Keine globalen Fehlerseiten,
Webcam-Routen oder Edge-Konfiguration wurden veraendert.

Live-Abnahme 12.09.2026 ca. 10:30 CEST: oeffentlicher GET liefert 200 und
HTML; Upgrade-Anfrage ohne Chisel-Protokoll liefert weiterhin dieselbe 404
wie zuvor; direkter Origin-Aufruf von Loopback bleibt 403. Bestehender
SSH-Zugang ueber Reverse-Port 22101 erfolgreich, Chisel-PID unveraendert,
DHT-Langzeittest, Sensor und beide Pi-Worker weiterhin aktiv.
Nur Apache auf .34 wurde graceful neu geladen, nicht der Tunnelserver.
Sicherung der vorherigen Origin-Konfiguration:
`/root/camera-tunnel-404-before.1C9yoPq5/origin.conf`.
Rueckweg fuer diese Ansichts-Aenderung: gesicherte Origin-Konfiguration
wiederherstellen, `apachectl -t`, dann `systemctl reload apache2`.
Die danach unreferenzierte HTML-Datei kann liegen bleiben.

### Tunnel-Abnahme

Abnahme: fehlender/ungueltiger Key verweigert, kein oeffentlicher Reverse-Port,
SSH-Hostkey gleich bisherigem Pi, gezieltes Schliessen und automatische
Ablaufgrenze, danach Capture/Upload aller Ziele weiter pruefen.
Die Tests verwenden Fake-Kamera bzw. echten lokalen Chisel/TLS/Echo, niemals
einen produktiven Capture-/Focus-Auftrag.

Echte Edge/Pi-Abnahme am 11.09.2026 abgeschlossen: Edge vom Betreiber um
22:40 CEST installiert (Sicherung `/root/camera-tunnel-edge-before.rmeAnaV1`),
Pi um 22:40:55 (Sicherungen `/root/camera-rpi-maintenance-before.zDm9SxKa`
und `/root/camera-tunnel-enroll-before.HUAFPBgZ`). SSH als admin ueber
Loopback22101 mit bekanntem, strikt geprueftem Pi-Hostkey erfolgreich;
manuelles Schliessen um 22:43:13 bestaetigt.

Erneute Freigabe 22:44:01 CEST, Verbindung ab 22:44:04; automatischer Ablauf
**23:14:01 CEST / 22:14:01 BST auf dem Pi**. Nachkontrolle gegen 23:17
ueber den unabhaengigen alten PC-Tunnel: Reverse-Port verschwunden,
Pi/DEV closed, beide Capture-Worker aktiv. Keine vollstaendige neue
Capture-/Uploadrunde nach Ablauf aus dem Dienststatus ableiten.
Der Betreiber schloss anschliessend auch den alten PowerShell-Tunnel;
der Wartungszugang bleibt bis zu einer ausdruecklichen neuen Freigabe zu.

Rueckweg: zuerst Pi-Dienst disable --now, dann Relay-Service disable --now.
Edge: gesicherten db0bi-proxy.conf wiederherstellen, zugehoeriges neues
camera-rpi-tunnel.inc sichern/entfernen; apachectl -t und Reload.
Origin: a2disconf camera-rpi-tunnel-origin, apachectl -t und Reload.
Pi-Worker aus `/root/camera-rpi-maintenance-before.*` bei gestoppten Workern
wiederherstellen, vorher aktive Worker starten. Keys/Spool bleiben erhalten.
Neue Dienste/Dateien nur anhand der konkreten Installation entfernen.
Bei weiteren Pi-Registrierungen nicht bestehende users.json ersetzen:
Eintraege nach Sicherung zusammenfuehren, Portkollisionen ausschliessen.
LoadCredential erfordert anschliessend Relay-Neustart (beendet alle Tunnel).
