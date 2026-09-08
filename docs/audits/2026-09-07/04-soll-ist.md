# 4. Anforderungskonformität und konkrete Befunde

## Nachträgliche Benutzerentscheidungen vom 7. September 2026

Nach Abschluss dieses Audits wurden zwei bisherige Anforderungen geändert:

- [ADR 0005](../../adr/0005-automatic-backup-recovery.md): automatische Wiederfreigabe nach vollständiger frischer Taskklärung ohne eindeutige Zuordnung, ohne Sonderwartefrist oder mehrere Pflichtprüfzyklen; vor jedem Start gilt das allgemeine Remote-Task-Gate einschließlich manueller/externer Backups. Ein zusätzliches nachfolgendes Backup ist akzeptiert. Keine zwingende manuelle Auflösung; kein blinder POST-Retry.
- [ADR 0006](../../adr/0006-maintenance-upgrade-database-restore.md): Wartungsupgrade mit DB-Sicherung, Funktionstests bei gesperrtem Betrieb und Restore samt alter Anwendung bei Fehlern vor Freigabe.

**Nachtrag 8. September: Die geänderten Verträge und F03/F07–F11 sind implementiert. Wartung und Backup-Recovery wurden auf realen DEV-Systemen abgenommen; Details und Grenzen stehen in [Bericht 09](09-followup-implementation.md) und [Bericht 10](10-existing-dev-upgrade-and-backup-acceptance.md). Abschließende Veröffentlichungsgates und Registry-Pins stehen noch aus. Die folgenden Befunde dokumentieren den ursprünglichen Auditstand.**
Die folgenden Analysen und Testergebnisse beschreiben den ursprünglichen Auditstand.
Zum ursprünglichen Auditstand erfüllte die Folgepromotion die neuen
Klärungs-/Startbedingungen noch nicht (F01). Der Nachtrag dokumentiert die
Umsetzung der automatischen Wiederfreigabe nach ADR 0005.
F02 kann durch den neuen getesteten Restorepfad behoben werden; Expand/Contract
ist dann nicht die einzige Lösung. Solange das bestehende Image-Rollbackverfahren
verwendet wird, bleibt dessen Kompatibilitätsproblem bestehen.
Die Restarbeiten sind mit diesen beiden ADRs als maßgeblichem Zielvertrag zu lesen.


## 4.1 Bewertungsmaßstab

**Vorhanden** bedeutet im Quellcode implementiert und im genannten Umfang geprüft, nicht automatisch live abgenommen. **Teilweise** bedeutet nachgewiesene Teilimplementierung mit Lücke oder fehlendem Abschlussnachweis. **Abweichung** bezeichnet einen konkreten Vertragswiderspruch. **Offen** bezeichnet eine fehlende Funktion oder Abnahme.

Die S-IDs stammen aus [02-soll.md](02-soll.md). Die Bewertung gilt für den im [Evidenzmanifest](evidence.json) festgehaltenen Arbeitsbaum.

## 4.2 Anforderungsmatrix

