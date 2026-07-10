# Hoddmímir backend scaffold

This directory contains the shared PHP application core, the Symfony HTTP API,
and the two worker entry points. The target runtime is PHP 8.5 with Symfony 7.4
LTS.

## Entry points

```sh
php bin/console hoddmimir:worker:data
php bin/console hoddmimir:worker:backup
```

Both commands run continuously by default. `--interval=<seconds>` controls the
wait between iterations and `--once` runs exactly one iteration for diagnostics
and tests. These scaffold commands only report readiness; they do not access
Proxmox or start backups.

The HTTP health endpoint is `GET /api/health`.

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
