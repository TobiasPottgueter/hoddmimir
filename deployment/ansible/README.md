# Hoddmímir deployment with Ansible

This scaffold bootstraps the Alpine host, installs Docker with OpenRC, deploys the four-service production Compose stack (`webapp`, `data-worker`, `backup-worker`, and `mariadb`), and verifies its health. Nothing runs remotely until an operator explicitly invokes a playbook.

## Prepare local configuration

```sh
./scripts/init-ansible-inventory.sh
make production-secrets
```

The ignored `.secrets/deployment.env` supplies `DEPLOYMENT_HOST` and `DEPLOYMENT_USER`. `DEPLOYMENT_FQDN` is optional; for DNS-based hosts the generator uses `DEPLOYMENT_HOST`, while IP-based hosts receive the safe local default `hoddmimir.localdomain` unless an explicit FQDN is provided.

The production initializer generates a set that is separate from the local
development secrets. It creates distinct application and MariaDB secrets, a
structured revision-1 encryption keyring, a local administrator password and
an Ansible Vault password below the ignored `.secrets/production` directory.
It then writes the ignored `vault.yml` directly in encrypted form. All secret
files are mode `0600`; existing valid material is verified and retained, while
invalid, mismatching or symlinked state fails closed without being replaced.
The command never prints secret values.

Matrix delivery remains disabled by default. The encrypted Vault contains only
`https://matrix.invalid/disabled` until a real webhook is configured as a
separate explicit maintenance action. The local administrator password is not
part of the Ansible Vault and is intended only for the secret-file based
`hoddmimir:user:create-admin` bootstrap command.

The Make targets use `.secrets/production/ansible_vault_password`
non-interactively when it exists. Set `ANSIBLE_VAULT_ARGS` explicitly only
when an operator intentionally uses a different Vault identity.

Set the application image references in `inventories/production/group_vars/hoddmimir_hosts/main.yml` to immutable Hoddmímir release digests (`image@sha256:...`). The checked-in development tags intentionally fail the production pinning assertion. Authenticate Docker to a private registry before deployment without putting registry credentials in this repository.

The manual publish option of the existing `Hoddmímir CI` workflow publishes
the worker and web runtime images for both `linux/amd64` and `linux/arm64`.
The operator selects the GitHub Actions source ref and supplies an OCI tag plus
the confirmation text `PUBLISH_MULTIARCH_IMAGES`; ordinary CI never publishes.
The workflow uses the job-scoped `GITHUB_TOKEN` with `packages: write` only
after the full manual gate set succeeds, records the resolved source commit,
and uploads `published-images.json`. Copy only its immutable
`@sha256:` worker and web references into the production variables; the
human-readable tag is not a deployment pin. The collector and backup worker
use the same worker image digest with different commands and credentials.
Both builds carry the repository source label so GHCR links them to this
repository. Before it emits deployment references, the workflow logs out of
GHCR and resolves both manifest digests anonymously. This proof is
release-blocking because the deployment host intentionally has no registry
token. GitHub creates a new personal container package as private by default;
if the first publication stops at this proof, its owner must make both linked
packages public once in GitHub's package settings and rerun the same manual
workflow. Package visibility is never changed with a local or long-lived PAT.

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

`hoddmimir_collector_grid_width_seconds` is the collector's only cadence
setting, defaults to 120, and is validated against the Application range of one
second through one year. The runtime fails closed if it differs from the
already persisted schedule; changing an existing grid requires a future
explicit maintenance operation, not a normal deployment. The data-worker gets
75 seconds to stop at a safe checkpoint. Its container healthcheck reads the
exact process ID from `/app/var` and accepts only that worker's fresh MariaDB
heartbeat together with base readiness; another replica cannot mask it.

`hoddmimir_pve_storage_max_node_fanout` defaults to `128` and accepts values
from `1` through `1024`. Exceeding the configured limit fails the storage
scope without issuing a truncated subset of node requests; it does not alter
the collector cadence.

`hoddmimir_pbs_max_datastore_fanout` defaults to `128` and accepts values
from `1` through `1024`. Exceeding it fails the PBS datastore scope without
issuing a truncated subset of datastore status requests.

The encrypted Vault stores `hoddmimir_encryption_keyring` as a structured object. Deployment validates its exact shape, a positive revision, one to sixteen unique IDs and unique 32-byte hexadecimal key materials, and the presence of the primary ID. It writes canonical compact JSON to the existing `encryption_key` Docker Secret with mode `0600`; secret-bearing validation, rendering, and copy tasks use `no_log`. Only the non-secret revision is exported as `ENCRYPTION_KEYRING_REVISION` in `runtime.env` and the production Compose model.

Key rotation is additive: append a newly generated key under a new ID, select it with `primaryKeyId`, increment `revision`, and deploy all application components together. Keep old key entries until every active envelope and every retained database backup no longer requires them. The normal deployment transaction rejects revision regression, changed material under an existing ID, moving material to a different ID, and every historical-key removal before its first Docker call or installed-file mutation.

Historical-key removal is currently unsupported. There is no deployment acknowledgement or Ansible variable that bypasses the guard. A future separate maintenance command must first rewrap every active credential, verify directly in MariaDB that no row references the retired IDs, and require proof of a successful database backup-and-restore test. Until that DB-verified maintenance workflow exists, retain historical IDs and never reuse them.

The database is a clean V2 database backed by a named volume; this scaffold performs no legacy migration. `BACKUP_EXECUTION_ENABLED` defaults to `false`. Enabling it later requires both `hoddmimir_backup_execution_enabled: true` and the explicit acknowledgement `hoddmimir_backup_execution_activation_ack: ENABLE_PRODUCTION_BACKUPS`.

