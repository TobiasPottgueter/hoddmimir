# Hoddmímir: Bestandsaufnahme und Soll-Ist-Abgleich

## Nachträgliche Benutzerentscheidungen vom 7. September 2026

Nach Abschluss dieses Audits wurden zwei bisherige Anforderungen geändert:

- [ADR 0005](../../adr/0005-automatic-backup-recovery.md): automatische Wiederfreigabe nach vollständiger frischer Taskklärung ohne eindeutige Zuordnung, ohne Sonderwartefrist oder mehrere Pflichtprüfzyklen; vor jedem Start gilt das allgemeine Remote-Task-Gate einschließlich manueller/externer Backups. Ein zusätzliches nachfolgendes Backup ist akzeptiert. Keine zwingende manuelle Auflösung; kein blinder POST-Retry.
- [ADR 0006](../../adr/0006-maintenance-upgrade-database-restore.md): Wartungsupgrade mit DB-Sicherung, Funktionstests bei gesperrtem Betrieb und Restore samt alter Anwendung bei Fehlern vor Freigabe.

**Aktueller Nachtrag, 8. September:** Die isolierte reale DEV-Abnahme des
Maintenance-Protokolls ist im [Bericht 08](08-dev-maintenance-acceptance.md)
abgeschlossen. Die anschließenden Funktionskorrekturen und Erweiterungen,
einschließlich F03 und F07–F11, sind im [Bericht 09](09-followup-implementation.md)
dokumentiert. Die Backup-Recovery-Abnahme einschließlich fremder Taskbelegung
ist auf der bestehenden DEV bestanden. Abschließende Veröffentlichungsgates
und Registry-Pins stehen noch aus. Die gesamte vorhandene Estate ist
DEV; die einmalige Umstellung älterer V2-Installationen bleibt manuell.
Der Übergang der bestehenden Installation hinter `hoddmimir.netzkultur.cloud`
und die anschließende Backup-Abnahme werden in
[Bericht 10](10-existing-dev-upgrade-and-backup-acceptance.md) fortgeschrieben.

Die folgenden Analysen und Testergebnisse beschreiben den ursprünglichen Auditstand.
Zum ursprünglichen Auditstand erfüllte die Folgepromotion die neuen
Klärungs-/Startbedingungen noch nicht (F01). Der Nachtrag dokumentiert die
Umsetzung der automatischen Wiederfreigabe nach ADR 0005.
F02 kann durch den neuen getesteten Restorepfad behoben werden; Expand/Contract
ist dann nicht die einzige Lösung. Solange das bestehende Image-Rollbackverfahren
verwendet wird, bleibt dessen Kompatibilitätsproblem bestehen.
Die Restarbeiten sind mit diesen beiden ADRs als maßgeblichem Zielvertrag zu lesen.


Stand: 7. September 2026. Gegenstand sind das angegebene Alt-Repository und der aktuelle lokale V2-Arbeitsbaum einschließlich seiner offenen Änderungen.

**Urteil: Der Rewrite ist weit fortgeschritten, aber weder vollständig anforderungskonform noch releasefähig.** Die drei Anwendungskomponenten, das Inventar, die Konfigurationsoberfläche und große Teile der Backupsteuerung existieren. Es fehlen nicht lediglich letzte Live-Tests: Im aktuellen Stand sind Sicherheits-, Migrations- und Integrationsfehler sowie einige funktionale Lücken nachweisbar.

Die wichtigste Feststellung ist eine Lücke über die Grenze zwischen Reconciliation und nächstem Scheduler-Zyklus: Ein Request wird korrekt als `unknown` mit `forbidden_ambiguous` abgeschlossen; der nächste automatische Zyklus darf danach aber einen neuen Request für denselben Gast erzeugen und bis zur erneuten Submission-Vorbereitung bringen. Das umgeht die beabsichtigte Sperre über eine neue Request-ID. Dieser Pfad wurde ohne PVE-Schreibzugriff gegen eine isolierte MariaDB reproduziert.

[Aktueller Implementierungs- und Prüfstand des Wartungsupgrades](06-maintenance-implementation.md).

## Die fünf angeforderten Analysen