| Soll | Ist / Urteil | Nachweis oder verbleibende Grenze |
| --- | --- | --- |
| S01 Eigene Adapter, fünf Major-Linien | Vorhanden, Liveabschluss teilweise | Eigene PVE/PBS-Clients, Contracts grün; komplette aktuelle Live-Matrix fehlt. |
| S02 Capabilities/Schema | Vorhanden | Typisierte Versions-/Capability-/Payloadverträge und Driftwerkzeuge; Fixtures sind kein Livebeweis. |
| S03 Onboarding/Failover | Vorhanden, Liveabschluss teilweise | Verified-only Einrichtung implementiert; historische positive Labnachweise, vollständige Negativmatrix offen. |
| S04 Collector-Raster | Vorhanden | Application-Scheduler und Tests für überlange Zyklen/Skip/Fencing; kein manuelles Scanprodukt. |
| S05 Inventarumfang | Weitgehend vorhanden | PVE QEMU/LXC, PBS Content/Tasks/Jobs; PBS-Taskdetail/-log siehe F09. |
| S06 Autorität/Identität | Vorhanden | Gefencete Inventar- und Childrun-Verträge, autoritative Abwesenheit; aktuelle Live-Ausfall-/Placementmatrix offen. |
| S07 Status/Health | Vorhanden | Sync-/Workerprojektionen und Websichten; Queue-Zeitreihe fehlt separat. |
| S08 Auswahl | Vorhanden | Include/Exclude-Hierarchie, aktuelle Platzierung, Einzel-/Bulk-Konfiguration und Tests. |
| S09 Ziele/PBS-Mapping | Vorhanden | Explizite Zuordnung und Node-/Kapazitäts-/Executor-Verträge. |
| S10 Aktivierung | Vorhanden | Blocker und revidierte Konfiguration; Gate-Duplikation ist Architekturabweichung F07. |
| S11 Vererbung | Teilweise / Klärung nötig | Policy + Gast vorhanden; zusätzliche Storage-Defaults aus Hauptplan fehlen, F08. |
| S12 Prioritäten/FIFO | Vorhanden | Domain-Regeln und Grenztests; Retry keine eigene Prioritätsklasse. |
| S13 Trigger/Counter | Abweichung | Veralteter Schreibzähler wird verwertet, F03. |
| S14 Promotion/Deduplizierung | Abweichung am Folgezustand | Atomare Promotion vorhanden; unknown lässt neuen automatischen Request zu, F01. |
| S15 Freshness | Weitgehend vorhanden | Inventar/Placement/Kapazität/Executor-Gates; Byte-Evidenzlücke F03. |
| S16 Slots/Reservation | Vorhanden | Row Locks, Node-/Zielslots und Größenfallbacks; Live-Race-Abnahme offen. |
| S17 Startpipeline | Vorhanden mit Sicherheitslücke | starting vor POST und UPID-Persistenz; zyklusübergreifender Schutz F01 fehlt. |
| S18 Mehrdeutige Starts | Nicht ausreichend erfüllt | Neue Request-ID umgeht forbidden_ambiguous, reproduziert F01. |
| S19 Monitoring/Cancel | Vorhanden, Liveabschluss offen | PVE-Status/Log/Recovery/Cancel-Verträge; repräsentative reale Fälle fehlen. |
| S20 Probleme/Entwarnung | Teilweise | Outbox/Formatter vorhanden; Pre-POST-Wiring fehlt F04, API-Drift F05. |
| S21 Retention | Im Code abgesichert, Abnahme offen | PBS kein löschwirksames Retentionpayload; Nicht-PBS Opt-in; aktuelle reale Nachweise fehlen. |
| S22 WebApp/Queuehistorie | Teilweise | Hauptviews vorhanden; persistierter Queue-Zeitverlauf fehlt F10. |
| S23 Auth/RBAC/Secrets | Vorhanden | Commands, Rollen, Audit, verschlüsselte Secrets; neue komplette Security-Abnahme nicht erfolgt. |
| S24 Minimalrechte/TLS | Teilweise | Schutzarchitektur vorhanden; Rechtevertrag rot F06 und aktuelle Negativ-Live-Matrix offen. |
| S25 Schichtentrennung | Abweichung | Fachregeln in DB-Repositories F07; gemeinsamer Worker-/Web-Kernel F11. |
| S26 Deployment/Schema | Abweichung | Neue Migration nicht kompatibel mit vorherigem Writer, F02; Restore/Produktionsabnahme offen. |
| S27 Vollständige Gates | Nicht erfüllt | Zwei reguläre Prüfungen rot; reproduzierte Schutzlücken; aktuelle Voll-/Live-Gates nicht vollständig durchgeführt. |

## 4.3 Priorisierte Befunde

### F01 — P0: Neuer automatischer Request nach mehrdeutigem Backupstart

**Reproduziert gegen echte MariaDB.** Nach einer mehrdeutigen Submission und Reconciliation endet der Request in unknown mit forbidden_ambiguous. Der nächste Collector-Zyklus kann denselben Gast wieder als frei bewerten, einen neuen automatischen Request promoten, claimen und bis zur Submission-Vorbereitung bringen.

Ursache: Die Deduplizierung behandelt aktive Requests, aber keinen ungelösten mehrdeutigen Abschluss als fortbestehende Gastsperre. Die Retry-Sperre ist an die alte Request-ID gebunden. Der Grund never_backed_up kann unverändert fortbestehen.

Quellen: [DbalBackupRequestGuestGuard](../../../backend/src/Infrastructure/Persistence/MariaDb/DbalBackupRequestGuestGuard.php), [automatische Quelle](../../../backend/src/Infrastructure/Persistence/MariaDb/DbalAutomaticShadowEvaluationSource.php), [QueueStore](../../../backend/src/Infrastructure/Persistence/MariaDb/DbalBackupQueueStore.php), [SubmissionStore](../../../backend/src/Infrastructure/Persistence/MariaDb/DbalBackupSubmissionStore.php), [Reproduktion](backup-safety-reproduction.patch).

