# Hoddmímir deployment with Ansible

This scaffold bootstraps the Alpine host, installs Docker with OpenRC, deploys the four-service production Compose stack (`webapp`, `data-worker`, `backup-worker`, and `mariadb`), and verifies its health. Nothing runs remotely until an operator explicitly invokes a playbook.

## Prepare local configuration

```sh
./scripts/init-ansible-inventory.sh
cd deployment/ansible
cp inventories/production/group_vars/hoddmimir_hosts/vault.yml.example inventories/production/group_vars/hoddmimir_hosts/vault.yml
```

The ignored `.secrets/deployment.env` supplies `DEPLOYMENT_HOST` and `DEPLOYMENT_USER`. `DEPLOYMENT_FQDN` is optional; for DNS-based hosts the generator uses `DEPLOYMENT_HOST`, while IP-based hosts receive the safe local default `hoddmimir.localdomain` unless an explicit FQDN is provided.

Replace every vault placeholder with `openssl rand -hex 32`, then encrypt the file:

```sh
ansible-vault encrypt inventories/production/group_vars/hoddmimir_hosts/vault.yml
```

Set the application image references in `inventories/production/group_vars/hoddmimir_hosts/main.yml` to immutable Hoddmímir release digests (`image@sha256:...`). The checked-in development tags intentionally fail the production pinning assertion. Authenticate Docker to a private registry before deployment without putting registry credentials in this repository.

## Run explicitly

From the repository root, local preparation and validation never connect to the VM:

```sh
make inventory
python3.14 -m pip install --requirement deployment/ansible/requirements-dev.txt
make lint
make syntax
make deployment-test
```

Remote operations are separate and explicit:

```sh
make ping
make bootstrap
make deploy
make verify
```

Bootstrap connects initially as `root`, installs Python if absent, and configures Alpine, Docker, and OpenRC. Deploy creates `/opt/hoddmimir` and root-only secrets under `/etc/hoddmimir/secrets`.

The database is a clean V2 database backed by a named volume; this scaffold performs no legacy migration. `BACKUP_EXECUTION_ENABLED` defaults to `false`. Enabling it later requires both `hoddmimir_backup_execution_enabled: true` and the explicit acknowledgement `hoddmimir_backup_execution_activation_ack: ENABLE_PRODUCTION_BACKUPS`.

Deployment stages every candidate file before touching the installed state. It validates the rendered Compose model and pulls immutable images before mutation, then snapshots Compose, `runtime.env`, the MariaDB initialization script, and all seven secret files. A changed deployment is accepted only after the exact service set and API health pass. On failure, all overwritten files are restored and a previously running stack is restarted and verified again. An unchanged stack is only verified and is never stopped by recovery logic.

Failed first-time deployments are stopped without deleting the MariaDB volume. The five MariaDB credential files remain root-only on disk after such a failure because the persistent database may already have initialized those users; a retry must reuse the same values. Application and encryption secrets without a previous installed state are removed.

### MariaDB credential-rotation gate

The normal deployment path never claims to rotate existing MariaDB users. If any staged MariaDB password differs from its installed `0600` file, the transaction fails before Docker or installed files are changed. Updating `vault.yml` alone is therefore deliberately rejected.

Credential rotation is a separate maintenance transaction: take and verify a logical database backup, stop the three application services, use the current root credential through protected standard input or a root-only client file to `ALTER USER` for migration, web, collector, backup worker, and root, atomically replace the five installed secret files, update the encrypted Vault with the same values, then deploy and verify. Never pass database passwords as process arguments or log SQL containing them. If any database or file update fails, restore both user passwords and files before restarting the application services.

The stdlib test suite renders the real Compose template and exercises inventory validation, preflight gates, file modes, first deployment, unchanged verification failure, changed rollback with file restoration, rollback re-verification, and the MariaDB credential gate against an isolated fake Docker executable.

Never commit `hosts.yml`, `vault.yml`, vault passwords, registry credentials, or generated secret files. Secret validation and file writes use Ansible `no_log`.
