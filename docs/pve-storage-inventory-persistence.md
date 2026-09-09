# PVE storage inventory persistence

The collector reads PVE core and storage inventory through one endpoint-bound,
GET-only connector and persists both parts in one fenced MariaDB transaction.
There is no second storage writer and no snapshot merge across failover
endpoints.

## Read contract

For PVE 7, 8, and 9 the selected endpoint is read in this order:

1. `GET /version`
2. `GET /access/permissions`
3. `GET /cluster/status`
4. `GET /cluster/resources`
5. `GET /storage`
6. `GET /nodes/{node}/storage?content=backup` for every unique topology node,
   in binary node-name order
7. `GET /storage`

The default node fanout limit is 128 and the supported deployment range is
1 through 1024. Exceeding the limit performs no node-storage requests. Core
inventory remains available, but storage is failed and the connection run is
partial. The collector never truncates the node list and never creates a burst
of parallel PVE requests.

## Authority and partial reads

A run is authoritative only when topology, guests, storage configuration and
every node-storage scope are complete. Only such a run may archive absent
nodes, guests or storages, or delete absent placements and node-storage state.

The first partial observation without an installation binding is diagnostic
only. For an existing binding, a partial run may update positive storage data
only when the definition is identical in the start and end configuration and
all nodes on which that definition is expected produced matching observations.
Absence never deletes or archives data during a partial run. A direct
`unavailable` or `invalid` observation replaces an older measured capacity so
later target evaluation fails closed.

Backup-capable disabled definitions remain visible in inventory with
`disabled=true`, but have no node-state rows and cannot become targets.

## Persistence contract

The single aggregate apply verifies, under row locks:

- collector lease owner, token, expiry and fencing token;
- running collector cycle and inventory sync run;
- unchanged enabled connection revision;
- exactly one selected endpoint, equal to the commit endpoint;
- the existing installation binding or a first fully authoritative binding;
- apply-once finalization.

The same transaction writes core inventory, `pve_storages`,
`pve_node_storage_state`, `pve_storage_pbs_mappings`, scope results and the
terminal sync-run counters. A failure rolls everything back.

Capacity status is persisted as exactly `measured`, `unavailable`, or
`invalid`. Byte values are all non-null only for `measured` and all null for
the other states. Freshness is derived later from `observed_at`; it is not a
stored status. Capacity and `shared` observations remain node-specific.

PBS mappings retain the complete PVE configuration tuple: server, port,
datastore and optional namespace. They are not automatically linked to a PBS
connection by hostname.

The schema change is additive in `Version20260711000100`; the already-published
foundation migration remains unchanged. Integration tests execute the additive
migration up, down, and up again against MariaDB 11.4 and exercise the real
collector database user.

## Deliberately deferred

- PVE backup jobs and task persistence;
- backup targets, policies and capacity freshness gates;
- WebApp storage administration;
- any PVE/PBS write request.

PBS runtime inventory and persistence use a separate product-specific adapter
and store documented in
[`pbs-runtime-inventory-persistence.md`](pbs-runtime-inventory-persistence.md).
