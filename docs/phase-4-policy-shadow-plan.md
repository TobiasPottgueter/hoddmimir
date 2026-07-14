# Phase 4: Policies, Scheduler und Shadow Mode

Status: **lokale Implementierung am 13. Juli 2026 abgeschlossen.** Die
Umgebungsabnahme gegen reale PVE-/PBS-Systeme ist Bestandteil der noch nicht
begonnenen Phase 7.

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

## Lokaler Abschlussstand

Der lokale Phase-4-Umfang ist implementiert:

- reine, geschlossene Domainregeln für Auswahl, Eligibility, Freshness,
  Gründe, Priorität, Policy-Auflösung, Kandidatengewinn und Explainability;
- revisionierte Ziele, Policies, Auswahl und Gast-Overrides ohne Legacy-Import;
- serverseitige Zielkandidaten mit Node-, Kapazitäts-, PBS- und
  Executor-Evidenz sowie fail-closed Aktivierungsblockern;
- automatische, gefencete Shadow-Auswertung nach einem autoritativen
  Collector-Apply einschließlich Duplicate-, Kapazitäts- und
  Node-/Ziel-Concurrency-Gates;
- append-only Evaluation-Runs, Decisions und geordnete Gates sowie
  cursor-paginierte API-/WebApp-Projektionen;
- authentifizierte, autorisierte, revisionierte und auditierte
  Konfigurationscommands.

Unit-/Contract-, echte MariaDB-11.4-, OpenAPI-, Frontend- und Playwright-Tests
decken diese lokalen Verträge ab. Der vollständige Gate-Satz muss auf jedem
eingefrorenen Releasekandidaten erneut ausgeführt werden; bereinigte Fixtures
und die lokale QA-Datenbank ersetzen keine Live-PVE-/PBS-Evidenz. Phase 4 hat
keinen PVE-Schreibzugriff aktiviert und zu diesem lokalen Abschlusszeitpunkt
war Phase 7 noch nicht begonnen. Ihr späterer Live-Fortschritt steht in
[`phase-7-live-acceptance.md`](phase-7-live-acceptance.md).

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

Administrative Schreibzugriffe verwenden die aus Phase 6 vorgezogene
fail-closed Sicherheitsgrenze:

- authentifizierter Principal;
- explizite Permission `backup_configuration.manage`;
- serverseitiger Authorizer;
- sichere 401/403/409/422-Fehler ohne Interna oder Secrets;
- Audit-Event und Optimistic-Concurrency-Revision je Änderung.

Ein Frontend-Flag oder ein `LOCAL_ADMIN`-Schalter ist keine
Produktionsautorisierung. Der `hoddmimir_web`-Datenbankbenutzer besitzt nur die
für die versionierten Read- und Command-Repositories erforderlichen Rechte;
direkter PVE-/PBS-Zugriff bleibt ausgeschlossen.

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

Status: lokal abgeschlossen.

- dieses Dokument reviewen;
- offene Fachentscheidungen einzeln festlegen;
- Mutation-Allowlist um neue Scheduler-/State-Machine-Pfade erweitern.

### 4.1 – Inventarvoraussetzungen

Status: lokal abgeschlossen.

- `placement_revision` nur bei Nodewechsel;
- roher, frischer Guest-Write-State;
- serverseitige Target-Candidate-Projektion;
- Unit-, Contract- und MariaDB-Up/Down/Up-Tests.

### 4.2 – Reine Regelprimitive

Status: lokal abgeschlossen.

- Auswahl, Explainability, bekannte Gründe und Prioritäten;
- strikte Alters-/Cooldown-/Byte-Grenzen;
- Retry-Inheritance und klassenstabile FIFO-/Fairness-Grenze;
- 100 % Line/Branch sowie mindestens 90 % Critical-MSI.

### 4.3 – Shadow-Persistenz

Status: lokal abgeschlossen.

- Evaluation-Run, Decision und Gates;
- idempotente, gefencete Transaktion;
- keinerlei claimbare Queue-Daten oder Backup-Worker-Grants;
- MariaDB-Concurrency- und Least-Privilege-Tests.

