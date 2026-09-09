# 2. Soll-Analyse der Anforderungen

## Nachträgliche Benutzerentscheidungen vom 7. September 2026

Nach Abschluss dieses Audits wurden zwei bisherige Anforderungen geändert:

- [ADR 0005](../../adr/0005-automatic-backup-recovery.md): automatische Wiederfreigabe nach vollständiger frischer Taskklärung ohne eindeutige Zuordnung, ohne Sonderwartefrist oder mehrere Pflichtprüfzyklen; vor jedem Start gilt das allgemeine Remote-Task-Gate einschließlich manueller/externer Backups. Ein zusätzliches nachfolgendes Backup ist akzeptiert. Keine zwingende manuelle Auflösung; kein blinder POST-Retry.
- [ADR 0006](../../adr/0006-maintenance-upgrade-database-restore.md): Wartungsupgrade mit DB-Sicherung, Funktionstests bei gesperrtem Betrieb und Restore samt alter Anwendung bei Fehlern vor Freigabe.

**Nachtrag 8. September: Beide Entscheidungen sind implementiert und lokal in Prüfung. Live-Abnahme und Veröffentlichungsfreigabe stehen aus. Die folgenden Befunde dokumentieren den ursprünglichen Auditstand; Details zum Wartungsprotokoll in ADR 0006.**
Die folgenden Analysen und Testergebnisse beschreiben den ursprünglichen Auditstand.
Zum ursprünglichen Auditstand erfüllte die Folgepromotion die neuen
Klärungs-/Startbedingungen noch nicht (F01). Der Nachtrag dokumentiert die
Umsetzung der automatischen Wiederfreigabe nach ADR 0005.
F02 kann durch den neuen getesteten Restorepfad behoben werden; Expand/Contract
ist dann nicht die einzige Lösung. Solange das bestehende Image-Rollbackverfahren
verwendet wird, bleibt dessen Kompatibilitätsproblem bestehen.
Die Restarbeiten sind mit diesen beiden ADRs als maßgeblichem Zielvertrag zu lesen.


## 2.1 Verbindliche Quellen und ihre Einordnung

Die fachliche Hauptquelle ist [rewrite-plan.md](../../rewrite-plan.md), ergänzt durch [AGENTS.md](../../../AGENTS.md), die vier [ADRs](../../adr/) und die spezifizierten Detailverträge. Die Phasenpläne konkretisieren Implementierung und Abnahme; ein dort genannter lokaler Abschluss ersetzt keine Prüfung des aktuellen Arbeitsbaums. Ein Statusbericht ist ein Nachweis zu seinem Kandidaten, keine stillschweigende Änderung des Funktionsumfangs.

| Quelle | Regelungsgegenstand |
| --- | --- |
| Rewrite-Plan, Abschnitte 1, 3–7, 9 und 11 | Clean Start, drei Komponenten, Funktionsumfang, Architektur, fachliche Regeln und Gesamtabschluss. |
| AGENTS.md | Unverhandelbare Grenzen, Schichtentrennung, Tests, AMD64, sichere Entwicklung und Deployment. |
| ADR 0001–0004 | Identitäten/UTC, autoritative Inventarisierung, Secrets und Collector-Raster/Fencing. |
| [Phase 4](../../phase-4-policy-shadow-plan.md) | Konkrete Auswahlhierarchie, 300-Sekunden-Evidenzvertrag, Trigger, Gleichheitsgrenzen, Reservation und Explainability. |
| [Phase 5](../../phase-5-backup-worker-plan.md) | Queue, Submission, Monitoring, Reconciliation, Retry, Cancel und Matrix-Problemmeldung/Entwarnung. |
| [Phase 6](../../phase-6-webapp-plan.md) und [Onboarding](../../proxmox-connection-onboarding-plan.md) | Administration, verified-only Verbindungen, Rollen, Secretverwaltung, Operations und Browserabnahme. |
| [Phase 7](../../phase-7-live-acceptance.md) und [Fault-Injection-Runbook](../../phase-7-fault-injection-runbook.md) | Praktische Nachweise, Kandidatenbindung, verbleibende Labfälle und nach Phase 8 verschobene Chaosmatrix. |
| PVE-/PBS-Read-, Write- und Persistenzverträge in docs | Produktidentität, Endpunkte, ACL-Autorität, Pagination, Kapazität, Tasks, Snapshotinventar und Fehlerverhalten. |
| Coverage-, Mutation-, E2E- und Supply-Chain-Dokumente | Ausführbare Qualitätsschwellen und technische Nachweiswege. |

