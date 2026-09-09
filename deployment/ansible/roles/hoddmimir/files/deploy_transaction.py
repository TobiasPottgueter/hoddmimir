#!/usr/bin/env python3
"""Apply a Hoddmímir deployment as a recoverable local file transaction."""

from __future__ import annotations

import argparse
import fcntl
import json
import importlib.util
import os
import re
import shutil
import stat
import subprocess
import sys
import tempfile
import urllib.error
import urllib.request
from contextlib import contextmanager
from dataclasses import dataclass
from pathlib import Path
from types import MappingProxyType
from typing import Mapping, Sequence

SECRET_NAMES = (
    "app_secret",
    "encryption_key",
    "mariadb_root_password",
    "mariadb_migration_password",
    "mariadb_web_password",
    "mariadb_collector_password",
    "mariadb_backup_worker_password",
    "matrix_webhook_url",
)
DATABASE_SECRET_NAMES = (
    "mariadb_root_password",
    "mariadb_migration_password",
    "mariadb_web_password",
    "mariadb_collector_password",
    "mariadb_backup_worker_password",
)
APPLICATION_SERVICES = (
    "data-worker",
    "backup-worker",
    "webapp",
)
MAXIMUM_KEYRING_BYTES = 65536
KEY_ID_PATTERN = re.compile(r"\A[a-z0-9][a-z0-9_-]{0,31}\Z")
KEY_MATERIAL_PATTERN = re.compile(r"\A[0-9a-f]{64}\Z")


class DeploymentError(RuntimeError):
    """A safe deployment or recovery operation failed."""


@dataclass(frozen=True)
class ManagedFile:
    staged: Path
    current: Path
    mode: int


@dataclass(frozen=True)
class Snapshot:
    managed_file: ManagedFile
    existed: bool
    backup: Path | None
    mode: int | None


@dataclass(frozen=True, repr=False)
class EncryptionKey:
    key_id: str
    material: bytes


@dataclass(frozen=True, repr=False)
class EncryptionKeyring:
    revision: int
    primary_key_id: str
    keys: Mapping[str, EncryptionKey]


class _InvalidKeyring(ValueError):
    """Internal marker that never carries untrusted input."""


class _DuplicateJsonObjectField(_InvalidKeyring):
    """The JSON document contains a duplicate object field."""


@contextmanager
def deployment_lock(path: Path):
    if not path.is_absolute():
        raise DeploymentError("The deployment transaction lock path is invalid.")

    descriptor: int | None = None
    try:
        flags = os.O_RDWR | os.O_CREAT | os.O_CLOEXEC
        if hasattr(os, "O_NOFOLLOW"):
            flags |= os.O_NOFOLLOW
        descriptor = os.open(path, flags, 0o600)
        metadata = os.fstat(descriptor)
        if not stat.S_ISREG(metadata.st_mode):
            raise DeploymentError("The deployment transaction lock file is invalid.")
        os.fchmod(descriptor, 0o600)
        try:
            fcntl.flock(descriptor, fcntl.LOCK_EX | fcntl.LOCK_NB)
        except BlockingIOError:
            raise DeploymentError("Another deployment transaction is already running.") from None
    except DeploymentError:
        if descriptor is not None:
            os.close(descriptor)
        raise
    except OSError:
        if descriptor is not None:
            os.close(descriptor)
        raise DeploymentError("The deployment transaction lock is unavailable.") from None

    assert descriptor is not None
    try:
        yield
    finally:
        try:
            fcntl.flock(descriptor, fcntl.LOCK_UN)
        except OSError:
            pass
        try:
            os.close(descriptor)
        except OSError:
            pass


def _json_object_without_duplicates(pairs: list[tuple[str, object]]) -> dict[str, object]:
    value: dict[str, object] = {}
    for key, item in pairs:
        if key in value:
            raise _DuplicateJsonObjectField()
        value[key] = item

    return value


