# Gemeinsamer RAM-Bilderpool

## Ablauf

Eine physische Kamera, mehrere Upload-Clients: Die erste automatische Capture-
Anforderung erzeugt **ein** JPEG. Dieses wird allen zu diesem Zeitpunkt
aktivierten Zielservern in ihre Upload-Warteschlangen gestellt, auch wenn ein
Ziel gerade offline ist oder noch ein aelteres Bild uebertraegt. Legacy und
Chunk-Upload verwenden dieselben JPEG-Bytes und denselben Wetter-Snapshot.

Ein bereits belieferter Client darf mit einem neuen Capture-Auftrag das naechste
Bild ausloesen. Andere Clients behalten ihre aelteren Bilder zusaetzlich. Das ist
kein fester Zehn-Minuten-Cache: Die Serverauftraege bestimmen die Aufnahmen.
Parallel schon laufende Command-Polls merken sich die vorherige Generation;
hat zwischenzeitlich ein anderer Client aufgenommen, erzeugen sie kein zweites
Bild. Wiederholte/alte Command-Epochen erzeugen ebenfalls keine Neuaufnahme.

Jeder Client bestaetigt nur seinen Upload. Nach der letzten Bestaetigung wird
das Poolbild entfernt. Die unabhaengige Vorschau fuer die lokale Webseite
bleibt bestehen. Manuelle Aufnahmen im Admin bleiben ausdruecklich frisch und
auf das ausgewaehlte Ziel beschraenkt; sie werden nicht an alle Ziele verteilt.

## Zehn Bilder pro Client

Bei mehr als zehn wartenden Bildern wird das aelteste Bild **nur aus der
Warteschlange dieses Clients** entfernt. Dessen noch ausstehende Zustellung
entfaellt damit bewusst. Andere Clients behalten ihre Referenzen. Liest ein
Uploader gerade dieses Bild, wird nicht geloescht: Die Warteschlange darf
voruebergehend groesser sein und wird bei der naechsten Pooloperation bereinigt.
Auch ein fehlgeschlagener Upload gibt die Lesesperre wieder frei.

Die Grenze zaehlt Bilder, nicht Bytes. Ist das tmpfs voll, kann eine neue
Aufnahme scheitern; vorhandene Bilder werden nicht pauschal geloescht und es
gibt keinen stillen SD-Fallback. RAM-Bilder bleiben bei Stromausfall fluechtig.

## Daten und Zeitstempel

- Master: `/var/lib/camera-rpi/spool/.capture-pool/EPOCH/image.jpg`.
- Offene Empfaenger: `EPOCH/pending/SERVER-ID` (private RAM-Dateien).
- Upload: `spool/SERVER-ID/images/EPOCH.jpg` als Hardlink auf denselben Inode.
- Wetter und Legacy-Archivzeit werden vor dem JPEG-Link veroeffentlicht und
  bleiben bis zur Bestaetigung/gezielten Verwerfung unveraendert.
- Der erste Legacy-Auftrag liefert die Kalenderzeit. Fehlt diese, wird sie aus
  der Auftragsepoche in `legacy_timezone` abgeleitet. Ohne diese optionale
  Einstellung gilt aus Kompatibilitaetsgruenden weiterhin die Pi-Zeitzone.
  Bei unterschiedlicher Pi-/Server-Zeitzone muss die Legacy-Serverzone explizit
  gesetzt werden; sonst landen erfolgreiche Uploads im falschen Archivslot.
  Eine gelieferte Server-Kalenderzeit wird nicht nochmals umgerechnet. Die bisher vereinbarte
  Abrundung der automatischen Legacy-Archivminute auf zehn Minuten bleibt.
  Chunk-Auftraege behalten die numerische Epoche. Uploadzeit ist nie Bildzeit.
- `latest` ist ein kleiner RAM-Merker gegen doppelte Aufnahmen; er bleibt auch
  dann erhalten, wenn alle Bilddateien bestaetigt wurden.

Alle Pool-/Queue-Aenderungen benutzen die bestehende globale Kamerasperre.
Uploads halten zusaetzlich eine Lesesperre auf dem Bild-Inode, jedoch **nicht**
die Kamerasperre waehrend HTTP. Eine unterbrochene Verteilung wird beim naechsten
Worker-Durchlauf vervollstaendigt. Nach einem Prozessabbruch zwischen HTTP-ACK
und lokaler Verbuchung ist eine erneute Uebertragung moeglich (at least once).