Der Altcode erklärt die Herkunft der Regeln. Er definiert nicht nachträglich die Grenzen der V2: WebApp, LXC, PBS, sichere Reconciliation und viele Schutzregeln sind ausdrücklich neue Anforderungen.

## 2.2 Zielprodukt

V2 ist eine neu konfigurierte Backupsteuerung mit eigener Datenbank und eigener Historie. Sie kopiert weder Altschema noch Altcode, importiert keine Altdaten und übernimmt keine alten Credentials. Produktname ist **Hoddmímir**, technischer Slug **hoddmimir**.

Es gibt genau drei Anwendungskomponenten:

- **Collector:** dauerhaft laufende, ausschließlich lesende PVE-/PBS-Inventarisierung, aktuelle Messwerte, Shadow-Entscheidungen und idempotente Request-Erzeugung.
- **Backup Worker:** einziger Remote-Schreiber für Start/Abbruch; atomare Claims, erneute Gateprüfung, Überwachung und Wiederanlauf.
- **WebApp:** PHP-API und Vue-/PrimeVue-Frontend für Inventar, Konfiguration und Operations. Manuelle Backups erzeugen Requests; Browser und Web-API starten PVE-Backups nicht direkt.

MariaDB ist die gemeinsame Persistenz, kein vierter Anwendungsdienst. PHP 8.5, Symfony 7.4 als Hülle, Vue 3/TypeScript/PrimeVue 4, Alpine-Anwendungsimages, getrennte Rechte und gehärtete Container bilden den technischen Sollstand. Verbindliche Releasearchitektur ist ausschließlich linux/amd64.

## 2.3 Vollständiger fachlicher Anforderungskatalog

