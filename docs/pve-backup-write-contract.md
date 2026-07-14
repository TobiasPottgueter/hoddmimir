# Dormant PVE backup-write contract

## Status and activation boundary

This slice defines the typed PVE 7/8/9 contract required by the future backup
worker. It is intentionally dormant:

- the current `BackupWorkerCommand` does not receive or construct a PVE write
  client;
- the collector has no path to the client and remains read-only;
- no Web API endpoint exposes backup execution or task stopping;
- `BACKUP_EXECUTION_ENABLED` still defaults to `false`;
- production activation still requires the deployment acknowledgement
  `ENABLE_PRODUCTION_BACKUPS` and a later application-level wiring change.

The infrastructure namespace `Infrastructure/Proxmox/PveBackup` must remain
excluded from Symfony's default service discovery until that activation work
is implemented. This prevents constructor autowiring from turning a dormant
transport into a reachable runtime service.

## Operations

| Purpose | Method and route | Retry contract |
|---|---|---|
| Submit one guest backup | `POST /nodes/{node}/vzdump` | Exactly one physical request; never automatically retried |
| Read task status | `GET /nodes/{node}/tasks/{upid}/status` | Bounded read-only retry policy |
| Read task log | `GET /nodes/{node}/tasks/{upid}/log?start={start}&limit={limit}` | Bounded read-only retry policy |
| Request task stop | `DELETE /nodes/{node}/tasks/{upid}` | Exactly one physical request; never automatically retried |

A timeout, disconnect, generic post-dispatch exception, oversized response,
invalid JSON/envelope, null data, malformed UPID, or node/VMID identity mismatch
during `POST` yields the typed `ambiguous` submission outcome. Once the HTTP
write has been dispatched, only a definitive non-2xx rejection or a valid,
identity-matching UPID can remove that ambiguity. The client never invents a
UPID. A future worker must persist the ambiguity and reconcile it against
observed task inventory; it must not submit the same backup again merely
because the response was lost or unusable. The same conservative ambiguity
applies to an unusable response while stopping a task.

Credential decryption, endpoint URL construction, version validation, and TLS
client construction happen before dispatch and remain definitive typed
failures. They do not turn into an ambiguous submission because no write
request has been handed to the HTTP client.

Definitive non-2xx responses are typed failures. Authentication,
authorization, rate limiting, temporary remote failures, missing resources,
invalid envelopes, and invalid response shapes remain distinguishable without
including remote response bodies in exception messages.

## Version-aware submission payload

The application builder emits only guest-scoped fields:

- `vmid`;
- `storage`;
- `mode` (`snapshot`, `suspend`, or `stop`);
- `compress` (`0`, `gzip`, `lzo`, or `zstd`);
- optionally `prune-backups`;
- optionally legacy `maxfiles` on PVE 7 or 8.

`prune-backups` and `maxfiles` are mutually exclusive. `maxfiles` is rejected
for PVE 9 and must never be emitted there. Both deletion-capable fields require
an explicit retention-execution approval and are permitted only for non-PBS
targets such as local, directory, NFS, or CIFS storage. For a PVE storage of
type `pbs`, retention is owned exclusively by PBS prune jobs and the submission
builder must emit neither field, even when an older policy snapshot contains an
approval or the PBS mapping is missing or inconsistent. Root-only scheduled-job
controls, including mail, script, temporary-directory, removal, node-selection,
and bandwidth-policy fields, are not part of this contract.

## Credentials, TLS, and sensitive data

The client accepts only a credential encrypted for
`pve_backup_token`. Collector credentials fail construction. Plaintext exists
only inside the scoped authorization callback and is neither stored in a DTO
nor exposed through debug or serialization paths.

The shared native-client factory exclusively owns TLS trust. System CA and
custom CA verify peer and hostname; the explicitly selected fingerprint mode
instead requires the exact SHA-256 leaf digest and fails before transmitting
HTTP headers on a mismatch. Backup requests cannot override this policy and no
generic insecure mode exists. Every request disables redirects, uses bounded
timeouts, streams the response, and aborts payload processing above 8 MiB.
Tokens, passwords, authorization headers, cookies, CSRF values, response bodies,
and decrypted secrets are never included in stable failures.

## Compatibility evidence

Sanitized fixtures under `backend/tests/Fixtures/Proxmox/Pve/{7,8,9}` cover the
submission request, returned UPID, task-log page, and successful task-stop
envelope. Contract tests prove the PVE 9 `maxfiles` absence, the strict payload
allowlist, UPID identity matching, ordered task-log decoding, and null stop
response. Unit tests separately prove the single-attempt write behavior,
ambiguous outcomes, factory-owned TLS trust without request overrides, failure
typing, and current worker isolation.
