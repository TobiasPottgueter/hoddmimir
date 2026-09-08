# Wartung: Coverage, Image-Sicherheit und Laufzeitabnahme

Stand: 8. September 2026. Fortsetzung von [Bericht 06](06-maintenance-implementation.md).
Die Änderungen bleiben im Arbeitsbaum. Nach Freischaltung des SSH-Zugangs läuft
die Abnahme gegen echte Dev-PVE/PBS-Systeme; der zusätzliche Befund und die
Wiederholung sind in [Bericht 08](08-dev-maintenance-acceptance.md) dokumentiert.
Die beiden vorhandenen Installationen sind laut Betreiber ebenfalls Dev.
Die absichtlichen DDL- und Absturztests verwenden eine getrennte Testinstanz.
Es gab keine Veröffentlichung in einer externen Registry.

## Umgesetzt

- Die einmalige Überführung einer älteren V2 ohne Wartungsprotokoll ist in
  ADR 0006 und Betriebsdokumentation ausdrücklich **ausschließlich manuell**.
  Die automatische Ablehnung einer inkompatiblen Baseline bleibt erhalten.
- Der nicht erreichbare `Passed`-Zweig der Problemklassifizierung wurde
  entfernt: `GateResult` verbietet bereits einen fehlgeschlagenen Gate mit
  diesem Detailcode. Tests ergänzen die Wiederanlaufentscheidung und erlaubte
  JSON-Objekte beziehungsweise fehlerhafte Proxmox-Berechtigungsmatrizen.
  Zwei `in_array`-Aufrufe sind ausdrücklich global qualifiziert. Dadurch
  entfallen die zusätzlichen PHP-Zweige für eine unbenutzte namespaced
  Ersatzfunktion; alle drei korrigierten Klassen erreichen im gezielten
  instrumentierten Lauf 100 Prozent Zeilen- und Branch-Coverage.
- Worker: OpenSSL-Pakete auf `3.5.8-r0`, SQLite-Bibliothek auf `3.53.4-r0`
  gepinnt. MariaDB: unveränderte Datenbankversion, `gosu` mit Go `1.26.8`
  neu gebaut.
- Web: FrankenPHP `1.12.7` mit passendem offiziellen Builder und Runner sowie
  Go `1.26.8`. Die korrigierten Go-Abhängigkeiten sind vollständig in
  `docker/web/frankenphp/go.mod` und `go.sum` festgehalten; Build mit
  `go mod verify` und `-mod=readonly`. Nur das Binary gelangt in den Runner.
  OpenSSL ist ebenfalls auf `3.5.8-r0` gepinnt.
- Der eigene Alpine-Build erhält einen 8-MiB-Thread-Stack. Der erste Browserlauf
  hatte einen kalten Symfony-Start mit zu kleinem musl-Stack aufgedeckt.
  Das fertige ELF wurde auf `GNU_STACK` mit Größe `0x800000` geprüft.
- Die vollständige Restore-Probe deckte eine fehlende Compose-Definition auf:
  Beim Prüfen der Worker gegen die wiederhergestellte Kopie wird jetzt auch
  die Migration-/Maintenance-Basis eingebunden. Ein Regressionstest sichert
  diese Kombination ab.

