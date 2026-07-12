# Phase 4: Policies, Scheduler und Shadow Mode

Status: begonnen am 12. Juli 2026

Dieses Dokument konkretisiert Phase 4 aus dem
[`rewrite-plan.md`](rewrite-plan.md). Es ist dem Rewrite-Plan untergeordnet und
erfindet keine noch ungeklärten fachlichen Defaults.

## Ziel und Nicht-Ziele

Phase 4 liefert:

- unveränderliche Domainregeln für Auswahl, Eligibility, Gründe, Priorität,
  Vererbung und Explainability;
- neu konfigurierte Backupziele und Policies ohne Legacy-Import;
- automatische, persistierte Shadow-Entscheidungen;
- WebApp-Sichten für Ziele, Auswahl, Policies und Shadow-Entscheidungen;
- MariaDB-Transaktionen und Constraints für idempotente Auswertung.

Phase 4 startet, stoppt oder löscht keine Backups. Der Collector bleibt gegen
PVE und PBS read-only. Claimbare `backup_requests`, Backup-Worker-Leases, UPID
und Ausführung gehören zu Phase 5. Es gibt weiterhin weder einen manuellen
Scan-Endpunkt noch eine Aktion „Jetzt scannen“.

## Verbindliche Regeln

### Auswahl und Eligibility

- QEMU und LXC sind gleichberechtigt.
- Verbindung, Cluster, aktueller Node, Gast, Policy und Ziel müssen aktiviert
  sein.
- Ein expliziter Ausschluss gewinnt immer gegen geerbte oder explizite
  Includes.
- Archivierte Gäste sowie fehlendes oder veraltetes Placement sind nicht
  zulässig. Die Behandlung von Templates und weiteren Gastzuständen bleibt
  eine offene Fachentscheidung.
- Nur das aktuelle Placement zählt. Nach einem Nodewechsel wird eine alte
  Entscheidung niemals auf den neuen Node umgebogen.
- Ziel ist immer ein konkretes PVE-Storage mit Backup-Content. Es muss auf dem
  aktuellen Node enabled und active sein.
- Ziel-Node-Zuordnung, Executor-Berechtigung, Kapazitätsfrische,
  Mindestfreiplatz und Parallelität sind zusätzliche Gates.
- PBS-Ziele benötigen eine normalisierte und validierte Zuordnung von
  PVE-Storage zu PBS-Server, Datastore und optionalem Namespace.
- Jede Entscheidung speichert alle geprüften Gates in stabiler Reihenfolge,
  einschließlich der bestandenen Regeln.

Manuelle Anforderungen ändern nur Trigger, Grund und Priorität. Sie umgehen
keine Auswahl-, Placement-, Ziel-, Berechtigungs-, Kapazitäts- oder
Concurrency-Gates.

### Gründe und Priorität

| Grund             | Priorität | Semantik                                                                  |
| ----------------- | --------: | ------------------------------------------------------------------------- |
| `manual`          |       400 | persistierte manuelle Anforderung                                         |
| `never_backed_up` |       300 | kein erfolgreicher V2-Lauf; nur Fehler zählen weiterhin als nie gesichert |
| `max_age`         |       200 | Altersgrenze strikt überschritten                                         |
| `bytes_written`   |       100 | Byte-Schwelle und Cooldown strikt überschritten                           |

Treffen mehrere automatische Gründe zu, gilt nur der höchstpriorisierte.
`retry` ist ein Trigger und keine fünfte Klasse; Grund und Priorität werden vom
Ursprungsrequest übernommen. Innerhalb einer Klasse gilt FIFO nach
`scheduled_at`, danach stabile ID. Fairness darf nur innerhalb einer Klasse
umsortieren.

Die bekannten Grenzen sind strikt:

- `now > last_success_at + max_age`;
- Cooldown erst nach, nicht an der Grenze;
- `current_bytes - baseline_bytes > threshold`.

Jede Gleichheit und die erste Einheit oberhalb der Grenze benötigen eigene
Unit-Tests.

### Aufgelöste Policy