def _read_keyring_bytes(path: Path, invalid_message: str) -> bytes:
    try:
        with path.open("rb") as keyring_file:
            contents = keyring_file.read(MAXIMUM_KEYRING_BYTES + 1)
    except OSError:
        raise DeploymentError(invalid_message) from None

    if not contents or len(contents) > MAXIMUM_KEYRING_BYTES:
        raise DeploymentError(invalid_message)

    return contents


def load_encryption_keyring(path: Path, invalid_message: str) -> EncryptionKeyring:
    contents = _read_keyring_bytes(path, invalid_message)

    return parse_encryption_keyring_bytes(contents, invalid_message)


def parse_encryption_keyring_bytes(contents: bytes, invalid_message: str) -> EncryptionKeyring:
    try:
        document = json.loads(
            contents.decode("utf-8"),
            object_pairs_hook=_json_object_without_duplicates,
        )
        if not isinstance(document, dict) or set(document) != {"format", "revision", "primaryKeyId", "keys"}:
            raise _InvalidKeyring()
        if type(document["format"]) is not int or document["format"] != 1:
            raise _InvalidKeyring()

        revision = document["revision"]
        if type(revision) is not int or revision < 1:
            raise _InvalidKeyring()

        primary_key_id = document["primaryKeyId"]
        if not isinstance(primary_key_id, str) or KEY_ID_PATTERN.fullmatch(primary_key_id) is None:
            raise _InvalidKeyring()

        entries = document["keys"]
        if not isinstance(entries, list) or not 1 <= len(entries) <= 16:
            raise _InvalidKeyring()

        keys: dict[str, EncryptionKey] = {}
        materials: set[bytes] = set()
        for entry in entries:
            if not isinstance(entry, dict) or set(entry) != {"id", "material"}:
                raise _InvalidKeyring()
            key_id = entry["id"]
            material_text = entry["material"]
            if (
                not isinstance(key_id, str)
                or KEY_ID_PATTERN.fullmatch(key_id) is None
                or not isinstance(material_text, str)
                or KEY_MATERIAL_PATTERN.fullmatch(material_text) is None
            ):
                raise _InvalidKeyring()
            material = bytes.fromhex(material_text)
            if key_id in keys or material in materials:
                raise _InvalidKeyring()
            keys[key_id] = EncryptionKey(key_id, material)
            materials.add(material)

        if primary_key_id not in keys:
            raise _InvalidKeyring()

        return EncryptionKeyring(revision, primary_key_id, MappingProxyType(keys))
    except (UnicodeDecodeError, json.JSONDecodeError, _InvalidKeyring, ValueError, TypeError):
        raise DeploymentError(invalid_message) from None


def _load_installed_keyring_or_legacy(path: Path) -> EncryptionKeyring | bytes:
    contents = _read_keyring_bytes(path, "The installed encryption keyring is invalid.")
    legacy = contents[:-1] if contents.endswith(b"\n") else contents
    if len(legacy) == 64:
        try:
            legacy_text = legacy.decode("ascii")
        except UnicodeDecodeError:
            legacy_text = ""
        if KEY_MATERIAL_PATTERN.fullmatch(legacy_text) is not None:
            return bytes.fromhex(legacy_text)

    return parse_encryption_keyring_bytes(contents, "The installed encryption keyring is invalid.")


def _parse_expected_keyring_revision(value: str) -> int:
    if not value or len(value) > 19 or value[0] not in "123456789" or not value.isascii() or not value.isdecimal():
        raise DeploymentError("The deployment encryption keyring revision is invalid.")

    revision = int(value)
    if str(revision) != value or revision > sys.maxsize:
        raise DeploymentError("The deployment encryption keyring revision is invalid.")

    return revision