**Auswirkung:** Das beabsichtigte Verbot eines automatischen Neustarts nach mehrdeutiger Antwort wird zyklusübergreifend umgangen. Die Reproduktion führte keinen zweiten PVE-POST aus; sie beweist dessen erneute lokale Freigabe, keinen tatsächlich beobachteten produktiven Doppelbackup.

**Abschlusskriterium:** Persistente, auditierbare Behandlung ungeklärter Submission auf Gastebene; automatische Promotion, manuelle Requests und Worker-Start prüfen denselben Zustand. Ein neuer Request darf eine bestehende Sperre nicht implizit aufheben. Kontrollierte Freigabe bekommt einen eigenen Berechtigungs-/Auditvertrag. Mehrere Folgezyklen, Neustart und Parallelität müssen ohne erneuten Start bestehen.

### F02 — P1: Neue Benachrichtigungsmigration verletzt Image-Rollback-Vertrag

**Reproduziert gegen echte MariaDB.** Version20260719000500 macht obligation_id ohne Default verpflichtend und verändert die Schlüssel-/Outboxstruktur. Ein INSERT entsprechend dem vorherigen Benachrichtigungsschreiber scheitert am neuen Schema.

Die Deploymenttransaktion rollt MariaDB-DDL ausdrücklich nicht zurück. Bei späterem Fehler werden ältere Anwendungsimages wiederhergestellt; Migrationen müssen deshalb in der Expand-Phase mit altem und neuem Image funktionieren.

Quellen: [Migration](../../../backend/migrations/Version20260719000500.php), [Deploymentvertrag](../../../deployment/ansible/README.md), [Reproduktion](backup-safety-reproduction.patch).

**Auswirkung:** Ein älterer Worker kann nach erfolgter Migration Benachrichtigungen nicht mehr wie zuvor speichern; Readiness kann dabei trotzdem erfolgreich sein. Das ist ein Kompatibilitätsfehler, kein nachgewiesener Datenverlust und kein vollständig ausgeführter Deployment-Rollbacktest.

**Abschlusskriterium:** Additive Expand-Migration mit alter und neuer Schreib-/Lesekompatibilität; spätere Contract-Migration erst nach Ende des alten Rollbackfensters. Tests müssen echten vorherigen Writer/Reader gegen neues Schema und den Deployment-Recovery-Pfad einschließen.

### F03 — P1: Byte-Trigger akzeptiert veraltete Messwerte

**Durch isolierten Unit-Test reproduziert.** Bei frischem übrigen Inventar und einem mehr als einen Tag alten Schreibzähler wird bytes_written als eligible ausgewertet und eine Promotion erzeugt. Der Zeitstempel des Schreibwertes ist im Kandidaten vorhanden, wird für diesen Grund aber nicht wirksam als Freshness-Gate verwendet.

Quellen: [RunAutomaticShadowEvaluation](../../../backend/src/Application/Scheduler/Shadow/RunAutomaticShadowEvaluation.php), [AutomaticShadowCandidate](../../../backend/src/Application/Scheduler/Shadow/AutomaticShadowCandidate.php), [Reproduktion](write-freshness-reproduction.patch).

**Auswirkung:** Eine Entscheidung über aktuelles Schreibvolumen wird aus veralteter Evidenz getroffen, etwa nach einem teilweise fehlgeschlagenen Messwert-Read. Das widerspricht dem fail-closed-Evidenzansatz. Ein Remote-Start wurde hierfür nicht ausgeführt.

**Abschlusskriterium:** Eigener testbarer Vertrag für Alter/Fehlen/Zukunftszeit des Schreibwerts. Ein stale Counter darf keinen Byte-Grund erzeugen; unabhängige andere Gründe sollten gemäß expliziter Fachentscheidung bewertet werden. Grenzfälle und Counterreset zusammen testen.

### F04 — P1: Pre-POST-Problemmeldungen sind nicht produktiv verdrahtet

**Codebefund.** PrePostProblemClassifier und DbalBackupProblemRecorder::prePost existieren einschließlich Tests. In den produktiven Scheduler-/Queuepfaden gibt es keine entsprechenden Aufrufe. Damit erzeugt ein blockierter fälliger Backupbedarf nicht allein durch diese neuen Klassen die vorgesehene Meldung.

Quellen: [Classifier](../../../backend/src/Application/Backup/Notification/PrePostProblemClassifier.php), [Recorder](../../../backend/src/Infrastructure/Persistence/MariaDb/DbalBackupProblemRecorder.php), [automatische Auswertung](../../../backend/src/Application/Scheduler/Shadow/RunAutomaticShadowEvaluation.php).

