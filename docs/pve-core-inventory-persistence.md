# PVE-Core-Inventarpersistenz

Stand: 11. Juli 2026

## Umfang

Dieser erste Persistence-Slice speichert das Kerninventar genau einer
konfigurierten PVE-Connection: Installation-Binding, Cluster beziehungsweise
Standalone-Installation, Nodes, QEMU-/LXC-Gäste und deren aktuelle
Node-Platzierung. Er umfasst außerdem den Connection-bezogenen Sync-Lauf,
Endpoint-Versuche und die Ergebnisse der beiden Scopes `pve_topology` und
`pve_guests`.

Bewusst noch nicht enthalten sind die Runtime-Aktivierung im Collector,
PVE-Storages und deren Node-Zustände, PBS-Inventar, Backupjobs sowie
PVE-/PBS-Tasks. Diese Grenzen dürfen durch den Core-Writer nicht implizit
vorweggenommen werden.

Der produktive Credential-/TLS-gebundene PVE-Core-Reader ist inzwischen als
separater, noch nicht verdrahteter Infrastructure-Slice vorhanden. Sein
Sicherheits- und Routenvertrag steht in
[`pve-runtime-core-reader.md`](pve-runtime-core-reader.md).

## Secret-freier Connection-Katalog

`DbalConnectionScanCatalog` liefert ausschließlich aktivierte Connections mit
Produkt, erwarteter Connection-Revision und den aktivierten Endpoint-IDs samt
Priorität. `ConnectionScanTarget` sortiert die Endpoint-Referenzen
deterministisch nach Priorität und ID.

Endpoint-Adressen, TLS-Material, Principals, Credential-Referenzen,
verschlüsselte Werte und Klartext-Secrets überschreiten diese
Application-Grenze nicht. Sie werden später ausschließlich hinter dem
`EndpointInstallationReader` durch Infrastructure aufgelöst. Ein Endpoint-
Snapshot ist stets in sich geschlossen; Failover-Ergebnisse werden nicht zu
einem gemeinsamen Snapshot zusammengeführt.

## Installation-Binding und Identität

Eine Connection wird beim ersten vollständig autoritativen PVE-Commit gebunden:

- Cluster: Topologieart `clustered` plus exakter Clustername;
- Standalone: Topologieart `standalone` plus exakter lokaler Nodename.

Ein erster partieller Commit ohne bestehendes Binding ist ausschließlich
diagnostisch. Er beendet den Sync-Lauf und speichert dessen Scope-Ergebnisse,
erzeugt aber weder Binding noch Cluster-, Node-, Gast- oder Placement-Zeilen.

Bei einem bereits gebundenen Cluster reicht der gleiche Name allein nicht aus.
Mindestens ein Node des neuen Snapshots muss zu den bereits bekannten Nodes des
Clusters gehören. Ein gleichnamiger, aber disjunkter Cluster wird als Konflikt
abgewiesen. Ein Standalone-Commit muss weiterhin exakt den einzigen gebundenen
Node enthalten.

## Autorität und negative Diffs

Der Core-Slice verwendet bewusst eine strikte globale Autoritätsgrenze:
`pve_topology` und `pve_guests` müssen beide `complete` sein. Sobald auch nur
einer der beiden Scopes `partial` oder `failed` ist, ist der gesamte Commit
nicht autoritativ und **kein** negativer Diff ist erlaubt.

Bei bestehendem Binding dürfen nicht autoritative Läufe sichere positive
Beobachtungen anlegen, aktualisieren oder reaktivieren. Die Abwesenheit eines
Nodes oder Gastes darf dann jedoch weder ein Objekt archivieren noch ein
Placement entfernen. Nur ein vollständig autoritativer Commit archiviert nicht
gesehene Gäste und Nodes; beim Archivieren eines Gastes wird dessen aktuelle
Platzierung entfernt. Wiederkehrende Objekte behalten über ihre natürlichen
Unique Keys dieselbe interne ID.

Diese globale Regel ist absichtlich enger als eine spätere, feinere Autorität
für Storage- oder PBS-Teilscopes. Der Core-Writer darf aus den beiden jetzigen
Scope-Zeilen keine zukünftige Storage-, Datastore- oder Task-Autorität ableiten.
Die Scope-Zeilen dokumentieren deshalb ausschließlich ihren jeweiligen Status.
Allein `inventory_sync_runs.authoritative` ist die persistierte Freigabe für
einen negativen Diff des gesamten Core-Snapshots.

## Transaktions- und Konfliktgrenzen

`DbalPveCoreInventoryStore` führt `beginRun`, Endpoint-Protokollierung,
`finishWithoutSnapshot` und `apply` jeweils in einer expliziten
MariaDB-Transaktion aus. Jeder Schreibpfad
prüft die Collector- und Connection-Grenzen. Für `apply` gelten zusätzlich die
Run- und Endpoint-Invarianten. Ein Apply wird nur akzeptiert, wenn:

- Schedule, Cycle, Lease-Token und Fencing-Token noch dem Collector gehören;
- der Sync-Lauf noch `running` und noch nicht angewandt ist;
- Connection, Produkt, Aktivierungszustand und erwartete Revision unverändert
  sind;
