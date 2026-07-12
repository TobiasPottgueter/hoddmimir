# PBS tasks and jobs read contract

## Scope

This contract covers the positive-only, GET-only inventory slice for Proxmox
Backup Server 3 and 4. It reads configured prune, sync, and verification jobs
and bounded task-list evidence. It does not start, stop, retry, or mutate a
task or job. Task status and log endpoints are deliberately outside this
slice.

The supported fixture matrix is PBS 3.4, 4.0, 4.1, and 4.2. The pinned
upstream sources are:

- PBS 3 API viewer 3.4.4, SHA-256
  `2ba388ace5bb8580da297a2e78d1ef636fe20cec80ed5e577b5e1efe9905f267`;
- PBS 3 source baseline 3.4.0,
  `36ef1b01f76a452abb3aebea9f9c9e0fdc339c33`;
- PBS 4 API viewer 4.2.2, SHA-256
  `c62063edd60fbc288c376ec1a71efd1b16239cdf9ff88cb8f873ae1a7410f64a`;
- PBS 4 source baseline 4.2.0,
  `035c449897fafc228c8bbf3a5b5ba38564478ac7`;
- task-status `endtime` addition,
  `2683bca432f934d032aa709ed9c690302ab564d5` (2026-05-04).

The last pin is compatibility evidence only: this slice does not call the
task-status endpoint, and `endtime` remains optional throughout the model.

## Fixed endpoints

Only these fixed GET descriptors are added by the slice:

- `/api2/json/admin/prune`;
- `/api2/json/admin/sync?sync-direction=all`;
- `/api2/json/admin/verify`;
- `/api2/json/nodes/{validated-node}/tasks`;
- `/api2/json/access/permissions?path=%2Fsystem%2Ftasks`;
- `/api2/json/access/permissions?path=%2Fdatastore`;
- `/api2/json/access/permissions?path=%2Fremote`.

Job list bodies are capped at 4 MiB; task-list bodies are capped at 2 MiB.
Node names and permission paths are validated locally before a request is
constructed. The sync-job request always includes `sync-direction=all` so
pull and push jobs are visible.

## Job model

The reader accepts an array envelope with a lowercase 64-character digest and
normalizes these allowlisted fields into typed observations:

- kind and external job ID;
- local datastore and optional namespace;
- optional schedule and the normalized disabled flag;
- for sync jobs only: direction, optional remote name, remote datastore, and
  optional remote namespace;
- optional last-run UPID/state/end time and optional next-run time.

Missing `sync-direction` is the PBS-compatible `pull` default. An empty
namespace means the root namespace. A last-run UPID and state must appear
together, while `last-run-endtime` remains optional. Unknown additive fields,
including PBS 4 tuning and encryption-key fields, are ignored. Persistence
serializes only the normalized allowlist above into canonical `details_json`
and derives `config_hash` from that canonical JSON; unknown response fields
cannot change the hash.

The configured maximum is 4,096 jobs per kind. Duplicate `(kind, id)` entries,
invalid identifiers, contradictory variants, malformed digests, and over-limit
responses fail closed.

## Task model and allowlist

The raw PBS UPID is retained up to 2,048 bytes and parsed into node, PID,
process-start, task ID, start time, worker type, optional worker ID, and auth
ID. The node reported in the list row is separate evidence: `localhost` or a
different valid node can be reported without contradicting the node embedded
in the UPID.

Only this exact local worker-type allowlist may enter the positive snapshot:

`backup`, `prune`, `prunejob`, `syncjob`, `verificationjob`, `verify`,
`verify_group`, and `verify_snapshot`.

The server-side `typefilter` is a bandwidth hint, never an authority. The four
filter families are requested as `backup`, `prune`, `syncjob`, and `verif`,
then every returned row is checked against the local allowlist. For every
family the scanner performs:

- a running pass with `running=1`;
- a history pass with the same fixed `since`/`until` window.

The task row must agree with its UPID for PID, process start, start time,
worker type, worker ID, and auth ID. Running rows omit `status`; terminal list
evidence supplies `status`, while `endtime` remains independently optional.
An `endtime` without terminal status is rejected. Remote status is normalized
to `ok`, `warning`, `error`, or `unknown` and is limited to 255 printable
ASCII bytes. Every observation explicitly records whether it was seen in the
running pass, history pass, or both. Running/terminal overlap is merged
monotonically by raw UPID and ORs both provenance flags.

## Bounds, pagination, and stream evidence

Production defaults are:

- page size: 256 (valid range 1..1,000);
- maximum pages per family/pass stream: 16 (valid range 1..64);
- maximum raw rows per stream: 4,096 (maximum 65,536 and never below page
  size);
- maximum jobs per kind: 4,096 (valid range 1..65,536);
- maximum history-window width: 86,400 seconds. A deployment may configure a
  smaller width, never a larger one.

Disallowlisted worker types never become task DTOs. Pagination nevertheless
advances by the raw row count, not by the allowlisted item count, and repeated
page detection hashes the ordered raw UPID page before filtering.
The optional envelope `total` is used when present; without it, only a full
page implies another request. Page fingerprints detect a repeated page.
Empty pages before a declared total produce `no_progress`. Page, row,
repeated-page, and no-progress caps retain already observed positive items but
mark the stream partial and truncated; they never trigger a catch-up or an
unbounded request.

A read failure is isolated to its family/pass stream. Failure before the first
successful page yields `failed`; failure after positive progress yields
`partial`, preserving already validated observations. Other streams continue.
Conflicting terminal evidence for the same raw UPID is retained
deterministically, reported as `conflicting_task_evidence`, and cannot abort
the aggregate scan.

Every family/pass stream produces deterministic evidence containing status,
pages read, raw rows read, allowlisted items seen, truncation, history-gap,
and an optional issue code. A partial history pass has `history_gap=true`; a
running pass never does. The fixed window is retained on the aggregate
snapshot.

## ACL evidence and absence semantics

Broad visibility diagnostics require evidence of:

- `Sys.Audit` at `/system/tasks`;
- propagated `Datastore.Audit` at `/datastore`;
- propagated `Remote.Audit` at `/remote`.

Completeness is narrower than the combined diagnostic: task scopes require
`Sys.Audit`; prune and verify job scopes require propagated
`Datastore.Audit`; the sync-job scope additionally requires propagated
`Remote.Audit`. Missing `Remote.Audit` therefore does not downgrade prune or
verify observations.

Per-datastore rights remain sufficient for positive datastore visibility, but
they are not proof of complete job-list visibility. P0 deliberately performs
no per-store permission fanout: without propagated `Datastore.Audit` at the
`/datastore` root, visible jobs are still persisted positive-only while the
affected prune, verify, and sync scopes remain partial. Complete sync evidence
also requires propagated `Remote.Audit` at `/remote`.

Missing evidence is reported using stable issue codes. Even complete task
pagination and all three ACL checks do not authorize absence decisions in
this P0 slice. The snapshot and ACL evidence therefore always report
`permitsAbsenceDecisions() === false`; missing items must never be interpreted
as deleted, disabled, or absent.

## Test contract

Sanitized, synthetic fixtures live under
`backend/tests/Fixtures/Proxmox/Pbs/{3,4.0,4.1,4.2}`. They cover pull and push
sync jobs, root and nested namespaces, disabled and optional last-run fields,
running and terminal tasks, differing reported/UPID nodes, nine-digit process
start hex, a foreign `tape-backup` row, optional totals, and additive future
fields. Unit and contract tests perform no network access.