Zieldefaults für Modus, Kompression und gewünschte Retention dürfen durch
Gastwerte überschrieben werden. Die vollständig aufgelöste Policy wird
kanonisch und versioniert serialisiert und gehasht. `maxfiles` ist nur für PVE
7/8 zulässig und gegenseitig exklusiv zu Prune-Parametern; PVE 9 erhält niemals
`maxfiles`. Gewünschte Retention und für die Ausführung freigegebene
Löschwirkung bleiben getrennte Felder. Löschwirksame Parameter bleiben bis zur
separaten Rechte-, Capability- und E2E-Abnahme deaktiviert.

## Sicherheitsgrenzen

Die bestehende Loopback-Bindung ist für die aktuelle GET-only-API ausreichend,
aber keine Autorisierung für administrative Schreibzugriffe. Bevor POST/PUT
aktiviert werden, wird eine minimale fail-closed Sicherheitsgrenze aus Phase 6
vorgezogen:

- authentifizierter Principal;
- explizite Permission `backup_configuration.manage`;
- serverseitiger Authorizer;
- sichere 401/403/409/422-Fehler ohne Interna oder Secrets;
- Audit-Event und Optimistic-Concurrency-Revision je Änderung.

Bis diese Grenze steht, bleiben neue HTTP-Sichten GET-only und der
`hoddmimir_web`-Datenbankbenutzer read-only. Ein Frontend-Flag oder ein
`LOCAL_ADMIN`-Schalter ist keine Produktionsautorisierung.

## Architektur

### Domain und Application

Neue Domainobjekte liegen unter `backend/src/Domain` und bleiben frei von
Symfony, DBAL, HTTP und MariaDB. Sie sind `final readonly`, verwenden Enums,
UTC-`DateTimeImmutable` und eigene ID-Value-Objects.

```text
Domain/Scheduler
├── SchedulerCandidate / SchedulerDecision
├── GateCode / GateResult / DecisionOutcome
├── BackupReason / RequestOrigin / Priority
├── EligibilityEvaluator / ReasonSelector / PriorityResolver
└── FairQueueOrderer

Domain/Policy
├── PolicyId / PolicyRevision / PolicyStatus
├── SelectionValue (inherit/include/exclude)
├── PolicyThresholds / ResolvedBackupPolicy / PolicyResolver
└── BackupMode / Compression / RetentionPolicy

Domain/Target
├── BackupTargetId / TargetStatus / AllowedNodes
├── MinimumFreeBytes / CapacityEvidence
├── ConcurrencyPolicy / ConcurrencySnapshot
└── PbsTargetMapping
```

Application-Services orchestrieren die Regeln nur über Ports. Die Pipeline
lautet: Policy auflösen, Auswahlgates, Freshnessgates, Duplicate-/Active-Gates,
Grund, Priorität, klasseninterne Fairness und Explainability persistieren.

### Explainability

Eine Entscheidung enthält mindestens:

- Outcome `eligible`, `blocked`, `not_due` oder `deduplicated`;
- optionalen Grund und optionale Priorität;
- Policy-/Target-Revision und kanonischen Policy-Snapshot-Hash;
- Guest, aktuelles Placement, Node und `placement_revision`;
- Inventar-, Capacity- und Write-State-Zeitpunkte;
- geordnete Gates mit geschlossenem Code, `passed`, Scope, Subject-ID,
  Beobachtungszeit und geschlossenem Detailcode.

Freie Exception-Texte, Tokens und Secrets werden nicht persistiert oder an die
WebApp geliefert.

## Persistenz und Transaktionen

### Voraussetzungen

1. `guest_placements` erhält eine `placement_revision`, die nur bei einem
   tatsächlichen Nodewechsel erhöht wird.
2. Der Collector persistiert den rohen PVE-`diskwrite`-Zähler mit
   Beobachtungszeit und autoritativem Sync-Kontext. Reset-/Baseline-Semantik
   wird erst nach fachlicher Entscheidung darauf aufgebaut.