- der Commit den vom Lauf als `selected` protokollierten Endpoint verwendet;
- das persistierte Installation-Binding und die bekannte Cluster-Mitgliedschaft
  zum Snapshot passen.

Ein Run darf genau einen Endpoint als `selected` binden. Das wird sowohl im
Writer unter dem Run-Lock als auch direkt in MariaDB abgesichert: Die generierte
Spalte `selected_sync_run_id` ist nur für `selected`-Versuche belegt und besitzt
einen Unique Constraint. Ein zweiter ausgewählter Endpoint kann die Invariante
daher auch nicht durch einen konkurrierenden oder direkten SQL-Schreibpfad
umgehen.

Der Fence wird unmittelbar vor dem Commit erneut geprüft. Inventar-Upserts,
Placements, erlaubter negativer Diff, Scope-Ergebnisse und Laufabschluss bilden
eine gemeinsame Transaktion. Ein zweites Anwenden desselben Runs oder eine
Konfigurationsänderung während des Remote-Reads wird als Konflikt verworfen.
Konflikte sind typisiert: Nur `ConnectionChanged` ist ein erwarteter,
operational terminalisierbarer Konflikt. Apply-once-, Selected-Endpoint- oder
andere Invariant-Verletzungen werden nicht als Konfigurationsdrift umgedeutet
und propagieren als nicht-operationaler Fehler.

Wenn kein verwendbarer Snapshot entsteht, beendet `finishWithoutSnapshot` den
noch laufenden, dem Lease gehörenden Run atomar als `failed`. Persistiert wird
nur ein typisierter stabiler `ConnectionReadFailureCode`; `error_summary`
bleibt leer. Der Pfad erzeugt weder Scope- noch Core-Inventarzeilen und kann
denselben Run ebenso wenig zweimal abschließen wie `apply`.
Ändert sich die Connection-Revision während des Remote-Reads, darf genau dieser
owned und weiterhin gefencete Run trotzdem als `connection_changed` geschlossen
werden. Die neue Revision autorisiert dabei keinen Inventar-Write. Ein verlorener
Fence bleibt dagegen ein harter Abbruch; der alte Worker darf den Run nicht mehr
terminalisieren.

## Application-Orchestrierung ohne Runtime-Aktivierung

`ExecuteClaimedPveCoreInventoryCycle` verarbeitet einen bereits geclaimten
`CollectorActiveCycle`. Der Use-Case liest den secret-freien Connection-Katalog
einmal, verarbeitet aktivierte Targets seriell und erneuert den Lease vor und
nach Katalog-/Binding-I/O, jedem Remote-Versuch sowie jedem Writer-Aufruf.
`ReadConnectionWithFailover` meldet jeden Versuch typisiert an einen
Attempt-Sink. `failover` wird nur gespeichert, wenn tatsächlich ein weiterer
Endpoint versucht wird; der letzte abgelehnte oder fehlgeschlagene Versuch ist
`terminal`.

PVE-Reads werden mit `MapPveCoreInventorySnapshot` rein in den Core-Commit
überführt. Ein Connection-Fehler beendet nur seinen eigenen Run und verhindert
nicht die folgenden Connections. Die Cycle-Aggregation ist konservativ:

- kein Target: `succeeded`;
- nur zurückgestellte PBS-Targets: `partial`;
- PVE-Targets, aber kein einziger verwendbarer Snapshot: `failed`;
- verwendbarer PVE-Snapshot zusammen mit Partial-/Fehler-/PBS-Anteilen:
  `partial`;
- ausschließlich vollständige PVE-Ergebnisse: `succeeded`.

PBS wird in diesem Slice bewusst nur als `partial` markiert und weder gelesen
noch persistiert. Storages, Backupjobs und Tasks bleiben ebenfalls außerhalb.

Die MariaDB-Integrationstests prüfen zusätzlich zwei echte konkurrierende
Connections auf denselben Run, Lease-Ablauf während eines Schedule-Lock-Waits
mit anschließendem Takeover sowie Revision-Drift während des
Connection-Lock-Waits. In allen Verliererpfaden bleibt die Aggregate-Transaktion
ohne partielle Core- oder Scope-Schreibvorgänge.

Zeitstempel werden in UTC mit Mikrosekunden persistiert. Gastname und
Template-Status bleiben `NULL`, wenn die API sie nicht sicher liefert; der
Writer erfindet keine Ersatzwerte.

## Aktivierungsgrenze

Application-Ports, Orchestrierung, MariaDB-Adapter und der gehärtete
Infrastructure-Reader sind im dedizierten Collector-Runtime-Loop aktiviert. Der
Collector claimt ausschließlich fällige persistierte Zyklen, prüft Lease und
Shutdown an jedem sicheren Checkpoint und persistiert das PVE-Core-Inventar über
die festen GET-only-Routen. PBS-Verbindungen machen den Zyklus weiterhin
ehrlich `partial`; PVE-Storages, Backupjobs, Tasks und sämtliche PBS-Persistenz
bleiben außerhalb dieses aktivierten Umfangs.