Ein kanonischer Payload-Hash macht exakte Wiederholungen idempotent;
abweichende Wiederholungen sowie verlorene Collector-Fences schlagen
geschlossen fehl. Collector-Zugriffe sind auf die erforderlichen
`SELECT`-/`INSERT`-Pfade begrenzt; der Backup-Worker besitzt keine Rechte auf
die Shadow-Tabellen. Policy- und Target-Revisionen werden gegen die inzwischen
vorhandenen Aggregate gebunden. Shadow-Auswertungen erzeugen weiterhin keine
ausführbaren Queue-Daten.

### 4.4 – Ziele und Policies

Status: lokal abgeschlossen.

- Aggregate, Revisionen, FKs und serverseitige Aktivierungsvalidierung;
- GET-Projektionen;
- weiterhin keine unauthentifizierten Commands.

### 4.5 – Minimale Security und Commands

Status: lokal abgeschlossen.

- Principal, Permission, Authorizer und Audit;
- Targets, Policies und bounded Selection-Commands;
- OpenAPI-Client und negative Permission-/DML-Tests.

### 4.6 – Automatischer Shadow-Zyklus und WebApp

Status: lokal abgeschlossen.

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

## Festgelegte fachliche Entscheidungen

### Freshness von Ausführungsnachweisen

- Inventar-, Placement-, Kapazitäts- und Executor-Berechtigungsnachweise sind
  standardmäßig höchstens 300 Sekunden alt. Der Wert ist als gemeinsame
  Deployment-Konfiguration änderbar; ein abweichender Wert verändert keine
  Collector-Taktung.
- Die Altersgrenze ist inklusiv: `now <= observed_at + freshness_window` ist
  frisch, die erste Mikrosekunde danach ist stale.
- Jeder benötigte Nachweis wird einzeln geprüft. Ein fehlender oder stale
  Nachweis blockiert fail-closed sowohl die Shadow-Eligibility als auch die
  unmittelbare Startfreigabe im Backup-Worker.
- Der Backup-Worker prüft alle Nachweise nach dem Claim und unmittelbar vor
  einem PVE-Schreibaufruf erneut. Bereits laufende PVE-Tasks werden bei stale
  Evidenz nicht abgebrochen, sondern weiterhin überwacht.
- Blockierte Requests bleiben nachvollziehbar und dürfen nach einem späteren
  erfolgreichen Collector-Apply erneut bewertet werden; es entsteht weder ein
  automatischer Backupstart mit stale Daten noch ein Catch-up-Burst.

### Auswahlhierarchie

- Die anwendbaren Ebenen sind global, Connection, Cluster, Node und Gast.
- Ohne explizites Include bleibt ein Gast fail-closed ausgeschlossen.
- `inherit` enthält keine eigene Entscheidung. Liegt mindestens ein explizites
  Exclude auf einer anwendbaren Ebene vor, gewinnt es gegen jedes Include.
- Ohne Exclude genügt mindestens ein Include; für die Explainability wird die
  spezifischste explizite Entscheidung ausgewiesen.

### Automatische Auswertung und Trigger

- V2.0 besitzt kein Cron-Modell. Jede aktivierte Policy wird nach jedem
  autoritativen Collector-Apply ausgewertet. `scheduled_at` ist der
  UTC-Startzeitpunkt dieses Collector-Zyklus.
- Ausgefallene, übersprungene oder überlange Collector-Zyklen erzeugen keine
  nachträglichen Policy-Ticks und keinen Catch-up-Burst.
- Maximalalter, Byte-Schwelle und Byte-Cooldown haben keine versteckten
  Defaults. Sie werden explizit konfiguriert; zur Aktivierung muss mindestens
  ein automatischer Trigger vollständig konfiguriert sein.
- Der Byte-Cooldown beginnt mit dem letzten erfolgreichen V2-Backup. Fällt der
  PVE-`diskwrite`-Counter, wird die Baseline auf den neuen beobachteten Wert
  gesetzt, der Reset auditiert und aus dem Reset allein kein Backup abgeleitet.