def validate_encryption_keyring_transition(
    *,
    staged_keyring_file: Path,
    installed_keyring_file: Path,
    expected_revision_value: str,
    previous_compose_exists: bool,
) -> None:
    staged = load_encryption_keyring(staged_keyring_file, "The staged encryption keyring is invalid.")
    expected_revision = _parse_expected_keyring_revision(expected_revision_value)
    if staged.revision != expected_revision:
        raise DeploymentError(
            "The staged encryption keyring revision does not match the deployment revision.",
        )

    if not installed_keyring_file.is_file():
        if previous_compose_exists:
            raise DeploymentError("An installed encryption keyring is missing; deployment was not changed.")
        return

    installed = _load_installed_keyring_or_legacy(installed_keyring_file)
    if isinstance(installed, bytes):
        if previous_compose_exists:
            raise DeploymentError(
                "A legacy encryption key requires separate offline maintenance before deployment.",
            )
        if (
            len(staged.keys) != 1
            or staged.primary_key_id not in staged.keys
            or staged.keys[staged.primary_key_id].material != installed
        ):
            raise DeploymentError("The legacy encryption key cannot be upgraded by this deployment.")
        return

    if staged.revision < installed.revision:
        raise DeploymentError("The encryption keyring revision cannot decrease.")

    for key_id, installed_key in installed.keys.items():
        staged_key = staged.keys.get(key_id)
        if staged_key is not None and staged_key.material != installed_key.material:
            raise DeploymentError("Encryption key material cannot change under an existing identifier.")

    installed_material_ids = {key.material: key_id for key_id, key in installed.keys.items()}
    for key_id, staged_key in staged.keys.items():
        previous_key_id = installed_material_ids.get(staged_key.material)
        if previous_key_id is not None and previous_key_id != key_id:
            raise DeploymentError("Encryption key material cannot move to a different identifier.")

    if not installed.keys.keys() <= staged.keys.keys():
        raise DeploymentError("Encryption key removal is unsupported by normal deployment.")

    semantic_change = (
        installed.primary_key_id != staged.primary_key_id
        or installed.keys.keys() != staged.keys.keys()
    )
    if semantic_change and staged.revision == installed.revision:
        raise DeploymentError("Encryption keyring changes require a revision increase.")


class DockerCompose:
    def __init__(self, executable: str, wait_timeout: int) -> None:
        self.executable = executable
        self.wait_timeout = wait_timeout
        self.allow_schema_changes = True

    def _run(
        self,
        compose_files: Sequence[Path],
        arguments: Sequence[str],
        allowed_returncodes: Sequence[int] = (0,),
    ) -> subprocess.CompletedProcess[str]:
        compose_arguments: list[str] = []
        for compose_file in compose_files:
            compose_arguments.extend(("--file", str(compose_file)))

        result = subprocess.run(
            [self.executable, "compose", *compose_arguments, *arguments],
            check=False,
            capture_output=True,
            text=True,
        )
        if result.returncode not in allowed_returncodes:
            operation = arguments[0] if arguments else "command"
            raise DeploymentError(f"Docker Compose {operation} failed with exit code {result.returncode}.")

        return result

    def validate(self, compose_file: Path) -> None:
        self._run((compose_file,), ("config", "--quiet"))

    def validate_migration(self, compose_file: Path, migration_compose_file: Path) -> None:
        self._run((compose_file, migration_compose_file), ("config", "--quiet"))

    def pull(self, compose_file: Path) -> None:
        self._run((compose_file,), ("pull",))

    def force_recreate_applications(
        self,
        compose_file: Path,
        application_services: Sequence[str],
    ) -> None:
        self._run(
            (compose_file,),
            (
                "up",
                "--detach",
                "--no-deps",
                "--force-recreate",
                "--remove-orphans",
                "--wait",
                "--wait-timeout",
                str(self.wait_timeout),
                *application_services,
            ),
        )

    def up_database(self, compose_file: Path) -> None:
        self._run(
            (compose_file,),
            (
                "up",
                "--detach",
                "--wait",
                "--wait-timeout",
                str(self.wait_timeout),
                "mariadb",
            ),
        )

    def bootstrap_database_users(
        self,
        compose_file: Path,
        database_service: str,
        bootstrap_script: str,
    ) -> None:
        self._run(
            (compose_file,),
            ("exec", "-T", "--user", "0", database_service, bootstrap_script),
        )

    def migrate(
        self,
        compose_file: Path,
        migration_compose_file: Path,
        migration_service: str,
    ) -> bool:
        status = self._run(
            (compose_file, migration_compose_file),
            (
                "run",
                "--rm",
                "--no-deps",
                migration_service,
                "doctrine:migrations:up-to-date",
                "--no-interaction",
                "--no-ansi",
            ),
            (0, 1),
        )
        if status.returncode == 0:
            return False
        if not self.allow_schema_changes:
            raise DeploymentError("Schema changes require the maintenance/backup/restore workflow; image-only migration is disabled.")

        self._run(
            (compose_file, migration_compose_file),
            ("run", "--rm", "--no-deps", migration_service),
        )

        return True

    def down(self, compose_file: Path) -> None:
        self._run((compose_file,), ("down", "--remove-orphans"))

    def running_services(self, compose_file: Path) -> set[str]:
        result = self._run(
            (compose_file,),
            ("ps", "--services", "--filter", "status=running"),
        )

        return {line.strip() for line in result.stdout.splitlines() if line.strip()}


