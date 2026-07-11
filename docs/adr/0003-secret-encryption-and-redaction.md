# ADR 0003: Secret encryption and structured redaction

- Status: Accepted
- Date: 2026-07-10

## Context

Hoddmímir stores newly configured PVE and PBS API credentials. These credentials must be available to long-running workers without appearing as plaintext in MariaDB, API responses, logs, exception messages, container images, or environment dumps. The clean V2 database has no legacy ciphertext compatibility requirement.

The collector and backup worker use distinct Proxmox identities. A credential must therefore also be cryptographically bound to its purpose and database identity so that copying an encrypted value between rows or worker purposes cannot produce a valid secret.

## Decision

### Application boundary

The Domain contains credential identifiers, purposes, and non-secret metadata only. `Application/Security` owns opaque `PlaintextSecret`, `EncryptedSecret`, and `SecretContext` value objects plus the `SecretCipher` port. Sodium, key files, JSON parsing, Docker, and log processors remain Infrastructure concerns.

Plaintext access is explicit and callback-scoped. Secret value objects are not stringable or JSON-serializable, redact debug output, and reject native PHP serialization. These protections reduce accidental disclosure; callers must still avoid passing revealed strings to loggers or exceptions.

### Keyring

Production receives a Docker Secret containing one JSON keyring:

```json
{
  "format": 1,
  "revision": 1,
  "primaryKeyId": "k2026_07",
  "keys": [
    {
      "id": "k2026_07",
      "material": "64 lowercase hexadecimal characters"
    }
  ]
}
```

Each material value decodes to exactly 32 random bytes. Key IDs match `[a-z0-9][a-z0-9_-]{0,31}`. The keyring contains between one and sixteen entries, rejects duplicate IDs and duplicate material, and requires the primary ID to exist. A separately supplied non-secret revision must match the document. The keyring is loaded and validated once at process startup; invalid configuration fails closed.

### Ciphertext format

Version 1 uses Sodium XChaCha20-Poly1305-IETF with a fresh 24-byte nonce. A purpose-specific AEAD key is derived from the selected 32-byte master key with `sodium_crypto_kdf_derive_from_key`, context `HODDSEC1`, and these stable subkey IDs:

1. PVE collector token;
2. PBS collector token;
3. PVE backup token.

The persisted ASCII envelope is:

```text
hoddmimir-secret:1:<key-id>:<nonce-base64url>:<ciphertext-and-tag-base64url>
```

Base64url values are unpadded and must decode canonically. Version 1 accepts at most 4,096 plaintext bytes and an 8,192-byte envelope.

Associated authenticated data is the NUL-separated sequence:

```text
hoddmimir-secret, v1, key-id, credential-purpose, credential-id, token-secret
```

The credential ID is allocated before encryption and remains stable. Authentication therefore fails if an envelope is copied to another credential, purpose, field, version, or key ID.

### Write-only semantics

Credential creation and explicit replacement require a non-null, non-empty secret. Empty values, ASCII control characters, and values above 4,096 bytes are rejected without echoing the rejected value. General connection updates do not accept a secret field. Removal is an explicit use case rather than an empty or null update.

API responses expose only credential metadata and a `configured` flag. They never expose plaintext, ciphertext, a masked placeholder that could be submitted again, or an encryption key ID. Request and response schemas are separate; secret request fields are additionally marked `writeOnly`.

### Errors and redaction

Configuration and cipher exceptions use fixed safe messages and bounded reason enums. They do not include key material, plaintext, ciphertext, nonces, file contents, rejected inputs, or wrapped exceptions that could reintroduce those values. Presentation code maps them to stable problem codes and never returns raw exception messages.

All operational logs are structured. Before formatting, a central redactor recursively handles sensitive keys, authentication and cookie headers, PVE/PBS authentication schemes, secret-bearing query parameters, secret value objects, and throwable messages. Unknown objects are represented by class name and are never converted with `__toString()`.

Redaction is defense in depth, not permission to log arbitrary requests. HTTP bodies, raw headers, decrypted values, and raw external response bodies are never passed to logging. Proxmox transport events use an allowlisted context containing identifiers, method, route template, status, duration, and retry classification only.

### Rotation

Rotation is additive:

1. add a new key under a new ID, make it primary, increment the keyring revision, and restart all application containers;
2. new writes immediately use the new primary while old envelopes remain decryptable;
3. re-encrypt old envelopes in bounded transactions with optimistic locking;
4. verify no active row references the old ID;
5. retain the old key for the lifetime of database backups that require it, then remove it through an explicit maintenance procedure.

A normal deployment rejects changed material under an existing ID and every historical-key removal. There is intentionally no acknowledgement or maintenance variable that can bypass this guard. Key removal is currently unsupported and will require a future, separate maintenance command that proves all active credentials were rewrapped, verifies key usage directly in MariaDB, and records a successful database backup-and-restore test before it may change the installed keyring. Database backups and the keyring are protected and restored through separate secret-management procedures.

Deployment recovery preserves decryptability across the candidate-start boundary. Before candidate application services may have started, rollback restores the installed keyring byte-for-byte. After that boundary, recovery of an existing structured installation restores the old revision and old primary but retains the additive union of old and candidate keys; the old image therefore continues writing with its old primary while it can decrypt candidate envelopes. A failed first deployment retains the structured candidate keyring for the same reason, even when an offline raw-key seed existed. These retained keys must not be removed. A raw legacy key next to an existing Compose installation is never upgraded by normal deployment and requires separate offline maintenance.

## Consequences

- MariaDB compromise alone does not reveal API token plaintext.
- Authentication detects corrupted, swapped, or contextually misplaced ciphertext.
- Multiple historical keys permit online rotation without a read outage.
- Losing every copy of a referenced master key permanently loses the affected credential; key backup and restore tests are mandatory.
- Web, collector, and backup processes that can read the keyring remain trusted components. Container, database, and application permissions must still enforce least privilege.
- Regex redaction cannot recognize an arbitrary secret copied into an innocently named field. Typed secret values, allowlisted event contexts, and the prohibition on logging raw input remain primary controls.

## Verification

Unit tests cover value-object validation, keyring validation and memoization, fixed-nonce cipher behavior, context and purpose binding, tampering, unknown keys and versions, rotation compatibility, readiness failure mapping, and nested redaction. Real-MariaDB integration tests prove that an empty credential set and referenced available keys are ready while a referenced absent key fails closed without exposing its ID. Kernel-level HTTP and CLI tests prove that invalid revision and path configuration reaches 503/exit 1 as fixed safe reasons. Remaining credential API integration must prove that MariaDB contains no plaintext sentinel, problem documents never contain secrets, and JSON logs remain leak-free on credential success and failure paths.