1. [Ist-Analyse der alten Version](/root/Projects/hoddmimir/docs/audits/2026-09-07/01-alt-ist.md): vollständiger Repositoryumfang, Ablauf, Fachregeln, Datenmodell, Sicherheits- und Betriebsprobleme sowie Korrekturen der bisherigen Altbeschreibung.
2. [Soll-Analyse der Anforderungen](/root/Projects/hoddmimir/docs/audits/2026-09-07/02-soll.md): verbindliche Quellen, Zielumfang, fachliche Regeln, Abnahmebedingungen und widersprüchliche beziehungsweise überholte Vorgaben.
3. [Ist-Analyse der V2](/root/Projects/hoddmimir/docs/audits/2026-09-07/03-v2-ist.md): implementierte Komponenten, aktueller Arbeitsbaum, historische Abnahme und frisch ausgeführte Prüfungen.
4. [Anforderungskonformität und Befunde](/root/Projects/hoddmimir/docs/audits/2026-09-07/04-soll-ist.md): bereichsübergreifende Anforderungsmatrix und konkrete Abweichungen mit Quellen.
5. [Fehlende Arbeiten und Reihenfolge](/root/Projects/hoddmimir/docs/audits/2026-09-07/05-restarbeiten.md): priorisierter Arbeitsvorrat mit überprüfbaren Abschlusskriterien.

## Wesentliche Ergebnisse

| Bereich | Urteil |
| --- | --- |
| Alte Version | PHP-Monolith für konfigurierte PVE-Nodes und QEMU; keine WebApp, keine LXC- oder direkte PBS-Unterstützung im Repository. |
| Architektur der V2 | Grundstruktur umgesetzt; Fachregeln teilweise weiterhin in Infrastruktur/SQL dupliziert; Worker booten den gemeinsamen Symfony-Web-Kernel. |
| Scanning und Auswahl | Weitgehend implementiert, inklusive 120-Sekunden-Raster, PVE/PBS, QEMU/LXC, Auswahl, Ziele und Shadow-Projektionen. |
| Backupausführung | Claim, Start, Monitoring, Reconciliation und Cancel vorhanden; Schutz nach `unknown` über mehrere Scheduler-Zyklen unzureichend. |
| Offene Änderungen | 60 geänderte und 6 ungetrackte Dateien vor diesem Audit; neue Benachrichtigungsteile sind noch nicht vollständig integriert. |
| Aktuelle Tests | Backend-Unit/Contract und Frontend-Tests grün; API-Generierungsprüfung und ein MariaDB-Berechtigungstest rot. |
| Betrieb | Historische positive Lab-/Shadow-Evidenz dokumentiert; reale Backup-/Fehlerabnahme und Phase 8 bleiben offen. |

## Nachweisgrenzen und Quellstände

- Alt: Branch `master`, einziger veröffentlichter Branch, Commit `b0bbb7e441e96d410b1be6209542dd7deb6a407b`, Commitdatum 2. Juli 2024. Am Auditdatum über den vom Benutzer eingerichteten SSH-Zugang frisch gelesen. Keine Altprogramme ausgeführt, keine produktive Datenbank untersucht.
- V2: Branch `codex/policies-shadow-mode`, HEAD `bd6a915cc50b8b8b29aec86c7d57953427ef1d04` **plus** die vorhandenen 66 Arbeitsbaumänderungen. Der Dateimanifest-Hash dieses Zustands beträgt `84ca841c5744fb33872a1a5a55f34ed89a8b1396cbe03dddda50ad0ce5ea61c4`.
- Der im lokalen Phase-7-Bericht genannte Lab-Kandidat `a6b4d4f6b7562f45363606258cfe490abac8f75c` liegt sechs Commits hinter HEAD; die offenen Änderungen kommen hinzu. Seine historischen Nachweise gelten nicht automatisch für diese Änderungen.
- Historische CI-, Coverage-, Sicherheits- und Live-Angaben sind als Dokumentation ausgewiesen. Sie wurden nicht durch einen neuen Kontakt zu Lab/Produktion oder Abruf alter CI-Artefakte bestätigt.
- Die V2-Prüfung kombiniert Quellcodeprüfung, Anforderungsverfolgung und aktuelle Tests. Sie ist keine formale Fehlerfreiheitsgarantie und keine Produktionsfreigabe. Vollständige Betriebskenntnis des Altsystems ist aus dessen Repository allein nicht ableitbar; Schema, Cron-Konfiguration und installierte Bibliotheksversion fehlen dort.
- Bestehender Anwendungscode, Anforderungen und Benutzeränderungen wurden nicht korrigiert, committet oder veröffentlicht. Hinzugefügt wurden ausschließlich diese Auditunterlagen und reproduzierbare Test-Patches.

## Aktuelle Verifikation