def files_equal(left: Path, right: Path) -> bool:
    if not left.is_file() or not right.is_file():
        return False

    return left.read_bytes() == right.read_bytes()


def needs_update(managed_file: ManagedFile) -> bool:
    if not files_equal(managed_file.staged, managed_file.current):
        return True

    return stat.S_IMODE(managed_file.current.stat().st_mode) != managed_file.mode


def atomic_install(source: Path, destination: Path, mode: int) -> None:
    atomic_install_bytes(source.read_bytes(), destination, mode)


def atomic_install_bytes(contents: bytes, destination: Path, mode: int) -> None:
    destination.parent.mkdir(parents=True, exist_ok=True)
    descriptor, temporary_name = tempfile.mkstemp(
        prefix=f".{destination.name}.",
        dir=destination.parent,
    )
    temporary_path = Path(temporary_name)

    try:
        with os.fdopen(descriptor, "wb") as temporary_file:
            temporary_file.write(contents)
            temporary_file.flush()
            os.fsync(temporary_file.fileno())
        os.chmod(temporary_path, mode)
        os.replace(temporary_path, destination)
    finally:
        temporary_path.unlink(missing_ok=True)


def install_additive_recovery_keyring(
    installed_keyring_file: Path,
    candidate: EncryptionKeyring,
) -> None:
    installed = load_encryption_keyring(
        installed_keyring_file,
        "The restored encryption keyring is invalid.",
    )
    merged_keys = dict(installed.keys)
    material_ids = {key.material: key_id for key_id, key in installed.keys.items()}
    for key_id, candidate_key in candidate.keys.items():
        installed_key = merged_keys.get(key_id)
        if installed_key is not None:
            if installed_key.material != candidate_key.material:
                raise DeploymentError("Recovery encryption keyring invariants are invalid.")
            continue
        previous_key_id = material_ids.get(candidate_key.material)
        if previous_key_id is not None and previous_key_id != key_id:
            raise DeploymentError("Recovery encryption keyring invariants are invalid.")
        merged_keys[key_id] = candidate_key
        material_ids[candidate_key.material] = key_id
    document = {
        "format": 1,
        "revision": installed.revision,
        "primaryKeyId": installed.primary_key_id,
        "keys": [
            {"id": key_id, "material": merged_keys[key_id].material.hex()}
            for key_id in sorted(merged_keys)
        ],
    }
    encoded = (json.dumps(document, sort_keys=True, separators=(",", ":")) + "\n").encode("utf-8")
    atomic_install_bytes(encoded, installed_keyring_file, 0o600)


def install_recovery_keyring(keyring: EncryptionKeyring, destination: Path) -> None:
    document = {
        "format": 1,
        "revision": keyring.revision,
        "primaryKeyId": keyring.primary_key_id,
        "keys": [
            {"id": key_id, "material": keyring.keys[key_id].material.hex()}
            for key_id in sorted(keyring.keys)
        ],
    }
    encoded = (json.dumps(document, sort_keys=True, separators=(",", ":")) + "\n").encode("utf-8")
    atomic_install_bytes(encoded, destination, 0o600)


