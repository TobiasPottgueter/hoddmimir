# Capability-Snapshot-Persistenz

## Zweck und Grenzen

Der Collector speichert nach einem erfolgreichen Read des ausgewählten
PVE-/PBS-Endpunkts und vor dem Inventory-Apply einen unveränderlichen
Capability-Snapshot. Er persistiert ausschließlich bereits durch die
Kompatibilitätsmatrix verifizierte Eigenschaften:

- PVE 7/8/9: Backupjob-Response-Vertrag, `maxfiles`-Unterstützung und
  Prune-Response-Form;
- PBS 3/4: Versionsvertrag und Unterstützung der Instanzidentität.

Unbekannte Felder, optionale Drift-Funktionen oder aus einer Serverantwort
abgeleitete Vermutungen aktivieren keine Capability.

## Kanonisierung und Identität

Das Profilformat besitzt eine explizite `profileVersion`. Capability-Schlüssel
werden binär sortiert und als kanonisches JSON ohne unnötiges Escaping
serialisiert. Der binäre SHA-256-Hash umfasst:

- Produkt und normalisierte Version einschließlich Release und Rohversion;
- Profilversion und verifizierte Capabilities;
- die ID des tatsächlich ausgewählten Endpunkts.

Damit ist dasselbe Profil am selben Endpunkt idempotent. Eine andere Version,
Capability oder ein anderer Endpunkt erzeugt einen neuen Snapshot. Bestehende
Sync-Runs behalten ihre historische Referenz.

## Transaktion und Fencing

`DbalCapabilitySnapshotStore` führt Lookup/Insert, Aktualisierung von
`last_observed_at` und das Setzen von
`inventory_sync_runs.capability_snapshot_id` in einer Transaktion aus. Vor dem
Schreiben und vor dem Commit werden Collector-Lease und Cycle-Fence geprüft.
Zusätzlich müssen folgende Werte unverändert zusammenpassen:

- Run und Connection;
- erwartete Connection-Revision;
- ausgewählter Endpoint;
- Produkt und aktivierter Connection-Status.

Die Connection-Zeile wird gesperrt. Dadurch serialisieren konkurrierende
Writer für dieselbe Verbindung; der Unique Key aus Connection und Hash bleibt
eine zusätzliche Datenbankgarantie. Ein verlorener Fence, eine geänderte
Konfiguration oder ein abweichender Run führt zum vollständigen Rollback. Es
entsteht weder ein verwaister Snapshot noch eine falsche Run-Referenz.

## Fehlersemantik

Capability- oder Konfigurationskonflikte werden fail-closed behandelt. Der
betroffene Parent-Run endet mit `connection_changed` bei Connection-Drift oder
mit `snapshot_invalid` bei einer inkonsistenten Capability-Beobachtung. Andere
Targets des Collector-Cycles können weiterlaufen; dadurch wird der Gesamtcycle
gegebenenfalls `partial`. Unerwartete Invarianten aus dem eigentlichen
PVE-/PBS-Read werden nicht als Capability-Fehler umklassifiziert.

## Datenbankrechte und Verifikation

Die additive Migration `Version20260712000300` gibt dem Collector für
`proxmox_capability_snapshots` exakt `SELECT`, `INSERT` und `UPDATE`, jedoch
kein `DELETE`. Die Integrationstests verwenden MariaDB 11.4 und prüfen:

- Migration Up/Down/Up und exakte Grants;
- Idempotenz, Hashwechsel und historische Referenzen;
- Revision-, Endpoint-, Connection- und Fence-Konflikte;
- Foreign Keys und konkurrierende Same-Hash-Writer.