- Fairness verändert die dokumentierte Prioritäts-/FIFO-Reihenfolge in V2.0
  nicht. Innerhalb einer Prioritätsklasse gilt strikt `scheduled_at`, danach
  stabile Request-ID.

### Ziel-, Kapazitäts- und Executor-Regeln

- `available_bytes == minimum_free_bytes` besteht das Mindestfreiplatz-Gate.
- Ein Ziel besitzt ein explizites festes Parallelitätslimit. Zusätzlich gilt
  pro PVE-Node genau ein Startslot; die wirksame Zielparallelität ist höchstens
  die Zahl der aktuell zulässigen, online Nodes. Reservierung und Freigabe
  erfolgen transaktional unter Row Locks.
- Vor einem parallelen Start wird Kapazität reserviert. Grundlage ist die
  letzte erfolgreiche V2-Backupgröße plus zehn Prozent Sicherheitsaufschlag;
  fehlt sie, wird fail-closed die provisionierte Gastgröße verwendet. Fehlen
  beide Werte, erfolgt kein Start.
- Bei PBS-Zielen müssen PVE-Storage- und echte PBS-Datastore-Kapazität frisch
  sein. Die kleinere nachweisbare verfügbare Kapazität ist maßgeblich;
  `local_cache` allein beweist keine Remote-Kapazität.
- Die ausführbare PBS-Zuordnung referenziert explizit PVE-Storage,
  PBS-Connection, Datastore und optionalen Namespace. Host-/Port-Matching ist
  nur Kandidatenevidenz und keine dauerhafte Identität.
- Der Backup-Worker erhebt mit seinem eigenen Executor-Credential read-only
  `VM.Backup` für den konkreten Gast und `Datastore.AllocateSpace` für das
  konkrete Ziel. Nur dieser Prozess besitzt das Credential; die Evidenz folgt
  dem gemeinsamen 300-Sekunden-Freshness-Vertrag.

### Gast-, Retention- und Entscheidungsregeln

- Templates und unbekannte oder nicht aktive Gastzustände sind in V2.0 nicht
  backupfähig. QEMU und LXC im aktiven Inventar sind gleichberechtigt.
- Extern beobachtete Tasks und Snapshots werden niemals automatisch als
  erfolgreiche V2-Historie gewertet.
- Gast-Overrides umfassen in V2.0 Auswahl, Modus, Kompression und gewünschte
  Retention. Schwellwerte, Schedule und Zielzuordnung bleiben Policy-Regeln.
- Gewünschte Retention erzeugt ohne separates
  `retention_execution_enabled`, passende Permission, Capability und E2E-Gate
  keinerlei löschwirksame VZDump-/Prune-Parameter.
- Bei einem PVE-Storage vom Typ `pbs` bleibt die löschwirksame Retention immer
  gesperrt und wird ausschließlich durch PBS-Prune-Jobs ausgeführt. Für
  Nicht-PBS-Ziele ist sie ausschließlich als explizites Opt-in zulässig;
  Legacy-`maxfiles` bleibt auf PVE 9 verboten.
- Die Shadow-Auswertung persistiert eine Entscheidung je anwendbarem
  Gast-/Policy-/Ziel-Tupel. Für die spätere Queue gewinnt je Gast der höchste
  fachliche Grund, danach explizite Policy-Priorität und schließlich stabile
  Policy-/Target-ID; alle verworfenen Kandidaten bleiben erklärbar.
- Direkt vor einem Phase-5-Start werden Auswahl, Gastzustand, Placement,
  Target/Node, Inventar-, Kapazitäts- und Executor-Freshness, Mindestfreiplatz,
  Reservation, Duplicate- und Concurrency-Gates erneut geprüft. Grund und
  Priorität werden nicht neu erfunden, sondern vom Request übernommen.

Die für Phase 4 bis 6 benötigten Fachentscheidungen sind damit festgelegt.
Abweichungen werden als explizite Vertragsänderung mit Tests dokumentiert und
nicht als versteckter Default eingeführt.