def snapshot_files(managed_files: Sequence[ManagedFile], parent: Path) -> tuple[Path, list[Snapshot]]:
    backup_directory = Path(tempfile.mkdtemp(prefix=".deployment-backup-", dir=parent))
    os.chmod(backup_directory, 0o700)
    snapshots: list[Snapshot] = []

    try:
        for index, managed_file in enumerate(managed_files):
            if not managed_file.current.is_file():
                snapshots.append(Snapshot(managed_file, False, None, None))
                continue

            backup_file = backup_directory / str(index)
            shutil.copy2(managed_file.current, backup_file)
            snapshots.append(
                Snapshot(
                    managed_file,
                    True,
                    backup_file,
                    stat.S_IMODE(managed_file.current.stat().st_mode),
                ),
            )
    except OSError:
        shutil.rmtree(backup_directory, ignore_errors=True)
        raise

    return backup_directory, snapshots


def restore_files(snapshots: Sequence[Snapshot], retain_new: set[Path] | None = None) -> None:
    retained_paths = retain_new or set()

    for snapshot in reversed(snapshots):
        if snapshot.existed:
            if snapshot.backup is None or snapshot.mode is None:
                raise DeploymentError("Deployment backup metadata is incomplete.")
            atomic_install(snapshot.backup, snapshot.managed_file.current, snapshot.mode)
        elif snapshot.managed_file.current not in retained_paths:
            snapshot.managed_file.current.unlink(missing_ok=True)


def describe_os_error(error: OSError) -> str:
    errno = f", errno={error.errno}" if error.errno is not None else ""

    return f"filesystem operation failed ({type(error).__name__}{errno})"


def recover_transaction(
    *,
    docker: DockerCompose,
    current_compose_file: Path,
    current_secrets_directory: Path,
    expected_services: set[str],
    application_services: Sequence[str],
    health_url: str,
    previous_compose_exists: bool,
    previous_running_services: set[str],
    previous_secrets_directory_exists: bool,
    previous_secrets_directory_mode: int | None,
    snapshots: Sequence[Snapshot],
    backup_directory: Path,
    candidate_services_may_have_started: bool,
) -> bool:
    recovery_keyring_retained = False
    try:
        candidate_keyring = (
            load_encryption_keyring(
                current_secrets_directory / "encryption_key",
                "The candidate encryption keyring is invalid during recovery.",
            )
            if candidate_services_may_have_started
            else None
        )
        if previous_compose_exists:
            restore_files(snapshots)
            if candidate_keyring is not None:
                install_additive_recovery_keyring(
                    current_secrets_directory / "encryption_key",
                    candidate_keyring,
                )
                recovery_keyring_retained = True
            if previous_secrets_directory_mode is not None:
                os.chmod(current_secrets_directory, previous_secrets_directory_mode)
            if previous_running_services:
                docker.up_database(current_compose_file)
                if candidate_services_may_have_started:
                    docker.force_recreate_applications(current_compose_file, application_services)
                verify_stack(docker, current_compose_file, expected_services, health_url)
            else:
                docker.down(current_compose_file)
        else:
            down_error: DeploymentError | None = None
            if current_compose_file.is_file():
                try:
                    docker.down(current_compose_file)
                except DeploymentError as error:
                    down_error = error

            retained_database_secrets = {
                current_secrets_directory / secret_name for secret_name in DATABASE_SECRET_NAMES
            }
            if candidate_keyring is not None:
                retained_database_secrets.add(current_secrets_directory / "encryption_key")
            restore_files(snapshots, retain_new=retained_database_secrets)
            if candidate_keyring is not None:
                install_recovery_keyring(
                    candidate_keyring,
                    current_secrets_directory / "encryption_key",
                )
                recovery_keyring_retained = True
            if previous_secrets_directory_exists and previous_secrets_directory_mode is not None:
                os.chmod(current_secrets_directory, previous_secrets_directory_mode)
            elif current_secrets_directory.is_dir() and not any(current_secrets_directory.iterdir()):
                current_secrets_directory.rmdir()
            if down_error is not None:
                raise down_error

        shutil.rmtree(backup_directory)
        return recovery_keyring_retained
    except (DeploymentError, OSError) as recovery_error:
        detail = (
            str(recovery_error)
            if isinstance(recovery_error, DeploymentError)
            else describe_os_error(recovery_error)
        )
        raise DeploymentError(
            f"Deployment recovery failed: {detail}. "
            f"Backup retained at {backup_directory}; manual recovery is required.",
        ) from recovery_error