Die Empfaengerliste wird bei der Aufnahme festgelegt. Neu hinzugefuegte Ziele
erhalten die naechste Generation, nicht nachtraeglich alle alten Bilder.
Deaktivieren eines Ziels entfernt dessen bereits wartende Bilder nicht.
Ein bereits laufender Command-Long-Poll wird nicht abgebrochen (sonst koennten
Fokus-/ISO-Befehle verloren gehen); dessen Client beginnt die wartenden Uploads
nach Rueckkehr/Timeout des Polls. Ein langsamer Client blockiert andere nicht.

## Gemeinsame Kameraeinstellung

Private Datei `/etc/camera-rpi/capture-pool.conf`, root:root, 0600:

```ini
source_server=bielefeld-legacy
legacy_timezone=Europe/Berlin
```

Die Kameraoptionen dieses Ziels gelten fuer **alle** Clients, unabhaengig davon,
welcher zuerst anfragt. Das sichert auf Bielefeld insbesondere `eos-legacy`.
URL, Key, Webcam-Slug und Uploadmodus bleiben dagegen pro Ziel getrennt.
Kameraeinstellungen im Admin am Quellziel bearbeiten; die Worker lesen sie
erneut ein. Kameraoptionen anderer Ziele sind bei aktivem Pool nicht wirksam.
Alle beteiligten Zielkonfigurationen muessen denselben tmpfs-Spool verwenden.

Ohne Poolkonfiguration bleiben alte Installationen kompatibel. Deshalb ist
ein koordiniertes Update aller Worker erforderlich; nur eine neue Bibliothek
zu kopieren aktiviert keine gemeinsame Aufnahme. Ein fehlendes/ungueltiges
Quellprofil darf nicht auf eine beliebige andere Kamerasequenz zurueckfallen.
Alte wartende Requests ohne Scope-Markierung nehmen an der ersten gemeinsamen
Generation teil; bereits vorhandene JPEGs bleiben in ihren Zielqueues.

## Pruefung

Im lokalen Admin zeigt jeder Zielserver den Button **Letzte 10 Log-Eintraege**.
Er liest ausschliesslich `camera-rpi-worker@SERVER-ID.service`, ohne Dienst-
neustart oder persistente Logkopie. Ausgabe ist auf 32 KiB/fuenf Sekunden
begrenzt; HTML wird als Text dargestellt und der eigene gueltige Key maskiert.
Ist das Journal leer (z.B. nach Reboot bei fluechtigen Logs), wird das angezeigt.

`bash tests/capture-pool`: echte parallele Worker mit Fake-Kamera/HTTP,
Hardlink-Identitaet, unabhängige ACKs, Legacy/Chunk-Retry, offline bleibende
Clients, feste Wetterdaten, Generationenwechsel, Zehnergrenze und Schutz eines
offenen Upload-Inodes. Keine reale Kamera, kein GPIO, keine Dienstaktionen.

Auf Bielefeld am 13.09.2026 live nachgewiesen: ein gemeinsames JPEG, Legacy an
.2 und Chunk-v1 an .34, Freigabe erst nach beiden ACKs. Die 01:00-Runde prüfte
zusätzlich einen erst nach Aufnahme gestarteten DEV-Worker. Langzeit- und
Reboot-Verhalten bleiben gesondert zu beobachten; siehe `DEVELOPMENT.md`.

Ein verspäteter erster DEV-Poll kann keinen vorigen Poolstand beobachten.
Deshalb unterdrückt der DEV-Webcamserver automatische Aufträge für einen bereits
angenommenen exakten Archivslot (`ReceivedCaptureSlot`). Manuelle Aufträge haben
weiterhin Vorrang. Dies ist eine separate Serverkorrektur, kein Pi-Zeitcache.

## Quelltext und Queue-Vertrag

`scripts/lib/camera-rpi-pool.sh` enthält Profilwahl, Empfängersnapshot,
Veröffentlichung, Wiederaufnahme einer unterbrochenen Verteilung und ACK/Trim.
Der Worker übernimmt Poll/Queue/Upload; Common kapselt den Hardwarezugriff.
Alle mutierenden Poolfunktionen erwarten die globale `operation.lock` bereits
beim Aufrufer. `cr_pool_latest` darf den atomaren Merker ohne diese Sperre vor
einem blockierenden Poll lesen. `latest` ist eine Generationsepoche, kein
Archivslot und keine Ablaufzeit.

Requestdateien sind höchstens 64 Byte große Daten, nie ausführbare Shell:
`capture`, optional eine Zeile `YYYYMMDD_HHMM`, dann optional `pool=EPOCH`
(beim ersten Poll `pool=0`) oder `private`. Der Worker behält diese Angaben
beim Übernehmen eines RAM-Pendingmarkers in den Zielspool unverändert bei.
`read_capture_request_stamp` validiert sie und setzt `REQUEST_LEGACY_STAMP`,
`REQUEST_POOL_SLOT` und `REQUEST_PRIVATE`. Alte unmarkierte Requests bleiben
lesbar. `queue_request` erhält die drei Werte als Epoche, Kalenderzeit und Scope.

