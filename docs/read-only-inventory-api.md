# Read-only inventory API v1

The authoritative machine-readable contract is
[`openapi-v1.json`](openapi-v1.json). This Phase 3 slice is intentionally
GET-only and is served below `/api/v1`; it never exposes endpoint TLS material,
credential identities, encrypted secret envelopes, lease owners, or lease
tokens. A PVE storage's non-secret PBS mapping is inventory data and may contain
its configured server name, port, datastore, and namespace.

Until authentication and RBAC arrive in Phase 6, the API has a deliberately
hard deployment boundary: the WebApp port is bound to `127.0.0.1` in both the
development Compose file and the Ansible-generated production Compose file.
It must not be exposed through a reverse proxy or on an external interface.

## Resources

- `GET /api/v1/inventory/overview` returns bounded current counts and the most
  recent persisted inventory timestamp.
- `GET /api/v1/inventory/resources` returns one discriminated resource kind per
  request: PVE cluster, node, QEMU/LXC guest, storage, PBS server, datastore,
  namespace, backup group, or snapshot. `limit` defaults to 50 and is bounded
  to 100. Continuation uses an opaque `cursor` bound to the resource kind and
  active filters. Unknown, repeated-array, malformed, incompatible, stale, and
  cross-query cursors fail with a generic `400 invalid_query`.
- `GET /api/v1/collector/status` returns the persisted collector schedule and
  latest heartbeat. Freshness is evaluated against MariaDB UTC time. An active
  lease is represented only as a boolean and expiry timestamp.
- `GET /api/v1/collector/runs` returns bounded latest inventory-sync runs using
  a stable `(startedAt,id)` cursor.
- `GET /api/v1/collector/scopes?runId=...` returns bounded scope outcomes for
  exactly one run, including child PBS-content and monitoring outcomes, using
  a stable `(scopeType,scopeKey)` cursor.

Every timestamp is serialized in UTC with a trailing `Z`. Every inventory
resource carries `inventoryState`, `firstSeenAt`, `lastSeenAt`, `archivedAt`,
and the nullable timestamp of its current placement, mapping, status, or
capacity observation. A null current-state timestamp means that no such
measurement is persisted; the API does not invent freshness or PBS content
data.

## Persistence boundary

Application DTOs and query interfaces are independent of Symfony and Doctrine.
The DBAL adapter reads only the allowlisted inventory tables. Additive migration
`Version20260712000200` grants the `hoddmimir_web` user SELECT on that exact
surface with column-level SELECT grants; integration tests prove both the
positive reads and denial of credentials, lease/cycle/fencing fields, raw PBS
content metadata, and all mutations.

`php tools/check-openapi.php ../docs/openapi-v1.json` is part of
`composer verify`. It compares the five `/api/v1` operations with Symfony's
actual router, rejects unversioned or write operations, and requires exact,
closed successful response schemas.
