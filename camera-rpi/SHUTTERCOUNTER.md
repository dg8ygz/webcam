# Canon-Shuttercounter: auslesbar, aber kein belegtes +1 pro Aufnahme

## Direkte Abfrage auf Bielefeld (.16)

Die installierte Kombination gphoto2 2.5.28 / libgphoto2 2.5.30 und Canon
EOS 1500D/2000D stellt `/main/status/shuttercounter` bereit. Nur lesen,
unter derselben Kamerasperre wie Worker/Fokus und mit kurzem Zeitlimit:

```sh
sudo flock -w 2 /run/lock/camera-rpi.operation.lock \
  timeout --signal=TERM --kill-after=3 15s \
  env LC_ALL=C /usr/bin/gphoto2 --get-config /main/status/shuttercounter
```

Keine neue Aufnahme, kein Set-Config, kein Reset und kein Dienststop. Ist die
Kamera beschäftigt, endet die Sperrwartezeit ohne USB-Zugriff; später nochmals
lesen. Nicht parallel zum alten Poller benutzen, der diese Sperre nicht kennt.

## Gemessener Befund am 13.09.2026

- Erste erfolgreiche gezielte Abfrage vor der 01:20-Runde: `Current: 194341`,
  `Readonly: 1`, `Type: TEXT`.
- Reguläre gemeinsame Aufnahme 1789255200 um 01:20:02–01:21:10; DEV-Chunk
  bestätigt 01:21:33, Legacy bestätigt 01:21:45. Keine zweite Aufnahme.
- Erneute Abfrage ab 01:21:51 in einer neuen gphoto2-Verbindung: wieder 194341.
- Ein Versuch während der laufenden Aufnahme wurde durch die Sperre abgewiesen.
  Keine Zusatzaufnahme für den Zählertest und kein Reboot zur Aktualisierung.

Damit ist das Auslesen bewiesen, **nicht** die erwartete Erhöhung um eins pro
JPEG. Ein früheres vollständiges Eventprotokoll enthielt 194336. Firmware-
Caching, verzögerte Aktualisierung oder Unterschiede der Zählung im verwendeten
EOS-Aufnahmemodus sind mögliche Erklärungen, aber hier nicht nachgewiesen.
Den Wert nicht als verlässlichen Nachweis ausgelöster Poolbilder anzeigen.
Ein eigener Softwarezähler wäre ein anderer Messwert, kein Ersatz für den
mechanischen Lebensdauerzähler der Kamera.

## Recherche: möglicherweise erst nach Kamera-Neustart aktualisiert

Recherche vom 13.09.2026: Zwei Entwicklerquellen beschreiben einen unveränderten
angezeigten Zähler nach Aufnahmen und das Aus-/Einschalten der Kamera, um den
aktualisierten Wert auszulesen:

- Die [EOSCount-FAQ](https://eoscount.com/faq.aspx), Abschnitt „I clicked the
  shutter button, but the displayed shutter count did not change“, nennt dieses
  Verhalten im Zusammenhang mit ihrer Software. Die dortige Modellliste ist
  älter und bestätigt unsere EOS 1500D/2000D nicht ausdrücklich.
- Der [ShutterCount-Entwicklerbericht vom 04.04.2016](https://www.laszlopusztai.net/2016/04/04/shuttercount-on-iphone-and-ipad/)
  beschreibt drei Testaufnahmen und anschließendes Aus-/Einschalten einer
  EOS 7D Mark II, um den aktualisierten Zählerstand anzuzeigen.

Das sind konkrete Hinweise auf verzögerte Sichtbarkeit, aber kein Nachweis für
unsere Kamera/Firmware mit gphoto2. Der unveränderte Wert 194341 widerlegt daher
**nicht** die Richtigkeit des Gesamtzählers. Bisher ist lediglich seine Eignung
als live aktualisierter +1-Nachweis pro Aufnahme nicht bestätigt.

Bei einem später geplanten Aus-/Einschalten der Canon: Ausgangswert notieren,
die tatsächlich ausgeführten Aufnahmen bis dahin zählen und anschließend den
Wert erneut unter der Kamerasperre auslesen. Ein Pi-Reboot allein schaltet die
Canon nicht zwingend aus und ein. Keine zusätzliche Betriebsunterbrechung oder
automatischen Kamera-Resets nur für diese Prüfung; der Vergleich steht noch aus.

## Quellennachweis

Die zur installierten Bibliothek passende upstream-Datei
[libgphoto2 2.5.30, camlibs/ptp2/config.c](https://github.com/gphoto/libgphoto2/blob/libgphoto2-2_5_30-release/camlibs/ptp2/config.c)
ordnet `shuttercounter` der Canon-Eigenschaft `PTP_DPC_CANON_EOS_ShutterCounter`
zu, liest sie als UINT32 und bietet keinen Setter (`_put_None`). Das belegt
die Eigenschaft, nicht deren Aktualisierungsrhythmus durch diese Firmware.
Dass sie modellabhängig fehlt, ist auch im
[upstream-Ticket zur EOS 5DS](https://github.com/gphoto/libgphoto2/issues/224)
dokumentiert. Kein fremdes Diagnoseprogramm oder experimenteller PTP-Opcode
wurde auf der laufenden Kamera installiert/ausgeführt.
