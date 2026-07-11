# PVE backup job and task read contract

This document pins the GET-only Proxmox VE 7/8/9 contract used to inventory
configured `vzdump` jobs and observe their tasks. It is intentionally narrower
than the complete Proxmox API. It does not authorize backup execution, task
stopping, task-log access, persistence, collector scheduling, or dependency
injection activation.

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
task IDs are both valid. PVE 7 status responses publish `starttime` as a JSON
number and do not declare `pstart`; the reader accepts only a finite,
integral-valued number and treats `pstart` as optional. PVE 8 and PVE 9 status
responses require integral `starttime` and `pstart` values.

A task is successful only when `status` is exactly `stopped` and `exitstatus`
is exactly `OK`. Running, missing, 404, malformed, unknown, stopped without an
exit status, and every non-`OK` exit status are never successful.

## Bounded pagination and completeness

Active and archived sources have independent offset sequences. Each sequence
starts at `start=0`, advances by its explicit `limit`, and stops at the first
short response. A configured page cap is a hard upper bound; reaching it before
a short page marks the result partial. There is no catch-up or unbounded scan.

Rows are de-duplicated by their complete UPID across pages and sources. An
identical duplicate is retained once. A duplicate UPID with conflicting
content marks the result partial and records an issue. Absence from a bounded
window has no deletion semantics because archived tasks can age out and a task
can move between active and archived sources during the read.

## Constructed fixture matrix

Each PVE major has these seven complete API2 JSON envelopes directly below
`backend/tests/Fixtures/Proxmox/Pve/{major}/`:

| File | Probe |
|---|---|
| `backup-jobs.json` | Minimal job, versioned typed fields, unknown fields, legacy/PVE 9 capability boundaries |
| `node-tasks-active.json` | Active `vzdump` row and unknown-field tolerance |
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