Das `.EPOCH.build`-Verzeichnis ist noch nicht sichtbar für Uploads. Erst das
geprüfte JPEG samt Empfänger-/Sidecardaten wird atomar als Generation publiziert.
Eine Quittierung ist eine Zustandsänderung, nicht nur eine Logzeile. Absturz
zwischen Serverannahme und lokalem ACK kann weiterhin eine Wiederholung erzeugen.

## Aktualisierung der Bestandskamera .16

Wichtig beim Umstieg: Nicht nur `webcam_wait` stoppen. Der alte minütliche
`webcam_check`-Cronjob startet ihn sofort wieder. Am 13.09.2026 wurde dies
live nachgewiesen: Altaufnahme und neuer Worker griffen gleichzeitig auf USB
zu (`Could not claim the USB device`). Den Alt-Überwachungsjob mit Sicherung
deaktivieren; `ops/disable-legacy-poller-cron.sh --apply` erledigt dies gezielt
für den bekannten einzelnen Eintrag in `/etc/crontab`. Die neue Aufnahme-
routine verweigert zusätzlich jeden GPIO-/USB-Zugriff, solange ein Prozess
`webcam_wait` oder `webcam_capture` vorhanden ist. Andere Cronjobs bleiben an.

`ops/update-capture-pool.sh --apply` installiert nur bei gestoppten Kamera-Workern
und bekanntem Ausgangsstand. Der gemeinsame Profilgeber ist `bielefeld-legacy`;
Keys, Zielkonfigurationen, Sensor/Klima und Boot-Aktivierungen bleiben unverändert.
Der Installer startet keine Dienste und löst keine Aufnahme/Fokusbewegung aus.
Er sichert die ersetzten Dateien unter `/var/backups/camera-rpi-pool.*` und gibt
den maschinengebundenen Rollback-Befehl aus. Ein selbst geöffnetes RW-Fenster wird
anschließend geschlossen. Das Paket wird mit `ops/build-capture-pool-package.sh`
gebaut und inklusive Fixture-Tests geprüft.

Vor einem späteren Rollback alle Worker stoppen. Offene Aufnahmeaufträge müssen
vorher abgearbeitet sein: Der Installer verweigert andernfalls die Rückstellung,
statt neue Auftragsformate mit der alten Software zu verlieren. Wartende JPEGs
und Zeitstempel werden nicht gelöscht; alte Worker können ihre Ziel-Hardlinks
weiter hochladen. Pool-Master bleiben nach Rollback im RAM und werden von der
alten Software nicht bereinigt. Den DEV-Worker danach **nicht** mit seinem alten
Standard-Aufnahmeprofil starten. Eine bereits verstellte Schärfe korrigiert das
Update nicht.

## Automatischer Kamera-Stromreset (16.10.2026)

Die gemeinsame Wiederholungspause nach fehlgeschlagener Aufnahme beträgt
30, 60, 120, 240 und anschließend maximal 300 Sekunden. Aufnahmezeit und
Worker-Schleife kommen hinzu (Bestandskameras: 20 Sekunden Fehlerpause).
Aufnahmeversuche sind weiterhin unbegrenzt; ein gültiges JPEG löscht den
Backoff. Uploadfehler betreffen nur den Versand bereits gespeicherter Bilder.

Nach drei echten fehlgeschlagenen gphoto-Aufnahmen veranlasst der Worker
einen Stromreset über den **aktiven, validierten Kamerastrom-GPIO**. Wie im
alten `webcam_capture`: 45 Sekunden aus, danach 15 Sekunden Anlaufzeit.
Kein GPIO wird geraten und der Pi wird nicht neu gestartet. Die Funktion
setzt einen eingerichteten Kamerastrom-Dienst voraus; RTSP/V4L2 sind ausgenommen.

Der native GPIO-Dienst führt die begrenzte Ausschaltphase selbst aus. Der
Worker muss nicht 60 Sekunden unter der Operationssperre schlafen; Sensor und
Klima können weiterarbeiten. Bei Worker-Abbruch wird trotzdem eingeschaltet;
bei geordnetem Ende des GPIO-Dienstes während eines Resets ebenfalls. Ein
SIGKILL/Stromausfall des GPIO-Owners ist keine garantierte Rückstellung.
Manuelles AUS wird respektiert und bricht einen laufenden Reset ab.

