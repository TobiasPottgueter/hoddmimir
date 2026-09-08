# 3. Ist-Analyse der bereits umgesetzten V2

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


## 3.1 Bewerteter Stand

Bewertet wird der lokale Branch codex/policies-shadow-mode, HEAD bd6a915cc50b8b8b29aec86c7d57953427ef1d04 einschließlich der bereits vorhandenen 60 modifizierten und sechs ungetrackten Dateien. Quellmanifest und aktuelle Testergebnisse stehen in [evidence.json](evidence.json). Diese Auditdateien sind vom Manifest ausgeschlossen.

Das ist ein umfangreicher funktionsfähiger Implementierungsstand, kein bloßes Scaffold. Dennoch sind weder ein historisches „Phase abgeschlossen“ noch eine grüne Unit-Suite eine Zusage für die vollständige Integration des offenen Arbeitsbaums.

## 3.2 Architektur und implementierte Schichten

| Schicht | Tatsächlicher Umfang |
| --- | --- |
| Domain | Explizite Backup-/Requestzustände, Prioritäten, Eligibility, Auswahl, Policies/Retention, Ziele, Concurrency, Leases/Fences, Benachrichtigungsregeln und Auth-/Rollenregeln als PHP-Typen. |
| Application | Collector-Zyklen, Inventarorchestrierung, Shadowauswertung, Konfigurationscommands, Backup-Ausführung/Monitoring/Recovery/Cancel, Notification- und Security-Use-Cases sowie typisierte PVE/PBS-Verträge. |
| Infrastructure | Eigene HTTP-/Auth-/TLS-/Proxmox-Adapter, MariaDB-Repositories, Secretverschlüsselung, Logging und Runtime-Wiring. |
| Presentation | Symfony-HTTP-Controller und Console-Commands; versionierte API, Authentifizierung und Worker-Einstiegspunkte. |
| Frontend | Vue 3/PrimeVue, typisierte API, Composables und Komponenten für Konfiguration, Inventar und Operations. |
| Betrieb | Docker/Compose, Migrationen, Ansible/OpenRC, isolierte Labkonfiguration, CI und Supply-Chain-/Schema-Verträge. |

Quellen: [backend/src](../../../backend/src/), [frontend/src](../../../frontend/src/), [docker](../../../docker/), [deployment/ansible](../../../deployment/ansible/), [.github/workflows](../../../.github/workflows/).

Die Domain ist erkennbar unabhängig aufgebaut. Nicht vollständig eingehalten wird die gewünschte Richtung bei der Regelorchestrierung: MariaDB-Repositories enthalten umfangreiche erneute Fachentscheidungen. Der gemeinsame Console-Einstieg verwendet außerdem denselben Symfony-Kernel wie das Webbackend; eine isolierte Worker-Bootstrap-Konfiguration ist nicht erkennbar.

## 3.3 Collector, Proxmox und Inventar

Vorhanden sind eigene typisierte Adapter für alle vorgesehenen Major-Linien, Version-/Capability-Erkennung, Credential-/TLS-Verträge, Failover, Pagination und bereinigte Contract-Fixtures. Die Collector-Anwendung besitzt den fachlichen Raster-Scheduler, Lease/Fencing und Heartbeats; sie ist nicht auf die generische WorkerLoop beschränkt.

Der Inventarpfad umfasst PVE-Core, Nodes, QEMU/LXC, Placements, Gastzustand/-größe, Storage und Kapazität. PBS umfasst Serverstatus, Datastores, Kapazität, Namespaces, Snapshots und abgeleitete Gruppen. Capability-Snapshots sowie externe Jobs/Tasks werden in eigenen gefenceten Persistenzpfaden abgelegt. Positive Beobachtungen und autoritative Abwesenheitsentscheidungen sind bewusst unterschieden.

Die WebApp verfügt über Systeme-/Inventarsichten und stellt Collector-Zustand dar. Ein „Jetzt scannen“-Pfad ist nicht Teil des vorgesehenen Produkts.

Nicht mit der vorhandenen PBS-Taskliste gleichzusetzen ist ein vollständiger separater PBS-Taskdetail-/Logclient. Die sichtbaren Detail-/Logpfade für ausgeführte Backups sind PVE-UPID-basiert. Außerdem bleibt der im Projekt dokumentierte Connect-Timeout-Nachweis offen; Richtwert und technisch separat durchgesetzte Verbindungsfrist sind nicht dasselbe.

Relevante Verträge: [Collector](../../collector-cycle-scheduler.md), [PVE-Core](../../pve-core-inventory-persistence.md), [PBS-Inventar](../../pbs-runtime-inventory-persistence.md), [PBS-Content](../../pbs-content-inventory-contract.md), [externes Monitoring](../../proxmox-external-monitoring-persistence.md).

## 3.4 Auswahl, Ziele, Policies und Scheduler

Umgesetzt sind mehrstufige Includes/Excludes, Ziele mit zulässigen Nodes und PBS-Zuordnung, Aktivierungsblocker, Policyverwaltung, Gast-Overrides, revisionsgebundene Snapshots und Explainability.

Automatische Auswertung nach Collector-Apply, die drei Gründe, manuelle Priorität, FIFO, Kandidatengewinner, Shadowpersistenz und Promotion existieren. Pure Domain-Typen sind unter [Domain/Scheduler](../../../backend/src/Domain/Scheduler/) und [Domain/Policy](../../../backend/src/Domain/Policy/) vorhanden.

Wesentliche Einschränkungen:

- PolicyResolver löst Policywerte plus Gast-Overrides auf, keine separate Zielebene mit Modus-/Kompressions-/Retentiondefaults.
- Frische des Byte-Messwerts wird in der Reason-Auswertung nicht wirksam berücksichtigt: Ein mehr als einen Tag alter Counter kann bei ansonsten frischer Evidenz bytes_written erzeugen.
- Die Gast-Deduplizierung sperrt aktive Requests, hält den Gast aber nach einem mehrdeutigen unknown-Abschluss nicht dauerhaft gegen einen neuen automatischen Request zurück.

## 3.5 Backup Worker und Benachrichtigungen

Der Worker implementiert Claim, Slot-/Kapazitätsreservation, Startvorbereitung, PVE-Submission, UPID-Persistierung, Monitoring, Loglesen, Recovery, Cancel und kontrollierte Wiederholungen. Ein mehrdeutiger Start wird innerhalb desselben Requests nicht blind erneut gesendet. Der Fehler liegt in der späteren Wiederzulassung desselben Gastes über einen neuen Request.

Die Matrix-Implementierung besitzt Problemzustände, Outbox und Formatter sowie Entwarnungen. Der offene Arbeitsbaum erweitert sie um dauerhafte obligation_id, occurrence_id und check_number sowie Meldungen vor einem POST. Diese Erweiterung ist nicht abgeschlossen: PrePostProblemClassifier und der Recorder-Einstieg prePost haben keinen produktiven Aufrufer. Unit-Tests ihrer Bausteine belegen daher noch keine funktionierende Meldung blockierter Scheduler-/Queuefälle.

Retention und Fehler-Mail-Empfänger wurden ebenfalls verändert: PBS-Retention bleibt gesperrt, Nicht-PBS-Retention besitzt ein explizites Gate; Policyempfänger werden durch Payload-, Konfigurations- und Darstellungsverträge geführt. Das sind relevante Änderungen am tatsächlichen Startpfad, keine reine Dokumentationspflege.

## 3.6 WebApp und API

Vorhandene Views:

| Bereich | Oberfläche |
| --- | --- |
| Einstieg | Login, Dashboard |
| Inventar | Systeme, Inventar, Verbindungen |
| Konfiguration | Backupziele, Policies, Shadow |
| Operations | Queue, Läufe, Laufdetails/Logs, Operations |
| Administration | Benutzer/Rollen, Audit und Einstellungen im Administrationsbereich |

Serverseitige Readmodels, Zugriffskontrolle, Audit und Secretmaskierung sowie generierte API-Typen sind vorhanden. Aktuell sind 61 Vitest-Dateien mit 290 Tests grün; Typecheck/Lint/Format ebenfalls.

Es fehlt der geforderte persistierte Queue-Zeitverlauf. Aktuelle Queuezahlen, Shadowhistorie und Runhistorie ersetzen keine queue_metric_samples-Zeitreihe. Der generierte TypeScript-Client ist außerdem gegenüber der aktuellen OpenAPI bei BackupNotification veraltet. Ein erfolgreicher Typecheck erkennt diese Abweichung nicht, weil er gegen die vorhandenen generierten Typen prüft.

Eine neue visuelle Browser-/Accessibility-Abnahme wurde in diesem Audit nicht durchgeführt; die früheren Browsernachweise werden nicht als frisch geprüft ausgegeben.

## 3.7 Tests und Lieferfähigkeit

Die vollständige Ergebnistabelle steht im [Audit-Einstieg](README.md#aktuelle-verifikation). Hervorzuheben sind:

- 2.562 Unit-/Contract-Tests ohne Fehler, vier Skips; PHPStan max grün.
- 290 Frontendtests und statische Frontendprüfungen grün.
- 296 echte MariaDB-Integrationstests mit einem Fehler im Rechtevertrag.
- API-Generierungscheck rot.
- Zwei zusätzliche MariaDB-Reproduktionen und eine Unit-Reproduktion bestätigen Sicherheits-, Migrations- und Freshness-Befunde.
- 57 Schema-/Supply-/Publication-Vertragstests grün; keine neuen Vulnerability- oder Secret-Scans.

Existierende Dependencies und lokale Images wurden mit aktuellem Quellcode genutzt. Eine komplette frische Releaseproduktion wurde nicht behauptet. Coverage, Mutation und E2E wurden nicht erneut ausgeführt. Ihre historische Erfolgsdokumentation belegt weder die neuen Zeilen noch die hier gefundenen fehlenden Übergänge.

## 3.8 Historische Abnahme versus heutige Einsatzreife

Der [Phase-7-Bericht](../../phase-7-live-acceptance.md) dokumentiert für a6b4d4f unter anderem 37/37 CI-Jobs, AMD64-Publikation, Deployment, fünf Verbindungen/elf Endpunkte, sechs Ziele/Policies, drei stabile Shadowzyklen und 18 deduplizierte Requests. Benigne Matrix-Grundzustellung ist ebenfalls dokumentiert.

Weiter offen sind dort die repräsentativen QEMU-/LXC-Backups auf allen drei PVE-Majors, Fehler/Retry, Cancel und die gekoppelte Problem-/Entwarnungszustellung. Vollständige negative TLS-/ACL-/Chaosfälle, Produktionseinrichtung sowie Backup/Restore und Aktivierung gehören zur ausstehenden Phase 8.

Der historische Kandidat liegt sechs Commits plus dem offenen Arbeitsbaum zurück. Ein Wiederaufnehmen der Live-Abnahme sollte deshalb erst nach Behebung der aktuellen Releaseblocker und Festlegung eines neuen Kandidaten erfolgen.