**Abschlusskriterium:** Fachliche Klassifizierung mit tatsächlicher Scheduler-/Queueauswertung verbinden, Zustandsänderung und Outbox atomar persistieren, Wiederholungen deduplizieren und spätere Entwarnung zustellen. Nicht fällige Gäste oder normale Slotwartezeiten dürfen nicht pauschal als Fehler behandelt werden.

### F05 — P1: Generierter Frontendvertrag ist veraltet

**Regulärer Generierungscheck fehlgeschlagen.** BackupNotification.attempt muss number | null sein; checkNumber fehlt im generierten Client. OpenAPI und PHP-Readmodel sind weiter als die TypeScript-Ausgabe.

Quellen: [OpenAPI](../../openapi-v1.json), [generierte Typen](../../../frontend/src/api/generated/types.gen.ts), [Operationsreadmodel](../../../backend/src/Infrastructure/Persistence/MariaDb/DbalOperationsReadModel.php).

**Abschlusskriterium:** Client aus aktuellem Schema reproduzierbar generieren und Null-attempt/Pre-POST-Notification in der Oberfläche komponententesten. Danach API-Generierungscheck und Typecheck grün.

### F06 — P1: MariaDB-Rechtevertrag und Migration widersprechen sich

**Reguläre Integrationssuite rot.** BackupQueuePrivilegeTest::testRuntimeUsersHaveOnlyTheirQueueResponsibilities erwartet für den Collector verweigerten Zugriff, während die neue Migration Rechte auf Problemzustände/Notification-Outbox gewährt.

Quellen: [Rechtetest](../../../backend/tests/Integration/Database/BackupQueuePrivilegeTest.php), [Migration](../../../backend/migrations/Version20260719000500.php).

**Einordnung:** Daraus folgt nicht automatisch eine unzulässige PVE-Schreibberechtigung oder eine Rechteeskalation. Collector-seitige Outboxerzeugung kann zur neuen Funktion passen. Der gegenwärtige Zustand besitzt aber keinen konsistenten getesteten Minimalrechtevertrag.

**Abschlusskriterium:** Benötigte Tabellen-/Operationsrechte aus dem fertig integrierten Ablauf ableiten, minimal gewähren und sowohl erlaubte als auch verbotene Operationen explizit testen. Den Test nicht lediglich entfernen.

### F07 — P2: Fachliche Gate-Logik in mehreren MariaDB-Repositories

**Codebefund.** Auswahl-, Aktivierungs-, Freshness-, PBS-, Konfigurations- und Startbedingungen werden in mehreren Repositories per SQL und PHP entschieden. Transaktionales erneutes Laden ist erforderlich; mehrfach implementierte fachliche Entscheidungen sind es nicht.

Quellen: [DbalAutomaticShadowEvaluationSource](../../../backend/src/Infrastructure/Persistence/MariaDb/DbalAutomaticShadowEvaluationSource.php), [DbalBackupQueueStore](../../../backend/src/Infrastructure/Persistence/MariaDb/DbalBackupQueueStore.php), [DbalBackupSubmissionStore](../../../backend/src/Infrastructure/Persistence/MariaDb/DbalBackupSubmissionStore.php), [DbalBackupOperationCommandRepository](../../../backend/src/Infrastructure/Persistence/MariaDb/DbalBackupOperationCommandRepository.php).

**Auswirkung:** Domain-/Application-Coverage deckt diese Regeln nicht vollständig ab; Unterschiede zwischen Shadow, manuellem Request, Claim und Submission können entstehen.

**Abschlusskriterium:** Typisierte, unter Lock geladene Evidenz an gemeinsame reine Regelservices übergeben. Transaktionen/Fencing bleiben in der Infrastruktur, Fachentscheidungen erhalten einen zentralen Testvertrag.

### F08 — P2: Storage-Default-Vererbung fehlt beziehungsweise Scope ist ungeklärt

**Code-/Vertragsbefund.** PolicyResolver nimmt Policy und Gast-Overrides entgegen; Backupziele tragen keine entsprechende zusätzliche Vererbungsebene für Modus/Kompression/Retention. Der Hauptplan nennt diese Storage-Defaults ausdrücklich.

Quellen: [PolicyResolver](../../../backend/src/Domain/Policy/PolicyResolver.php), [BackupTarget](../../../backend/src/Domain/Target/BackupTarget.php), [Rewrite-Plan](../../rewrite-plan.md).