3. PVE-Node-Storage-State und PBS-Mappings werden so projiziert, dass
   Aktivierung, Node-Verfügbarkeit, Kapazität und Freshness serverseitig
   validierbar sind.

Der genaue Revisions-, Staleness-, Rohdaten- und Berechtigungsvertrag für die
ersten beiden Voraussetzungen steht in
[`pve-guest-state-persistence.md`](pve-guest-state-persistence.md).
Der fail-closed Vertrag der serverseitigen Storage-/Node-/PBS-Evidenz steht in
[`backup-target-candidate-read-model.md`](backup-target-candidate-read-model.md).

### Phase-4-Tabellen

- `backup_targets` und `backup_target_allowed_nodes`;
- `backup_policies`, `backup_policy_assignments` und
  `backup_policy_guest_overrides`;
- `scheduler_evaluation_runs`;
- `scheduler_decisions`;
- `scheduler_decision_gates`;
- `guest_backup_state`.

Alle Aggregate verwenden `BINARY(16)`-IDs, UTC-`DATETIME(6)`, Revisionen,
Foreign Keys, Check Constraints und Soft-disable. Cluster-, Node-, Storage-,
Target- und Policy-Beziehungen werden mit zusammengesetzten Constraints gegen
Cross-Cluster-Mischung geschützt.

Shadow Mode bleibt strukturell getrennt: Er schreibt ausschließlich
Evaluation-Runs, Decisions und Gates. Er erzeugt niemals `backup_requests`,
Queue-Events oder ausführbare Outbox-Events. Der Backup-Worker erhält in Phase
4 keine Grants auf diese Tabellen.

### Spätere Live-Queue in Phase 5

Die Live-Queue erhält einen eindeutigen Schlüssel für Policy, Gast und
Planzeitpunkt sowie einen Claim-Index in der Reihenfolge `status`,
`available_at`, `priority DESC`, `scheduled_at`, `id`. Claiming erfolgt in
einer expliziten MariaDB-Transaktion mit `FOR UPDATE SKIP LOCKED`, Lease-Token,
Expiry und monotonem Fence. Node- und Zielslots werden unter Row Locks
reserviert; ein ungesperrtes `COUNT(*)` reicht nicht.

## API und WebApp

Der kleinste sichere HTTP-Schnitt beginnt mit GET-Projektionen:

- Backupziel-Kandidaten mit Node-State, Kapazität, Freshness, PBS-Bindung,
  `canEnable` und typisierten Blockern;
- konfigurierte Ziele und Policies;
- effektive Auswahl für Nodes, QEMU und LXC;
- cursor-paginierte Shadow-Entscheidungen und Gate-Details.

Nach der Auth-Grenze folgen revisionierte, idempotente Commands für Ziele,
Policies und bounded Bulk-Selection. Es gibt keine Route zum manuellen
Shadow-Trigger, Scan oder Backupstart/-stopp/-cancel. Eine Änderung wird im
nächsten automatischen Collector-Zyklus ausgewertet.

Frontend-Slices verwenden eigene `configurationApi`-/`shadowApi`-Module und
Pinia-Stores. Die Views `Backup-Ziele`, `Policies` und `Shadow-Entscheidungen`
zeigen Loading-, Empty-, Fehler-, Permission-, Konflikt- und Blockerzustände.
Schreibcontrols bleiben verborgen oder gesperrt, solange die serverseitige
Permission fehlt.

## Umsetzungswellen

### 4.0 – Vertrag und Entscheidungen

- dieses Dokument reviewen;
- offene Fachentscheidungen einzeln festlegen;
- Mutation-Allowlist um neue Scheduler-/State-Machine-Pfade erweitern.

### 4.1 – Inventarvoraussetzungen

- `placement_revision` nur bei Nodewechsel;
- roher, frischer Guest-Write-State;
- serverseitige Target-Candidate-Projektion;
- Unit-, Contract- und MariaDB-Up/Down/Up-Tests.

### 4.2 – Reine Regelprimitive

