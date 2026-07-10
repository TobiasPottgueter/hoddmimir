from __future__ import annotations

import contextlib
import errno
import http.server
import importlib.util
import io
import json
import os
import stat
import subprocess
import sys
import tempfile
import threading
import unittest
from pathlib import Path
from unittest import mock

ANSIBLE_ROOT = Path(__file__).resolve().parents[1]
REPOSITORY_ROOT = ANSIBLE_ROOT.parents[1]
INVENTORY_SCRIPT = REPOSITORY_ROOT / "scripts" / "init-ansible-inventory.sh"
TRANSACTION_SCRIPT = ANSIBLE_ROOT / "roles" / "hoddmimir" / "files" / "deploy_transaction.py"
FAKE_DOCKER = ANSIBLE_ROOT / "tests" / "support" / "fake_docker.py"
EXPECTED_SERVICES = {"webapp", "data-worker", "backup-worker", "mariadb"}
SECRET_NAMES = (
    "app_secret",
    "encryption_key",
    "mariadb_root_password",
    "mariadb_migration_password",
    "mariadb_web_password",
    "mariadb_collector_password",
    "mariadb_backup_worker_password",
)
DATABASE_SECRET_NAMES = SECRET_NAMES[2:]

TRANSACTION_SPEC = importlib.util.spec_from_file_location("deploy_transaction_under_test", TRANSACTION_SCRIPT)
if TRANSACTION_SPEC is None or TRANSACTION_SPEC.loader is None:
    raise RuntimeError("Cannot load deployment transaction module for tests.")
TRANSACTION_MODULE = importlib.util.module_from_spec(TRANSACTION_SPEC)
sys.modules[TRANSACTION_SPEC.name] = TRANSACTION_MODULE
TRANSACTION_SPEC.loader.exec_module(TRANSACTION_MODULE)


def run(command: list[str], *, cwd: Path = ANSIBLE_ROOT, env: dict[str, str] | None = None) -> subprocess.CompletedProcess[str]:
    return subprocess.run(command, cwd=cwd, env=env, capture_output=True, text=True, check=False)


def valid_variables() -> dict[str, object]:
    variables: dict[str, object] = {
        "hoddmimir_data_worker_image": f"registry.example/data-worker@sha256:{'a' * 64}",
        "hoddmimir_backup_worker_image": f"registry.example/backup-worker@sha256:{'b' * 64}",
        "hoddmimir_webapp_image": f"registry.example/webapp@sha256:{'c' * 64}",
        "hoddmimir_mariadb_image": f"registry.example/mariadb@sha256:{'d' * 64}",
        "hoddmimir_backup_execution_enabled": False,
        "hoddmimir_backup_execution_activation_ack": "",
    }
    for index, secret_name in enumerate(SECRET_NAMES, start=1):
        variables[f"hoddmimir_{secret_name}"] = f"{index:x}" * 64

    return variables


class QuietHealthHandler(http.server.BaseHTTPRequestHandler):
    def do_GET(self) -> None:  # noqa: N802
        self.send_response(200)
        self.end_headers()

    def log_message(self, format: str, *args: object) -> None:
        return


@contextlib.contextmanager
def health_server():
    server = http.server.ThreadingHTTPServer(("127.0.0.1", 0), QuietHealthHandler)
    thread = threading.Thread(target=server.serve_forever, daemon=True)
    thread.start()
    try:
        yield f"http://127.0.0.1:{server.server_port}/api/health"
    finally:
        server.shutdown()
        thread.join()
        server.server_close()


