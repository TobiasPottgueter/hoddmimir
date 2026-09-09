from __future__ import annotations

import contextlib
import errno
import fcntl
import grp
import http.server
import importlib.util
import io
import json
import os
import pwd
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
    "matrix_webhook_url",
)
DATABASE_SECRET_NAMES = (
    "mariadb_root_password",
    "mariadb_migration_password",
    "mariadb_web_password",
    "mariadb_collector_password",
    "mariadb_backup_worker_password",
)
KEY_MATERIAL_OLD = "a" * 64
KEY_MATERIAL_NEW = "b" * 64

TRANSACTION_SPEC = importlib.util.spec_from_file_location("deploy_transaction_under_test", TRANSACTION_SCRIPT)
if TRANSACTION_SPEC is None or TRANSACTION_SPEC.loader is None:
    raise RuntimeError("Cannot load deployment transaction module for tests.")
TRANSACTION_MODULE = importlib.util.module_from_spec(TRANSACTION_SPEC)
sys.modules[TRANSACTION_SPEC.name] = TRANSACTION_MODULE
TRANSACTION_SPEC.loader.exec_module(TRANSACTION_MODULE)


def run(command: list[str], *, cwd: Path = ANSIBLE_ROOT, env: dict[str, str] | None = None) -> subprocess.CompletedProcess[str]:
    return subprocess.run(command, cwd=cwd, env=env, capture_output=True, text=True, check=False)


def valid_variables() -> dict[str, object]:
    image_repository = "registry.example/hoddmimir"
    worker_image = f"{image_repository}/worker@sha256:{'a' * 64}"
    variables: dict[str, object] = {
        "hoddmimir_image_repository": image_repository,
        "hoddmimir_registry_index_digests": {
            "worker": "sha256:" + "1" * 64,
            "web": "sha256:" + "2" * 64,
            "mariadb": "sha256:" + "3" * 64,
        },
        "hoddmimir_data_worker_image": worker_image,
        "hoddmimir_backup_worker_image": worker_image,
        "hoddmimir_webapp_image": f"{image_repository}/web@sha256:{'c' * 64}",
        "hoddmimir_mariadb_image": f"{image_repository}/mariadb@sha256:{'d' * 64}",
        "hoddmimir_backup_execution_enabled": False,
        "hoddmimir_backup_execution_activation_ack": "",
        "hoddmimir_timezone": "UTC",
        "hoddmimir_public_domain": "hoddmimir.example.test",
        "hoddmimir_acme_email": "acme@example.test",
        "hoddmimir_hetzner_dns_api_token": "test_token_" + "h" * 54,
        "hoddmimir_docker_subnet": "172.31.253.0/24",
        "hoddmimir_docker_gateway": "172.31.253.1",
        "hoddmimir_matrix_webhook_url": "https://matrix.example.test/hook/secret",
        "hoddmimir_encryption_keyring": {
            "format": 1,
            "revision": 7,
            "primaryKeyId": "k2026_07",
            "keys": [
                {
                    "id": "k2026_07",
                    "material": "2" * 64,
                },
            ],
        },
    }
    for index, secret_name in enumerate(SECRET_NAMES, start=1):
        if secret_name == "encryption_key":
            continue
        if secret_name != "matrix_webhook_url":
            variables[f"hoddmimir_{secret_name}"] = f"{index:x}" * 64

    return variables


class QuietHealthHandler(http.server.BaseHTTPRequestHandler):
    def do_GET(self) -> None:  # noqa: N802
        self.send_response(200)
        self.end_headers()

    def log_message(self, format: str, *args: object) -> None:
        return


@contextlib.contextmanager
def health_server(response_statuses: tuple[int, ...] = (200,)):
    pending_statuses = list(response_statuses)
    fallback_status = response_statuses[-1]
    status_lock = threading.Lock()

    class SequencedHealthHandler(QuietHealthHandler):
        def do_GET(self) -> None:  # noqa: N802
            with status_lock:
                status = pending_statuses.pop(0) if pending_statuses else fallback_status
            self.send_response(status)
            self.end_headers()

    server = http.server.ThreadingHTTPServer(("127.0.0.1", 0), SequencedHealthHandler)
    thread = threading.Thread(target=server.serve_forever, daemon=True)
    thread.start()
    try:
        yield f"http://127.0.0.1:{server.server_port}/api/health"
    finally:
        server.shutdown()
        thread.join()
        server.server_close()


class InventoryGeneratorTest(unittest.TestCase):
    PRODUCTION_SETTINGS = (
        "HODDMIMIR_PUBLIC_DOMAIN=hoddmimir.example.test\n"
        "HODDMIMIR_ACME_EMAIL=acme@example.test\n"
        "HODDMIMIR_DOCKER_SUBNET=172.31.253.0/24\n"
        "HODDMIMIR_DOCKER_GATEWAY=172.31.253.1\n"
    )

    def test_generates_parseable_private_inventory_with_quoted_ipv6(self) -> None:
        with tempfile.TemporaryDirectory() as temporary_directory:
            root = Path(temporary_directory)
            environment_file = root / "deployment.env"
            inventory_file = root / "hosts.yml"
            environment_file.write_text(
                "DEPLOYMENT_HOST=2001:db8::1\nDEPLOYMENT_USER=root\n" + self.PRODUCTION_SETTINGS,
                encoding="utf-8",
            )
            result = self.run_generator(environment_file, inventory_file)

            self.assertEqual(0, result.returncode, result.stderr)
            self.assertEqual(0o600, stat.S_IMODE(inventory_file.stat().st_mode))
            group_vars_file = inventory_file.with_name("main.yml")
            self.assertEqual(0o600, stat.S_IMODE(group_vars_file.stat().st_mode))
            self.assertIn(
                'hoddmimir_public_domain: "hoddmimir.example.test"',
                group_vars_file.read_text(encoding="utf-8"),
            )
            self.assertIn(
                'hoddmimir_image_repository: ""',
                group_vars_file.read_text(encoding="utf-8"),
            )
            self.assertIn(
                "hoddmimir_registry_index_digests: {}",
                group_vars_file.read_text(encoding="utf-8"),
            )
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
                environment_file.write_text(content + self.PRODUCTION_SETTINGS, encoding="utf-8")

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
                environment_file.write_text(content + self.PRODUCTION_SETTINGS, encoding="utf-8")
                inventory_file.write_text("sentinel\n", encoding="utf-8")
                result = self.run_generator(environment_file, inventory_file)

                self.assertNotEqual(0, result.returncode)
                self.assertEqual("sentinel\n", inventory_file.read_text(encoding="utf-8"))

    @staticmethod
    def run_generator(environment_file: Path, inventory_file: Path) -> subprocess.CompletedProcess[str]:
        environment = os.environ.copy()
        environment["DEPLOYMENT_ENV_FILE"] = str(environment_file)
        environment["ANSIBLE_INVENTORY_FILE"] = str(inventory_file)
        environment["ANSIBLE_GROUP_VARS_FILE"] = str(inventory_file.with_name("main.yml"))

        return run([str(INVENTORY_SCRIPT)], cwd=REPOSITORY_ROOT, env=environment)


