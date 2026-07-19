# External Jobs and Task Monitoring Persistence

Status: 12 July 2026

This document fixes the P0 persistence contract for collector-side PVE/PBS
job and task observations. It complements the product read contracts; it does
not authorize backup execution.

## Parent and child runs

`inventory_sync_runs` remains the authoritative core/storage parent. Its
successful apply happens exactly once before monitoring starts. A diagnostic
first read without a previously verified installation binding never creates
monitoring rows.

Every eligible parent can create exactly two `proxmox_monitoring_runs`:

- `external_jobs`;
- `observed_tasks`.

Each child pins the parent run, connection revision, installation binding,
selected endpoint, collector cycle token, and fencing token. Monitoring never
performs endpoint failover. Both child headers are opened before one combined
product read; jobs and tasks are then mapped from that same response set. A
failure in one child rolls back and terminalizes only that child. It cannot
undo the applied parent or suppress persistence of the other child.

Graceful shutdown fails every still-running child with
`collector_shutdown_requested`. Lease takeover fails remaining children with
`collector_lease_lost` before abandoning the parent cycle. Connection or
binding drift is fail-closed and cannot leave an owned child running.

## Read-only API surface

PVE monitoring uses only:

- `GET /cluster/backup`;
- bounded `GET /nodes/{node}/tasks` active and archive list calls for the
  topology nodes selected by the parent snapshot.

PBS monitoring uses only:

- `GET /access/permissions?path=/system/tasks`;
- `GET /access/permissions?path=/datastore`;
- `GET /access/permissions?path=/remote`;
- `GET /admin/prune`;
- `GET /admin/sync?sync-direction=all`;
- `GET /admin/verify`;
- bounded `GET /nodes/localhost/tasks` running and history list calls for the
  local allowlisted backup/prune/sync/verification families. No PBS response,
  UPID, inventory snapshot, or caller can select a different request node.

Job completeness is evaluated per family: prune and verify require propagated
`Datastore.Audit`; sync additionally requires propagated `Remote.Audit`.
Missing `Sys.Audit` affects task scopes only, while missing `Remote.Audit`
leaves only the sync-job scope partial.
Selected per-datastore privileges still permit positive observations, but P0
does not fan out permission checks per store. Without propagation at the
`/datastore` root, affected job scopes remain partial and no absence decision
is made.

The collector never calls task status, log, stop, tape, delete, or another
write endpoint. It never retries a write because it performs no write against
PVE/PBS.

## Positive-only projections

External jobs and observed tasks are positive observations. Incomplete,
failed, truncated, ACL-insufficient, or history-gap reads never authorize
deletion, archival, or absence decisions. Repeated observations enrich rows
monotonically:

- task pass provenance is ORed;
- terminal task evidence never regresses to running or unknown;
- a precise PBS reported node wins over `localhost` deterministically;
- PBS last-run job evidence is ordered by UPID start time, while an optional
  end time only enriches the same UPID;
- null and older responses cannot erase newer job or task evidence.

Raw UPIDs remain collision-checked against their SHA-256 identity. PBS worker
types are enforced by the DTO, commit boundary, store, and database constraint.

## Scope evidence and cursors

Every physical stream has its own scope result, including product, node/key,
source, filter family, bounded window, counters, truncation/history-gap state,
and sanitized error code.

A PVE archive cursor advances only for a complete, untruncated, gap-free node
archive scope. PBS task scopes and cursors always use the canonical local key
`localhost`; an optional response `reportedNode` and the UPID node remain
persisted evidence only. A PBS cursor advances only when all four history
families for that canonical key and identical window are complete,
untruncated, gap-free, and backed by `Sys.Audit` task-read evidence. Missing
cursor keys yield a fresh bounded window. Stale cursors are clipped to the
configured maximum and record a history gap; no catch-up burst is attempted.

## Deployment bounds

The data worker exposes bounded PVE node/page/request/raw/distinct/window
limits, PBS page/stream-row/job/window limits, and a history overlap. Defaults
are documented in `.env.example`; the PHP value objects and Ansible preflight
apply the same upper bounds.

## Verification

Run from the repository root:

```sh
make backend-test
make backend-integration
make backend-coverage
docker compose config
make lint
make syntax
make deployment-test
```
