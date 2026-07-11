# Hoddmímir 2.0

Hoddmímir is a clean rewrite for orchestrating Proxmox backups with two workers and one web application:

- **Collector worker** runs continuously, scans configured Proxmox VE and Proxmox Backup Server installations automatically, and builds the inventory.
- **Backup worker** claims, starts, and monitors backup jobs.
- **Web application** provides administration, selection, status, and history through a PHP API and a Vue/PrimeVue frontend.

The required target matrix is Proxmox VE 7/8/9 and Proxmox Backup Server 3/4. PVE and PBS integrations are implemented in this repository without a third-party Proxmox client library.

The user-facing product name is **Hoddmímir**. Technical identifiers use the ASCII slug `hoddmimir`, including packages, commands, containers, database identities, deployment paths, and registry images.

## Status

The repository is being initialized. No production backup execution is enabled yet.

## Architecture

- PHP 8.5 and Symfony 7.4 as the application shell.
- Framework-independent PHP domain and application logic.
- Vue 3, TypeScript, PrimeVue 4, Pinia, and Vue Router.
- MariaDB 11.4 LTS with a new V2 schema.
- Alpine-based application containers.

The complete scope, architecture, compatibility strategy, test matrix, and delivery phases are documented in [the rewrite plan](docs/rewrite-plan.md).

## Repository layout

```text
backend/             PHP API, workers, domain, application, and infrastructure
frontend/            Vue/PrimeVue single-page application
docker/              Runtime images and container configuration
deployment/ansible/  Alpine/OpenRC production deployment automation
docs/                Architecture and planning documents
.github/workflows/   Continuous integration
```

Development rules and verification expectations are documented in [AGENTS.md](AGENTS.md).

## Requirements

- Docker with Docker Compose;
- `make` for the convenience commands;
- OpenSSL for generating local development secrets.

Local PHP and Node.js installations are optional because validation runs in pinned container images.

## Local development

1. Optionally copy `.env.example` to `.env` to change non-secret development settings.
2. Generate local secret files with `./scripts/init-dev-secrets.sh`.
3. Build the images with `docker compose build`.
4. Run `make up`; it starts MariaDB, applies pending migrations through the one-shot container, and then starts the four-service stack.
5. Open the web application at `http://localhost:8080`.

If port `8080` is already in use, set `WEB_PORT=18080` in the local `.env` before starting the stack.

Use `make help` to list the test, build, start, stop, log, and cleanup commands.

`make migrate` is available when only the idempotent schema operation is needed. A local migration failure exits nonzero and does not stop containers or delete volumes. MariaDB may remain running, and forward-only DDL may already be fully or partially applied; inspect the migration error before retrying.

The standard Compose model contains exactly four services: `data-worker`, `backup-worker`, `webapp`, and `mariadb`. Schema changes run through the separate `compose.migration.yaml` one-shot overlay and never add a fifth long-running service. Web and worker readiness remain unavailable until every migration expected by the application image is present. The data worker runs the collector command continuously with a default inventory cadence of 120 seconds. Scans are not triggered through the WebApp or its API. The Vue application is compiled into the webapp image rather than running as a separate service.

## Security

- Never commit `.env` files or secrets.
- Local passwords and encryption keys live in ignored `.secrets/` files, not in `.env`.
- Production PVE/PBS connections must verify TLS through a trusted CA or an explicit SHA-256 fingerprint.
- Collector and backup worker use separate least-privilege API tokens.
- The collector is read-only against PVE/PBS.
- A `vzdump` start request is never retried blindly after an ambiguous response.

Backup execution is disabled in the initial scaffold through `BACKUP_EXECUTION_ENABLED=false`.

## Production deployment

The Ansible scaffold under `deployment/ansible/` bootstraps the Alpine/OpenRC host, installs Docker from Alpine packages, deploys registry images pinned by digest, and verifies all four services. It does not run unless an operator explicitly invokes a remote Make target.

Create the ignored production inventory from `.secrets/deployment.env` with `make inventory`. Run the local-only checks with `make lint`, `make syntax`, and `make deployment-test`. The remote targets are `make ping`, `make bootstrap`, `make deploy`, and `make verify`; they retain SSH host-key verification and prompt for the encrypted Ansible Vault by default.

Production inventory, Vault data, registry credentials, and vault-password files must never be committed. See [the Ansible deployment guide](deployment/ansible/README.md) for image pinning, secret preparation, activation safeguards, health verification, and rollback behavior.
