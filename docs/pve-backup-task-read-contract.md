# PVE backup job and task read contract

This document pins the GET-only Proxmox VE 7/8/9 contract used to inventory
configured `vzdump` jobs and observe their tasks. It is intentionally narrower
than the complete Proxmox API. It does not authorize backup execution, task
stopping, or task-log access. The bounded reader is active after every usable
PVE core/storage apply: two fenced monitoring child runs consume one combined
GET-only read from the exact endpoint selected by the parent, without a second
failover decision, and persist only positive job/task observations. Schema,
collector orchestration, and dependency-injection wiring are part of this
activated monitoring slice.

The fixtures described here are hand-constructed and sanitized. They are not
captures from a live installation and are not release evidence. A live,
read-only compatibility check against the latest patched PVE 7, 8 and 9
releases remains a separate release gate.

Retrieval and source-review date: **2026-07-11**.

## Exact endpoint allow-list

Only these requests belong to this slice:

| Purpose | Exact request |
|---|---|
| List configured backup jobs | `GET /cluster/backup` |
| Read active `vzdump` tasks for one node | `GET /nodes/{node}/tasks?source=active&typefilter=vzdump&start={start}&limit={limit}` |
| Read archived `vzdump` tasks for one node | `GET /nodes/{node}/tasks?source=archive&typefilter=vzdump&start={start}&limit={limit}&since={epoch}&until={epoch}` |
| Read one validated task status | `GET /nodes/{node}/tasks/{validated-upid}/status` |

`source`, `typefilter`, `start`, and `limit` are always explicit. `since` and
`until` are mandatory bounded UNIX-epoch seconds for archive queries, with
`since <= until`. Active queries never carry time bounds. Active and archived tasks are queried separately;
the adapter never uses `source=all`.

The following requests are explicitly outside the allow-list:

- `GET /cluster/tasks`;
- `GET /nodes/{node}/tasks/{upid}/log` and every other task-log request;
- `DELETE /nodes/{node}/tasks/{upid}` or any other stop operation;
- `POST /nodes/{node}/vzdump` and every other backup-start request;
- every other `POST`, `PUT`, `PATCH`, or `DELETE` request.

The validated UPID is encoded as one path segment only after parsing. It is
never accepted as an arbitrary raw path fragment.

The inventory reader never calls the status endpoint. That targeted adapter is
reserved for later Backup Worker reconciliation of one already known
Hoddmímir-owned UPID. Inventory fan-out is limited to job and task-list GETs.

## Pinned official sources

The reviewed `pve-manager` revisions and source files are:

| PVE major | Pinned revision | `PVE/API2/Backup.pm` | `PVE/API2/Tasks.pm` | `PVE/VZDump.pm` |
|---|---|---|---|---|
| 7 | [`5d6e3351c9405a769e0b2686bcd45dedfd6da9db`](https://git.proxmox.com/?p=pve-manager.git;a=commit;h=5d6e3351c9405a769e0b2686bcd45dedfd6da9db) | [source](https://git.proxmox.com/?p=pve-manager.git;a=blob;f=PVE/API2/Backup.pm;hb=5d6e3351c9405a769e0b2686bcd45dedfd6da9db) | [source](https://git.proxmox.com/?p=pve-manager.git;a=blob;f=PVE/API2/Tasks.pm;hb=5d6e3351c9405a769e0b2686bcd45dedfd6da9db) | [source](https://git.proxmox.com/?p=pve-manager.git;a=blob;f=PVE/VZDump.pm;hb=5d6e3351c9405a769e0b2686bcd45dedfd6da9db) |
| 8 | [`d38a429d06033d58b2231af6f332319a1d26b028`](https://git.proxmox.com/?p=pve-manager.git;a=commit;h=d38a429d06033d58b2231af6f332319a1d26b028) | [source](https://git.proxmox.com/?p=pve-manager.git;a=blob;f=PVE/API2/Backup.pm;hb=d38a429d06033d58b2231af6f332319a1d26b028) | [source](https://git.proxmox.com/?p=pve-manager.git;a=blob;f=PVE/API2/Tasks.pm;hb=d38a429d06033d58b2231af6f332319a1d26b028) | [source](https://git.proxmox.com/?p=pve-manager.git;a=blob;f=PVE/VZDump.pm;hb=d38a429d06033d58b2231af6f332319a1d26b028) |
| 9 | [`b0b650c16c520eaaf62db54810dcf8d66bc4249f`](https://git.proxmox.com/?p=pve-manager.git;a=commit;h=b0b650c16c520eaaf62db54810dcf8d66bc4249f) | [source](https://git.proxmox.com/?p=pve-manager.git;a=blob;f=PVE/API2/Backup.pm;hb=b0b650c16c520eaaf62db54810dcf8d66bc4249f) | [source](https://git.proxmox.com/?p=pve-manager.git;a=blob;f=PVE/API2/Tasks.pm;hb=b0b650c16c520eaaf62db54810dcf8d66bc4249f) | [source](https://git.proxmox.com/?p=pve-manager.git;a=blob;f=PVE/VZDump.pm;hb=b0b650c16c520eaaf62db54810dcf8d66bc4249f) |

UPID parsing was cross-checked against these pinned `pve-common` sources:

- PVE 7 line: [`c89e056e1dbdb91d5b98651293355a87f5548b43`](https://git.proxmox.com/?p=pve-common.git;a=commit;h=c89e056e1dbdb91d5b98651293355a87f5548b43),
  [`src/PVE/Tools.pm`](https://git.proxmox.com/?p=pve-common.git;a=blob;f=src/PVE/Tools.pm;hb=c89e056e1dbdb91d5b98651293355a87f5548b43);
- current split-out implementation:
  [`74c2506d053d53f50488975414b46ac17d79e79e`](https://git.proxmox.com/?p=pve-common.git;a=commit;h=74c2506d053d53f50488975414b46ac17d79e79e),
  [`src/PVE/UPID.pm`](https://git.proxmox.com/?p=pve-common.git;a=blob;f=src/PVE/UPID.pm;hb=74c2506d053d53f50488975414b46ac17d79e79e).

All hashes above are full commit IDs resolved from the official Proxmox Git
server. The links are provenance references only; the regular test suite has
no network dependency.

## Versioned job contract

For PVE 7 and PVE 8, the official `GET /cluster/backup` return schema declares
only `id` on each item, even though the implementation returns the configured
job properties. Therefore only a non-empty visible-ASCII `id` is required.
Other well-typed fields are best-effort observations and unknown fields never
enable capabilities. The fixtures cover legacy `maxfiles` plus both observed
`prune-backups` forms: a property string and a parsed object.

PVE 9 publishes a larger return schema assembled from the VZDump common
properties. Hoddmímir deliberately exposes a selected typed contract only:
`id`, raw `schedule`, `enabled`, `repeat-missed`, `comment`, `next-run`, `node`,
`storage`, `vmid`, `all`, `mode`, `compress`, and object-shaped
`prune-backups`. `schedule` is stored as `rawSchedule`; its calendar semantics
remain owned by PVE and are not claimed as locally validated. Other optional
PVE fields, including `performance` and `fleecing`, remain ignored forward-
compatible observations and do not affect completeness. A PVE 9
`maxfiles` observation is an unsupported-field probe: it must be reported or
ignored according to the reader policy, but it must never be interpreted as a
PVE 9 capability. That invalid capability probe is programmatic; the primary
PVE 9 fixture is a coherent, complete positive response.

## UPID and task-row contract

The accepted cross-version UPID form is:

```text
UPID:<node>:<pid-8hex>:<pstart-8-or-9hex>:<starttime-8hex>:<type>:<id>:<user>:
```

The parser applies the official anchored grammar: the node starts and ends in
an ASCII alphanumeric character and may contain internal hyphens; `pid` is
exactly eight hexadecimal characters; `pstart` is eight or nine hexadecimal
characters; `starttime` is exactly eight hexadecimal characters; type and user
are non-empty; id may be empty; colon, whitespace, and slash are rejected in
the final three fields. Hexadecimal values are decoded without truncation.

For this slice, the decoded type must be exactly `vzdump`, the decoded node must
equal the route node, and the decoded `pid`, `pstart`, `starttime`, `id`, and
user must agree with the task row or status document. A malformed or
inconsistent UPID makes the observation partial; it is never used to construct
a status path.

Task-list rows require `upid`, `node`, `pid`, `pstart`, `starttime`, `type`,
`id`, and `user`. `endtime` and `status` are optional. Empty and numeric-string
task IDs are both valid. For a non-token task, `user` must equal the complete
UPID principal and `tokenid` must be absent. Token-authenticated active rows can
likewise expose the complete `{user}@{realm}!{tokenid}` principal directly in
`user` without a separate `tokenid`; that form is accepted only by an exact
comparison with the UPID principal. Archived token-authenticated rows can
instead expose the token owner in `user` and the token name separately in
`tokenid`. The reader reconstructs the same canonical principal before
comparing it with the UPID. An owner-only `user` without `tokenid`, or a
malformed, overlong, unexpected, or mismatched `tokenid`, makes the row partial.
The split task-list representation is covered for PVE 7, 8, and 9 by sanitized
compatibility fixtures.

PVE 7 status responses publish `starttime` as a JSON number and do not declare
`pstart`; the reader accepts only a finite, integral-valued number and treats
`pstart` as optional. PVE 8 and PVE 9 status responses require integral
`starttime` and `pstart` values.

A task is successful only when `status` is exactly `stopped` and `exitstatus`
is exactly `OK`. Running, missing, 404, malformed, unknown, stopped without an
exit status, and every non-`OK` exit status are never successful.

## Runtime read order, fixed window, and topology authority

The backup inventory is appended to the existing composite read on the same
selected endpoint, client, and session. Within this slice its request order is
deterministic:

1. `GET /cluster/backup`;
2. for each binary-sorted authoritative topology node, read the active stream;
3. read the archived stream for that same node;
4. repeat steps 2 and 3 for the next node.

The reader accepts one typed immutable archive window from the monitoring
window planner. This permits a persisted authoritative cursor plus overlap to
choose `since`/`until`; the reader never advances that cursor itself. For an
initial/default read only, it derives one window exactly once from an injected
clock before the first remote request: `until` is that instant's UNIX epoch in
UTC and `since` is `max(0, until - archive-window-seconds)`. Every archive page
and node in the read receives those same immutable inclusive bounds. A supplied
window wider than the configured bound is rejected before remote I/O.

The typed window also carries a `historyGap` flag. The planner sets it when the
persisted cursor is older than the bounded catch-up horizon. A successfully
read recent window may still be complete while retaining that explicit gap;
only the integration layer advances a node's cursor, and only after that
node's archive stream is complete.

The supplied nodes must be valid and unique. Invalid or duplicate nodes, or a
node count above the configured hard limit, fail closed before every task-list
GET. The reader sorts valid nodes itself for deterministic ordering; it never
discovers extra nodes from task rows or resource observations.

## Bounded pagination and completeness

Active and archived sources have independent offset sequences. Each sequence
starts at `start=0`, advances by its explicit `limit`, and stops at the first
short response. A configured page cap is a hard upper bound; reaching it before
a short page marks the stream partial. There is no catch-up or unbounded scan.

The production defaults and hard maxima for one connection/read are:

| Bound | Default |
|---|---:|
| topology nodes | 128 |
| page size | 100 |
| active pages per node | 2 |
| archive pages per node | 10 |
| task-list GETs in total | 512 |
| raw task rows in total | 25,000 |
| distinct UPIDs in total | 25,000 |
| archive window | 86,400 seconds |

The reader reserves enough raw-row capacity for a complete next page before
issuing that GET. Request, raw-row, or distinct-UPID exhaustion marks the
current stream partial or not-scanned and prevents every remaining task-list
request. Per-node/source cursor outcomes retain request and raw-row counts so
the persistence slice can map complete, partial, failed, and
`not_scanned_limit` scopes without inference. Safe positive observations read
before a limit remain available.

Rows are de-duplicated by their complete UPID across pages and sources. The raw
UPID remains bounded to 1,024 bytes. An identical duplicate is retained once.
A later observation may monotonically enrich `RUNNING` with a final status
and/or an end time. A later `RUNNING` or missing field never regresses an
already observed final status or end time. `seenActive` and `seenArchive`
evidence is accumulated with a monotone OR so cross-source de-duplication never
loses provenance. The compatibility `source` value becomes archive when either
observation came from the archive stream.

Conflicting non-null end times or two different final statuses mark the result
partial, record `conflicting_duplicate_task`, and retain the first consistent
state. End times before the UPID start time are invalid optional observations
and are not retained. Remote list status is non-empty visible ASCII and bounded
to 255 bytes, matching the persistence boundary.

Absence from a bounded window has no deletion semantics because archived tasks
can age out and a task can move between active and archived sources during the
read. The reader never claims that a bounded window is a complete historical
record.

## Constructed fixture matrix

Each PVE major has these nine complete API2 JSON envelopes directly below
`backend/tests/Fixtures/Proxmox/Pve/{major}/`:

| File | Probe |
|---|---|
| `backup-jobs.json` | Minimal job, versioned typed fields, unknown fields, legacy/PVE 9 capability boundaries |
| `node-tasks-active.json` | Active `vzdump` row and unknown-field tolerance |
| `node-tasks-token-active.json` | API-token task row with split `user`/`tokenid` identity and canonical token principal in the UPID |
| `node-tasks-token-principal-active.json` | API-token task row with the complete token principal in `user` and no separate `tokenid` |
| `node-tasks-archive-page-0.json` | First archived page, numeric and empty IDs |
| `node-tasks-archive-page-next.json` | Next archived page and repeated UPID |
| `task-status-running.json` | Running is not success; PVE 7 integral JSON number and optional `pstart` |
| `task-status-stopped-ok.json` | The only successful lifecycle predicate |
| `task-status-stopped-error.json` | Stopped with non-`OK` exit status is not success |

The same complete row is deliberately repeated across the active fixture and
both archive pages for each major. This is a deterministic de-duplication
probe, not a claim that a stable live API snapshot normally contains the same
task in all three positions. PVE 9 additionally uses a valid nine-hex-digit
`pstart` to protect against 32-bit truncation.

All node names, users, job IDs, VMIDs, storage names, timestamps, and numeric
measurements are synthetic. No endpoint, token, password, cookie, CSRF value,
certificate, fingerprint, TOTP value, or decrypted secret from a real system
was used.