| Prüfung | Ergebnis |
| --- | --- |
| PHPUnit Unit + Contract, aktueller Backendcode | 2.562 Tests, 11.536 Assertions, kein Fehler, 4 Skips wegen im isolierten Runtime-Aufruf fehlendem `/run/secrets`. |
| PHPStan, `level: max` | Kein Fehler. |
| Vitest | 61 Dateien, 290 Tests bestanden. |
| Frontend Typecheck, ESLint, Prettier | Bestanden. |
| Generierter TypeScript-Client gegen aktuelle OpenAPI | Fehlgeschlagen: `BackupNotification.attempt` ist noch nicht nullable; `checkNumber` fehlt. |
| Echte MariaDB 11.4.12, vorhandene Integrationssuite | 296 Tests, 7.295 Assertions, 1 Fehler: `BackupQueuePrivilegeTest::testRuntimeUsersHaveOnlyTheirQueueResponsibilities`. |
| Schema-/Supply-Chain-/Publication-Vertragstests | 57 Python-Tests bestanden. Das sind Vertragsprüfungen, keine neuen Vulnerability-Scans. |
| Zwei gezielte MariaDB-Reproduktionen | 2 Tests, 44 Assertions bestätigen die `unknown`-Lücke und die Inkompatibilität des bisherigen Benachrichtigungsschreibers mit dem neuen Schema. |
| Gezielt geprüfte Write-State-Frische | 1 Test, 3 Assertions: Ein mehr als einen Tag alter Schreibzähler kann weiterhin `bytes_written` auslösen. |

Für diese Prüfungen wurden vorhandene lokale Testimages und Dependencies verwendet. Die Anwendungssources wurden explizit aus dem heutigen Arbeitsbaum eingehängt beziehungsweise in disposable Container übernommen. Das ersetzt weder eine neue Installation aus Lockfiles noch einen frischen Build aller Releaseimages. Die MariaDB lief in einem eigenen internen Netz ohne Hostports mit neu erzeugten Audit-Testsecrets.

Nicht erneut ausgeführt wurden vollständige Coverage, Mutation, Playwright, komplette Container-/Supply-Chain-Gates und reale PVE/PBS-Abnahmen. Die bereits nachgewiesenen roten Gates und Sicherheitsbefunde verhindern ohnehin ein positives Releaseurteil.

Maschinenlesbarer Nachweis: [evidence.json](/root/Projects/hoddmimir/docs/audits/2026-09-07/evidence.json). Die [Backup-Reproduktion](/root/Projects/hoddmimir/docs/audits/2026-09-07/backup-safety-reproduction.patch) und [Write-State-Reproduktion](/root/Projects/hoddmimir/docs/audits/2026-09-07/write-freshness-reproduction.patch) sind ausschließlich für Testkopien bestimmt. Sie dokumentieren beobachtetes Fehlverhalten; sie sind keine Korrekturen und wurden nicht auf die reguläre Suite angewendet.

## Reproduktionen und Abschlussprüfung

Die beiden Patches erzeugen separate Audit-Testdateien aus den im Patchkopf genannten vorhandenen Tests. Sie ändern ausschließlich Testkopien. Für eine Wiederholung:

1. Den oben referenzierten Quellzustand in einer separaten Testkopie bereitstellen.
2. Den jeweiligen Patch auf eine Kopie der angegebenen Testdatei anwenden; die Ausgabedatei entsprechend dem neuen Patchkopf benennen.
3. Für die Backup-Reproduktion die reguläre MariaDB-Integrationstestumgebung mit leerer Testdatenbank, Migrationen und getrennten Testbenutzern verwenden. Ausschließlich die beiden zusätzlichen Methoden mit Präfix testAudit ausführen.
4. Die Write-State-Reproduktion isoliert als Unit-Test ausführen; auch hier ausschließlich die zusätzliche testAudit-Methode wählen.
5. Audit-Testkopien nicht zusammen mit ihren Ursprungsdateien laden: Die übernommenen Hilfsklassen haben dieselben Namen. Keine produktiven Endpunkte oder Credentials konfigurieren.

Die Testassertionen bestätigen das beobachtete Fehlverhalten. Ein grüner Reproduktionstest ist deshalb **kein** positiver Sicherheitsnachweis. Die MariaDB-Backupreproduktion endet vor einem Remote-Schreibaufruf.

Zum Abschluss wurden alle lokalen Dokumentlinks geprüft und der Quellmanifest-Hash erneut berechnet: Der bestehende Anwendungsarbeitsbaum ist unverändert. Der ausschließlich für das Audit gestartete MariaDB-Container samt internem Netzwerk wurde entfernt.
