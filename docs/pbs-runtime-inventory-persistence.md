# PBS runtime inventory and persistence

The continuously running collector inventories configured Proxmox Backup
Server 3 and 4 installations through Hoddmimir's own typed, GET-only adapter.
It uses the same 120-second fenced cycle as PVE, but has an independent reader,
failure mapper and MariaDB aggregate store. A PBS failure cannot abort the PVE
targets in the same cycle, and a PVE failure cannot suppress PBS persistence.

## Activation and read contract

Runtime scanning is installation-wide only. No WebApp endpoint or manual
"scan now" action exists. A selected PBS endpoint uses the collector API-token
credential, one of the three exclusive TLS trust modes and the exact physical
route order fixed in `pbs-first-read-contract.md`:

- version, ping and the single-node list;
- system-status permission and node status;
- installation identity only on PBS 4.2 and newer;
- datastore permission, system configuration start and sorted datastore
  definitions;
- one sorted status request per expected datastore;
- system configuration end.

PBS 3.x and PBS 4.0/4.1 have no stable installation identity in this contract.
They bind to the exact endpoint plus the observed node name. PBS 4.2 and newer
bind to the 32-character lowercase instance identity and may fail over between
endpoints. A fully authoritative observation may upgrade an exact legacy
endpoint/node binding to instance identity; a partial observation never may.

`PBS_MAX_DATASTORE_FANOUT` defaults to 128 and accepts 1 through 1024. If the
expected datastore count exceeds the limit, the collector performs zero
datastore-status calls. It does not truncate the set or create a partial burst.

## Secret, TLS and checkpoint boundaries

`DbalPbsEndpointReadConfigurationSource` obtains the enabled connection,
owned endpoint, expected revision, TLS material and collector credential in one
statement. The PBS token principal is split at the last `@`, so usernames may
contain `@`. The encrypted token stays inside a redacted, non-serializable
Infrastructure DTO and is decrypted only inside the authorization callback.

Every physical HTTP attempt, including a retry, checkpoints the active
collector lease before I/O and again in `finally` after the secret callback has
closed. A checkpoint exception propagates unchanged and is never retried.
Peer and hostname verification remain enabled for system CA, custom CA and
SHA-256 fingerprint modes.

## Authority and persistence

Only stable start/end system configuration plus complete installation-wide
datastore definitions and every matching status scope form an authoritative
commit. Datastore and capacity identifier sets must match exactly. Filesystem
capacity means the datastore filesystem; S3 capacity always means the local
cache and is never presented as remote object-store capacity.

The first partial observation of an unbound connection is diagnostic only. For
an existing binding, a partial observation may upsert directly observed safe
positive state but never infer absence, archive a datastore or delete unrelated
capacity. A directly observed backend change invalidates only that datastore's
old backend-bound capacity, including in a partial run.

The aggregate apply locks and verifies the collector schedule/cycle fence, the
running sync run, connection revision, exact selected endpoint and installation
binding. One transaction writes server metadata, last known server status,
datastores, current capacity, scope results and terminal run counters. A fully
authoritative absence archives the datastore and deletes its current capacity.
Archived datastores reactivate with their stable identifier and first-seen
timestamp. Server status has no absence-delete path.

The additive `Version20260711000200` migration leaves the foundation migration
unchanged. The collector has SELECT/INSERT/UPDATE on PBS servers, server status
and datastores; DELETE exists only on current datastore capacity state.

## Operational diagnostics

Endpoint attempts and terminal runs persist only structured outcome and stable
error codes. Authentication, authorization, TLS/configuration, transport,
unsupported-version, configuration-fence and fanout failures remain
distinguishable without persisting exception messages. Connection revision
drift is reported as `connection_changed`; an invalid multi-endpoint legacy
configuration is rejected before cross-endpoint identity assumptions. Logs and
database diagnostics never contain tokens, Authorization headers, decrypted
secrets, cookies or TLS private material.

For capacity troubleshooting, operators must inspect the scope rows together
with `observed_at`: missing or failed datastore-status scopes cannot authorize
absence, and an S3 capacity row is explicitly `local_cache`. A failed apply
leaves the sync run running for the fenced failure path to terminate and rolls
back every inventory mutation in that transaction.

## Verification

Unit and contract tests cover PBS 3.4, 4.0, 4.1 and 4.2 route sequences, all TLS
modes, redaction, checkpoint retry boundaries, fanout 127/128/129, failover and
identity rules, mapper authority and model invariants. Real MariaDB 11.4 tests
cover migration up/down/up, constraints and grants, revision/endpoint/fence
conflicts, apply-once concurrency, lock-wait expiry rollback, partial safety,
archive/reactivation, backend replacement and atomic finalization.

Backup jobs, backup tasks, scheduling targets and every PBS write request remain
outside this slice.