def verify_stack(
    docker: DockerCompose,
    compose_file: Path,
    expected_services: set[str],
    health_url: str,
) -> None:
    running_services = docker.running_services(compose_file)
    if running_services != expected_services:
        raise DeploymentError("The running service set does not match the production contract.")

    try:
        with urllib.request.urlopen(health_url, timeout=5) as response:
            if response.status != 200:
                raise DeploymentError(f"Web health endpoint returned HTTP {response.status}.")
    except urllib.error.HTTPError as error:
        raise DeploymentError(f"Web health endpoint returned HTTP {error.code}.") from error
    except (OSError, urllib.error.URLError) as error:
        raise DeploymentError("Web health endpoint is not reachable.") from error


def validate_database_credentials(
    staged_secrets_directory: Path,
    current_secrets_directory: Path,
    previous_compose_exists: bool,
) -> None:
    for secret_name in DATABASE_SECRET_NAMES:
        staged_secret = staged_secrets_directory / secret_name
        current_secret = current_secrets_directory / secret_name

        if not staged_secret.is_file():
            raise DeploymentError("A staged MariaDB credential is missing.")
        if current_secret.is_file() and not files_equal(staged_secret, current_secret):
            raise DeploymentError(
                "MariaDB credentials differ from the installed values; normal deployment cannot rotate database users.",
            )
        if previous_compose_exists and not current_secret.is_file():
            raise DeploymentError("An installed MariaDB credential is missing; deployment was not changed.")


def build_managed_files(arguments: argparse.Namespace) -> list[ManagedFile]:
    staged_directory = Path(arguments.staging_directory)
    current_secrets_directory = Path(arguments.current_secrets_directory)

    managed_files = [
        ManagedFile(
            staged_directory / "runtime.env",
            Path(arguments.current_environment_file),
            0o640,
        ),
        ManagedFile(
            staged_directory / "compose.migration.yaml",
            Path(arguments.current_migration_compose_file),
            0o640,
        ),
        ManagedFile(
            staged_directory / "10-create-app-users.sh",
            Path(arguments.current_mariadb_init_script),
            0o555,
        ),
    ]
    managed_files.extend(
        ManagedFile(
            staged_directory / "secrets" / secret_name,
            current_secrets_directory / secret_name,
            0o600,
        )
        for secret_name in SECRET_NAMES
    )
    managed_files.append(
        ManagedFile(
            staged_directory / "compose.yaml",
            Path(arguments.current_compose_file),
            0o640,
        ),
    )

    return managed_files


def maintenance_module():
    spec = importlib.util.spec_from_file_location("hoddmimir_maintenance_upgrade", Path(__file__).with_name("maintenance_upgrade.py"))
    if spec is None or spec.loader is None:
        raise DeploymentError("Maintenance executor is unavailable.")
    module = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(module)
    return module


