# Hoddmímir backend scaffold

This directory contains the shared PHP application core, the Symfony HTTP API,
and the two worker entry points. The target runtime is PHP 8.5 with Symfony 7.4
LTS.

## Entry points

```sh
php bin/console hoddmimir:worker:data
php bin/console hoddmimir:worker:backup
```

Both commands run continuously by default. The collector defaults to an
automatic cycle on a persisted 120-second start-time grid; it is not triggered
by the WebApp or its API. `COLLECTOR_GRID_WIDTH_SECONDS` is the only grid-width
setting and must match the already persisted schedule. `--once` claims a cycle
only when it is due and never forces a scan. The collector currently persists
the read-only PVE core inventory; PBS, storage, backup-job, and task persistence
remain deferred. The backup worker remains readiness scaffolding and does not
start backups.

The HTTP health endpoint is `GET /api/health`.

## Runtime secrets and logging

Run `./scripts/init-dev-secrets.sh` from the repository root before starting
the Compose stack. The ignored `.secrets/encryption_key` file is a versioned
JSON keyring mounted at `/run/secrets/encryption_key`; key material is never
placed in an environment variable. Existing development files containing a
single 64-character hexadecimal key are atomically upgraded to keyring format
without changing the decoded key material. Repeated initialization leaves an
existing keyring unchanged.

`ENCRYPTION_KEYRING_REVISION` is non-secret runtime metadata and must match the
revision in the keyring. The Docker Secret loader memoizes either its first
validated keyring or its first safe configuration failure for the lifetime of
the process, so readiness and the cipher always observe the same snapshot.
Readiness validates the file and revision and checks every distinct `key_id`
referenced by `proxmox_credentials`; missing, invalid, mismatched, unreadable,
or incomplete key usage fails the HTTP health endpoint with 503 and worker
readiness with exit status 1 using fixed reason identifiers. Symfony's
application secret is read from the separate `APP_SECRET_FILE` Docker Secret.

Runtime logs are JSON records written to standard error. A global processor
redacts structured secret fields, authentication and cookie headers, sensitive
URLs, protected secret value objects, and exception messages before Monolog
formats the record. Raw request bodies, external response bodies, credentials,
and decrypted values must still never be submitted to a logger.

## Verification

Run the supported toolchain in the PHP 8.5 application container:

```sh
composer validate --strict
composer analyse
composer test
```

The current development host has PHP 8.3 and therefore does not satisfy the
intentional `php: ^8.5` platform requirement. Dependencies were installed once
with `--ignore-platform-req=php` solely to validate this initial scaffold; this
override must not be used in CI or production. A normal Composer install on PHP
8.3 is expected to fail, while the PHP 8.5 container is the authoritative
runtime.
