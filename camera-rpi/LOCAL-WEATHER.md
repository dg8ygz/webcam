# Lokale DHT22-Werte beim Bild-Upload

Pro erfolgreicher Aufnahme wird aus `/run/camera-rpi-status/current.status`
ein kleiner Snapshot neben das jeweilige Spool-JPEG geschrieben:
`images/SERIAL.jpg.weather.json`. Kein zusaetzlicher GPIO-/IIO-Zugriff.
Im Low-write-Betrieb liegen beide im tmpfs. Ohne Low-write folgt die kleine
Sidecar-Datei dem konfigurierten persistenten Spool, nicht heimlich `/run`.

Nur `sensor_state=ok`, Messung hoechstens 120 Sekunden alt, Temperatur
-40..80 C und Feuchte 0..100 %. Capture-Zeit und Messzeit stehen getrennt.
Jeder Server-Spool besitzt seine eigene Kopie; Upload-Retries benutzen keine
spaeteren Messwerte. Erst bei Bildbestaetigung werden Bild UND seine Kopie
entfernt. Alte Spool-Bilder ohne Sidecar werden unveraendert hochgeladen.

Legacy und Chunk-v1 erhalten das optionale Multipart-Feld `local_weather`
(JSON, maximal 512 Bytes). Beim Chunk-Protokoll wird es beim Finalisieren
ausgewertet; die JPEG-Hash-/Manifestpruefung bleibt unveraendert.
Der neue Webcamserver verwendet dieselbe Ingest-Strecke und die bestehenden
Wetter-Sidecars/DB-Zeilen, keine neue Datenbank/Schemaaenderung.
Temp/Humidity sind explizit als lokaler DHT22 mit Messzeit gekennzeichnet.

Konfigurierte Wetterscripte haben immer Vorrang, auch wenn sie fehlschlagen.
Nur wenn keine nichtleeren Wetterkommandos existieren, werden lokale Werte
uebernommen. Ungueltige Daten werden nicht als Wetter gespeichert; das Bild
wird dadurch nicht verworfen. Kein Erfinden fehlender Daten, keine Vermischung
mit heutigen Messwerten bei alten Uploads. Die taegliche Pi-Sensorsicherung
bleibt davon unabhaengig unveraendert.

Der alte Legacy-Webcamserver kann das zusaetzliche Feld ignorieren und benoetigt
fuer eine Anzeige eigene Unterstuetzung. PROD webcam2 muss separat ausgerollt
werden; in dieser Aenderung wird ausschliesslich DEV angepasst.