Alle Ziele teilen `/run/camera-rpi-recovery/state.json`. Es gibt höchstens
einen Reset je 30 Minuten und drei Resets ohne gültige Aufnahme dazwischen.
Ein gültiges Bild setzt Fehlerzähler und Budget zurück, behält aber die
30-Minuten-Sperrzeit. Neustarts einzelner Worker setzen diese Zähler nicht
zurück; ein Pi-Neustart leert den RAM-Zustand. Das Resetbudget wird vor dem
Socket-Befehl reserviert, damit auch ein verlorenes ACK keinen Resetsturm
auslöst. Native RESET-Meldungen stehen im Journal des Kamerastrom-Dienstes,
der Auslösegrund zusätzlich in der bestehenden Capture-Diagnose zum Server.

Nur tatsächlich gestartete gphoto-Versuche zählen. Privacy-Sperren,
Operations-/Klima-/Fokus-Vorprüfungen, Uploadfehler und ein ausgeschalteter
Kamerastrom zählen nicht. Ein fehlendes/ungültiges JPEG nach gphoto zählt.
Ein GPIO-ACK bestätigt keinen USB-Empfang und keinen erfolgreichen Upload:
die anschließende reguläre Aufnahme und alle Ziel-ACKs bleiben erforderlich.
Die bereits bekannte Zuordnung stark verspäteter Aufnahmen zu alten
Auftragszeiten wird hierdurch nicht verändert.

## Review-Korrekturen 17.10.2026 (Quellstand, noch kein Kamera-Rollout)

Vor Aufnahme-/Poolpublikation und vor einem Upload wird das JPEG vollstaendig
mit PHP-GD dekodiert (maximal8192px je Achse/40MP, PHP-memory_limit256MiB,
Timeout15s plus2s bis SIGKILL). Das PHP-Limit ist kein hartes RSS-Limit.
`install.sh --core --packages` nimmt deshalb `php-gd` auf; Installation und
Worker-`--check-config` pruefen die Funktion `imagecreatefromjpeg`. Ein fehlendes
Werkzeug, Timeout oder Ressourcenfehler behaelt das Bild im Spool.

Ein bereits vorhandenes, eingerahmtes, aber nicht dekodierbares Spool-JPEG
kommt nach `spool/SERVER-ID/quarantine/EPOCH.jpg`, zusammen mit seinen Sidecars.
Maximal drei solcher Bilder je Ziel bleiben als Diagnose erhalten; aeltere
Quarantaenebilder werden verworfen. Das wird als ERROR protokolliert und ist
**kein Upload-ACK**. Nur die betroffene Pool-Zielreferenz wird aufgeloest;
andere Ziele bearbeiten ihre eigenen Referenzen. Eine neu erzeugte ungueltige
Aufnahme wird dagegen gar nicht erst verteilt; Auftrag und Retry bleiben.
Quarantaene beansprucht zusaetzlichen tmpfs-Speicher und ist kein Dauerarchiv.

`EPOCH.jpg.captured` enthaelt die tatsaechliche Abschlusszeit des JPEG-Downloads
als Epochensekunde. Sie ist eine Pi-Beobachtung, kein Kamera-EXIF-Zeitbeleg.
Der Worker sendet sie als optionales Formularfeld `captured_at` bei Legacy und
allen Chunk-Schritten. `now` und die Upload-/Pool-ID bleiben die Sollslotzeit.
Der neue Server benutzt `captured_at` fuer DB, Wetterzuordnung und ISO/EV.
Alte Server ignorieren das Zusatzfeld. Altes Spool ohne diese Datei erfindet
keine physische Aufnahmezeit; der bisherige Slot-Fallback bleibt erkennbar.

EOS-Cleanup ignoriert waehrend Kindprozessbeendigung und abschliessendem
Release weitere HUP/INT/TERM. Der eigene `timeout --kill-after=2 10s` begrenzt
auch einen nicht auf TERM reagierenden Release-Prozess per KILL. Der aeussere
Capture-Timeout muss weiterhin mindestens15s KILL-Nachfrist einraeumen.
Wiederholte Prozess-/Gruppensignale und ein haengender Release sind lokal
getestet; die elektrische/mechanische Wirkung braucht eine echte EOS-Abnahme.

Beim spaeteren Update alle Worker koordiniert behandeln, vorab GD und freien
RAM pruefen und Quell-/Installationshashes vergleichen. Kein Paketinstallieren
parallel zu laufender Aufnahme. Alte Spooldaten erhalten; danach neue Aufnahme
und JPEG-/ACK auf jedem Ziel sowie Sensor/Klima/RO pruefen. Rueckweg ist die
gesicherte zusammenpassende Worker-/Pool-/Source-/EOS-Version; neue Sidecars
koennen liegen bleiben. Der Serveranteil ist in
`../webcam/ops/review-fixes-2026-10-17.md` dokumentiert.
