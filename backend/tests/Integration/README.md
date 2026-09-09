# Backend integration gates

`make backend-integration` first runs two fast gates without Docker: the
integration wrapper's exit and cleanup semantics, and the local migration
wrapper's failure propagation, ordering, and non-destructive behavior. It then
uses the production Doctrine configuration, asserts the migration status exit
contract against a fresh MariaDB 11.4 container, applies the real migrations
twice, and verifies the installed schema, constraints, and UTC behavior. The
database is isolated and has no host port. Cleanup is attempted after every run,
and the wrapper fails visibly if Docker cannot complete it.

The wrapper preserves the primary test exit status when both the test command
and cleanup fail. If the test command succeeds but cleanup fails, the cleanup
status becomes the wrapper status. Both mixed outcomes are reported explicitly;
cleanup errors are never discarded.

Run `make backend-integration-wrapper-test` to verify these exit semantics
without accessing Docker. The test runs the wrapper in temporary repository
copies with a fake Docker executable and covers successful cleanup, cleanup-only
failure, primary-only failure, combined failure, and failure before Compose is
started. Its temporary files are removed on exit. POSIX signal traps are checked
under `sh` and `dash`; the deterministic harness does not inject asynchronous
signals.

The collector scheduling slice exercises its production MariaDB adapters for
immediate bootstrap, persistent-grid claiming, lease renewal and expiry,
fencing, strict-future finalization, heartbeat freshness, and a real
two-connection claim race.

The first PVE-core persistence slice now provides the production
`DbalConnectionScanCatalog` and `DbalPveCoreInventoryStore`. Its green MariaDB
gate exercises the production writer directly and covers:

- a secret-free, deterministically ordered connection catalog;
- idempotent synchronization with stable cluster, node and guest identities;
- current-placement reconciliation and guest reactivation;
- the strict global authority rule: both PVE-core scopes must be complete
  before any negative diff may archive a missing node or guest;
- partial scans that retain absent inventory while applying safe positive
  observations for an existing binding;
- a first partial scan without a binding that remains diagnostic-only;
- rejection of a same-name cluster without known-node membership;
- a no-snapshot terminal path that atomically fails the owned run with only a
  typed redacted code and no scope or core writes;
- selected-endpoint, generated-column/unique selected-once,
  connection-revision, apply-once and collector-fence conflicts with complete
  transaction rollback;
- real two-connection races for the same run, lease expiry while waiting for
  the schedule lock followed by a second connection taking over through the
  production `DbalCollectorScheduleStore::claimDue()` path, and revision drift
  while waiting for the connection lock;
- QEMU and LXC with the same VMID as distinct identities, placement moves,
  stable first-seen values, structured endpoint attempts, and an intentionally
  injected mid-write MariaDB failure that leaves no partial aggregate behind.

The same real-MariaDB gate also exercises the still-unwired production PVE
endpoint configuration source. It proves that the expected connection
revision, owned enabled endpoint, and collector `api_token` credential are read
from one consistent statement; revision drift, a foreign/disabled endpoint,
and a missing collector credential fail closed before a remote client can be
created.

The PVE-storage enrichment extends that same aggregate transaction. Its real
MariaDB gate covers stable storage identities, disabled backup definitions,
PBS mapping replacement, node-specific measured/unavailable/invalid capacity,
partial positive updates without absence diffs, authoritative archive and
reactivation, storage-specific mid-write rollback, exact constraints, the real
collector grants, and the additive migration's up/down/up path.

The PBS gate exercises the product-specific configuration source and the full
fenced aggregate writer against MariaDB 11.4. It covers legacy and instance
bindings, selected-endpoint and revision drift, lease/fence loss, apply-once
and concurrent double apply, lock-wait expiry rollback, diagnostic first
partials, safe positive partials, authoritative archive/reactivation, backend-
bound capacity replacement, S3 local-cache semantics, exact collector grants,
and schema constraints. The same migration test proves the additive PBS schema
up, down to the unchanged foundation, and up again.

The monitoring persistence gate now exercises the real MariaDB adapter for PVE
and PBS jobs/tasks. Its PBS cases cover stable create/update identities,
monotone last-run and terminal-task state, explicit running/history provenance,
reported-node preference, the all-four-filter history cursor rule, persisted
worker allowlists, UPID hash/raw collision rollback, collector monitoring-table
grants with DELETE denial, and lease-takeover terminalization of running child
runs.

SQL-only stand-ins, fake repositories, skipped placeholders, and tests that
merely restate fixtures do not satisfy these gates.

`make backend-coverage` produces `backend/coverage/clover.xml` with Xdebug path
coverage and enforces 100% line and branch coverage for Domain plus Application,
100% for the Proxmox compatibility layer as soon as it exists, and 95% line plus
90% branch coverage globally. `src/Kernel.php` is the only explicit source
allowlist entry; no other bootstrap or infrastructure code is silently removed
from the report.
