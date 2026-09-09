# PBS namespace and snapshot inventory contract

Status: 12 July 2026

This contract completes the bounded GET-only PBS content slice for PBS 3 and
4. It does not use the backup data protocol and never creates, moves, prunes,
verifies, or deletes PBS content.

## Official API surface

For every selected datastore the collector uses only:

- `GET /access/permissions?path=/datastore/{store}[/{namespace}]`;
- `GET /admin/datastore/{store}/namespace?max-depth=7`;
- `GET /admin/datastore/{store}/snapshots[?ns={namespace}]`.

The root namespace is represented by the empty string. The namespace response
includes its anchor and visible descendants. Snapshot listing is exact to one
namespace and is not recursive. The collector therefore issues one snapshot
read for every visible namespace. It deliberately does not call the redundant
`groups` endpoint; group projections are derived from snapshot identities.

Each snapshot row's required `files` value is a JSON list of file objects, not
a list of strings. Every file object must contain a non-empty, bounded
`filename` string. Hoddmímir extracts only those filenames, rejects malformed
or duplicate filenames and rejects the legacy string-list shape. Additive
per-file metadata such as `size`, `crypt-mode`, or future properties is ignored
and cannot change the normalized filename list.

PBS exposes no pagination for either list. Response body, datastore,
namespace, per-namespace snapshot, and total snapshot limits are explicit
deployment settings. Reaching a limit is visible as a partial scope and never
silently authorizes absence changes.

## ACL and completeness

`Datastore.Audit` is the least-privilege collector permission. A namespace
list scope may complete when the privilege propagates from the selected
datastore anchor and remains present across the read. This proves that the
returned rows were readable, but does not prove namespace absence: PBS filters
inaccessible namespace rows and deeper ACL replacements cannot be excluded by
the anchor probe. Namespace scopes are therefore always positive-only.

An exact snapshot scope requires `Datastore.Audit` before and after its read.
Only that exact complete scope permits absence decisions for snapshots and
derived groups in the observed namespace. `Datastore.Backup` can expose owned
groups, but that result is positive-only and incomplete. The permissions API
accepts paths of at most 128 bytes while a valid namespace can reach 256 bytes.
If an exact namespace path cannot be probed, the collector still performs the
snapshot GET, retains its positive rows, and marks the scope partial.

Deeper ACLs replace inherited ACLs. Production therefore uses a dedicated
collector principal without a narrower `NoAccess` override under a selected
subtree. This deployment rule improves visibility but is not treated as proof
of namespace absence by persistence.

## Parent and child run

The content read starts only after the PBS server/datastore parent has been
applied. `pbs_content_runs` pins the parent, connection revision, collector
cycle/fence, installation binding, and its already selected endpoint. It loads
that exact endpoint and performs no second failover decision.

Namespace and snapshot failures are isolated scopes. They cannot undo the
parent inventory or the external jobs/tasks children. Shutdown and lease
takeover terminalize every running content child before the collector cycle
can finish. A successfully applied PBS parent with zero datastores still runs
and completes an empty content child as a successful no-op.

## Persistence

- `pbs_namespaces` stores the root explicitly and permits a missing parent link
  when ACL filtering hides that parent.
- `pbs_backup_groups` is derived from namespace, backup type, and backup ID.
- `pbs_snapshots` is identified by group plus UTC backup time and stores the
  normalized filename list plus optional manifest evidence without
  interpreting a missing size as zero.

Namespace scopes never archive unseen namespaces. Complete exact
per-namespace snapshot scopes archive unseen snapshots and then empty groups
only inside that observed namespace. Partial, failed, ACL-limited, changed, or
capped scopes are positive-only. Any archived snapshot or group is reactivated
when observed again.

## Version contract

The official PBS 3.4 and PBS 4.2 API viewer schemas are identical for the
three content list endpoints. The v3.4.0 and v4.2.0 source tags retain the same
GET signatures and typed snapshot-list return contract. Sanitized fixtures
cover PBS 3 and PBS 4 with root, nested, minimal, optional, verification,
protected, full and minimal file objects, and additive-future-field forms.
They intentionally contain no legacy string-list `files` response. Release
acceptance still requires live checks against the supported major matrix.

Official sources:

- <https://pbs.proxmox.com/docs/api-viewer/index.html#/admin/datastore/{store}/namespace>
- <https://pbs.proxmox.com/docs-3/api-viewer/index.html#/admin/datastore/{store}/namespace>
- <https://pbs.proxmox.com/docs/storage.html#backup-namespaces>
- <https://pbs.proxmox.com/docs/user-management.html>
- <https://git.proxmox.com/?p=proxmox-backup.git;a=blob;f=src/api2/admin/namespace.rs;hb=v3.4.0>
- <https://git.proxmox.com/?p=proxmox-backup.git;a=blob;f=src/api2/admin/datastore.rs;hb=v3.4.0>
- <https://git.proxmox.com/?p=proxmox-backup.git;a=blob;f=src/api2/admin/namespace.rs;hb=v4.2.0>
- <https://git.proxmox.com/?p=proxmox-backup.git;a=blob;f=src/api2/admin/datastore.rs;hb=v4.2.0>