Matrix delivery is independently gated by `hoddmimir_matrix_notifications_enabled`. Backup execution cannot be enabled unless problem delivery is enabled with a valid non-placeholder HTTPS webhook; execution-disabled development and test deployments may keep outbound delivery disabled. Its HTTPS webhook URL lives only in the encrypted Vault and is mounted into the backup worker as the root-owned `matrix_webhook_url` Docker Secret; it is never written to `runtime.env`, process arguments, logs, API responses, or the WebApp. The channel and timeout remain non-secret validated settings.

Application secret files remain root-owned with mode `0600` on the host. The worker, schema-migration, WebApp, and browser-test images start a minimal root entrypoint with only `CHOWN`, `SETGID`, and `SETUID`; it accepts only the documented direct `/run/secrets/*` file variables, rejects symlinks, special files, empty data, and files above 64 KiB, and atomically stages the mounted subset as runtime-user-owned `0400` files in `/run/hoddmimir-secrets`. Compose mounts that directory as a private tmpfs, so staged plaintext is never persistent. The entrypoint and every healthcheck then drop to `app` or `www-data`; the application processes run with zero effective capabilities. Missing tmpfs or malformed secret input fails closed without logging secret content.

Every play creates its own root-only `0700` staging directory below `/opt/hoddmimir`, stages the transaction executor there, executes that isolated copy, and removes only the directory registered for that play. Concurrent plays therefore cannot overwrite an executor or delete another play's candidate files. The executor takes a non-blocking exclusive advisory lock on the persistent `0600` file `/opt/hoddmimir/.deployment-transaction.lock` before validation and holds it through every Docker call, installed-file mutation, verification, and recovery action. A second transaction fails safely before Docker or managed-state mutation. The empty lock file intentionally persists; it is not a stale-lock marker because the kernel releases `flock` automatically when the owning process exits, including on failure.

Deployment stages every candidate file before touching the installed state. While holding the transaction lock, it validates the rendered four-service Compose model and the separate migration overlay, pulls immutable images, and snapshots both Compose files, `runtime.env`, the MariaDB bootstrap script, and all eight secret files. It starts MariaDB alone, creates or reconciles the four application users locally through the database socket, runs the one-shot migration container with only the migration identity, and starts the application services only after migration success. Every changed candidate force-recreates exactly `data-worker`, `backup-worker`, and `webapp` without dependencies so that file-backed secret changes are actually restaged; MariaDB is never included in that forced recreation. A changed deployment is accepted only after the exact service set and API health pass. If a failure occurs before the candidate application recreation, overwritten files and the keyring are restored byte-for-byte while the existing application containers remain running; only MariaDB is reconciled before the stack is verified. Once a candidate application start may have happened, recovery retains the safe additive key union and force-recreates the same three application services from the restored files before verification. Even when application files are unchanged, deployment starts or verifies MariaDB, reconciles database users, applies pending migrations idempotently, and then verifies the existing stack without recreating or stopping application services.

Keyring recovery changes immediately before the three candidate application containers may be recreated. Database start, user bootstrap and schema migration do not receive the keyring, so failures before that boundary restore every installed keyring byte exactly and a failed first deployment removes the unused candidate keyring. After that boundary, recovery of an existing structured installation uses the old revision and old primary and retains a deterministic additive union of old and candidate keys. The restored image therefore keeps writing with its old primary while remaining able to decrypt any candidate envelope. A failed first deployment after an application start may have occurred likewise retains the structured candidate keyring with mode `0600`, whether the original state had no key or an offline raw-key seed. Recovery verifies the old stack with this safe keyring, reports that the additional decryption keys were retained, and the normal removal guard prevents their later deletion.

A 64-character lowercase raw key can be established as a one-key structured keyring only as a first-deployment/offline seed with no installed Compose file, identical material, and exactly one staged primary key. If an installed Compose file exists, normal deployment blocks the raw key before Docker and requires separate offline maintenance; it never assumes the running image is read-only.

MariaDB DDL is forward-only and is never rolled back by the deployment transaction. A failed migration or later application failure may therefore leave a fully or partially applied schema while application artifacts are restored. Every migration must use an expand/contract sequence: expand changes remain compatible with both the previous and candidate images, and destructive contract changes occur only in a later release after rollback to the older image is no longer possible. Readiness requires all migrations expected by an image but deliberately tolerates additional newer migrations during recovery.

Failed first-time deployments are stopped without deleting the MariaDB volume. The five MariaDB credential files remain root-only on disk after such a failure because the persistent database may already have initialized those users; a retry must reuse the same values. Before candidate services may start, application and encryption secrets without a previous installed state are removed. After that boundary, the application secret is removed but the structured encryption keyring is retained so a retry can decrypt any candidate envelope already persisted.

### MariaDB credential-rotation gate

The normal deployment path never claims to rotate existing MariaDB users. If any staged MariaDB password differs from its installed `0600` file, the transaction fails before Docker or installed files are changed. Updating `vault.yml` alone is therefore deliberately rejected.

Credential rotation is a separate maintenance transaction: take and verify a logical database backup, stop the three application services, use the current root credential through protected standard input or a root-only client file to `ALTER USER` for migration, web, collector, backup worker, and root, atomically replace the five installed secret files, update the encrypted Vault with the same values, then deploy and verify. Never pass database passwords as process arguments or log SQL containing them. If any database or file update fails, restore both user passwords and files before restarting the application services.

The stdlib test suite renders the real Compose template and exercises inventory validation, preflight gates, file modes, first deployment, unchanged verification failure, changed rollback with file restoration, rollback re-verification, and the MariaDB credential gate against an isolated fake Docker executable.

Never commit `hosts.yml`, `vault.yml`, vault passwords, registry credentials, or generated secret files. Secret validation and file writes use Ansible `no_log`.
