#!/usr/bin/env python3
"""Apply a Hoddmímir deployment as a recoverable local file transaction."""

from __future__ import annotations

import argparse
import json
import os
import shutil
import stat
import subprocess
import sys
import tempfile
import urllib.error
import urllib.request
from dataclasses import dataclass
from pathlib import Path
from typing import Sequence

SECRET_NAMES = (
    "app_secret",
    "encryption_key",
    "mariadb_root_password",
    "mariadb_migration_password",
    "mariadb_web_password",
    "mariadb_collector_password",
    "mariadb_backup_worker_password",
)
DATABASE_SECRET_NAMES = (
    "mariadb_root_password",
    "mariadb_migration_password",
    "mariadb_web_password",
    "mariadb_collector_password",
    "mariadb_backup_worker_password",
)


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


class DockerCompose:
    def __init__(self, executable: str, wait_timeout: int) -> None:
        self.executable = executable
        self.wait_timeout = wait_timeout

    def _run(self, compose_file: Path, arguments: Sequence[str]) -> subprocess.CompletedProcess[str]:
        result = subprocess.run(
            [self.executable, "compose", "--file", str(compose_file), *arguments],
            check=False,
            capture_output=True,
            text=True,
        )
        if result.returncode != 0:
            operation = arguments[0] if arguments else "command"
            raise DeploymentError(f"Docker Compose {operation} failed with exit code {result.returncode}.")

        return result

    def validate(self, compose_file: Path) -> None:
        self._run(compose_file, ("config", "--quiet"))

    def pull(self, compose_file: Path) -> None:
        self._run(compose_file, ("pull",))

    def up(self, compose_file: Path) -> None:
        self._run(
            compose_file,
            (
                "up",
                "--detach",
                "--remove-orphans",
                "--wait",
                "--wait-timeout",
                str(self.wait_timeout),
            ),
        )

    def down(self, compose_file: Path) -> None:
        self._run(compose_file, ("down", "--remove-orphans"))

    def running_services(self, compose_file: Path) -> set[str]:
        result = self._run(
            compose_file,
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
    destination.parent.mkdir(parents=True, exist_ok=True)
    descriptor, temporary_name = tempfile.mkstemp(
        prefix=f".{destination.name}.",
        dir=destination.parent,
    )
    temporary_path = Path(temporary_name)

    try:
        with os.fdopen(descriptor, "wb") as temporary_file:
            temporary_file.write(source.read_bytes())
            temporary_file.flush()
            os.fsync(temporary_file.fileno())
        os.chmod(temporary_path, mode)
        os.replace(temporary_path, destination)
    finally:
        temporary_path.unlink(missing_ok=True)


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
    health_url: str,
    previous_compose_exists: bool,
    previous_running_services: set[str],
    previous_secrets_directory_exists: bool,
    previous_secrets_directory_mode: int | None,
    snapshots: Sequence[Snapshot],
    backup_directory: Path,
) -> None:
    try:
        if previous_compose_exists:
            restore_files(snapshots)
            if previous_secrets_directory_mode is not None:
                os.chmod(current_secrets_directory, previous_secrets_directory_mode)
            if previous_running_services:
                docker.up(current_compose_file)
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
            restore_files(snapshots, retain_new=retained_database_secrets)
            if previous_secrets_directory_exists and previous_secrets_directory_mode is not None:
                os.chmod(current_secrets_directory, previous_secrets_directory_mode)
            elif current_secrets_directory.is_dir() and not any(current_secrets_directory.iterdir()):
                current_secrets_directory.rmdir()
            if down_error is not None:
                raise down_error

        shutil.rmtree(backup_directory)
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


def run_transaction(arguments: argparse.Namespace) -> dict[str, object]:
    staged_directory = Path(arguments.staging_directory)
    staged_compose_file = staged_directory / "compose.yaml"
    current_compose_file = Path(arguments.current_compose_file)
    current_secrets_directory = Path(arguments.current_secrets_directory)
    expected_services = set(arguments.expected_service)
    docker = DockerCompose(arguments.docker_executable, arguments.wait_timeout)
    managed_files = build_managed_files(arguments)

    if len(expected_services) != 4:
        raise DeploymentError("The production service contract must contain exactly four services.")
    for managed_file in managed_files:
        if not managed_file.staged.is_file():
            raise DeploymentError("A required staged deployment file is missing.")

    previous_compose_exists = current_compose_file.is_file()
    validate_database_credentials(
        staged_directory / "secrets",
        current_secrets_directory,
        previous_compose_exists,
    )
    docker.validate(staged_compose_file)

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
        verify_stack(docker, current_compose_file, expected_services, arguments.health_url)

        return {"changed": False, "status": "verified-unchanged"}

    previous_running_services: set[str] = set()
    if previous_compose_exists:
        previous_running_services = docker.running_services(current_compose_file)

    docker.pull(staged_compose_file)
    current_compose_file.parent.mkdir(parents=True, exist_ok=True)
    backup_directory, snapshots = snapshot_files(managed_files, current_compose_file.parent)
    mutation_started = False

    try:
        mutation_started = True
        current_secrets_directory.mkdir(parents=True, exist_ok=True)
        os.chmod(current_secrets_directory, 0o700)
        for managed_file in changed_files:
            atomic_install(managed_file.staged, managed_file.current, managed_file.mode)

        docker.up(current_compose_file)
        verify_stack(docker, current_compose_file, expected_services, arguments.health_url)
    except (DeploymentError, OSError) as deployment_error:
        if not mutation_started:
            raise
        recover_transaction(
            docker=docker,
            current_compose_file=current_compose_file,
            current_secrets_directory=current_secrets_directory,
            expected_services=expected_services,
            health_url=arguments.health_url,
            previous_compose_exists=previous_compose_exists,
            previous_running_services=previous_running_services,
            previous_secrets_directory_exists=previous_secrets_directory_exists,
            previous_secrets_directory_mode=previous_secrets_directory_mode,
            snapshots=snapshots,
            backup_directory=backup_directory,
        )
        raise DeploymentError("Deployment failed; the previous state was restored and verified.") from deployment_error

    shutil.rmtree(backup_directory)

    return {"changed": True, "status": "deployed"}


def parse_arguments(argv: Sequence[str]) -> argparse.Namespace:
    parser = argparse.ArgumentParser()
    parser.add_argument("--docker-executable", required=True)
    parser.add_argument("--staging-directory", required=True)
    parser.add_argument("--current-compose-file", required=True)
    parser.add_argument("--current-environment-file", required=True)
    parser.add_argument("--current-mariadb-init-script", required=True)
    parser.add_argument("--current-secrets-directory", required=True)
    parser.add_argument("--expected-service", action="append", required=True)
    parser.add_argument("--health-url", required=True)
    parser.add_argument("--wait-timeout", type=int, required=True)

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