class PreflightTest(unittest.TestCase):
    def test_accepts_valid_configuration_and_explicit_backup_acknowledgement(self) -> None:
        variables = valid_variables()
        self.assertEqual(0, self.run_preflight(variables).returncode)
        variables["hoddmimir_backup_execution_enabled"] = True
        variables["hoddmimir_backup_execution_activation_ack"] = "ENABLE_PRODUCTION_BACKUPS"
        variables["hoddmimir_matrix_notifications_enabled"] = True
        variables["hoddmimir_matrix_webhook_url"] = "https://matrix.example.test/hook/production"
        self.assertEqual(0, self.run_preflight(variables).returncode)

    def test_rejects_mutable_or_invalid_digest_for_each_application_image(self) -> None:
        image_variables = (
            "hoddmimir_webapp_image",
            "hoddmimir_data_worker_image",
            "hoddmimir_backup_worker_image",
            "hoddmimir_mariadb_image",
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

    def test_requires_one_project_repository_same_worker_digest_and_amd64(self) -> None:
        invalid_mutations = (
            ("hoddmimir_backup_worker_image", f"registry.example/hoddmimir/worker@sha256:{'b' * 64}"),
            ("hoddmimir_webapp_image", f"registry.example/other/web@sha256:{'c' * 64}"),
            ("hoddmimir_mariadb_image", f"registry.example/library/mariadb@sha256:{'d' * 64}"),
            ("hoddmimir_release_platform", "linux/arm64"),
        )
        for variable, value in invalid_mutations:
            with self.subTest(variable=variable):
                variables = valid_variables()
                variables[variable] = value
                self.assertNotEqual(0, self.run_preflight(variables).returncode)

    def test_rejects_missing_or_noncanonical_project_image_repository(self) -> None:
        for repository in ("", "registry.example/hoddmimir:latest", "Registry.example/hoddmimir"):
            with self.subTest(repository=repository):
                variables = valid_variables()
                variables["hoddmimir_image_repository"] = repository
                self.assertNotEqual(0, self.run_preflight(variables).returncode)

    def test_rejects_missing_invalid_duplicate_or_platform_reused_as_any_registry_index_digest(self) -> None:
        invalid_evidence = (
            {},
            {"worker": "sha256:" + "1" * 64, "web": "sha256:" + "2" * 64},
            {
                "worker": "sha256:" + "A" * 64,
                "web": "sha256:" + "2" * 64,
                "mariadb": "sha256:" + "3" * 64,
            },
        )
        for evidence in invalid_evidence:
            with self.subTest(evidence=evidence):
                variables = valid_variables()
                variables["hoddmimir_registry_index_digests"] = evidence
                self.assertNotEqual(0, self.run_preflight(variables).returncode)

        variables = valid_variables()
        variables["hoddmimir_registry_index_digests"]["worker"] = "sha256:" + "a" * 64
        self.assertNotEqual(0, self.run_preflight(variables).returncode)

        variables = valid_variables()
        variables["hoddmimir_registry_index_digests"]["web"] = "sha256:" + "a" * 64
        self.assertNotEqual(0, self.run_preflight(variables).returncode)

        variables = valid_variables()
        variables["hoddmimir_registry_index_digests"]["web"] = variables["hoddmimir_registry_index_digests"]["worker"]
        self.assertNotEqual(0, self.run_preflight(variables).returncode)

        variables = valid_variables()
        variables["hoddmimir_webapp_image"] = variables["hoddmimir_webapp_image"].split("@", maxsplit=1)[0] + "@sha256:" + "a" * 64
        self.assertNotEqual(0, self.run_preflight(variables).returncode)

    def test_rejects_unacknowledged_backup_execution(self) -> None:
        variables = valid_variables()
        variables["hoddmimir_backup_execution_enabled"] = True
        result = self.run_preflight(variables)
        self.assertNotEqual(0, result.returncode)

    def test_rejects_placeholder_https_identity_or_secret_without_leaking_token(self) -> None:
        invalid = (
            ("hoddmimir_public_domain", "hoddmimir.example.invalid"),
            ("hoddmimir_acme_email", "acme@example.invalid"),
            ("hoddmimir_hetzner_dns_api_token", "REPLACE_WITH_HETZNER_DNS_API_TOKEN"),
        )
        for variable, value in invalid:
            with self.subTest(variable=variable):
                variables = valid_variables()
                variables[variable] = value
                result = self.run_preflight(variables)
                self.assertNotEqual(0, result.returncode)
                self.assertNotIn(value, result.stdout + result.stderr)

    def test_disabled_host_https_needs_no_public_identity_or_dns_token(self) -> None:
        variables = valid_variables()
        variables["hoddmimir_manage_https"] = False
        variables.pop("hoddmimir_public_domain")
        variables.pop("hoddmimir_acme_email")
        variables.pop("hoddmimir_hetzner_dns_api_token")

        self.assertEqual(0, self.run_preflight(variables).returncode)

    def test_host_https_switch_must_be_a_boolean(self) -> None:
        variables = valid_variables()
        variables["hoddmimir_manage_https"] = "false"

        self.assertNotEqual(0, self.run_preflight(variables).returncode)

    def test_isolated_lab_profile_uses_its_own_ack_and_needs_no_https_identity(self) -> None:
        variables = valid_variables()
        variables.update({
            "hoddmimir_deployment_profile": "lab",
            "hoddmimir_manage_https": False,
            "hoddmimir_project_name": "hoddmimir-lab",
            "hoddmimir_install_directory": "/opt/hoddmimir-lab",
            "hoddmimir_secrets_directory": "/etc/hoddmimir-lab/secrets",
            "hoddmimir_database_name": "hoddmimir_lab",
            "hoddmimir_web_port": 18080,
            "hoddmimir_backup_execution_required_ack": "ENABLE_LAB_BACKUPS",
        })
        variables.pop("hoddmimir_public_domain")
        variables.pop("hoddmimir_acme_email")
        variables.pop("hoddmimir_hetzner_dns_api_token")

        self.assertEqual(0, self.run_preflight(variables).returncode)

    def test_lab_profile_rejects_production_namespaces_and_acknowledgement(self) -> None:
        variables = valid_variables()
        variables.update({
            "hoddmimir_deployment_profile": "lab",
            "hoddmimir_manage_https": False,
            "hoddmimir_backup_execution_required_ack": "ENABLE_PRODUCTION_BACKUPS",
        })

        self.assertNotEqual(0, self.run_preflight(variables).returncode)

    def test_lab_execution_requires_its_exact_ack_and_real_matrix_delivery(self) -> None:
        variables = valid_variables()
        variables.update({
            "hoddmimir_deployment_profile": "lab",
            "hoddmimir_manage_https": False,
            "hoddmimir_project_name": "hoddmimir-lab",
            "hoddmimir_install_directory": "/opt/hoddmimir-lab",
            "hoddmimir_secrets_directory": "/etc/hoddmimir-lab/secrets",
            "hoddmimir_database_name": "hoddmimir_lab",
            "hoddmimir_web_port": 18080,
            "hoddmimir_backup_execution_enabled": True,
            "hoddmimir_backup_execution_required_ack": "ENABLE_LAB_BACKUPS",
            "hoddmimir_backup_execution_activation_ack": "ENABLE_LAB_BACKUPS",
            "hoddmimir_matrix_notifications_enabled": True,
            "hoddmimir_matrix_webhook_url": "https://matrix.example.test/hook/lab",
        })

        self.assertEqual(0, self.run_preflight(variables).returncode)
        variables["hoddmimir_backup_execution_activation_ack"] = "ENABLE_PRODUCTION_BACKUPS"
        self.assertNotEqual(0, self.run_preflight(variables).returncode)

    def test_production_profile_rejects_the_lab_acknowledgement(self) -> None:
        variables = valid_variables()
        variables["hoddmimir_backup_execution_required_ack"] = "ENABLE_LAB_BACKUPS"

        self.assertNotEqual(0, self.run_preflight(variables).returncode)

    def test_requires_one_canonical_private_24_bridge_and_in_range_gateway(self) -> None:
        invalid = (
            ("172.31.253.0/16", "172.31.253.1"),
            ("203.0.113.0/24", "203.0.113.1"),
            ("172.31.253.0/24", "172.31.254.1"),
            ("172.31.253.0/24", "172.31.253.0"),
            ("172.31.253.0/24", "172.31.253.255"),
            ("172.031.253.0/24", "172.31.253.1"),
        )
        for subnet, gateway in invalid:
            with self.subTest(subnet=subnet, gateway=gateway):
                variables = valid_variables()
                variables["hoddmimir_docker_subnet"] = subnet
                variables["hoddmimir_docker_gateway"] = gateway
                self.assertNotEqual(0, self.run_preflight(variables).returncode)

    def test_rejects_backup_execution_without_problem_delivery(self) -> None:
        variables = valid_variables()
        variables["hoddmimir_backup_execution_enabled"] = True
        variables["hoddmimir_backup_execution_activation_ack"] = "ENABLE_PRODUCTION_BACKUPS"
        variables["hoddmimir_matrix_notifications_enabled"] = False
        self.assertNotEqual(0, self.run_preflight(variables).returncode)

    def test_rejects_collector_grid_width_outside_application_bounds(self) -> None:
        for width in (0, 31_536_001):
            with self.subTest(width=width):
                variables = valid_variables()
                variables["hoddmimir_collector_grid_width_seconds"] = width
                self.assertNotEqual(0, self.run_preflight(variables).returncode)

    def test_rejects_evidence_freshness_outside_application_bounds(self) -> None:
        for seconds in (0, 86_401):
            with self.subTest(seconds=seconds):
                variables = valid_variables()
                variables["hoddmimir_evidence_freshness_seconds"] = seconds
                self.assertNotEqual(0, self.run_preflight(variables).returncode)

    def test_rejects_pve_storage_node_fanout_outside_application_bounds(self) -> None:
        for fanout in (0, 1025):
            with self.subTest(fanout=fanout):
                variables = valid_variables()
                variables["hoddmimir_pve_storage_max_node_fanout"] = fanout
                self.assertNotEqual(0, self.run_preflight(variables).returncode)

    def test_rejects_pbs_datastore_fanout_outside_application_bounds(self) -> None:
        for fanout in (0, 1025):
            with self.subTest(fanout=fanout):
                variables = valid_variables()
                variables["hoddmimir_pbs_max_datastore_fanout"] = fanout
                self.assertNotEqual(0, self.run_preflight(variables).returncode)

    def test_rejects_monitoring_limits_outside_application_bounds(self) -> None:
        invalid_values = {
            "hoddmimir_monitor_history_overlap_seconds": (-1, 3601),
            "hoddmimir_pve_monitor_page_size": (0, 101),
            "hoddmimir_pve_monitor_max_nodes": (0, 129),
            "hoddmimir_pve_monitor_active_page_cap": (0, 3),
            "hoddmimir_pve_monitor_archive_page_cap": (0, 11),
            "hoddmimir_pve_monitor_request_limit": (0, 513),
            "hoddmimir_pve_monitor_raw_row_limit": (99, 25001),
            "hoddmimir_pve_monitor_distinct_task_limit": (0, 25001),
            "hoddmimir_pve_monitor_history_window_seconds": (0, 86401),
            "hoddmimir_pbs_monitor_page_size": (0, 1001),
            "hoddmimir_pbs_monitor_max_pages_per_stream": (0, 65),
            "hoddmimir_pbs_monitor_max_rows_per_stream": (255, 65537),
            "hoddmimir_pbs_monitor_max_jobs_per_kind": (0, 65537),
            "hoddmimir_pbs_monitor_history_window_seconds": (0, 86401),
            "hoddmimir_pbs_content_max_datastores": (0, 1025),
            "hoddmimir_pbs_content_max_namespaces_per_datastore": (0, 65537),
            "hoddmimir_pbs_content_max_snapshots_per_namespace": (0, 1048577),
            "hoddmimir_pbs_content_max_total_snapshots": (65535, 4194305),
            "hoddmimir_pbs_content_namespace_body_bytes": (65535, 67108865),
            "hoddmimir_pbs_content_snapshot_body_bytes": (1048575, 268435457),
        }
        for variable, values in invalid_values.items():
            for value in values:
                with self.subTest(variable=variable, value=value):
                    variables = valid_variables()
                    variables[variable] = value
                    self.assertNotEqual(0, self.run_preflight(variables).returncode)

    def test_secret_validation_does_not_echo_rejected_value(self) -> None:
        variables = valid_variables()
        rejected_secret = "NOT_A_VALID_SECRET"
        variables["hoddmimir_app_secret"] = rejected_secret
        result = self.run_preflight(variables)

        self.assertNotEqual(0, result.returncode)
        self.assertNotIn(rejected_secret, result.stdout + result.stderr)

    def test_matrix_webhook_is_strict_https_and_secret_safe(self) -> None:
        for webhook in (
            "http://matrix.example.test/hook/secret",
            "https://user:secret@matrix.example.test/hook",
            "https://matrix.example.test/hook#fragment",
            "not-a-url",
        ):
            with self.subTest(webhook=webhook):
                variables = valid_variables()
                variables["hoddmimir_matrix_webhook_url"] = webhook
                result = self.run_preflight(variables)
                self.assertNotEqual(0, result.returncode)
                self.assertNotIn(webhook, result.stdout + result.stderr)

        variables = valid_variables()
        variables["hoddmimir_matrix_notifications_enabled"] = True
        variables["hoddmimir_matrix_webhook_url"] = "https://matrix.invalid/disabled"
        self.assertNotEqual(0, self.run_preflight(variables).returncode)

    def test_rejects_invalid_encryption_keyring_envelopes(self) -> None:
        invalid_keyrings: dict[str, object] = {
            "not a mapping": "NOT_A_KEYRING",
            "unexpected top-level field": {
                "format": 1,
                "revision": 1,
                "primaryKeyId": "key-a",
                "keys": [{"id": "key-a", "material": "8" * 64}],
                "unexpected": True,
            },
            "wrong format": {
                "format": 2,
                "revision": 1,
                "primaryKeyId": "key-a",
                "keys": [{"id": "key-a", "material": "8" * 64}],
            },
            "non-positive revision": {
                "format": 1,
                "revision": 0,
                "primaryKeyId": "key-a",
                "keys": [{"id": "key-a", "material": "8" * 64}],
            },
            "boolean revision": {
                "format": 1,
                "revision": True,
                "primaryKeyId": "key-a",
                "keys": [{"id": "key-a", "material": "8" * 64}],
            },
            "invalid primary ID": {
                "format": 1,
                "revision": 1,
                "primaryKeyId": "INVALID KEY",
                "keys": [{"id": "INVALID KEY", "material": "8" * 64}],
            },
            "empty keys": {
                "format": 1,
                "revision": 1,
                "primaryKeyId": "key-a",
                "keys": [],
            },
            "keys are not a list": {
                "format": 1,
                "revision": 1,
                "primaryKeyId": "key-a",
                "keys": "key-a",
            },
            "too many keys": {
                "format": 1,
                "revision": 1,
                "primaryKeyId": "key-0",
                "keys": [
                    {"id": f"key-{index}", "material": f"{index + 8:064x}"}
                    for index in range(17)
                ],
            },
        }

        for name, keyring in invalid_keyrings.items():
            with self.subTest(name=name):
                variables = valid_variables()
                variables["hoddmimir_encryption_keyring"] = keyring
                self.assertNotEqual(0, self.run_preflight(variables).returncode)

        variables = valid_variables()
        variables.pop("hoddmimir_encryption_keyring")
        self.assertNotEqual(0, self.run_preflight(variables).returncode)

    def test_rejects_invalid_encryption_keyring_entries(self) -> None:
        invalid_entries: dict[str, list[dict[str, object]]] = {
            "unexpected entry field": [{"id": "key-a", "material": "8" * 64, "extra": True}],
            "missing material": [{"id": "key-a"}],
            "invalid ID": [{"id": "Key-A", "material": "8" * 64}],
            "invalid material": [{"id": "key-a", "material": "8" * 63}],
            "duplicate IDs": [
                {"id": "key-a", "material": "8" * 64},
                {"id": "key-a", "material": "9" * 64},
            ],
            "duplicate materials": [
                {"id": "key-a", "material": "8" * 64},
                {"id": "key-b", "material": "8" * 64},
            ],
        }

        for name, entries in invalid_entries.items():
            with self.subTest(name=name):
                variables = valid_variables()
                variables["hoddmimir_encryption_keyring"] = {
                    "format": 1,
                    "revision": 1,
                    "primaryKeyId": entries[0]["id"],
                    "keys": entries,
                }
                self.assertNotEqual(0, self.run_preflight(variables).returncode)

    def test_requires_present_primary_key_and_materials_distinct_from_other_secrets(self) -> None:
        variables = valid_variables()
        variables["hoddmimir_encryption_keyring"] = {
            "format": 1,
            "revision": 1,
            "primaryKeyId": "missing-key",
            "keys": [{"id": "key-a", "material": "8" * 64}],
        }
        self.assertNotEqual(0, self.run_preflight(variables).returncode)

        variables = valid_variables()
        variables["hoddmimir_encryption_keyring"] = {
            "format": 1,
            "revision": 1,
            "primaryKeyId": "key-a",
            "keys": [{"id": "key-a", "material": variables["hoddmimir_app_secret"]}],
        }
        self.assertNotEqual(0, self.run_preflight(variables).returncode)

    def test_encryption_keyring_validation_never_echoes_rejected_material(self) -> None:
        variables = valid_variables()
        rejected_material = "LEAK_ME_NOT_" * 8
        variables["hoddmimir_encryption_keyring"] = {
            "format": 1,
            "revision": 1,
            "primaryKeyId": "key-a",
            "keys": [{"id": "key-a", "material": rejected_material}],
        }

        result = self.run_preflight(variables)

        self.assertNotEqual(0, result.returncode)
        self.assertNotIn(rejected_material, result.stdout + result.stderr)

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
            migration_compose_file = root / "compose.migration.yaml"
            runtime_file = root / "runtime.env"
            secrets_directory = root / "secrets"
            secrets_directory.mkdir()
            for secret_name in SECRET_NAMES:
                (secrets_directory / secret_name).write_text("test\n", encoding="utf-8")
            encryption_key_file = secrets_directory / "encryption_key"
            init_script = root / "10-create-app-users.sh"
            caddy_file = root / "Caddyfile.candidate"
            renewal_file = root / "hoddmimir-certificate-renew"
            variables = valid_variables() | {
                "hoddmimir_test_compose_output": str(compose_file),
                "hoddmimir_test_migration_compose_output": str(migration_compose_file),
                "hoddmimir_test_mariadb_init_output": str(init_script),
                "hoddmimir_test_runtime_output": str(runtime_file),
                "hoddmimir_test_encryption_key_output": str(encryption_key_file),
                "hoddmimir_test_caddy_output": str(caddy_file),
                "hoddmimir_test_renewal_output": str(renewal_file),
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
            self.assertEqual(
                json.dumps(variables["hoddmimir_encryption_keyring"], sort_keys=True, separators=(",", ":")) + "\n",
                encryption_key_file.read_text(encoding="utf-8"),
            )
            self.assertEqual(0o600, stat.S_IMODE(encryption_key_file.stat().st_mode))
            caddy = caddy_file.read_text(encoding="utf-8")
            self.assertIn("http://hoddmimir.example.test", caddy)
            self.assertIn("https://hoddmimir.example.test", caddy)
            self.assertIn("reverse_proxy 127.0.0.1:8080", caddy)
            self.assertIn("header_up -X-Forwarded-For", caddy)
            self.assertIn("header_up X-Forwarded-For {remote_host}", caddy)
            self.assertIn("header_up X-Forwarded-Proto https", caddy)
            self.assertIn('Strict-Transport-Security "max-age=31536000"', caddy)
            self.assertNotIn("includeSubDomains", caddy)
            self.assertNotIn("tls internal", caddy)
            renewal = renewal_file.read_text(encoding="utf-8")
            self.assertIn("--candidate-caddyfile=/etc/hoddmimir/caddy/Caddyfile.candidate", renewal)
            self.assertIn("--caddyfile=/etc/caddy/Caddyfile", renewal)
            self.assertIn("--health-url=https://hoddmimir.example.test/api/health", renewal)
            self.assertIn("--acme-user=hoddmimir-acme", renewal)
            self.assertIn("--token-file=/etc/hoddmimir/acme/hetzner_dns_api_token", renewal)
            self.assertNotIn(str(variables["hoddmimir_hetzner_dns_api_token"]), renewal)
            runtime_environment = runtime_file.read_text(encoding="utf-8")
            self.assertIn("ENCRYPTION_KEYRING_REVISION=7\n", runtime_environment)
            self.assertNotIn("MATRIX_WEBHOOK_URL_FILE", runtime_environment)
            self.assertNotIn("/run/secrets/matrix_webhook_url", runtime_environment)
            configured = run(["docker", "compose", "--file", str(compose_file), "config", "--format", "json"])
            self.assertEqual(0, configured.returncode, configured.stderr)
            model = json.loads(configured.stdout)
            services = model["services"]

            self.assertEqual("hoddmimir", model["name"])
            self.assertEqual(EXPECTED_SERVICES, set(services))
            self.assertTrue(all("build" not in service for service in services.values()))
            self.assertTrue(all("@sha256:" in service["image"] for service in services.values()))
            self.assertTrue(all(service["platform"] == "linux/amd64" for service in services.values()))
            self.assertEqual(services["data-worker"]["image"], services["backup-worker"]["image"])
            self.assertTrue(services["mariadb"]["image"].startswith("registry.example/hoddmimir/mariadb@sha256:"))
            self.assertEqual("hoddmimir", services["mariadb"]["environment"]["MARIADB_DATABASE"])
            self.assertEqual("localhost", services["mariadb"]["environment"]["MARIADB_ROOT_HOST"])
            self.assertEqual("hoddmimir", services["data-worker"]["environment"]["DATABASE_NAME"])
            self.assertEqual("hoddmimir_collector", services["data-worker"]["environment"]["DATABASE_USER"])
            self.assertEqual("120", services["data-worker"]["environment"]["COLLECTOR_GRID_WIDTH_SECONDS"])
            for application_service in ("data-worker", "backup-worker", "webapp"):
                self.assertEqual(
                    "300",
                    services[application_service]["environment"]["EVIDENCE_FRESHNESS_SECONDS"],
                )
            self.assertEqual("128", services["data-worker"]["environment"]["PVE_STORAGE_MAX_NODE_FANOUT"])
            self.assertEqual("128", services["data-worker"]["environment"]["PBS_MAX_DATASTORE_FANOUT"])
            expected_content_environment = {
                "PBS_CONTENT_MAX_DATASTORES": "128",
                "PBS_CONTENT_MAX_NAMESPACES_PER_DATASTORE": "1024",
                "PBS_CONTENT_MAX_SNAPSHOTS_PER_NAMESPACE": "65536",
                "PBS_CONTENT_MAX_TOTAL_SNAPSHOTS": "262144",
                "PBS_CONTENT_NAMESPACE_BODY_BYTES": "8388608",
                "PBS_CONTENT_SNAPSHOT_BODY_BYTES": "67108864",
            }
            for name, expected in expected_content_environment.items():
                self.assertEqual(expected, services["data-worker"]["environment"][name])
            expected_monitoring_environment = {
                "MONITOR_HISTORY_OVERLAP_SECONDS": "300",
                "PVE_MONITOR_PAGE_SIZE": "100",
                "PVE_MONITOR_MAX_NODES": "128",
                "PVE_MONITOR_ACTIVE_PAGE_CAP": "2",
                "PVE_MONITOR_ARCHIVE_PAGE_CAP": "10",
                "PVE_MONITOR_REQUEST_LIMIT": "512",
                "PVE_MONITOR_RAW_ROW_LIMIT": "25000",
                "PVE_MONITOR_DISTINCT_TASK_LIMIT": "25000",
                "PVE_MONITOR_HISTORY_WINDOW_SECONDS": "86400",
                "PBS_MONITOR_PAGE_SIZE": "256",
                "PBS_MONITOR_MAX_PAGES_PER_STREAM": "16",
                "PBS_MONITOR_MAX_ROWS_PER_STREAM": "4096",
                "PBS_MONITOR_MAX_JOBS_PER_KIND": "4096",
                "PBS_MONITOR_HISTORY_WINDOW_SECONDS": "86400",
            }
            for name, expected in expected_monitoring_environment.items():
                self.assertEqual(expected, services["data-worker"]["environment"][name])
            self.assertEqual("a" * 64, services["data-worker"]["environment"]["APP_BUILD_VERSION"])
            for application_service in ("data-worker", "backup-worker", "webapp"):
                self.assertEqual(
                    "7",
                    services[application_service]["environment"]["ENCRYPTION_KEYRING_REVISION"],
                )
            self.assertEqual("hoddmimir_backup_worker", services["backup-worker"]["environment"]["DATABASE_USER"])
            self.assertEqual("hoddmimir_web", services["webapp"]["environment"]["DATABASE_USER"])
            self.assertEqual("127.0.0.1", services["webapp"]["ports"][0]["host_ip"])
            self.assertEqual(8080, services["webapp"]["ports"][0]["target"])
            self.assertEqual("172.31.253.1", services["webapp"]["environment"]["HODDMIMIR_TRUSTED_PROXY"])
            self.assertEqual(
                [{"subnet": "172.31.253.0/24", "gateway": "172.31.253.1"}],
                model["networks"]["internal"]["ipam"]["config"],
            )
            self.assertEqual("false", services["backup-worker"]["environment"]["BACKUP_EXECUTION_ENABLED"])
            self.assertEqual("false", services["backup-worker"]["environment"]["MATRIX_NOTIFICATION_ENABLED"])
            self.assertEqual("proxmox-backup", services["backup-worker"]["environment"]["MATRIX_WEBHOOK_CHANNEL"])
            self.assertEqual("10", services["backup-worker"]["environment"]["MATRIX_WEBHOOK_TIMEOUT_SECONDS"])
            self.assertEqual("/run/secrets/matrix_webhook_url", services["backup-worker"]["environment"]["MATRIX_WEBHOOK_URL_FILE"])
            self.assertIn(
                "matrix_webhook_url",
                {secret["source"] for secret in services["backup-worker"]["secrets"]},
            )
            for service_name in ("data-worker", "webapp"):
                self.assertNotIn("MATRIX_WEBHOOK_URL_FILE", services[service_name]["environment"])
                self.assertNotIn(
                    "matrix_webhook_url",
                    {secret["source"] for secret in services[service_name]["secrets"]},
                )
            self.assertEqual(
                ["hoddmimir:worker:data"],
                services["data-worker"]["command"],
            )
            self.assertEqual(
                ["hoddmimir:worker:backup", "--interval=5"],
                services["backup-worker"]["command"],
            )
            self.assertEqual(
                ["CMD", "/usr/local/bin/hoddmimir-app-healthcheck", "php", "bin/console", "hoddmimir:worker:health", "collector"],
                services["data-worker"]["healthcheck"]["test"],
            )
            self.assertEqual("1m15s", services["data-worker"]["stop_grace_period"])
            self.assertEqual(
                ["CMD", "/usr/local/bin/hoddmimir-app-healthcheck", "php", "bin/console", "hoddmimir:worker:readiness", "backup"],
                services["backup-worker"]["healthcheck"]["test"],
            )
            self.assertNotIn("ports", services["mariadb"])
            for application_service in ("data-worker", "backup-worker", "webapp"):
                service = services[application_service]
                self.assertEqual(1, service["cpus"])
                self.assertEqual("536870912", str(service["mem_limit"]))
                self.assertEqual(128, service["pids_limit"])
                self.assertTrue(service["read_only"])
                self.assertEqual(["ALL"], service["cap_drop"])
                self.assertEqual(["CHOWN", "DAC_READ_SEARCH", "SETGID", "SETUID"], service["cap_add"])
                self.assertEqual(["no-new-privileges:true"], service["security_opt"])
                self.assertIn("/run/hoddmimir-secrets:mode=0700", service["tmpfs"])

            mariadb = services["mariadb"]
            self.assertEqual(2, mariadb["cpus"])
            self.assertEqual("2147483648", str(mariadb["mem_limit"]))
            self.assertEqual(256, mariadb["pids_limit"])
            self.assertTrue(mariadb["read_only"])
            self.assertEqual(["ALL"], mariadb["cap_drop"])
            self.assertEqual(["CHOWN", "DAC_OVERRIDE", "SETGID", "SETUID"], mariadb["cap_add"])
            self.assertEqual(["no-new-privileges:true"], mariadb["security_opt"])
            self.assertIn("/tmp:mode=1777", mariadb["tmpfs"])
            self.assertIn("/run/mysqld:uid=999,gid=999,mode=1770", mariadb["tmpfs"])
            bootstrap_mount = next(
                volume
                for volume in mariadb["volumes"]
                if volume["target"] == "/usr/local/bin/hoddmimir-database-user-bootstrap"
            )
            self.assertEqual("bind", bootstrap_mount["type"])
            self.assertEqual(str(init_script), bootstrap_mount["source"])
            self.assertTrue(bootstrap_mount["read_only"])

            migration_configured = run([
                "docker",
                "compose",
                "--file",
                str(compose_file),
                "--file",
                str(migration_compose_file),
                "config",
                "--format",
                "json",
            ])
            self.assertEqual(0, migration_configured.returncode, migration_configured.stderr)
            migration_model = json.loads(migration_configured.stdout)
            self.assertEqual(EXPECTED_SERVICES | {"schema-migration", "maintenance-check"}, set(migration_model["services"]))
            probe = migration_model["services"]["maintenance-check"]
            self.assertEqual("false", probe["environment"]["BACKUP_EXECUTION_ENABLED"])
            self.assertEqual("false", probe["environment"]["MATRIX_NOTIFICATION_ENABLED"])
            self.assertNotIn("ports", probe)
            self.assertEqual("no", probe["restart"])
            self.assertEqual("linux/amd64", probe["platform"])
            for service_name in ("data-worker", "backup-worker", "webapp"):
                self.assertEqual("/run/hoddmimir-maintenance", services[service_name]["environment"]["HODDMIMIR_MAINTENANCE_DIRECTORY"])
                mounts = [mount for mount in services[service_name]["volumes"] if mount["target"] == "/run/hoddmimir-maintenance"]
                self.assertEqual(1, len(mounts))
                self.assertTrue(mounts[0]["read_only"])
            migration = migration_model["services"]["schema-migration"]
            self.assertEqual("linux/amd64", migration["platform"])
            self.assertEqual(services["data-worker"]["image"], migration["image"])
            self.assertEqual(services["data-worker"]["image"], migration["image"])
            self.assertIn("@sha256:", migration["image"])
            self.assertEqual("hoddmimir_migration", migration["environment"]["DATABASE_USER"])
            self.assertEqual("/run/secrets/mariadb_migration_password", migration["environment"]["DATABASE_PASSWORD_FILE"])
            self.assertNotIn("MATRIX_WEBHOOK_URL_FILE", migration["environment"])
            self.assertTrue(migration["read_only"])
            self.assertEqual(["ALL"], migration["cap_drop"])
            self.assertEqual(["CHOWN", "DAC_READ_SEARCH", "SETGID", "SETUID"], migration["cap_add"])
            self.assertIn("/run/hoddmimir-secrets:mode=0700", migration["tmpfs"])
            self.assertEqual(1, migration["cpus"])
            self.assertEqual("536870912", str(migration["mem_limit"]))
            self.assertEqual(128, migration["pids_limit"])
            self.assertEqual(["no-new-privileges:true"], migration["security_opt"])
            self.assertEqual(
                {"app_secret", "mariadb_migration_password"},
                {secret["source"] for secret in migration["secrets"]},
            )
            self.assertNotIn(
                "matrix_webhook_url",
                {secret["source"] for secret in migration["secrets"]},
            )
            self.assertEqual(
                ["doctrine:migrations:migrate", "--no-interaction", "--allow-no-migration", "--no-ansi"],
                migration["command"],
            )

            users = init_script.read_text(encoding="utf-8")
            for database_user in (
                "hoddmimir_migration",
                "hoddmimir_web",
                "hoddmimir_collector",
                "hoddmimir_backup_worker",
            ):
                self.assertIn(f"CREATE USER IF NOT EXISTS '{database_user}'@'%'", users)
            self.assertIn("protocol=socket", users)
            self.assertIn("readonly database_socket=/run/mysqld/mysqld.sock", users)
            self.assertIn('"socket=$database_socket"', users)
            self.assertNotIn("protocol=tcp", users)
            self.assertNotIn("MARIADB_ROOT_HOST", users)
            self.assertNotIn("HODDMIMIR_DATABASE_BOOTSTRAP_HOST", users)


class DeploymentStagingIsolationTest(unittest.TestCase):
    def test_role_uses_unique_staged_executor_and_no_shared_staging_cleanup(self) -> None:
        tasks = (ANSIBLE_ROOT / "roles" / "hoddmimir" / "tasks" / "main.yml").read_text(encoding="utf-8")
        defaults = (ANSIBLE_ROOT / "roles" / "hoddmimir" / "defaults" / "main.yml").read_text(encoding="utf-8")

        self.assertIn("ansible.builtin.tempfile:", tasks)
        self.assertIn('prefix: "{{ hoddmimir_staging_prefix }}"', tasks)
        self.assertIn("hoddmimir_deployment_staging.path ~ '/deploy_transaction.py'", tasks)
        self.assertIn("--transaction-lock-file=", tasks)
        self.assertIn("Remove only this deployment staging directory", tasks)
        self.assertNotIn("hoddmimir_staging_directory", tasks + defaults)
        self.assertNotIn("Remove stale deployment staging directory", tasks)
        self.assertNotIn("hoddmimir_transaction_script", tasks + defaults)

    def test_ansible_tempfile_produces_two_unique_private_directories_and_cleans_both(self) -> None:
        with tempfile.TemporaryDirectory() as parent, tempfile.TemporaryDirectory() as local_temp:
            environment = os.environ.copy()
            environment["ANSIBLE_LOCAL_TEMP"] = local_temp
            environment["HODDMIMIR_STAGING_TEST_PARENT"] = parent
            result = run(
                [
                    "ansible-playbook",
                    "--inventory",
                    "localhost,",
                    "tests/playbooks/staging-isolation.yml",
                ],
                env=environment,
            )

            self.assertEqual(0, result.returncode, result.stdout + result.stderr)
            self.assertEqual([], list(Path(parent).iterdir()))


class HostHttpsAnsibleContractTest(unittest.TestCase):
    def test_fresh_host_creates_https_runtime_parents_before_executor_copy(self) -> None:
        tasks = (ANSIBLE_ROOT / "roles" / "hoddmimir" / "tasks" / "https-setup.yml").read_text(encoding="utf-8")
        self.assertLess(
            tasks.index("Create required HTTPS runtime parent directories"),
            tasks.index("Install recoverable HTTPS transaction executor"),
        )

        with tempfile.TemporaryDirectory() as root, tempfile.TemporaryDirectory() as local_temp:
            environment = os.environ.copy()
            environment["ANSIBLE_LOCAL_TEMP"] = local_temp
            environment["HODDMIMIR_HTTPS_PARENT_TEST_ROOT"] = root
            environment["HODDMIMIR_HTTPS_PARENT_TEST_OWNER"] = pwd.getpwuid(os.getuid()).pw_name
            environment["HODDMIMIR_HTTPS_PARENT_TEST_GROUP"] = grp.getgrgid(os.getgid()).gr_name
            first = run(
                ["ansible-playbook", "--inventory", "localhost,", "tests/playbooks/https-runtime-directories.yml"],
                env=environment,
            )
            second = run(
                ["ansible-playbook", "--inventory", "localhost,", "tests/playbooks/https-runtime-directories.yml"],
                env=environment,
            )

            self.assertEqual(0, first.returncode, first.stdout + first.stderr)
            self.assertEqual(0, second.returncode, second.stdout + second.stderr)
            self.assertIn("changed=0", second.stdout)
            for relative in ("usr/local/sbin", "etc/periodic/daily", "run/lock"):
                directory = Path(root, relative)
                self.assertTrue(directory.is_dir())
                self.assertEqual(0o755, stat.S_IMODE(directory.stat().st_mode))
            executor = Path(root, "usr/local/sbin/hoddmimir-https-transaction")
            self.assertTrue(executor.is_file())
            self.assertEqual(0o555, stat.S_IMODE(executor.stat().st_mode))

    def test_https_role_keeps_caddy_outside_compose_and_acme_identity_isolated(self) -> None:
        tasks = (ANSIBLE_ROOT / "roles" / "hoddmimir" / "tasks" / "https-setup.yml").read_text(encoding="utf-8")
        activation = (ANSIBLE_ROOT / "roles" / "hoddmimir" / "tasks" / "https-activate.yml").read_text(encoding="utf-8")
        role_tasks = (ANSIBLE_ROOT / "roles" / "hoddmimir" / "tasks" / "main.yml").read_text(encoding="utf-8")
        defaults = (ANSIBLE_ROOT / "roles" / "hoddmimir" / "defaults" / "main.yml").read_text(encoding="utf-8")
        compose = (ANSIBLE_ROOT / "roles" / "hoddmimir" / "templates" / "compose.yaml.j2").read_text(encoding="utf-8")
        verify = (ANSIBLE_ROOT / "roles" / "hoddmimir" / "tasks" / "verify-https.yml").read_text(encoding="utf-8")
        common_verify = (ANSIBLE_ROOT / "roles" / "hoddmimir" / "tasks" / "verify.yml").read_text(encoding="utf-8")

        for package in ("caddy", "caddy-openrc", "iproute2", "lego", "openssl"):
            self.assertIn(f"  - {package}", defaults)
        self.assertNotIn("\n  caddy:\n", compose)
        self.assertIn("Create dedicated nologin ACME runtime user", tasks)
        self.assertIn("shell: /sbin/nologin", tasks)
        self.assertIn('mode: "0700"', tasks)
        self.assertIn('mode: "0440"', tasks)
        self.assertIn("group: \"{{ hoddmimir_acme_group }}\"", tasks)
        self.assertIn("no_log: true", tasks)
        self.assertIn("--candidate-caddyfile={{ hoddmimir_caddy_candidate_file }}", activation)
        self.assertIn("Enable the Caddy OpenRC service without starting it", tasks)
        self.assertIn("Enable and start the Alpine periodic scheduler", tasks)
        self.assertLess(
            role_tasks.index("ansible.builtin.import_tasks: https-setup.yml"),
            role_tasks.index("ansible.builtin.import_tasks: https-activate.yml"),
        )
        self.assertNotIn("notify:", tasks + activation)
        self.assertIn("Check public HTTPS API health with full certificate verification", verify)
        self.assertIn("validate_certs: true", verify)
        self.assertIn("Require exactly four running services", common_verify)

    def test_token_is_only_passed_to_lego_through_file_environment(self) -> None:
        transaction = (ANSIBLE_ROOT / "roles" / "hoddmimir" / "files" / "https_transaction.py").read_text(encoding="utf-8")
        tasks = (ANSIBLE_ROOT / "roles" / "hoddmimir" / "tasks" / "https-setup.yml").read_text(encoding="utf-8")

        self.assertIn('"HETZNER_API_TOKEN_FILE": str(token_file)', transaction)
        self.assertNotIn('"HETZNER_API_TOKEN":', transaction)
        self.assertNotIn("--token=", transaction)
        self.assertIn("context=ssl.create_default_context()", transaction)
        self.assertNotIn("_create_unverified", transaction)
        self.assertIn("health_checker(arguments.health_url)", transaction)
        self.assertIn("HTTPS_STARTUP_TIMEOUT_SECONDS = 15.0", transaction)
        self.assertNotIn("hoddmimir_hetzner_dns_api_token }}\"\n      -", tasks)

    def test_failed_https_transaction_does_not_parse_empty_stdout_as_json(self) -> None:
        https_task = (ANSIBLE_ROOT / "roles" / "hoddmimir" / "tasks" / "https-activate.yml").read_text(encoding="utf-8")

        self.assertIn("hoddmimir_https_transaction.rc == 0 and", https_task)
        self.assertIn("default('{}') | from_json", https_task)

        with tempfile.TemporaryDirectory() as local_temp:
            environment = os.environ.copy()
            environment["ANSIBLE_LOCAL_TEMP"] = local_temp
            result = run(
                [
                    "ansible-playbook",
                    "--inventory",
                    "localhost,",
                    "tests/playbooks/https-failure-reporting.yml",
                ],
                env=environment,
            )

        self.assertEqual(0, result.returncode, result.stdout + result.stderr)
        self.assertIn("failed=0", result.stdout)

    def test_listener_verification_uses_the_configured_non_default_web_port(self) -> None:
        verify = (ANSIBLE_ROOT / "roles" / "hoddmimir" / "tasks" / "verify-https.yml").read_text(encoding="utf-8")
        listener_contract = verify.split(
            "- name: Require public TLS listeners and loopback-only application and admin listeners",
            maxsplit=1,
        )[1].split("- name: Require a non-expiring public certificate", maxsplit=1)[0]
        configured_port = 18_080

        self.assertNotIn(":8080", listener_contract)
        self.assertEqual(3, listener_contract.count("hoddmimir_web_port"))
        self.assertIn(
            "':' ~ (hoddmimir_web_port | string) ~ '(\\\\s|$)'",
            listener_contract,
        )
        self.assertIn(
            "'127\\\\.0\\\\.0\\\\.1:' ~ (hoddmimir_web_port | string) ~ '(\\\\s|$)'",
            listener_contract,
        )
        self.assertIn(
            f"127.0.0.1:{configured_port}",
            listener_contract.replace("{{ hoddmimir_web_port }}", str(configured_port)),
        )

    def test_https_management_defaults_on_and_every_entrypoint_is_fail_closed(self) -> None:
        defaults = (ANSIBLE_ROOT / "roles" / "hoddmimir" / "defaults" / "main.yml").read_text(encoding="utf-8")
        tasks = (ANSIBLE_ROOT / "roles" / "hoddmimir" / "tasks" / "main.yml").read_text(encoding="utf-8")
        verify = (ANSIBLE_ROOT / "roles" / "hoddmimir" / "tasks" / "verify.yml").read_text(encoding="utf-8")
        preflight = (ANSIBLE_ROOT / "roles" / "hoddmimir" / "tasks" / "preflight.yml").read_text(encoding="utf-8")

        self.assertIn("hoddmimir_manage_https: true", defaults)
        self.assertEqual(2, tasks.count("when: hoddmimir_manage_https | bool"))
        self.assertIn("ansible.builtin.import_tasks: https-setup.yml", tasks)
        self.assertIn("ansible.builtin.import_tasks: https-activate.yml", tasks)
        self.assertEqual(1, verify.count("when: hoddmimir_manage_https | bool"))
        self.assertIn("ansible.builtin.import_tasks: verify-https.yml", verify)
        self.assertEqual(2, preflight.count("when: hoddmimir_manage_https | bool"))
        self.assertIn("hoddmimir_manage_https is boolean", preflight)

    def test_disabled_https_tasks_are_skipped_without_resolving_secrets_or_host_tools(self) -> None:
        with tempfile.TemporaryDirectory() as local_temp:
            environment = os.environ.copy()
            environment["ANSIBLE_LOCAL_TEMP"] = local_temp
            result = run(
                [
                    "ansible-playbook",
                    "--inventory",
                    "localhost,",
                    "--check",
                    "--tags",
                    "hoddmimir_https",
                    "tests/playbooks/https-disabled.yml",
                ],
                env=environment,
            )

        self.assertEqual(0, result.returncode, result.stdout + result.stderr)
        self.assertIn("changed=0", result.stdout)
        self.assertIn("failed=0", result.stdout)
        self.assertNotIn("hoddmimir_hetzner_dns_api_token is undefined", result.stdout + result.stderr)

class DeploymentTransactionTest(unittest.TestCase):
    def setUp(self) -> None:
        self.temporary_directory = tempfile.TemporaryDirectory()
        self.root = Path(self.temporary_directory.name)
        self.reset_case("default")

    def reset_case(self, name: str) -> None:
        case_root = self.root / name
        self.staging = case_root / "staging"
        self.install = case_root / "install"
        self.install.mkdir(parents=True, exist_ok=True)
        self.secrets = case_root / "secrets"
        self.lock_file = self.install / ".deployment-transaction.lock"
        self.state_file = case_root / "fake-docker.json"
        self.expected_keyring_revision = "2"
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
        self.assertEqual(0o640, stat.S_IMODE((self.install / "compose.migration.yaml").stat().st_mode))
        self.assertEqual(0o640, stat.S_IMODE((self.install / "runtime.env").stat().st_mode))
        self.assertEqual(0o555, stat.S_IMODE((self.install / "10-create-app-users.sh").stat().st_mode))
        self.assertEqual(
            ["config", "config", "pull", "up", "bootstrap", "schema-check", "run", "up", "ps"],
            self.operations(),
        )
        self.assert_safe_docker_calls()
        self.assert_transaction_lock_available()

    def test_concurrent_transaction_is_rejected_before_docker_or_managed_mutation(self) -> None:
        self.install_current("new")
        self.write_state(sorted(EXPECTED_SERVICES))
        before = self.current_state()
        descriptor = os.open(self.lock_file, os.O_RDWR | os.O_CREAT, 0o600)
        fcntl.flock(descriptor, fcntl.LOCK_EX | fcntl.LOCK_NB)

        try:
            result = self.run_transaction()
        finally:
            fcntl.flock(descriptor, fcntl.LOCK_UN)
            os.close(descriptor)

        self.assertNotEqual(0, result.returncode)
        self.assertIn("Another deployment transaction is already running", result.stderr)
        self.assertEqual([], self.operations())
        self.assertEqual(before, self.current_state())
        self.assertNotIn(KEY_MATERIAL_OLD, result.stdout + result.stderr)
        self.assertNotIn(KEY_MATERIAL_NEW, result.stdout + result.stderr)
        self.assert_transaction_lock_available()

    def test_noncanonical_database_service_is_rejected_before_docker_or_mutation(self) -> None:
        self.install_current("new")
        before = self.current_state()
        self.write_state(sorted(EXPECTED_SERVICES))
        environment = os.environ.copy()
        environment["FAKE_DOCKER_STATE"] = str(self.state_file)
        with health_server() as health_url:
            arguments = self.transaction_arguments(health_url)
            database_argument = arguments.index("--database-service=mariadb")
            arguments[database_argument] = "--database-service=database"
            result = run([sys.executable, str(TRANSACTION_SCRIPT), *arguments], env=environment)

        self.assertNotEqual(0, result.returncode)
        self.assertEqual(before, self.current_state())
        self.assertEqual([], self.operations())
        self.assertIn("exactly four services", result.stderr)

    def test_transaction_body_oserror_is_not_misclassified_as_a_lock_failure(self) -> None:
        with self.assertRaises(OSError) as caught:
            with TRANSACTION_MODULE.deployment_lock(self.lock_file):
                raise OSError(errno.EIO, "BODY-OSERROR-SENTINEL")

        self.assertIn("BODY-OSERROR-SENTINEL", str(caught.exception))
        self.assertNotIn("lock is unavailable", str(caught.exception))
        self.assert_transaction_lock_available()

    def test_unchanged_verify_failure_never_stops_stack(self) -> None:
        self.install_current("new")
        self.write_state(sorted(EXPECTED_SERVICES), failures={"ps": [1]})
        before = self.current_state()
        result = self.run_transaction()

        self.assertNotEqual(0, result.returncode)
        self.assertEqual(before, self.current_state())
        self.assertEqual(["config", "config", "up", "bootstrap", "schema-check", "ps"], self.operations())
        self.assertIn("without changing application files", result.stderr)
        self.assertIn("existing application services were not stopped", result.stderr)
        self.assertIn("forward-only and was not rolled back", result.stderr)
        self.assertNotIn("down", self.operations())
        self.assert_safe_docker_calls()

    def test_unchanged_up_to_date_schema_reports_verified_unchanged(self) -> None:
        self.install_current("new")
        self.write_state(sorted(EXPECTED_SERVICES), schema_up_to_date=True)

        result = self.run_transaction()

        self.assertEqual(0, result.returncode, result.stderr)
        self.assertEqual(
            {"changed": False, "status": "verified-unchanged"},
            json.loads(result.stdout),
        )
        self.assertEqual(["config", "config", "up", "bootstrap", "schema-check", "ps"], self.operations())
        self.assertEqual([], self.force_recreate_calls())
        self.assertEqual(
            {service: 1 for service in EXPECTED_SERVICES},
            self.service_generations(),
        )
        self.assert_safe_docker_calls()

    def test_secret_only_rotation_force_recreates_each_application_but_not_mariadb(self) -> None:
        self.install_current("new")
        rotated_app_secret = "ROTATED-APP-SECRET-SENTINEL"
        rotated_matrix_webhook = "https://matrix.example.test/ROTATED-WEBHOOK-SENTINEL"
        (self.staging / "secrets" / "app_secret").write_text(rotated_app_secret + "\n", encoding="utf-8")
        (self.staging / "secrets" / "matrix_webhook_url").write_text(
            rotated_matrix_webhook + "\n",
            encoding="utf-8",
        )
        self.write_state(sorted(EXPECTED_SERVICES), schema_up_to_date=True)

        result = self.run_transaction()

        self.assertEqual(0, result.returncode, result.stderr)
        self.assertEqual({"changed": True, "status": "deployed"}, json.loads(result.stdout))
        self.assertEqual(rotated_app_secret + "\n", (self.secrets / "app_secret").read_text(encoding="utf-8"))
        self.assertEqual(
            rotated_matrix_webhook + "\n",
            (self.secrets / "matrix_webhook_url").read_text(encoding="utf-8"),
        )
        self.assertEqual(1, len(self.force_recreate_calls()))
        self.assertEqual(
            {"mariadb": 1, "data-worker": 2, "backup-worker": 2, "webapp": 2},
            self.service_generations(),
        )
        self.assertNotIn(rotated_app_secret, result.stdout + result.stderr)
        self.assertNotIn(rotated_matrix_webhook, result.stdout + result.stderr)
        self.assert_safe_docker_calls()

    def test_secret_only_failure_recreates_restored_applications_before_reverification(self) -> None:
        self.install_current("new")
        before = self.current_state()
        candidate_secret = "FAILED-CANDIDATE-SECRET-SENTINEL"
        (self.staging / "secrets" / "app_secret").write_text(candidate_secret + "\n", encoding="utf-8")
        self.write_state(sorted(EXPECTED_SERVICES), failures={"ps": [2]}, schema_up_to_date=True)

        result = self.run_transaction()

        self.assertNotEqual(0, result.returncode)
        after = self.current_state()
        keyring_path = str(self.secrets / "encryption_key")
        self.assertEqual(before[keyring_path][1], after[keyring_path][1])
        before_keyring = json.loads(before[keyring_path][0])
        after_keyring = json.loads(after[keyring_path][0])
        self.assertEqual(before_keyring["format"], after_keyring["format"])
        self.assertEqual(before_keyring["revision"], after_keyring["revision"])
        self.assertEqual(before_keyring["primaryKeyId"], after_keyring["primaryKeyId"])
        self.assertEqual(
            {entry["id"]: entry["material"] for entry in before_keyring["keys"]},
            {entry["id"]: entry["material"] for entry in after_keyring["keys"]},
        )
        before.pop(keyring_path)
        after.pop(keyring_path)
        self.assertEqual(before, after)
        self.assertEqual(2, len(self.force_recreate_calls()))
        self.assertEqual(
            {"mariadb": 1, "data-worker": 3, "backup-worker": 3, "webapp": 3},
            self.service_generations(),
        )
        self.assertEqual(
            ["config", "config", "ps", "pull", "up", "bootstrap", "schema-check", "up", "ps", "up", "up", "ps"],
            self.operations(),
        )
        self.assertIn("restored application services were recreated", result.stderr)
        self.assertNotIn(candidate_secret, result.stdout + result.stderr)
        self.assert_safe_docker_calls()

    def test_unchanged_bootstrap_failure_keeps_stack_and_is_retried_next_deployment(self) -> None:
        self.install_current("new")
        before = self.current_state()
        self.write_state(sorted(EXPECTED_SERVICES), failures={"bootstrap": [1]}, schema_up_to_date=True)

        failed = self.run_transaction()

        self.assertNotEqual(0, failed.returncode)
        self.assertEqual(before, self.current_state())
        self.assertEqual(["config", "config", "up", "bootstrap"], self.operations())
        self.assertNotIn("down", self.operations())
        self.assertIn("existing application services were not stopped", failed.stderr)

        retried = self.run_transaction()

        self.assertEqual(0, retried.returncode, retried.stderr)
        self.assertEqual(
            [
                "config", "config", "up", "bootstrap",
                "config", "config", "up", "bootstrap", "schema-check", "ps",
            ],
            self.operations(),
        )
        self.assert_safe_docker_calls()

    def test_unchanged_schema_status_error_never_runs_migration_or_stops_stack(self) -> None:
        self.install_current("new")
        before = self.current_state()
        self.write_state(sorted(EXPECTED_SERVICES), schema_check_returncode=2)

        result = self.run_transaction()

        self.assertNotEqual(0, result.returncode)
        self.assertEqual(before, self.current_state())
        self.assertEqual(["config", "config", "up", "bootstrap", "schema-check"], self.operations())
        self.assertNotIn("run", self.operations())
        self.assertNotIn("down", self.operations())
        self.assertIn("without changing application files", result.stderr)
        self.assertIn("existing application services were not stopped", result.stderr)
        self.assertIn("forward-only and was not rolled back", result.stderr)
        self.assert_safe_docker_calls()

    def test_unchanged_files_cannot_apply_pending_migration_without_maintenance(self) -> None:
        self.install_current("new")
        before = self.current_state()
        self.write_state(sorted(EXPECTED_SERVICES), schema_up_to_date=False)
        result = self.run_transaction()
        self.assertNotEqual(0, result.returncode)
        self.assertEqual(before, self.current_state())
        self.assertIn("image-only migration is disabled", result.stderr)
        self.assertNotIn("run", self.operations())
        self.assertNotIn("down", self.operations())
        self.assert_safe_docker_calls()

    def test_unchanged_migration_failure_keeps_application_files_and_services(self) -> None:
        self.install_current("new")
        before = self.current_state()
        self.write_state(sorted(EXPECTED_SERVICES), failures={"run": [1]}, schema_up_to_date=False)

        result = self.run_transaction()

        self.assertNotEqual(0, result.returncode)
        self.assertEqual(before, self.current_state())
        self.assertEqual(["config", "config", "up", "bootstrap", "schema-check"], self.operations())
        self.assertNotIn("down", self.operations())
        self.assertIn("without changing application files", result.stderr)
        self.assertIn("existing application services were not stopped", result.stderr)
        self.assertIn("forward-only and was not rolled back", result.stderr)
        self.assertIn("image-only migration is disabled", result.stderr)
        self.assert_safe_docker_calls()

    def test_changed_verify_failure_restores_all_files_and_reverifies(self) -> None:
        self.install_current("old")
        self.write_state(sorted(EXPECTED_SERVICES), failures={"ps": [2]})
        before = self.current_state()
        result = self.run_transaction()

        self.assertNotEqual(0, result.returncode)
        self.assert_recovered_with_additive_union(before)
        self.assertEqual(
            ["config", "config", "ps", "pull", "up", "bootstrap", "schema-check", "up", "ps", "up", "up", "ps"],
            self.operations(),
        )
        self.assertIn("retained for recovery and must not be removed", result.stderr)
        self.assertNotIn("down", self.operations())
        self.assert_safe_docker_calls()
        self.assert_transaction_lock_available()

    def test_changed_bootstrap_failure_restores_previous_stack_before_migration(self) -> None:
        self.install_current("old")
        before = self.current_state()
        self.write_state(sorted(EXPECTED_SERVICES), failures={"bootstrap": [1]})

        result = self.run_transaction()

        self.assertNotEqual(0, result.returncode)
        self.assertEqual(before, self.current_state())
        self.assertEqual(
            ["config", "config", "ps", "pull", "up", "bootstrap", "up", "ps"],
            self.operations(),
        )
        self.assertNotIn("schema-check", self.operations())
        self.assertNotIn("run", self.operations())
        self.assertIn("managed application files were restored", result.stderr)
        self.assertIn("application services remained running", result.stderr)
        self.assertNotIn("retained for recovery", result.stderr)
        self.assert_safe_docker_calls()

    def test_changed_schema_status_error_restores_previous_stack_without_running_migration(self) -> None:
        self.install_current("old")
        before = self.current_state()
        self.write_state(sorted(EXPECTED_SERVICES), schema_check_returncode=2)

        result = self.run_transaction()

        self.assertNotEqual(0, result.returncode)
        self.assertEqual(before, self.current_state())
        self.assertEqual(
            ["config", "config", "ps", "pull", "up", "bootstrap", "schema-check", "up", "ps"],
            self.operations(),
        )
        self.assertNotIn("run", self.operations())
        self.assertNotIn("down", self.operations())
        self.assertIn("managed application files were restored", result.stderr)
        self.assertIn("application services remained running", result.stderr)
        self.assertNotIn("retained for recovery", result.stderr)
        self.assertIn("forward-only and was not rolled back", result.stderr)
        self.assert_safe_docker_calls()

    def test_changed_candidate_health_failure_reports_http_status_after_successful_recovery(self) -> None:
        self.install_current("old")
        before = self.current_state()
        self.write_state(sorted(EXPECTED_SERVICES), schema_up_to_date=True)

        result = self.run_transaction((503, 200))

        self.assertNotEqual(0, result.returncode)
        self.assert_recovered_with_additive_union(before)
        self.assertEqual(
            ["config", "config", "ps", "pull", "up", "bootstrap", "schema-check", "up", "ps", "up", "up", "ps"],
            self.operations(),
        )
        self.assertIn("managed application files were restored", result.stderr)
        self.assertIn("restored application services were recreated", result.stderr)
        self.assertIn("Cause: Web health endpoint returned HTTP 503.", result.stderr)
        self.assertIn("retained for recovery and must not be removed", result.stderr)
        self.assert_safe_docker_calls()

    def test_failed_first_deployment_stops_containers_but_preserves_database_credentials(self) -> None:
        self.write_state([], failures={"ps": [1]})
        result = self.run_transaction()

        self.assertNotEqual(0, result.returncode)
        self.assertEqual(
            ["config", "config", "pull", "up", "bootstrap", "schema-check", "run", "up", "ps", "down"],
            self.operations(),
        )
        self.assertFalse((self.install / "compose.yaml").exists())
        self.assertFalse((self.install / "compose.migration.yaml").exists())
        self.assertFalse((self.install / "runtime.env").exists())
        self.assertFalse((self.install / "10-create-app-users.sh").exists())
        self.assertFalse((self.secrets / "app_secret").exists())
        self.assert_retained_candidate_keyring()
        for secret_name in DATABASE_SECRET_NAMES:
            self.assertTrue((self.secrets / secret_name).is_file())
            self.assertEqual(0o600, stat.S_IMODE((self.secrets / secret_name).stat().st_mode))
        self.assertIn("retained for recovery and must not be removed", result.stderr)
        self.assert_safe_docker_calls()

    def test_failed_first_bootstrap_stops_database_and_preserves_database_credentials(self) -> None:
        self.write_state([], failures={"bootstrap": [1]})

        result = self.run_transaction()

        self.assertNotEqual(0, result.returncode)
        self.assertEqual(
            ["config", "config", "pull", "up", "bootstrap", "down"],
            self.operations(),
        )
        for artifact in (
            self.install / "compose.yaml",
            self.install / "compose.migration.yaml",
            self.install / "runtime.env",
            self.install / "10-create-app-users.sh",
            self.secrets / "app_secret",
            self.secrets / "encryption_key",
        ):
            self.assertFalse(artifact.exists())
        for secret_name in DATABASE_SECRET_NAMES:
            self.assertTrue((self.secrets / secret_name).is_file())
            self.assertEqual(0o600, stat.S_IMODE((self.secrets / secret_name).stat().st_mode))
        self.assertIn("First-deployment containers were stopped", result.stderr)
        self.assertNotIn("retained for recovery", result.stderr)
        self.assertNotIn("schema-check", self.operations())
        self.assertNotIn("run", self.operations())
        self.assert_safe_docker_calls()

    def test_failed_first_migration_preserves_database_credentials_and_reports_partial_ddl(self) -> None:
        self.write_state([], failures={"run": [1]})

        result = self.run_transaction()

        self.assertNotEqual(0, result.returncode)
        self.assertEqual(
            ["config", "config", "pull", "up", "bootstrap", "schema-check", "run", "down"],
            self.operations(),
        )
        for artifact in (
            self.install / "compose.yaml",
            self.install / "compose.migration.yaml",
            self.install / "runtime.env",
            self.install / "10-create-app-users.sh",
            self.secrets / "app_secret",
            self.secrets / "encryption_key",
        ):
            self.assertFalse(artifact.exists())
        for secret_name in DATABASE_SECRET_NAMES:
            self.assertTrue((self.secrets / secret_name).is_file())
            self.assertEqual(0o600, stat.S_IMODE((self.secrets / secret_name).stat().st_mode))
        self.assertIn("First-deployment containers were stopped", result.stderr)
        self.assertNotIn("retained for recovery", result.stderr)
        self.assertIn("MariaDB DDL may already be applied or partially applied", result.stderr)
        self.assertIn("forward-only and was not rolled back", result.stderr)
        self.assert_safe_docker_calls()

    def test_pending_migration_is_blocked_before_ddl_on_image_only_recovery_path(self) -> None:
        self.install_current("old")
        before = self.current_state()
        self.write_state(sorted(EXPECTED_SERVICES), failures={"run": [1]}, schema_up_to_date=False)

        result = self.run_transaction()

        self.assertNotEqual(0, result.returncode)
        self.assertEqual(before, self.current_state())
        self.assertIn("image-only migration is disabled", result.stderr)
        self.assertIn("forward-only and was not rolled back", result.stderr)
        self.assertEqual(
            ["config", "config", "ps", "pull", "up", "bootstrap", "schema-check", "up", "ps"],
            self.operations(),
        )
        self.assertIn("application services remained running", result.stderr)
        self.assertNotIn("retained for recovery", result.stderr)
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

    def test_additive_rotation_and_primary_switch_with_revision_increase_deploys(self) -> None:
        self.install_current("old")
        self.write_state(sorted(EXPECTED_SERVICES), schema_up_to_date=True)

        result = self.run_transaction()

        self.assertEqual(0, result.returncode, result.stderr)
        self.assertEqual("deployed", json.loads(result.stdout)["status"])
        self.assertEqual(
            json.loads((self.staging / "secrets" / "encryption_key").read_text(encoding="utf-8")),
            json.loads((self.secrets / "encryption_key").read_text(encoding="utf-8")),
        )
        self.assert_safe_docker_calls()

    def test_primary_only_switch_with_revision_increase_deploys(self) -> None:
        self.install_current("new")
        keys = [
            {"id": "key_old", "material": KEY_MATERIAL_OLD},
            {"id": "key_new", "material": KEY_MATERIAL_NEW},
        ]
        self.write_keyring_document(self.secrets / "encryption_key", 1, "key_old", keys)
        self.write_keyring_document(self.staging / "secrets" / "encryption_key", 2, "key_new", keys)
        self.write_state(sorted(EXPECTED_SERVICES), schema_up_to_date=True)

        result = self.run_transaction()

        self.assertEqual(0, result.returncode, result.stderr)
        self.assertEqual("deployed", json.loads(result.stdout)["status"])
        self.assert_safe_docker_calls()

    def test_additive_key_without_primary_switch_with_revision_increase_deploys(self) -> None:
        self.install_current("new")
        self.write_keyring_document(
            self.secrets / "encryption_key",
            1,
            "key_old",
            [{"id": "key_old", "material": KEY_MATERIAL_OLD}],
        )
        self.write_keyring_document(
            self.staging / "secrets" / "encryption_key",
            2,
            "key_old",
            [
                {"id": "key_old", "material": KEY_MATERIAL_OLD},
                {"id": "key_new", "material": KEY_MATERIAL_NEW},
            ],
        )
        self.write_state(sorted(EXPECTED_SERVICES), schema_up_to_date=True)

        result = self.run_transaction()

        self.assertEqual(0, result.returncode, result.stderr)
        self.assertEqual("deployed", json.loads(result.stdout)["status"])
        self.assert_safe_docker_calls()

    def test_pure_revision_increase_deploys(self) -> None:
        self.install_current("new")
        keys = [{"id": "key_old", "material": KEY_MATERIAL_OLD}]
        self.write_keyring_document(self.secrets / "encryption_key", 1, "key_old", keys)
        self.write_keyring_document(self.staging / "secrets" / "encryption_key", 2, "key_old", keys)
        self.write_state(sorted(EXPECTED_SERVICES), schema_up_to_date=True)

        result = self.run_transaction()

        self.assertEqual(0, result.returncode, result.stderr)
        self.assertEqual("deployed", json.loads(result.stdout)["status"])

    def test_key_entry_reordering_is_semantically_identical_without_revision_increase(self) -> None:
        self.install_current("new")
        staged_document = json.loads((self.staging / "secrets" / "encryption_key").read_text(encoding="utf-8"))
        reversed_keys = list(reversed(staged_document["keys"]))
        self.write_keyring_document(
            self.secrets / "encryption_key",
            2,
            staged_document["primaryKeyId"],
            reversed_keys,
        )
        self.write_state(sorted(EXPECTED_SERVICES), schema_up_to_date=True)

        result = self.run_transaction()

        self.assertEqual(0, result.returncode, result.stderr)
        self.assertEqual("deployed", json.loads(result.stdout)["status"])

    def test_revision_regression_is_blocked_before_docker_or_mutation(self) -> None:
        self.install_current("new")
        self.write_keyring_document(
            self.secrets / "encryption_key",
            3,
            "key_new",
            [
                {"id": "key_old", "material": KEY_MATERIAL_OLD},
                {"id": "key_new", "material": KEY_MATERIAL_NEW},
            ],
        )
        self.assert_guard_rejected("revision cannot decrease")

    def test_semantic_change_without_revision_increase_is_blocked_before_docker_or_mutation(self) -> None:
        self.install_current("old")
        self.write_keyring_document(
            self.staging / "secrets" / "encryption_key",
            1,
            "key_new",
            [
                {"id": "key_old", "material": KEY_MATERIAL_OLD},
                {"id": "key_new", "material": KEY_MATERIAL_NEW},
            ],
        )
        self.expected_keyring_revision = "1"
        self.assert_guard_rejected("require a revision increase")

    def test_existing_identifier_material_change_is_blocked_before_docker_or_mutation(self) -> None:
        self.install_current("old")
        self.write_keyring_document(
            self.staging / "secrets" / "encryption_key",
            2,
            "key_old",
            [{"id": "key_old", "material": KEY_MATERIAL_NEW}],
        )
        self.assert_guard_rejected("cannot change under an existing identifier")

    def test_existing_material_moved_to_new_identifier_is_blocked_before_docker_or_mutation(self) -> None:
        self.install_current("old")
        self.write_keyring_document(
            self.staging / "secrets" / "encryption_key",
            2,
            "renamed_key",
            [{"id": "renamed_key", "material": KEY_MATERIAL_OLD}],
        )
        self.assert_guard_rejected("cannot move to a different identifier")

    def test_historical_key_removal_is_unconditionally_blocked_before_docker_or_mutation(self) -> None:
        self.install_current("new")
        self.write_keyring_document(
            self.staging / "secrets" / "encryption_key",
            3,
            "key_new",
            [{"id": "key_new", "material": KEY_MATERIAL_NEW}],
        )
        self.expected_keyring_revision = "3"
        self.assert_guard_rejected("removal is unsupported")

    def test_cli_revision_mismatch_is_blocked_before_docker_or_mutation(self) -> None:
        self.install_current("new")
        self.expected_keyring_revision = "3"
        self.assert_guard_rejected("does not match the deployment revision")

    def test_invalid_cli_revision_is_blocked_before_docker_or_mutation(self) -> None:
        self.install_current("new")
        self.expected_keyring_revision = "not-an-integer"
        self.assert_guard_rejected("deployment encryption keyring revision is invalid")

    def test_missing_installed_keyring_on_existing_installation_is_blocked_before_docker_or_mutation(self) -> None:
        self.install_current("new")
        (self.secrets / "encryption_key").unlink()
        self.assert_guard_rejected("installed encryption keyring is missing")

    def test_malformed_staged_and_installed_keyrings_are_blocked_safely_before_docker(self) -> None:
        for location in ("staged", "installed"):
            with self.subTest(location=location):
                self.reset_case(f"malformed-{location}")
                self.install_current("new")
                target = (
                    self.staging / "secrets" / "encryption_key"
                    if location == "staged"
                    else self.secrets / "encryption_key"
                )
                target.write_text('{"material":"KEYRING-SENTINEL"', encoding="utf-8")

                result = self.assert_guard_rejected(f"{location} encryption keyring is invalid")
                self.assertNotIn("KEYRING-SENTINEL", result.stdout)
                self.assertNotIn("KEYRING-SENTINEL", result.stderr)

    def test_duplicate_json_fields_are_rejected_for_staged_and_installed_keyrings(self) -> None:
        duplicate_document = (
            '{"format":1,"format":1,"revision":2,"primaryKeyId":"key_new",'
            '"keys":[{"id":"key_new","material":"' + KEY_MATERIAL_NEW + '"}]}'
        )
        for location in ("staged", "installed"):
            with self.subTest(location=location):
                self.reset_case(f"duplicate-{location}")
                self.install_current("new")
                target = (
                    self.staging / "secrets" / "encryption_key"
                    if location == "staged"
                    else self.secrets / "encryption_key"
                )
                target.write_text(duplicate_document, encoding="utf-8")
                self.assert_guard_rejected(f"{location} encryption keyring is invalid")

    def test_legacy_lowerhex_key_upgrades_only_to_one_matching_primary_key(self) -> None:
        self.secrets.mkdir(parents=True, exist_ok=True)
        (self.secrets / "encryption_key").write_text(KEY_MATERIAL_OLD + "\n", encoding="ascii")
        self.write_keyring_document(
            self.staging / "secrets" / "encryption_key",
            2,
            "established_key",
            [{"id": "established_key", "material": KEY_MATERIAL_OLD}],
        )
        self.write_state([], schema_up_to_date=False)

        result = self.run_transaction()

        self.assertEqual(0, result.returncode, result.stderr)
        self.assertEqual("deployed", json.loads(result.stdout)["status"])

    def test_legacy_key_upgrade_rejects_different_material_and_multiple_keys_before_docker(self) -> None:
        staged_cases = (
            [{"id": "established_key", "material": KEY_MATERIAL_NEW}],
            [
                {"id": "established_key", "material": KEY_MATERIAL_OLD},
                {"id": "additional_key", "material": KEY_MATERIAL_NEW},
            ],
        )
        for index, keys in enumerate(staged_cases):
            with self.subTest(index=index):
                self.reset_case(f"legacy-bad-{index}")
                self.secrets.mkdir(parents=True, exist_ok=True)
                (self.secrets / "encryption_key").write_text(KEY_MATERIAL_OLD + "\n", encoding="ascii")
                self.write_keyring_document(
                    self.staging / "secrets" / "encryption_key",
                    2,
                    "established_key",
                    keys,
                )
                self.assert_guard_rejected("legacy encryption key cannot be upgraded")

    def test_legacy_key_with_existing_compose_requires_offline_maintenance_before_docker(self) -> None:
        self.install_current("new")
        (self.secrets / "encryption_key").write_text(KEY_MATERIAL_OLD + "\n", encoding="ascii")
        self.write_keyring_document(
            self.staging / "secrets" / "encryption_key",
            2,
            "established_key",
            [{"id": "established_key", "material": KEY_MATERIAL_OLD}],
        )

        self.assert_guard_rejected("requires separate offline maintenance")

    def test_first_deployment_with_legacy_seed_retains_candidate_keyring_after_post_start_failure(self) -> None:
        self.secrets.mkdir(parents=True, exist_ok=True)
        (self.secrets / "encryption_key").write_text(KEY_MATERIAL_OLD + "\n", encoding="ascii")
        self.write_keyring_document(
            self.staging / "secrets" / "encryption_key",
            2,
            "established_key",
            [{"id": "established_key", "material": KEY_MATERIAL_OLD}],
        )
        self.write_state([], failures={"ps": [1]})

        result = self.run_transaction()

        self.assertNotEqual(0, result.returncode)
        document = json.loads((self.secrets / "encryption_key").read_text(encoding="utf-8"))
        self.assertEqual(2, document["revision"])
        self.assertEqual("established_key", document["primaryKeyId"])
        self.assertEqual(["established_key"], [entry["id"] for entry in document["keys"]])
        self.assertEqual(0o600, stat.S_IMODE((self.secrets / "encryption_key").stat().st_mode))
        self.assertIn("retained for recovery and must not be removed", result.stderr)

    def test_post_start_recovery_union_blocks_a_followup_key_removal(self) -> None:
        self.install_current("old")
        self.write_state(sorted(EXPECTED_SERVICES), failures={"ps": [2]})
        failed_deployment = self.run_transaction()
        self.assertNotEqual(0, failed_deployment.returncode)
        self.assert_recovery_union_contract()

        self.write_keyring_document(
            self.staging / "secrets" / "encryption_key",
            2,
            "key_old",
            [{"id": "key_old", "material": KEY_MATERIAL_OLD}],
        )
        self.assert_guard_rejected("removal is unsupported")

    def test_transaction_keyring_parser_rejects_every_invalid_contract_without_leaking_values(self) -> None:
        valid_entry = {"id": "key_one", "material": KEY_MATERIAL_OLD}
        valid = {"format": 1, "revision": 1, "primaryKeyId": "key_one", "keys": [valid_entry]}
        invalid_documents: tuple[bytes, ...] = (
            b"",
            b"\xff",
            b"x" * 65537,
            json.dumps([]).encode(),
            json.dumps({**valid, "extra": "PARSER-SENTINEL"}).encode(),
            json.dumps({key: value for key, value in valid.items() if key != "keys"}).encode(),
            json.dumps({**valid, "format": True}).encode(),
            json.dumps({**valid, "revision": True}).encode(),
            json.dumps({**valid, "revision": 0}).encode(),
            json.dumps({**valid, "primaryKeyId": "INVALID"}).encode(),
            json.dumps({**valid, "keys": []}).encode(),
            json.dumps({**valid, "keys": [valid_entry] * 17}).encode(),
            json.dumps({**valid, "keys": [["not-an-object"]]}).encode(),
            json.dumps({**valid, "keys": [{**valid_entry, "extra": True}]}).encode(),
            json.dumps({**valid, "keys": [{"id": "INVALID", "material": KEY_MATERIAL_OLD}]}).encode(),
            json.dumps({**valid, "keys": [{"id": "key_one", "material": KEY_MATERIAL_OLD.upper()}]}).encode(),
            json.dumps({**valid, "keys": [valid_entry, {"id": "key_one", "material": KEY_MATERIAL_NEW}]}).encode(),
            json.dumps({**valid, "keys": [valid_entry, {"id": "key_two", "material": KEY_MATERIAL_OLD}]}).encode(),
            json.dumps({**valid, "primaryKeyId": "missing"}).encode(),
            (
                '{"format":1,"revision":1,"primaryKeyId":"key_one","keys":['
                '{"id":"key_one","id":"other","material":"' + KEY_MATERIAL_OLD + '"}]}'
            ).encode(),
        )
        parser_directory = self.root / "parser-invalid"
        parser_directory.mkdir(parents=True, exist_ok=True)

        for index, document in enumerate(invalid_documents):
            with self.subTest(index=index):
                path = parser_directory / str(index)
                path.write_bytes(document)
                with self.assertRaisesRegex(
                    TRANSACTION_MODULE.DeploymentError,
                    "The staged encryption keyring is invalid",
                ) as caught:
                    TRANSACTION_MODULE.load_encryption_keyring(
                        path,
                        "The staged encryption keyring is invalid.",
                    )
                self.assertNotIn("SENTINEL", str(caught.exception))

        key = TRANSACTION_MODULE.EncryptionKey("key_one", bytes.fromhex(KEY_MATERIAL_OLD))
        keyring = TRANSACTION_MODULE.EncryptionKeyring(1, "key_one", {"key_one": key})
        self.assertNotIn(KEY_MATERIAL_OLD, repr(key))
        self.assertNotIn(KEY_MATERIAL_OLD, repr(keyring))

    def test_recovery_union_defensively_rejects_changed_existing_material(self) -> None:
        recovery_directory = self.root / "recovery-invariant"
        recovery_directory.mkdir(parents=True, exist_ok=True)
        installed_file = recovery_directory / "encryption_key"
        self.write_keyring_document(
            installed_file,
            1,
            "key_old",
            [{"id": "key_old", "material": KEY_MATERIAL_OLD}],
        )
        candidate_file = recovery_directory / "candidate"
        self.write_keyring_document(
            candidate_file,
            2,
            "key_old",
            [{"id": "key_old", "material": KEY_MATERIAL_NEW}],
        )
        candidate = TRANSACTION_MODULE.load_encryption_keyring(
            candidate_file,
            "The candidate encryption keyring is invalid.",
        )
        before = installed_file.read_bytes()

        with self.assertRaisesRegex(
            TRANSACTION_MODULE.DeploymentError,
            "Recovery encryption keyring invariants are invalid",
        ) as caught:
            TRANSACTION_MODULE.install_additive_recovery_keyring(installed_file, candidate)

        self.assertEqual(before, installed_file.read_bytes())
        self.assertNotIn(KEY_MATERIAL_OLD, str(caught.exception))
        self.assertNotIn(KEY_MATERIAL_NEW, str(caught.exception))

    def test_each_install_failure_restores_bytes_modes_and_removes_backup(self) -> None:
        managed_names = (
            "runtime.env",
            "compose.migration.yaml",
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
                self.assertIn("managed application files were restored", str(error))
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
        self.assert_recovered_with_additive_union(before)
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
        (self.staging / "compose.migration.yaml").write_text(f"migration-compose-{marker}\n", encoding="utf-8")
        (self.staging / "runtime.env").write_text(f"RUNTIME={marker}\n", encoding="utf-8")
        (self.staging / "10-create-app-users.sh").write_text(f"init-{marker}\n", encoding="utf-8")
        for index, secret_name in enumerate(SECRET_NAMES, start=1):
            secret_file = self.staging / "secrets" / secret_name
            if secret_name == "encryption_key":
                self.write_keyring(secret_file, marker)
                continue
            value = f"{index:x}" * 64 if secret_name in DATABASE_SECRET_NAMES else f"{marker}-{secret_name}"
            secret_file.write_text(value + "\n", encoding="utf-8")

    def install_current(self, marker: str) -> None:
        self.install.mkdir(parents=True, exist_ok=True)
        self.secrets.mkdir(parents=True, exist_ok=True)
        self.secrets.chmod(0o700)
        (self.install / "compose.yaml").write_text(f"compose-{marker}\n", encoding="utf-8")
        (self.install / "compose.migration.yaml").write_text(f"migration-compose-{marker}\n", encoding="utf-8")
        (self.install / "runtime.env").write_text(f"RUNTIME={marker}\n", encoding="utf-8")
        (self.install / "10-create-app-users.sh").write_text(f"init-{marker}\n", encoding="utf-8")
        (self.install / "compose.yaml").chmod(0o640)
        (self.install / "compose.migration.yaml").chmod(0o640)
        (self.install / "runtime.env").chmod(0o640)
        (self.install / "10-create-app-users.sh").chmod(0o555)
        for index, secret_name in enumerate(SECRET_NAMES, start=1):
            secret_file = self.secrets / secret_name
            if secret_name == "encryption_key":
                self.write_keyring(secret_file, marker)
            else:
                value = f"{index:x}" * 64 if secret_name in DATABASE_SECRET_NAMES else f"{marker}-{secret_name}"
                secret_file.write_text(value + "\n", encoding="utf-8")
            secret_file.chmod(0o600)

    def write_keyring(self, path: Path, marker: str) -> None:
        if marker == "old":
            document = {
                "format": 1,
                "revision": 1,
                "primaryKeyId": "key_old",
                "keys": [{"id": "key_old", "material": KEY_MATERIAL_OLD}],
            }
        elif marker == "new":
            document = {
                "format": 1,
                "revision": 2,
                "primaryKeyId": "key_new",
                "keys": [
                    {"id": "key_old", "material": KEY_MATERIAL_OLD},
                    {"id": "key_new", "material": KEY_MATERIAL_NEW},
                ],
            }
        else:
            raise AssertionError("Unknown test keyring marker.")
        path.write_text(json.dumps(document, separators=(",", ":")) + "\n", encoding="utf-8")

    def write_keyring_document(
        self,
        path: Path,
        revision: int,
        primary_key_id: str,
        keys: list[dict[str, str]],
    ) -> None:
        path.write_text(
            json.dumps(
                {
                    "format": 1,
                    "revision": revision,
                    "primaryKeyId": primary_key_id,
                    "keys": keys,
                },
                separators=(",", ":"),
            ) + "\n",
            encoding="utf-8",
        )

    def make_all_install_points_changed(self) -> None:
        modes = {
            "runtime.env": 0o600,
            "compose.migration.yaml": 0o600,
            "10-create-app-users.sh": 0o500,
            "app_secret": 0o640,
            "encryption_key": 0o400,
            "mariadb_root_password": 0o640,
            "mariadb_migration_password": 0o640,
            "mariadb_web_password": 0o640,
            "mariadb_collector_password": 0o640,
            "mariadb_backup_worker_password": 0o640,
            "matrix_webhook_url": 0o640,
            "compose.yaml": 0o600,
        }
        for name, mode in modes.items():
            self.current_path(name).chmod(mode)

    def write_state(
        self,
        running_services: list[str],
        failures: dict[str, list[int]] | None = None,
        schema_up_to_date: bool | None = None,
        schema_check_returncode: int | None = None,
    ) -> None:
        self.state_file.write_text(
            json.dumps(
                {
                    "expected_services": sorted(EXPECTED_SERVICES),
                    "running_services": running_services,
                    "failures": failures or {},
                    "schema_up_to_date": bool(running_services) if schema_up_to_date is None else schema_up_to_date,
                    "schema_check_returncode": schema_check_returncode,
                    "service_generations": {service: 1 for service in running_services},
                    "calls": [],
                },
            ),
            encoding="utf-8",
        )

    def run_transaction(
        self,
        health_statuses: tuple[int, ...] = (200,),
    ) -> subprocess.CompletedProcess[str]:
        environment = os.environ.copy()
        environment["FAKE_DOCKER_STATE"] = str(self.state_file)
        with health_server(health_statuses) as health_url:
            command = [sys.executable, str(TRANSACTION_SCRIPT), *self.transaction_arguments(health_url)]
            return run(command, env=environment)

    def assert_guard_rejected(self, expected_message: str) -> subprocess.CompletedProcess[str]:
        before = self.current_state()
        self.write_state(sorted(EXPECTED_SERVICES))

        result = self.run_transaction()

        self.assertNotEqual(0, result.returncode)
        self.assertEqual(before, self.current_state())
        self.assertEqual([], self.operations())
        self.assertIn(expected_message.lower(), result.stderr.lower())
        self.assertNotIn("Traceback", result.stderr)
        for sensitive_value in (KEY_MATERIAL_OLD, KEY_MATERIAL_NEW, "SENTINEL"):
            self.assertNotIn(sensitive_value, result.stdout)
            self.assertNotIn(sensitive_value, result.stderr)
        self.assert_transaction_lock_available()

        return result

    def assert_recovered_with_additive_union(self, previous_state: dict[str, tuple[bytes, int]]) -> None:
        current_state = self.current_state()
        keyring_path = str(self.secrets / "encryption_key")
        previous_without_keyring = dict(previous_state)
        current_without_keyring = dict(current_state)
        previous_without_keyring.pop(keyring_path)
        current_without_keyring.pop(keyring_path)
        self.assertEqual(previous_without_keyring, current_without_keyring)
        self.assert_recovery_union_contract()

    def assert_recovery_union_contract(self) -> None:
        keyring_file = self.secrets / "encryption_key"
        document = json.loads(keyring_file.read_text(encoding="utf-8"))
        self.assertEqual(1, document["revision"])
        self.assertEqual("key_old", document["primaryKeyId"])
        self.assertEqual(
            {
                "key_old": KEY_MATERIAL_OLD,
                "key_new": KEY_MATERIAL_NEW,
            },
            {entry["id"]: entry["material"] for entry in document["keys"]},
        )
        self.assertEqual(0o600, stat.S_IMODE(keyring_file.stat().st_mode))

    def assert_retained_candidate_keyring(self) -> None:
        keyring_file = self.secrets / "encryption_key"
        document = json.loads(keyring_file.read_text(encoding="utf-8"))
        self.assertEqual(2, document["revision"])
        self.assertEqual("key_new", document["primaryKeyId"])
        self.assertEqual(
            {"key_old", "key_new"},
            {entry["id"] for entry in document["keys"]},
        )
        self.assertEqual(0o600, stat.S_IMODE(keyring_file.stat().st_mode))

    def assert_transaction_lock_available(self) -> None:
        descriptor = os.open(self.lock_file, os.O_RDWR)
        try:
            fcntl.flock(descriptor, fcntl.LOCK_EX | fcntl.LOCK_NB)
            fcntl.flock(descriptor, fcntl.LOCK_UN)
            self.assertEqual(0o600, stat.S_IMODE(os.fstat(descriptor).st_mode))
        finally:
            os.close(descriptor)

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
            f"--current-migration-compose-file={self.install / 'compose.migration.yaml'}",
            f"--current-environment-file={self.install / 'runtime.env'}",
            f"--current-mariadb-init-script={self.install / '10-create-app-users.sh'}",
            f"--current-secrets-directory={self.secrets}",
            f"--health-url={health_url}",
            "--wait-timeout=10",
            "--migration-service=schema-migration",
            "--database-service=mariadb",
            "--database-bootstrap-script=/usr/local/bin/hoddmimir-database-user-bootstrap",
            f"--encryption-keyring-revision={self.expected_keyring_revision}",
            f"--transaction-lock-file={self.lock_file}",
        ]
        arguments.extend(f"--expected-service={service}" for service in sorted(EXPECTED_SERVICES))
        return arguments

    def operations(self) -> list[str]:
        state = json.loads(self.state_file.read_text(encoding="utf-8"))
        return [call["operation"] for call in state["calls"]]

    def force_recreate_calls(self) -> list[dict[str, object]]:
        state = json.loads(self.state_file.read_text(encoding="utf-8"))
        return [
            call
            for call in state["calls"]
            if call["operation"] == "up" and "--force-recreate" in call["arguments"]
        ]

    def service_generations(self) -> dict[str, int]:
        state = json.loads(self.state_file.read_text(encoding="utf-8"))
        return state["service_generations"]

    def assert_safe_docker_calls(self) -> None:
        expected_arguments = {
            "config": ["config", "--quiet"],
            "bootstrap": [
                "exec",
                "-T",
                "--user",
                "0",
                "mariadb",
                "/usr/local/bin/hoddmimir-database-user-bootstrap",
            ],
            "pull": ["pull"],
            "up": (
                ["up", "--detach", "--wait", "--wait-timeout", "10", "mariadb"],
                [
                    "up",
                    "--detach",
                    "--no-deps",
                    "--force-recreate",
                    "--remove-orphans",
                    "--wait",
                    "--wait-timeout",
                    "10",
                    "data-worker",
                    "backup-worker",
                    "webapp",
                ],
            ),
            "run": ["run", "--rm", "--no-deps", "schema-migration"],
            "schema-check": [
                "run",
                "--rm",
                "--no-deps",
                "schema-migration",
                "doctrine:migrations:up-to-date",
                "--no-interaction",
                "--no-ansi",
            ],
            "ps": ["ps", "--services", "--filter", "status=running"],
            "down": ["down", "--remove-orphans"],
        }
        state = json.loads(self.state_file.read_text(encoding="utf-8"))
        self.assertFalse(state.get("forbidden_volume_delete", False))
        for call in state["calls"]:
            expected = expected_arguments[call["operation"]]
            if call["operation"] == "up":
                self.assertIn(call["arguments"], expected)
            else:
                self.assertEqual(expected, call["arguments"])
            expected_compose_file_count = 1
            if call["operation"] in {"run", "schema-check"} or (
                call["operation"] == "config" and call["call"] % 2 == 0
            ):
                expected_compose_file_count = 2
            self.assertEqual(expected_compose_file_count, call["compose_file_count"])

    def current_bytes(self) -> dict[str, bytes]:
        files = [
            self.install / "compose.yaml",
            self.install / "compose.migration.yaml",
            self.install / "runtime.env",
            self.install / "10-create-app-users.sh",
        ]
        files.extend(self.secrets / secret_name for secret_name in SECRET_NAMES)
        return {str(path): path.read_bytes() for path in files if path.is_file()}

    def current_state(self) -> dict[str, tuple[bytes, int]]:
        files = [
            self.install / "compose.yaml",
            self.install / "compose.migration.yaml",
            self.install / "runtime.env",
            self.install / "10-create-app-users.sh",
        ]
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