class InventoryGeneratorTest(unittest.TestCase):
    def test_generates_parseable_private_inventory_with_quoted_ipv6(self) -> None:
        with tempfile.TemporaryDirectory() as temporary_directory:
            root = Path(temporary_directory)
            environment_file = root / "deployment.env"
            inventory_file = root / "hosts.yml"
            environment_file.write_text("DEPLOYMENT_HOST=2001:db8::1\nDEPLOYMENT_USER=root\n", encoding="utf-8")
            result = self.run_generator(environment_file, inventory_file)

            self.assertEqual(0, result.returncode, result.stderr)
            self.assertEqual(0o600, stat.S_IMODE(inventory_file.stat().st_mode))
            host_result = run(["ansible-inventory", "--inventory", str(inventory_file), "--host", "hoddmimir-production"])
            self.assertEqual(0, host_result.returncode, host_result.stderr)
            host = json.loads(host_result.stdout)
            self.assertEqual("2001:db8::1", host["ansible_host"])
            self.assertEqual("hoddmimir.localdomain", host["alpine_base_hoddmimir_fqdn"])

            inventory_result = run(["ansible-inventory", "--inventory", str(inventory_file), "--list"])
            self.assertEqual(0, inventory_result.returncode, inventory_result.stderr)
            inventory = json.loads(inventory_result.stdout)
            self.assertIn("hoddmimir_hosts", inventory)
            self.assertIn("hoddmimir-production", inventory["hoddmimir_hosts"]["hosts"])

    def test_derives_dns_fqdn_and_accepts_an_explicit_fqdn_for_ip_hosts(self) -> None:
        cases = (
            (
                "DEPLOYMENT_HOST=hoddmimir.example.invalid\nDEPLOYMENT_USER=root\n",
                "hoddmimir.example.invalid",
            ),
            (
                "DEPLOYMENT_HOST=192.0.2.10\nDEPLOYMENT_USER=root\nDEPLOYMENT_FQDN=hoddmimir.example.invalid\n",
                "hoddmimir.example.invalid",
            ),
        )
        for content, expected_fqdn in cases:
            with self.subTest(content=content), tempfile.TemporaryDirectory() as temporary_directory:
                root = Path(temporary_directory)
                environment_file = root / "deployment.env"
                inventory_file = root / "hosts.yml"
                environment_file.write_text(content, encoding="utf-8")

                result = self.run_generator(environment_file, inventory_file)

                self.assertEqual(0, result.returncode, result.stderr)
                host_result = run(
                    ["ansible-inventory", "--inventory", str(inventory_file), "--host", "hoddmimir-production"],
                )
                self.assertEqual(0, host_result.returncode, host_result.stderr)
                self.assertEqual(expected_fqdn, json.loads(host_result.stdout)["alpine_base_hoddmimir_fqdn"])

    def test_rejects_missing_or_unsafe_values_without_clobbering_inventory(self) -> None:
        for content in (
            "DEPLOYMENT_HOST=example.invalid\n",
            "DEPLOYMENT_HOST=$(touch /tmp/nope)\nDEPLOYMENT_USER=root\n",
            "DEPLOYMENT_HOST=example.invalid\nDEPLOYMENT_USER=root\nDEPLOYMENT_FQDN=$(touch /tmp/nope)\n",
        ):
            with self.subTest(content=content), tempfile.TemporaryDirectory() as temporary_directory:
                root = Path(temporary_directory)
                environment_file = root / "deployment.env"
                inventory_file = root / "hosts.yml"
                environment_file.write_text(content, encoding="utf-8")
                inventory_file.write_text("sentinel\n", encoding="utf-8")
                result = self.run_generator(environment_file, inventory_file)

                self.assertNotEqual(0, result.returncode)
                self.assertEqual("sentinel\n", inventory_file.read_text(encoding="utf-8"))

    @staticmethod
    def run_generator(environment_file: Path, inventory_file: Path) -> subprocess.CompletedProcess[str]:
        environment = os.environ.copy()
        environment["DEPLOYMENT_ENV_FILE"] = str(environment_file)
        environment["ANSIBLE_INVENTORY_FILE"] = str(inventory_file)

        return run([str(INVENTORY_SCRIPT)], cwd=REPOSITORY_ROOT, env=environment)


