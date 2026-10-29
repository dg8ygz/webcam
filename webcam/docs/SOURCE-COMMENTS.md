# Quellkommentar-Nachkontrolle seit dem Entzerren

Stand: 12.09.2026. Geprüfter Bereich: `919761b..25bc9df`, **33 Commits**.
`919761b` ist „Complete camera service refactors“; der danach folgende
Abschluss `07938f4` (gemeinsame Pfade/Template-Payloads) ist eingeschlossen.
Geprüft wurden die Änderungen dieser Commits und die zugehörigen heutigen
Funktionsblöcke, nicht nur die Commit-Texte oder Markdown-Anleitungen.

## Ergebnis und Maßstab

Die Leseführung aus dem Refactoring ist unter anderem in
`CameraStoragePathResolver`, `CameraPagePayloadBuilder`, den Kamera-Routen und
den Storage-Klassen erhalten geblieben. Es gab keinen allgemeinen Verlust aller
Kommentare. Später hinzugekommene Funktionen waren jedoch teilweise nur auf
Klassenebene oder im Runbook beschrieben.

Nachgepflegt wurden Zweck, Eingaben/Ergebnis und relevante Nebenwirkungen direkt
an den Blöcken: Kamera-Queues, Lease/Capability, Poll-Reihenfolge, DHT-Fallback,
Wetterprojektion/-anzeige, Kalender, Boolean-Konfiguration, Ressourcenbudgets,
Backup-Anstoß, Chunk-Sperren, Limiter-Transaktion, Boot-/Ban-Abnahme und isolierte
Browsertests. Triviale Getter, gleichförmige Testfälle und reine Datenlisten
brauchen keinen Kommentar, der nur ihren Namen wiederholt. Bestehende gute
Kommentare bleiben bestehen.

Wichtig: Eine Queue-Bestätigung ist noch keine ausgeführte Aufnahme; `running`
beim Tunnel beweist keinen erfolgreichen SSH-Zugang. Der Ban-Abnahmetest prüft
Zustände/Regeln für reservierte Testadressen, keinen echten Paketdurchlauf.
Diese Grenzen stehen nun auch an den jeweiligen Funktionen.

## Commit-Abdeckung

„Vorhanden“ bedeutet: Die geänderten Blöcke waren durch Funktions-/Blockkommentare
oder ihre unmittelbar zugehörige Leseführung ausreichend erklärt.
„Ergänzt“ bezieht sich auf den heutigen Quellstand; alte Commits werden nicht
umgeschrieben. Doku-/Release-Commits enthalten keine neuen Funktionsblöcke.

| Commit | Bereich | Ergebnis |
| --- | --- | --- |
| `07938f4` | Pfad-/Payload-Entzerrung | Vorhanden |
| `f3a0b61` | Retained-Archivpfade | Vorhanden |
| `1a06ebd` | Iteration-165-Betriebsdoku | Keine neuen Blöcke |
| `cc9d550` | Aktuelle Vorschaubilder | Vorhanden |
| `9d7b475` | Iteration-166-Betriebsdoku | Keine neuen Blöcke |
| `4b04b6b` | RO-Backup-Rechte | Vorhanden |
| `6b4ef55` | Lightbox-Ausschnittdownload | Vorhanden |
| `93ae085` | Release-Metadaten | Keine neuen Blöcke |
| `3c32f84` | Schreibrechte/Runtime-Provisionierung | Vorhanden |
| `a679c90` | Limiter/Mount-Reihenfolge | Limiter-Verträge ergänzt |
| `828cac2` | Retention ohne Originale | Vorhanden |
| `f5d7e1b` | Legacy-Like-Absicherung | Vorhanden |
| `d74e7bc` | Upload-Body-/Proxygrenzen | Vorhanden |
| `bc0ccd6` | Fail2Ban-Installer/Filter | Rückweg-Kommentar ergänzt |
| `3626355` | Watchdog-/HTTP-Grenzen | Funktionsverträge ergänzt |
| `070d9c0` | Admin-Body, Overlay, Loglock, Backup | Grenzen/Backup ergänzt |
| `e272e06` | Review-Härtung/Wetterprojektion | Chunk-/Wetter-/Kalenderverträge ergänzt |
| `a99c3e1` | Netzteilwechsel-Doku | Keine neuen Blöcke |
| `977f06a` | Boot-Zeitlinie | Keine neuen Blöcke |
| `6394725` | Betriebsabnahme/Ban-Test | Abnahmegrenzen ergänzt |
| `caa1237` | Apache-Boot-Recovery | Funktionsverträge ergänzt |
| `c9636c6` | Ausfall-/Reboot-Doku | Keine neuen Blöcke |
| `6009cf2` | Steinhagen-IPv4-Doku | Keine neuen Blöcke |
| `7fe60de` | Mitternachtslast/Backup-Deckung | Backup-Einstieg ergänzt, Retention vorhanden |
| `736c380` | PROD-Abnahme-Doku | Keine neuen Blöcke |
| `85d52c3` | Proxy-Nachkontrolle | Keine neuen Blöcke |
| `f01fcc4` | Testkameras, Capture, Uploadmodus | Queue-/UI-/Persistenzgrenzen ergänzt |
| `88e2863` | Pi-Webupdate-Doku | Keine neuen Blöcke |
| `7508ad9` | Remote-Fokus | Queue-/UI-Verträge ergänzt |
| `c363bfb` | Wartungslease/DHT-Metadaten | Lease-/Fallback-/Poll-Verträge ergänzt |
| `7525d09` | Wetteranzeige/Feuchte | Parser-/Darstellungsblöcke ergänzt |
| `da42b76` | Boolean-Umgebung | Default/Fehlervertrag ergänzt |
| `25bc9df` | Isolierte Browserabnahme | Prozess-/Fixture-Isolation ergänzt |

## Prüfung und Betriebsgrenze

- Für sämtliche hier geänderten PHP-Dateien wurden die Tokens gegen den
  Ausgangsstand verglichen, ohne `T_COMMENT`, `T_DOC_COMMENT`, `T_WHITESPACE`:
  identischer ausführbarer Tokenstrom, Syntaxprüfung über `TOKEN_PARSE`.
- Für JavaScript wurde der Acorn-AST ohne Quellpositionen verglichen; für Python
  der AST ohne Docstrings. Shell-Diffs enthalten nur Kommentar-/Leerzeilen;
  `bash -n` und `git diff --check` bestanden.
- Keine Logikänderung, DB-Migration, Versions-/Iterationsanhebung, Dienstaktion
  oder PROD-Ausrollung durch diese Nachpflege. Bereits vorhandene Änderungen an
  Betriebsdokumenten gehören nicht automatisch zu diesem Kommentar-Commit.
- Historische Installer-Prüfsummen beschreiben den damaligen geprüften
  Ausgangsstand und wurden nicht an neue Kommentare angepasst.

Künftig bei jeder Vertragsänderung den Funktions-/Blockkommentar mitändern:
Zweck, Scope, Sperren, Fehler/Retry und Sicherheitsgrenzen. Runbooks beschreiben
Installation und Abnahme; sie ersetzen diese Quelltext-Erklärungen nicht.
Die korrespondierende Pi-Prüfung steht in `camera-rpi/SOURCE-COMMENTS.md`.