- Auswahl, Explainability, bekannte Gründe und Prioritäten;
- strikte Alters-/Cooldown-/Byte-Grenzen;
- Retry-Inheritance und klassenstabile FIFO-/Fairness-Grenze;
- 100 % Line/Branch sowie mindestens 90 % Critical-MSI.

### 4.3 – Shadow-Persistenz

- Evaluation-Run, Decision und Gates;
- idempotente, gefencete Transaktion;
- keinerlei claimbare Queue-Daten oder Backup-Worker-Grants;
- MariaDB-Concurrency- und Least-Privilege-Tests.

### 4.4 – Ziele und Policies

- Aggregate, Revisionen, FKs und serverseitige Aktivierungsvalidierung;
- GET-Projektionen;
- weiterhin keine unauthentifizierten Commands.

### 4.5 – Minimale Security und Commands

- Principal, Permission, Authorizer und Audit;
- Targets, Policies und bounded Selection-Commands;
- OpenAPI-Client und negative Permission-/DML-Tests.

### 4.6 – Automatischer Shadow-Zyklus und WebApp

- automatische Auswertung nach autoritativem Collector-Apply;
- erklärbare Views und Cursor-Pagination;
- Vitest/Component-Tests und Playwright mit dediziertem QA-Seed-CLI, niemals
  einem versteckten Produktions-Testendpoint.

## Quality Gates

- jede deterministische Regel und jeder Zustandsübergang als Unit-Test;
- Domain, Application und Scheduler-Kompatibilität 100 % Line/Branch;
- global mindestens 95 % Line und 90 % Branch;
- Critical-MSI mindestens 90 %, global mindestens 80 %;
- reale MariaDB-11.4-Tests für FKs, Revisionen, Idempotenz, Fencing,
  Least-Privilege und Parallelität;
- closed OpenAPI-Schemas und generierter TypeScript-Client ohne Drift;
- Stores/Composables 100 %, Frontend gesamt mindestens 90 %;
- Playwright für Zielblocker, Node-/QEMU-/LXC-Auswahl, Revision-Konflikt und
  erklärbare `never_backed_up`-Shadow-Entscheidung;
- negative E2E-Assertions: kein Scan, kein Backupstart/-stopp/-cancel.

## Offene fachliche Entscheidungen

Vor der jeweils betroffenen Welle werden festgelegt und in diesem Dokument
ersetzt:

1. Verhalten bei fallendem/resettem PVE-`diskwrite`-Counter.
2. Cooldown-Anker: letzter Erfolg, Versuch, Planung oder Byte-Planung.
3. Defaults und erlaubte Bereiche für Alter, Bytes, Cooldown und Freshness.
4. Default-Auswahl und exakte Hierarchie von global/Connection/Cluster/Node/Gast.
5. Umfang der Gast-Overrides über Modus, Kompression und Retention hinaus.
6. Fairnessalgorithmus und persistierter Fairnesszustand.
7. Bedeutung dynamischer Zielparallelität „ein Slot je Node“.
8. Ob `available == minimum_free_bytes` ausreichend ist.
9. Freiplatzreservierung beziehungsweise erwartete Backupgröße bei parallelen Starts.
10. PBS-Ziele: Zusammenspiel von PVE- und PBS-Kapazität; S3-Local-Cache ist
    niemals allein Remote-Kapazitätsnachweis.
11. Quelle und Freshness des Executor-Berechtigungsnachweises.
12. Relationale Identität und Eindeutigkeit der PVE-zu-PBS-Zuordnung.
13. Externe beobachtete Tasks/Snapshots zählen nicht automatisch als V2-Historie;
    eine spätere abweichende Regel wäre explizit zu entscheiden.
14. Schedule-Modell, Zeitzone, verpasste Policy-Ticks und Bildung von
    `scheduled_at`.
15. Welche Gates nur zur Shadow-Entscheidung führen und welche erst unmittelbar
    vor Phase-5-Start erneut blockieren.
16. Zulässigkeit von Templates und weiteren Guest-States.
17. Trennung gewünschter Retention von freigegebener Ausführungswirkung.

Bis zur Klärung werden keine vermeintlichen Defaults im Code versteckt.