class PreflightTest(unittest.TestCase):
    def test_accepts_valid_configuration_and_explicit_backup_acknowledgement(self) -> None:
        variables = valid_variables()
        self.assertEqual(0, self.run_preflight(variables).returncode)
        variables["hoddmimir_backup_execution_enabled"] = True
        variables["hoddmimir_backup_execution_activation_ack"] = "ENABLE_PRODUCTION_BACKUPS"
        self.assertEqual(0, self.run_preflight(variables).returncode)

    def test_rejects_mutable_or_invalid_digest_for_each_application_image(self) -> None:
        image_variables = (
            "hoddmimir_webapp_image",
            "hoddmimir_data_worker_image",
            "hoddmimir_backup_worker_image",
        )
        invalid_references = (
            "registry.example/application:latest",
            f"registry.example/application@sha256:{'a' * 63}",
            f"registry.example/application@sha256:{'A' * 64}",
        )
        for image_variable in image_variables:
            for invalid_reference in invalid_references:
                with self.subTest(image_variable=image_variable, invalid_reference=invalid_reference):
                    variables = valid_variables()
                    variables[image_variable] = invalid_reference
                    self.assertNotEqual(0, self.run_preflight(variables).returncode)

    def test_rejects_unacknowledged_backup_execution(self) -> None:
        variables = valid_variables()
        variables["hoddmimir_backup_execution_enabled"] = True
        result = self.run_preflight(variables)
        self.assertNotEqual(0, result.returncode)

    def test_secret_validation_does_not_echo_rejected_value(self) -> None:
        variables = valid_variables()
        rejected_secret = "NOT_A_VALID_SECRET"
        variables["hoddmimir_app_secret"] = rejected_secret
        result = self.run_preflight(variables)

        self.assertNotEqual(0, result.returncode)
        self.assertNotIn(rejected_secret, result.stdout + result.stderr)

    @staticmethod
    def run_preflight(variables: dict[str, object]) -> subprocess.CompletedProcess[str]:
        with tempfile.TemporaryDirectory() as local_temp:
            environment = os.environ.copy()
            environment["ANSIBLE_LOCAL_TEMP"] = local_temp
            return run(
                [
                    "ansible-playbook",
                    "--inventory",
                    "localhost,",
                    "tests/playbooks/preflight.yml",
                    "--extra-vars",
                    json.dumps(variables),
                ],
                env=environment,
            )


