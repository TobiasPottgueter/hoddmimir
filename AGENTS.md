# AGENTS.md

## Project scope

This repository contains Hoddmímir 2.0. It is a clean rewrite with three application components:

- collector worker;
- backup worker;
- web application with a PHP API and a Vue/PrimeVue frontend.

The authoritative architecture and functional requirements are in `docs/rewrite-plan.md`. Read that file before changing architecture or behavior.

Use `Hoddmímir` (NFC) as the user-facing display name and `hoddmimir` as the technical slug for packages, images, paths, databases, commands, and deployment identifiers.

## Non-negotiable requirements

- Support Proxmox VE 7, 8, and 9 and Proxmox Backup Server 3 and 4.
- Implement our own typed PVE/PBS API adapters. Never add `saleh7/proxmox-ve_php_api`, `netzkultur/proxmoxve-php-api`, or another Proxmox client library.
- Preserve the required functions: scan configured PVE/PBS installations, select nodes/VMs/CTs, select backup locations, and prioritize backups by the documented rules.
- Treat V2 as a clean start. Do not create a legacy database importer or compatibility layer.
- Keep domain and application logic independent of Symfony, HTTP, MariaDB, and Vue.
- The collector is read-only against PVE/PBS. Only the backup worker may start or stop backup tasks.
- Never automatically retry a `vzdump` POST after an ambiguous response.
- Never disable TLS verification in production code.
- Never log tokens, passwords, cookies, CSRF values, TOTP data, or decrypted secrets.

## Repository layout

- `backend/`: PHP domain, application, infrastructure, web API, and worker entry points.
- `frontend/`: Vue 3 / PrimeVue 4 single-page application.
- `docker/`: application image definitions and container configuration.
- `deployment/ansible/`: Alpine/OpenRC bootstrap, production deployment, and verification.
- `docs/`: architecture, plans, ADRs, and operational documentation.
- `.github/workflows/`: continuous integration.

Do not read from or modify the old project unless a task explicitly requests read-only comparison. Never copy its source code, credentials, or Git history.

## Architecture boundaries

- `backend/src/Domain` must be plain PHP and may not depend on Symfony, Doctrine, HTTP clients, or database classes.
- `backend/src/Application` orchestrates use cases through interfaces declared in Domain/Application.
- `backend/src/Infrastructure` contains MariaDB, HTTP, Proxmox, encryption, clock, and logging adapters.
- `backend/src/Presentation` contains HTTP and CLI delivery code only.
- Worker commands are thin process shells. Scheduling, prioritization, state transitions, and payload construction belong in testable services.
- Frontend code consumes the versioned web API. It must not call PVE or PBS directly.

## Tests and quality gates

- Every deterministic business rule and state transition requires unit tests.
- Domain, Application, and custom Proxmox compatibility code target 100% line and branch coverage.
- Use real MariaDB containers for repository, locking, lease, and schema integration tests; never substitute SQLite for those tests.
- Maintain sanitized contract fixtures for PVE 7/8/9 and PBS 3/4.
- Add component tests for stores, composables, and non-trivial Vue components.
- Add Playwright tests for critical administration flows when those flows are implemented.
- A behavior change is incomplete until its tests pass.

## Development rules

- Use strict types in every PHP file.
- Prefer immutable value objects, explicit enums, injected clocks, and typed DTOs.
- Store timestamps in UTC.
- Keep external I/O behind interfaces.
- Use explicit transactions for queue claiming and state transitions.
- Use conventional commits when commits are requested.
- Do not commit `.env`, runtime secrets, generated coverage, dependency directories, IDE metadata, or build output.
- Do not modify unrelated user changes in a dirty worktree.
- Never commit a real Ansible inventory, Vault file, vault password, SSH material, registry credential, or generated deployment secret.
- CI may parse the example inventory and run lint/syntax checks, but it must not connect to deployment hosts.
- Production deployment uses registry images pinned by digest and keeps backup execution disabled unless its explicit acknowledgement is supplied.
- Deployment changes must pass the isolated inventory, Compose-contract, preflight, secret-permission, and transaction-rollback tests.

## Initial verification commands

These commands may evolve with the scaffold. Keep this section current when tooling changes.

```sh
./scripts/init-dev-secrets.sh
docker compose config
docker compose build
docker compose up --detach --wait
curl --fail http://localhost:8080/api/health
docker compose down --volumes

docker build --target backend-test --file docker/php/Dockerfile .
docker build --target frontend-test --file docker/web/Dockerfile .
docker build --target frontend-build --file docker/web/Dockerfile .

make inventory
make lint
make syntax
make deployment-test
```