def _run_transaction_with_lock_held(arguments: argparse.Namespace) -> dict[str, object]:
    if getattr(arguments, 'maintenance_directory', None):
        active = Path(arguments.current_compose_file).parent / '.maintenance-transactions' / 'active.json'
        if active.exists():
            module = maintenance_module()
            try:
                return module.Upgrade(arguments, DockerCompose(arguments.docker_executable, arguments.wait_timeout), sys.modules[__name__]).run(build_managed_files(arguments))
            except module.MaintenanceError as error:
                raise DeploymentError(str(error)) from None
    staged_directory = Path(arguments.staging_directory)
    staged_compose_file = staged_directory / "compose.yaml"
    staged_migration_compose_file = staged_directory / "compose.migration.yaml"
    current_compose_file = Path(arguments.current_compose_file)
    current_migration_compose_file = Path(arguments.current_migration_compose_file)
    current_secrets_directory = Path(arguments.current_secrets_directory)
    expected_services = set(arguments.expected_service)
    application_services = APPLICATION_SERVICES
    docker = DockerCompose(arguments.docker_executable, arguments.wait_timeout)
    managed_files = build_managed_files(arguments)

    if (
        arguments.database_service != "mariadb"
        or expected_services != {arguments.database_service, *application_services}
    ):
        raise DeploymentError("The production service contract must contain exactly four services.")
    for managed_file in managed_files:
        if not managed_file.staged.is_file():
            raise DeploymentError("A required staged deployment file is missing.")

    previous_compose_exists = current_compose_file.is_file()
    validate_encryption_keyring_transition(
        staged_keyring_file=staged_directory / "secrets" / "encryption_key",
        installed_keyring_file=current_secrets_directory / "encryption_key",
        expected_revision_value=arguments.encryption_keyring_revision,
        previous_compose_exists=previous_compose_exists,
    )
    validate_database_credentials(
        staged_directory / "secrets",
        current_secrets_directory,
        previous_compose_exists,
    )
    docker.validate(staged_compose_file)
    docker.validate_migration(staged_compose_file, staged_migration_compose_file)

    if previous_compose_exists and not getattr(arguments, 'maintenance_directory', None):
        docker.allow_schema_changes = False
    maintenance = None
    maintenance_control = None
    if getattr(arguments, 'maintenance_directory', None):
        maintenance = maintenance_module()
        try:
            if previous_compose_exists:
                return maintenance.Upgrade(arguments, docker, sys.modules[__name__]).run(managed_files)
            maintenance_control = maintenance.Control(Path(arguments.maintenance_directory), arguments.maintenance_timeout)
            maintenance_control.initialize(False)
        except maintenance.MaintenanceError as error:
            raise DeploymentError(str(error)) from None

    changed_files = [managed_file for managed_file in managed_files if needs_update(managed_file)]
    previous_secrets_directory_exists = current_secrets_directory.is_dir()
    previous_secrets_directory_mode = (
        stat.S_IMODE(current_secrets_directory.stat().st_mode)
        if previous_secrets_directory_exists
        else None
    )
    secrets_directory_needs_update = (
        not previous_secrets_directory_exists or previous_secrets_directory_mode != 0o700
    )
    if not changed_files and not secrets_directory_needs_update:
        try:
            docker.up_database(current_compose_file)
            docker.bootstrap_database_users(
                current_compose_file,
                arguments.database_service,
                arguments.database_bootstrap_script,
            )
            migration_changed = docker.migrate(
                current_compose_file,
                current_migration_compose_file,
                arguments.migration_service,
            )
            verify_stack(docker, current_compose_file, expected_services, arguments.health_url)
        except DeploymentError as deployment_error:
            raise DeploymentError(
                "Deployment verification failed without changing application files; existing application "
                "services were not stopped. MariaDB DDL may already be applied or partially applied; "
                f"it is forward-only and was not rolled back. Cause: {deployment_error}",
            ) from deployment_error

        return {
            "changed": migration_changed,
            "status": "schema-migrated-and-verified" if migration_changed else "verified-unchanged",
        }

    previous_running_services: set[str] = set()
    if previous_compose_exists:
        previous_running_services = docker.running_services(current_compose_file)

    docker.pull(staged_compose_file)
    current_compose_file.parent.mkdir(parents=True, exist_ok=True)
    backup_directory, snapshots = snapshot_files(managed_files, current_compose_file.parent)
    mutation_started = False
    candidate_services_may_have_started = False

    try:
        mutation_started = True
        current_secrets_directory.mkdir(parents=True, exist_ok=True)
        os.chmod(current_secrets_directory, 0o700)
        for managed_file in changed_files:
            atomic_install(managed_file.staged, managed_file.current, managed_file.mode)

        docker.up_database(current_compose_file)
        docker.bootstrap_database_users(
            current_compose_file,
            arguments.database_service,
            arguments.database_bootstrap_script,
        )
        docker.migrate(
            current_compose_file,
            current_migration_compose_file,
            arguments.migration_service,
        )
        candidate_services_may_have_started = True
        if maintenance is not None:
            operations = maintenance.DockerMaintenance(docker, arguments)
            operations.validate()
            operations.start_frozen()
            operations.wait_web()
        else:
            docker.force_recreate_applications(current_compose_file, application_services)
        verify_stack(docker, current_compose_file, expected_services, arguments.health_url)
    except (DeploymentError, OSError, RuntimeError) as deployment_error:
        if not mutation_started:
            raise
        failure_detail = (
            str(deployment_error)
            if isinstance(deployment_error, DeploymentError)
            else describe_os_error(deployment_error)
        )
        recovery_keyring_retained = recover_transaction(
            docker=docker,
            current_compose_file=current_compose_file,
            current_secrets_directory=current_secrets_directory,
            expected_services=expected_services,
            application_services=application_services,
            health_url=arguments.health_url,
            previous_compose_exists=previous_compose_exists,
            previous_running_services=previous_running_services,
            previous_secrets_directory_exists=previous_secrets_directory_exists,
            previous_secrets_directory_mode=previous_secrets_directory_mode,
            snapshots=snapshots,
            backup_directory=backup_directory,
            candidate_services_may_have_started=candidate_services_may_have_started,
        )
        recovery_status = "Deployment failed; managed application files were restored."
        if previous_compose_exists and previous_running_services:
            if candidate_services_may_have_started:
                recovery_status += " The restored application services were recreated and the stack was verified."
            else:
                recovery_status += " The previously running application services remained running and the stack was verified."
        elif previous_compose_exists:
            recovery_status += " The previously stopped stack remains stopped."
        else:
            recovery_status += " First-deployment containers were stopped and database credentials were retained."
        if recovery_keyring_retained:
            recovery_status += (
                " Encryption keys that may protect candidate writes were retained for recovery and must not be removed."
            )

        raise DeploymentError(
            f"{recovery_status} MariaDB DDL may already be applied or partially applied; "
            f"it is forward-only and was not rolled back. Cause: {failure_detail}",
        ) from deployment_error

    if maintenance_control is not None:
        maintenance_control.phase('open')
    shutil.rmtree(backup_directory)

    return {"changed": True, "status": "deployed"}