**Abschlusskriterium:** Entweder Zielebene einschließlich nachvollziehbarer Herkunft und Override-Tests implementieren oder die Anforderung ausdrücklich auf das vorhandene Policy-Modell ändern. Eine stille Umdeutung als bereits erfüllte Parität genügt nicht.

### F09 — P2: PBS-Taskdetail und -log nicht im ursprünglich beschriebenen Umfang vorhanden

**Code-/Vertragsbefund.** PBS-Taskseiten und externe Prune-/Sync-/Verify-Jobs sind vorhanden; ein eigener PBS-Taskstatus-/Logpfad wie im Hauptplan beschrieben ist nicht erkennbar. PVE-Tasklogs eigener vzdump-Läufe ersetzen diese PBS-Funktion nicht.

Quellen: [PBS-Monitoringclient](../../../backend/src/Infrastructure/Proxmox/Pbs/PbsMonitoringClient.php), [PBS-Taskvertrag](../../pbs-tasks-jobs-read-contract.md), [Rewrite-Plan](../../rewrite-plan.md).

**Abschlusskriterium:** PBS-Detail-/Logvertrag und erforderliche UI/API implementieren oder die Beschränkung auf Listen ausdrücklich als Scopeänderung entscheiden.

### F10 — P2: Persistierte Queue-Zeitreihe fehlt

**Repositorybefund.** Die geforderten queue_metric_samples sowie ein entsprechender Sampler und administrativer Zeitverlauf sind nicht implementiert. Momentane Queue-/Dashboardzahlen und persistierte Shadowruns liefern andere Informationen.

Quellen: [Datenmodell/Soll](../../rewrite-plan.md), [Migrationen](../../../backend/migrations/), [Dashboard](../../../frontend/src/views/DashboardView.vue), [Queue](../../../frontend/src/views/QueueView.vue).

**Abschlusskriterium:** Einmalige, idempotente Samples pro definierter Zeit-/Zielgruppe, dokumentierte Aufbewahrung, aggregierte Abfragen und sichtbarer Verlauf. Kein erneutes Mehrfachschreiben wie im Altcode.

### F11 — P2: Worker verwenden gemeinsamen Symfony-Web-Kernel

**Codebefund.** backend/bin/console erzeugt App\Kernel über FrameworkBundle\Console\Application. Eine getrennte minimale Worker-Containerkonfiguration ist nicht vorhanden. Das weicht von der expliziten Vorgabe ab, keinen Webstack in Worker zu laden.

Quellen: [Console-Einstieg](../../../backend/bin/console), [Kernel](../../../backend/src/Kernel.php), [Bundles](../../../backend/config/bundles.php).

Dies ist eine Architekturabweichung, kein allein daraus belegter Funktions- oder Sicherheitsfehler. Es wird insbesondere nicht behauptet, dass ein nicht installiertes SecurityBundle geladen würde.

**Abschlusskriterium:** Minimales Worker-Wiring oder ausdrückliche Architekturentscheidung zugunsten des gemeinsamen Kernels; Domain/Application bleiben unabhängig.

### F12 — P1 vor Release: Aktuelle Gesamt- und Liveabnahme fehlt

Historische Lab-/CI-Erfolge gehören zu einem älteren Kandidaten. Repräsentative Backups, Fehler/Retry, Cancel und gekoppelte Matrix-Zustellung sind schon dort offen. Vollständige TLS/ACL/Chaos-, Restore- und Produktionsnachweise fehlen ebenfalls.

Quelle: [Phase-7-Abnahme](../../phase-7-live-acceptance.md).

**Abschlusskriterium:** Nach Korrekturen neuer unveränderlicher Kandidat, passende vollständige Gates und abgestufte Lab-/Betriebsabnahme. F01 ist vor weiteren echten Backupstarts zu schließen.

## 4.4 Ergebnis

Die vier Kernfunktionen sind weitgehend implementiert, ihre Releaseabnahme ist aber nicht vollständig erfüllt. Besonders die zyklusübergreifende Startsicherheit kann nicht durch die vorhandenen grünen Tests oder historische Shadow-Deduplizierung ersetzt werden.

Es gibt keinen belastbaren Prozentwert „fertig“: Ein einzelner Startschutzfehler wiegt für die Einsatzreife schwerer als viele fertige Oberflächen. Die sinnvollere Fortschrittsangabe ist: **breite Implementierung vorhanden; gezielte Schutz-/Integrationskorrekturen, mehrere Scopeentscheidungen und der vollständige aktuelle Betriebsnachweis fehlen.**