class ComposeContractTest(unittest.TestCase):
    def test_rendered_production_contract_is_safe_and_exact(self) -> None:
        with tempfile.TemporaryDirectory() as temporary_directory:
            root = Path(temporary_directory)
            compose_file = root / "compose.yaml"
            (root / "runtime.env").write_text(
                "APP_ENV=prod\nAPP_DEBUG=0\nAPP_TIMEZONE=UTC\n"
                "COLLECTOR_INTERVAL_SECONDS=60\nBACKUP_WORKER_POLL_INTERVAL_SECONDS=5\n"
                "BACKUP_EXECUTION_ENABLED=false\n",
                encoding="utf-8",
            )
            secrets_directory = root / "secrets"
            secrets_directory.mkdir()
            for secret_name in SECRET_NAMES:
                (secrets_directory / secret_name).write_text("test\n", encoding="utf-8")
            init_script = root / "10-create-app-users.sh"
            variables = valid_variables() | {
                "hoddmimir_test_compose_output": str(compose_file),
                "hoddmimir_test_mariadb_init_output": str(init_script),
                "hoddmimir_secrets_directory": str(secrets_directory),
                "hoddmimir_mariadb_init_script": str(init_script),
            }
            rendered = run(
                [
                    "ansible-playbook",
                    "--inventory",
                    "localhost,",
                    "tests/playbooks/render-compose.yml",
                    "--extra-vars",
                    json.dumps(variables),
                ],
            )
            self.assertEqual(0, rendered.returncode, rendered.stderr)
            configured = run(["docker", "compose", "--file", str(compose_file), "config", "--format", "json"])
            self.assertEqual(0, configured.returncode, configured.stderr)
            model = json.loads(configured.stdout)
            services = model["services"]

            self.assertEqual("hoddmimir", model["name"])
            self.assertEqual(EXPECTED_SERVICES, set(services))
            self.assertTrue(all("build" not in service for service in services.values()))
            self.assertTrue(all("@sha256:" in service["image"] for service in services.values()))
            self.assertEqual("hoddmimir", services["mariadb"]["environment"]["MARIADB_DATABASE"])
            self.assertEqual("hoddmimir", services["data-worker"]["environment"]["DATABASE_NAME"])
            self.assertEqual("hoddmimir_collector", services["data-worker"]["environment"]["DATABASE_USER"])
            self.assertEqual("hoddmimir_backup_worker", services["backup-worker"]["environment"]["DATABASE_USER"])
            self.assertEqual("hoddmimir_web", services["webapp"]["environment"]["DATABASE_USER"])
            self.assertEqual("false", services["backup-worker"]["environment"]["BACKUP_EXECUTION_ENABLED"])
            self.assertEqual(
                ["hoddmimir:worker:data", "--interval=60"],
                services["data-worker"]["command"],
            )
            self.assertEqual(
                ["hoddmimir:worker:backup", "--interval=5"],
                services["backup-worker"]["command"],
            )
            self.assertEqual(
                ["CMD", "php", "bin/console", "hoddmimir:worker:readiness", "collector"],
                services["data-worker"]["healthcheck"]["test"],
            )
            self.assertEqual(
                ["CMD", "php", "bin/console", "hoddmimir:worker:readiness", "backup"],
                services["backup-worker"]["healthcheck"]["test"],
            )
            self.assertNotIn("ports", services["mariadb"])

            users = init_script.read_text(encoding="utf-8")
            for database_user in (
                "hoddmimir_migration",
                "hoddmimir_web",
                "hoddmimir_collector",
                "hoddmimir_backup_worker",
            ):
                self.assertIn(f"CREATE USER IF NOT EXISTS '{database_user}'@'%'", users)