def run_transaction(arguments: argparse.Namespace) -> dict[str, object]:
    with deployment_lock(Path(arguments.transaction_lock_file)):
        return _run_transaction_with_lock_held(arguments)


def parse_arguments(argv: Sequence[str]) -> argparse.Namespace:
    parser = argparse.ArgumentParser()
    parser.add_argument("--docker-executable", required=True)
    parser.add_argument("--staging-directory", required=True)
    parser.add_argument("--current-compose-file", required=True)
    parser.add_argument("--current-migration-compose-file", required=True)
    parser.add_argument("--current-environment-file", required=True)
    parser.add_argument("--current-mariadb-init-script", required=True)
    parser.add_argument("--current-secrets-directory", required=True)
    parser.add_argument("--expected-service", action="append", required=True)
    parser.add_argument("--health-url", required=True)
    parser.add_argument("--wait-timeout", type=int, required=True)
    parser.add_argument("--migration-service", required=True)
    parser.add_argument("--database-service", required=True)
    parser.add_argument("--database-bootstrap-script", required=True)
    parser.add_argument("--encryption-keyring-revision", required=True)
    parser.add_argument("--transaction-lock-file", required=True)
    parser.add_argument("--maintenance-directory")
    parser.add_argument("--maintenance-timeout", type=int, default=3600)
    parser.add_argument("--maintenance-external-schedulers-paused", action="store_true")

    return parser.parse_args(argv)


def main(argv: Sequence[str] | None = None) -> int:
    try:
        result = run_transaction(parse_arguments(argv or sys.argv[1:]))
    except (DeploymentError, OSError) as error:
        print(json.dumps({"changed": False, "status": "failed"}, sort_keys=True))
        detail = str(error) if isinstance(error, DeploymentError) else describe_os_error(error)
        print(f"ERROR: {detail}", file=sys.stderr)

        return 1

    print(json.dumps(result, sort_keys=True))

    return 0


if __name__ == "__main__":
    raise SystemExit(main())