Quellen der Paketkorrekturen sind die [Alpine-Sicherheitsdatenbank](https://secdb.alpinelinux.org/v3.23/main.json),
die [Go-Veröffentlichungen](https://go.dev/dl/) und der
[offizielle FrankenPHP-Buildvertrag einschließlich musl-Stackgröße](https://frankenphp.dev/docs/compile/).
Alle Basisimages und Builder bleiben per Digest gepinnt; die Veröffentlichung
und Prüfung bleiben auf `linux/amd64` begrenzt.

## Prüfungen des aktuellen Stands

| Prüfung | Ergebnis |
| --- | --- |
| Gezielt geänderte PHP-Regeln und Proxmox-Adapter | 57 Tests, 176 Assertions |
| PHPStan | keine Fehler in 1.173 PHP-Dateien |
| Backend-Container mit aktualisierten Bibliotheken | 2.723 Tests, 12.495 Assertions; Composer/OpenAPI/PHPStan erfolgreich |
| Sicherheits-Gate | alle drei fertigen Images: null HIGH/CRITICAL nach unveränderter Trivy-Einstufung; SBOMs vorhanden |
| Browser mit neu gebautem FrankenPHP und kaltem Cache | 19 Playwright-Tests erfolgreich |
| Deployment-Verträge | 125 Tests, zwei bewusst opt-in ausgeführte Laufzeittests übersprungen |
| Ansible Lint und Syntax, isolierte Inventories | erfolgreich |
| Vollständiger Coverage-Gate | erfolgreich; Domain/Application und Proxmox jeweils 100 Prozent Zeilen und Branches |
| Gesamtes Backend | 95,45 Prozent Zeilen- und 92,01 Prozent Branch-Coverage |
| MariaDB-Phase des endgültigen Coverage-Laufs | 479 Tests, 8.028 Assertions erfolgreich |
| Mutation-Gates | erfolgreich: kritisch MSI 90,22 Prozent (4.535 Mutationen), global MSI 80,34 Prozent (20.704 Mutationen) |
| Vollständige lokale Wartungs-/Restore-Laufzeitprobe | alle sechs Szenarien erfolgreich, 317 Sekunden |

Die Mutation-Schwellen bleiben unverändert bei 90 beziehungsweise 80 Prozent.
Die maschinenlesbaren Infection-Summaries weisen null Timeouts und 127
beziehungsweise 2.188 übersprungene Mutationen aus; die vollständigen Summaries
und Protokolle sind archiviert.

Die beiden übersprungenen Deployment-Tests sind kein stiller Ersatz für Laufzeitnachweise:
der bestehende isolierte MariaDB-Dump/Restore-Test wurde in Bericht 06 separat
ausgeführt, die neue vollständige Probe wird ebenfalls separat aufgerufen.

Die endgültigen Coverage-/Mutation-Images enthalten die Bibliotheksupdates und
die explizit globalen Funktionsaufrufe. Ihre **1.173 PHP-Quell- und Testdateien
stimmen vollständig mit dem aktuellen Arbeitsbaum überein**. Nach dem normalen
Backend-Container- und Browserlauf wurden ausschließlich diese zwei eingebauten
Funktionsaufrufe in Backup-Klassen qualifiziert. Der endgültige instrumentierte
Lauf hat die gesamten 2.723 Kern-/Vertragstests und 479 MariaDB-Tests danach
erneut erfolgreich ausgeführt. Die unveränderten Browser- und Frontend-Tests
bleiben gültig. Die endgültigen Image-Archive wurden nach den beiden
Quelländerungen erneut gebaut und gescannt; die erweiterte Wartungsprobe
verwendet diese endgültigen Images.

Lokale Nachweise liegen unter `artifacts/maintenance-validation/2026-09-08/`;
Image-Archive und SBOMs unter `artifacts/supply-chain/`. Die ursprüngliche
Zuordnung von 15 MariaDB-Befunden in Bericht 06 war fehlerhaft: Dieser Bericht
stammte vom 13. Juli. Die hier berichteten Ergebnisse stammen ausschließlich
aus neu gebauten und erneut gescannten Images.

## Reproduzierbare lokale Laufzeitprobe

`make maintenance-runtime-test` verwendet die echten vier Anwendungscontainer,
die echten Ansible-Templates und eine echte MariaDB. Es erzeugt eine zufällig
benannte eigene Installation mit frischen Testgeheimnissen, ohne echte Proxmox-
Systeme und mit deaktivierter Backupausführung und Benachrichtigung. Eine
vorübergehende deaktivierte PVE-Verbindung verweist ausschließlich auf eine
lokale TLS-Testinstanz am eigenen Docker-Bridge-Gateway. Die produktiven Adapter
verwenden eine erzeugte CA mit aktiver Zertifikatsprüfung. Die Testinstanz
liefert ausschließlich synthetische Daten und leitet keine Anfragen weiter.
Die gescannten Images werden dafür nur in eine vorübergehende Registry auf
Loopback geladen und anschließend per Digest verwendet.

Sechs Szenarien werden geprüft:

1. Laufendes Backup eines fremden Benutzers: Die Migration bleibt ohne
   DB-Sicherung oder Mutation gesperrt; nach Ende des Tasks ist Recovery möglich.
2. Unerreichbare TLS-Gegenstelle: ebenfalls Sperre vor der Migration; nach
   Wiederkehr wird die vorherige Installation kontrolliert freigegeben.
3. Erfolgreiches Upgrade einschließlich tatsächlichem Restore der Sicherung in
   eine zweite MariaDB, Rollen-/Worker-Prüfung und HTTP-Wartungsschutz.
4. Fehlgeschlagene Migration nach tatsächlichem Spaltenverlust und zusätzlichem
   DDL; Wiederherstellung des ursprünglichen Binärinhalts und der Konfiguration.
5. Tatsächlicher SIGKILL an der dauerhaften Mutationsgrenze; ein neuer Prozess
   nimmt die Recovery auf und entfernt den zwischenzeitlichen DB-Zustand.
6. SIGKILL nach dem dauerhaften Freigabemarker; Wiederaufnahme darf die DB
   nicht zurücksetzen. Eine nach diesem Marker angelegte Tabelle
   bleibt erhalten.

Zusätzlich werden unveränderte Secret-Dateien, aufgehobene Sperre und entferntes
aktives Journal geprüft. Die Testinstallation und ihre Volumes werden danach
entfernt. Sie ist kein automatisierter Übergang einer älteren V2.

## Fortsetzung gegen echte Dev-Systeme

Der SSH-Zugang wurde anschließend freigeschaltet. Der erste Lauf gegen echte
PVE-/PBS-Verbindungen deckte eine im Leerlauf gehaltene Collector-Sperre auf.
Die nachstehend verlinkte Fortsetzung enthält Korrektur, neue Prüfstände und
die tatsächlichen Abnahmeergebnisse. Die hier aufgeführten Gate-Ergebnisse
beziehen sich auf den Stand vor dieser zusätzlichen Korrektur.

Siehe [Bericht 08](08-dev-maintenance-acceptance.md). Die einmalige Überführung
einer älteren V2 ohne Wartungsprotokoll bleibt ausschließlich manuell.