| ID | Sollanforderung und überprüfbare Bedeutung |
| --- | --- |
| S01 | PVE 7/8/9 und PBS 3/4 über eigene typisierte Adapter; keine fremde Proxmox-Clientbibliothek. Major-Unterstützung benötigt Contract- und reale Nachweise. |
| S02 | Versionsprobe, Capability-Matrix, validierte Pflichtfelder, tolerierte Zusatzfelder; optionale Schreibparameter nur bei nachgewiesener Unterstützung. |
| S03 | Neue Verbindungen werden identitäts-, versions-, ACL- und TLS-geprüft; Endpunkte derselben Installation deduplizieren und dienen als Failover. |
| S04 | Collector auf startzeitbasiertem 120-Sekunden-Raster; Override verändert nur die Breite. Keine Überlappung, keine Nachholzyklen, keine manuelle Scan-API/-Schaltfläche. |
| S05 | PVE-Cluster/Nodes/QEMU/LXC/Placement/Storages und PBS-Server/Datastores/Namespaces/Snapshots sowie externe Jobs/Tasks inventarisieren. |
| S06 | Fehlende Objekte nur bei vollständiger autoritativer Sicht archivieren. Teilfehler/ACL-Teilansichten dürfen keinen falschen Entfernungsnachweis erzeugen. Wiederkehr und Placementwechsel erhalten die Gastidentität. |
| S07 | Syncstatus, Dauer, letzte erfolgreiche Beobachtung, Teilfehler, nächster Lauf, Workerheartbeat und stale Daten sichtbar machen. |
| S08 | Auswahl auf globaler, Connection-, Cluster-, Node- und Gastebene; explizites Exclude gewinnt. Ohne wirksames Include fail-closed. Einzel- und Massenauswahl für Nodes/QEMU/LXC. |
| S09 | Konkretes PVE-Storage als Backupziel; PBS-Mapping referenziert Connection/Datastore/Namespace explizit. Zulässige Nodes, Freiplatz und Parallelität administrieren. |
| S10 | Ziel-/Policy-Aktivierung validieren; geänderte oder ungültige Zuordnungen blockieren Starts mit nachvollziehbarem Grund. |
| S11 | Policies mit expliziten Triggern, Schwellen, Modus, Kompression und Retention; Gast-Overrides. Die im Hauptplan genannte zusätzliche Storage-Default-Vererbung ist noch mit dem Policy-Modell abzugleichen. |
| S12 | Automatisch never_backed_up (300), max_age (200), bytes_written (100); manuell 400. Höchster Grund gewinnt; Retry behält Grund/Priorität. FIFO nach scheduled_at und stabiler ID. |
| S13 | Alters-, Byte- und Cooldown-Grenzen strikt überschreiten; ausschließlich frühere Fehler zählen nicht als erfolgreiches Backup. Counterreset setzt Baseline und löst allein kein Backup aus. |
| S14 | Je Gast deterministische Gewinnerauswahl über Policy-/Zielkandidaten; atomare persistierte Shadow-Entscheidungen und Promotion, idempotentes Enqueue, keine Catch-up-Requests. |
| S15 | Frisches Inventar, Placement, PVE-/PBS-Kapazität und Executor-Berechtigungen; Nachweise grundsätzlich höchstens 300 Sekunden alt, einzeln fail-closed geprüft. |
| S16 | Ein Startslot je Node; explizites Ziellimit, Row Locks und Kapazitätsreservation. Letzte erfolgreiche Größe + 10 %, sonst provisionierte Größe; fehlen beide, kein Start. Bei PBS echte Remote-Kapazität, kein Cache-Ersatz. |
| S17 | Claim mit Lease/Fence, starting vor POST persistieren, genau einen Gast anfordern, UPID speichern; unmittelbar vor Start erneut alle relevanten Nachweise prüfen. |
| S18 | Kein automatischer neuer Start nach mehrdeutiger POST-Antwort. Reconciliation statt blindem Retry; ungelöst unknown mit kontrollierter Entscheidung. Schutz muss auch neue Request-IDs und Folgezyklen umfassen. |
| S19 | Status/Logs asynchron lesen, transienten Readfehler nicht als sicheren Taskfehler deuten; Cancel und Recovery ohne unkontrollierte wiederholte Remote-Mutationen. |
| S20 | Definitive Fehler, begrenzte/terminierte Retry-Planung, Problemzustand und spätere Entwarnung dauerhaft und korrelierbar speichern; Matrix-Outbox mit Versuch/Prüfung und Zustellstatus. |
| S21 | PBS-Retention ausschließlich durch PBS-Prune-Jobs. Löschwirksame Retention für Nicht-PBS nur nach separatem Opt-in/Recht/Capability; maxfiles auf PVE 9 verboten. |
| S22 | Dashboard, Systeme, Inventar, Ziele, Policies, Shadow, Queue, Läufe, Logs, Health, Benutzer/Rollen und Audit; serverseitige Filter/Sortierung/Pagination. Queue-Verlauf bleibt sichtbar. |
| S23 | Lokale Authentifizierung/RBAC, Schutz administrativer Aktionen, Audit, verschlüsselte versionierte Secrets, keine lesbaren Secret-Rückgaben. OIDC ist eine Erweiterungsmöglichkeit, keine Pflichtintegration für V2.0. |
| S24 | TLS immer verifizieren; getrennte Collector-/Executor-Credentials und Datenbankrechte. Keine Secrets in Logs, Git oder Artefakten. |
| S25 | Domain/Application unabhängig von Symfony/HTTP/MariaDB; Regeln dort zentral testen. Infrastruktur implementiert I/O und Transaktionen, keine parallelen fachlichen Regelwerke. Worker laden keinen Webstack. |
| S26 | Leere Installation, sichere interne Migrationen, Digest-Deployment, Healthchecks, ausdrückliche Aktivierung; Upgrade/Rollback/Backup/Restore praktisch prüfen. |
| S27 | Alle deterministischen Regeln und Übergänge testen; vollständige Qualitäts- und Live-Gates am finalen Kandidaten erfüllen. |