class DeploymentTransactionTest(unittest.TestCase):
    def setUp(self) -> None:
        self.temporary_directory = tempfile.TemporaryDirectory()
        self.root = Path(self.temporary_directory.name)
        self.reset_case("default")

    def reset_case(self, name: str) -> None:
        case_root = self.root / name
        self.staging = case_root / "staging"
        self.install = case_root / "install"
        self.secrets = case_root / "secrets"
        self.state_file = case_root / "fake-docker.json"
        self.write_staged("new")

    def tearDown(self) -> None:
        self.temporary_directory.cleanup()

    def test_first_deployment_installs_private_files(self) -> None:
        self.write_state([])
        result = self.run_transaction()

        self.assertEqual(0, result.returncode, result.stderr)
        self.assertTrue(json.loads(result.stdout)["changed"])
        self.assertEqual(0o700, stat.S_IMODE(self.secrets.stat().st_mode))
        for secret_name in SECRET_NAMES:
            self.assertEqual(0o600, stat.S_IMODE((self.secrets / secret_name).stat().st_mode))
        self.assertEqual(0o640, stat.S_IMODE((self.install / "compose.yaml").stat().st_mode))
        self.assertEqual(0o640, stat.S_IMODE((self.install / "runtime.env").stat().st_mode))
        self.assertEqual(0o555, stat.S_IMODE((self.install / "10-create-app-users.sh").stat().st_mode))
        self.assertEqual(["config", "pull", "up", "ps"], self.operations())
        self.assert_safe_docker_calls()

    def test_unchanged_verify_failure_never_stops_stack(self) -> None:
        self.install_current("new")
        self.write_state(sorted(EXPECTED_SERVICES), failures={"ps": [1]})
        before = self.current_bytes()
        result = self.run_transaction()

        self.assertNotEqual(0, result.returncode)
        self.assertEqual(before, self.current_bytes())
        self.assertEqual(["config", "ps"], self.operations())
        self.assert_safe_docker_calls()

    def test_changed_verify_failure_restores_all_files_and_reverifies(self) -> None:
        self.install_current("old")
        self.write_state(sorted(EXPECTED_SERVICES), failures={"ps": [2]})
        before = self.current_bytes()
        result = self.run_transaction()

        self.assertNotEqual(0, result.returncode)
        self.assertEqual(before, self.current_bytes())
        self.assertEqual(["config", "ps", "pull", "up", "ps", "up", "ps"], self.operations())
        self.assertNotIn("down", self.operations())
        self.assert_safe_docker_calls()

    def test_failed_first_deployment_stops_containers_but_preserves_database_credentials(self) -> None:
        self.write_state([], failures={"ps": [1]})
        result = self.run_transaction()

        self.assertNotEqual(0, result.returncode)
        self.assertEqual(["config", "pull", "up", "ps", "down"], self.operations())
        self.assertFalse((self.install / "compose.yaml").exists())
        self.assertFalse((self.install / "runtime.env").exists())
        self.assertFalse((self.install / "10-create-app-users.sh").exists())
        self.assertFalse((self.secrets / "app_secret").exists())
        self.assertFalse((self.secrets / "encryption_key").exists())
        for secret_name in DATABASE_SECRET_NAMES:
            self.assertTrue((self.secrets / secret_name).is_file())
            self.assertEqual(0o600, stat.S_IMODE((self.secrets / secret_name).stat().st_mode))
        self.assert_safe_docker_calls()

    def test_database_credential_change_is_blocked_before_docker_or_file_mutation(self) -> None:
        self.install_current("old")
        (self.staging / "secrets" / "mariadb_web_password").write_text("f" * 64 + "\n", encoding="utf-8")
        before = self.current_bytes()
        self.write_state(sorted(EXPECTED_SERVICES))
        result = self.run_transaction()

        self.assertNotEqual(0, result.returncode)
        self.assertEqual(before, self.current_bytes())
        self.assertEqual([], self.operations())
        self.assertIn("cannot rotate database users", result.stderr)

    def test_each_install_failure_restores_bytes_modes_and_removes_backup(self) -> None:
        managed_names = (
            "runtime.env",
            "10-create-app-users.sh",
            *SECRET_NAMES,
            "compose.yaml",
        )
        real_atomic_install = TRANSACTION_MODULE.atomic_install

        for index, managed_name in enumerate(managed_names):
            with self.subTest(managed_name=managed_name):
                self.reset_case(f"install-failure-{index}")
                self.install_current("old")
                self.make_all_install_points_changed()
                self.write_state(sorted(EXPECTED_SERVICES))
                before = self.current_state()
                target = self.current_path(managed_name)
                injected = False

                def fail_once(source: Path, destination: Path, mode: int) -> None:
                    nonlocal injected
                    if not injected and destination == target and source.is_relative_to(self.staging):
                        injected = True
                        raise OSError(errno.ENOSPC, "injected disk full")
                    real_atomic_install(source, destination, mode)

                with mock.patch.object(TRANSACTION_MODULE, "atomic_install", side_effect=fail_once):
                    error = self.run_transaction_in_process()

                self.assertTrue(injected)
                self.assertIsInstance(error, TRANSACTION_MODULE.DeploymentError)
                self.assertIn("restored and verified", str(error))
                self.assertEqual(before, self.current_state())
                self.assertEqual([], list(self.install.glob(".deployment-backup-*")))
                self.assert_safe_docker_calls()

    def test_restore_error_keeps_backup_and_reports_manual_recovery(self) -> None:
        self.install_current("old")
        self.write_state(sorted(EXPECTED_SERVICES), failures={"ps": [2]})
        real_atomic_install = TRANSACTION_MODULE.atomic_install

        def fail_restore(source: Path, destination: Path, mode: int) -> None:
            if source.parent.name.startswith(".deployment-backup-"):
                raise OSError(errno.EIO, "injected restore failure")
            real_atomic_install(source, destination, mode)

        with mock.patch.object(TRANSACTION_MODULE, "atomic_install", side_effect=fail_restore):
            error = self.run_transaction_in_process()

        self.assertIn("Deployment recovery failed", str(error))
        self.assertIn("manual recovery is required", str(error))
        self.assertEqual(1, len(list(self.install.glob(".deployment-backup-*"))))

    def test_failed_rollback_verification_is_nonzero_and_keeps_backup(self) -> None:
        self.install_current("old")
        before = self.current_state()
        self.write_state(sorted(EXPECTED_SERVICES), failures={"ps": [2, 3]})
        result = self.run_transaction()

        self.assertNotEqual(0, result.returncode)
        self.assertIn("Deployment recovery failed", result.stderr)
        self.assertNotIn("status\": \"deployed", result.stdout)
        self.assertEqual(before, self.current_state())
        self.assertEqual(1, len(list(self.install.glob(".deployment-backup-*"))))
        self.assert_safe_docker_calls()

    def test_main_converts_oserror_to_safe_nonzero_result_without_traceback(self) -> None:
        output = io.StringIO()
        errors = io.StringIO()
        with (
            mock.patch.object(TRANSACTION_MODULE, "run_transaction", side_effect=OSError(errno.EACCES, "denied")),
            contextlib.redirect_stdout(output),
            contextlib.redirect_stderr(errors),
        ):
            result = TRANSACTION_MODULE.main(self.transaction_arguments("http://127.0.0.1:1/api/health"))

        self.assertEqual(1, result)
        self.assertEqual("failed", json.loads(output.getvalue())["status"])
        self.assertIn("PermissionError", errors.getvalue())
        self.assertNotIn("Traceback", errors.getvalue())

    def test_fake_docker_hard_rejects_volume_deletion(self) -> None:
        self.install.mkdir(parents=True, exist_ok=True)
        compose_file = self.install / "compose.yaml"
        compose_file.write_text("compose\n", encoding="utf-8")
        self.write_state(sorted(EXPECTED_SERVICES))
        environment = os.environ.copy()
        environment["FAKE_DOCKER_STATE"] = str(self.state_file)
        result = run(
            [str(FAKE_DOCKER), "compose", "--file", str(compose_file), "down", "--volumes"],
            env=environment,
        )

        self.assertEqual(97, result.returncode)
        self.assertTrue(json.loads(self.state_file.read_text(encoding="utf-8"))["forbidden_volume_delete"])

    def write_staged(self, marker: str) -> None:
        (self.staging / "secrets").mkdir(parents=True, exist_ok=True)
        (self.staging / "compose.yaml").write_text(f"compose-{marker}\n", encoding="utf-8")
        (self.staging / "runtime.env").write_text(f"RUNTIME={marker}\n", encoding="utf-8")
        (self.staging / "10-create-app-users.sh").write_text(f"init-{marker}\n", encoding="utf-8")
        for index, secret_name in enumerate(SECRET_NAMES, start=1):
            value = f"{index:x}" * 64 if secret_name in DATABASE_SECRET_NAMES else f"{marker}-{secret_name}"
            (self.staging / "secrets" / secret_name).write_text(value + "\n", encoding="utf-8")

    def install_current(self, marker: str) -> None:
        self.install.mkdir(parents=True, exist_ok=True)
        self.secrets.mkdir(parents=True, exist_ok=True)
        (self.install / "compose.yaml").write_text(f"compose-{marker}\n", encoding="utf-8")
        (self.install / "runtime.env").write_text(f"RUNTIME={marker}\n", encoding="utf-8")
        (self.install / "10-create-app-users.sh").write_text(f"init-{marker}\n", encoding="utf-8")
        for index, secret_name in enumerate(SECRET_NAMES, start=1):
            value = f"{index:x}" * 64 if secret_name in DATABASE_SECRET_NAMES else f"{marker}-{secret_name}"
            (self.secrets / secret_name).write_text(value + "\n", encoding="utf-8")

    def make_all_install_points_changed(self) -> None:
        modes = {
            "runtime.env": 0o600,
            "10-create-app-users.sh": 0o500,
            "app_secret": 0o640,
            "encryption_key": 0o400,
            "mariadb_root_password": 0o640,
            "mariadb_migration_password": 0o640,
            "mariadb_web_password": 0o640,
            "mariadb_collector_password": 0o640,
            "mariadb_backup_worker_password": 0o640,
            "compose.yaml": 0o600,
        }
        for name, mode in modes.items():
            self.current_path(name).chmod(mode)

    def write_state(self, running_services: list[str], failures: dict[str, list[int]] | None = None) -> None:
        self.state_file.write_text(
            json.dumps(
                {
                    "expected_services": sorted(EXPECTED_SERVICES),
                    "running_services": running_services,
                    "failures": failures or {},
                    "calls": [],
                },
            ),
            encoding="utf-8",
        )

    def run_transaction(self) -> subprocess.CompletedProcess[str]:
        environment = os.environ.copy()
        environment["FAKE_DOCKER_STATE"] = str(self.state_file)
        with health_server() as health_url:
            command = [sys.executable, str(TRANSACTION_SCRIPT), *self.transaction_arguments(health_url)]
            return run(command, env=environment)

    def run_transaction_in_process(self):
        environment = os.environ.copy()
        environment["FAKE_DOCKER_STATE"] = str(self.state_file)
        with health_server() as health_url, mock.patch.dict(os.environ, environment, clear=True):
            arguments = TRANSACTION_MODULE.parse_arguments(self.transaction_arguments(health_url))
            try:
                TRANSACTION_MODULE.run_transaction(arguments)
            except TRANSACTION_MODULE.DeploymentError as error:
                return error
        self.fail("Deployment transaction unexpectedly succeeded.")

    def transaction_arguments(self, health_url: str) -> list[str]:
        arguments = [
            f"--docker-executable={FAKE_DOCKER}",
            f"--staging-directory={self.staging}",
            f"--current-compose-file={self.install / 'compose.yaml'}",
            f"--current-environment-file={self.install / 'runtime.env'}",
            f"--current-mariadb-init-script={self.install / '10-create-app-users.sh'}",
            f"--current-secrets-directory={self.secrets}",
            f"--health-url={health_url}",
            "--wait-timeout=10",
        ]
        arguments.extend(f"--expected-service={service}" for service in sorted(EXPECTED_SERVICES))
        return arguments

    def operations(self) -> list[str]:
        state = json.loads(self.state_file.read_text(encoding="utf-8"))
        return [call["operation"] for call in state["calls"]]

    def assert_safe_docker_calls(self) -> None:
        expected_arguments = {
            "config": ["config", "--quiet"],
            "pull": ["pull"],
            "up": ["up", "--detach", "--remove-orphans", "--wait", "--wait-timeout", "10"],
            "ps": ["ps", "--services", "--filter", "status=running"],
            "down": ["down", "--remove-orphans"],
        }
        state = json.loads(self.state_file.read_text(encoding="utf-8"))
        self.assertFalse(state.get("forbidden_volume_delete", False))
        for call in state["calls"]:
            self.assertEqual(expected_arguments[call["operation"]], call["arguments"])

    def current_bytes(self) -> dict[str, bytes]:
        files = [self.install / "compose.yaml", self.install / "runtime.env", self.install / "10-create-app-users.sh"]
        files.extend(self.secrets / secret_name for secret_name in SECRET_NAMES)
        return {str(path): path.read_bytes() for path in files if path.is_file()}

    def current_state(self) -> dict[str, tuple[bytes, int]]:
        files = [self.install / "compose.yaml", self.install / "runtime.env", self.install / "10-create-app-users.sh"]
        files.extend(self.secrets / secret_name for secret_name in SECRET_NAMES)
        return {
            str(path): (path.read_bytes(), stat.S_IMODE(path.stat().st_mode))
            for path in files
            if path.is_file()
        }

    def current_path(self, name: str) -> Path:
        if name in SECRET_NAMES:
            return self.secrets / name
        return self.install / name


if __name__ == "__main__":
    unittest.main()