## 2.4 Quality Gates und Abschlussbegriff

- Backend Domain/Application/Proxmox-Kompatibilität: 100 % Line- und Branch-Coverage; gesamtes PHP mindestens 95 % Line und 90 % Branch.
- Mutation: mindestens 90 % für Domain/Scheduler/State-Machines, mindestens 80 % global.
- Frontend: 100 % Stores/Composables/Fachlogik, mindestens 90 % insgesamt; nichttriviale Komponenten und kritische Browserflüsse.
- Echte MariaDB für Schema, Rechte, Transaktionen, Claims, Leases und Races; keine SQLite-Ersatztests.
- Sanitized Contracts für alle fünf Produktlinien, offizielle gepinnte Schemaquellen und Driftprüfung; kein automatisches Aktivieren neuer Capabilities.
- PHPStan, Typecheck/Lint/Format, OpenAPI-Generierung, Dependency-/Secret-/Container-Scans, SBOM, AMD64-Builds.
- Reale QEMU-/LXC-Backups, unterstützte PVE/PBS-Kombinationen, Fehler/Cancel, TLS/ACL, Ausfälle und mehrdeutiger Start ohne Doppelbackup.
- Neue MariaDB sichern und wiederherstellen; Aktivierung und kontrollierte Deaktivierung praktisch nachweisen.

Die proportionalen Tests während Entwicklung reduzieren Wiederholungen, nicht den notwendigen Abschlussnachweis. Vor einem Release muss ein identischer, eindeutig referenzierter Kandidat die jeweils vollständigen Gates bestehen.

## 2.5 Aufzulösende Widersprüche und Präzisierungen

1. **Phase-7-Status:** Der Hauptplan nennt Tokens, Onboarding, Inventar und Shadow noch offen. Der detaillierte Abnahmebericht belegt sie bereits für a6b4d4f. Für den historischen Kandidaten ist der detaillierte Nachweis maßgeblich; für heutigen Code ist eine neue Abnahme nötig.
2. **Freiplatzgrenze:** Altcode startet strikt oberhalb des Minimums. Phase 4 lässt Gleichheit zu. Das ist eine dokumentierte V2-Abweichung, kein unentdeckter Paritätsfehler.
3. **Parallelität:** Alt kennt -1 als dynamische Einstellung. Phase 4 legt ein explizites festes Limit, begrenzt durch zulässige Nodes, fest. Diese neuere Entscheidung ist nachvollziehbar dokumentiert.
4. **Erfolgsbasis/Cooldown:** Alt berücksichtigt laufende Jobs in der Erfolgsabfrage. V2 trennt aktive Requests von erfolgreicher V2-Historie; externe Snapshots werden nicht rückwirkend als eigener Erfolg gewertet.
5. **Policy-Vererbung:** Hauptplan nennt Storage-Defaults plus Gast-Overrides; umgesetzt und in Phase 4 konkretisiert ist Policy plus Gast. Eine ausdrückliche Entscheidung zur zusätzlichen Zielebene fehlt.
6. **PBS-Details:** Hauptplan nennt PBS-Taskstatus/-log. Detailverträge konzentrieren sich auf Tasklisten und externe Jobprojektionen; die Einschränkung sollte als Scopeentscheidung geklärt werden.
7. **Releaseumfang:** Nach Phase 8 verschobene TLS-/ACL-/Chaosfälle sind nicht entfallen. Ein Abschluss von Phase 7 allein erfüllt die Gesamt-Definition-of-Done nicht.
8. **Datums-/Nachweisbindung:** Der Hauptplan trägt einen älteren Stand, enthält aber spätere Änderungen. Künftig Status, Quellcommit, getesteten Arbeitsbaum und Abnahmedatum gemeinsam führen.

Nicht erforderlich sind Legacy-Importer, CIDR-Discovery, direkte PBS-Backupdatenübertragung, manuelle Scans, ARM-Releases oder eine zwingende OIDC-Einführung.
