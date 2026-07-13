from __future__ import annotations

import pathlib
import re
import unittest


ROOT = pathlib.Path(__file__).resolve().parents[2]
TARGETS = {
    ("worker", "docker/php/Dockerfile", "worker"),
    ("web", "docker/web/Dockerfile", "web"),
    ("mariadb", "docker/mariadb/Dockerfile", ""),
}
PLATFORMS = {"linux/amd64", "linux/arm64"}


def read(path: str) -> str:
    return (ROOT / path).read_text(encoding="utf-8")


def matrix_lines(path: str) -> list[str]:
    return [
        line
        for raw in read(path).splitlines()
        if (line := raw.strip()) and not line.startswith("#")
    ]


class SupplyChainGateContractTest(unittest.TestCase):
    def test_matrix_defines_exactly_six_platform_artifacts(self) -> None:
        targets = {
            tuple(line.split("|", maxsplit=2))
            for line in matrix_lines("scripts/ci/container-targets.txt")
        }
        platforms = set(matrix_lines("scripts/ci/container-platforms.txt"))

        self.assertEqual(TARGETS, targets)
        self.assertEqual(PLATFORMS, platforms)
        self.assertEqual(6, len(targets) * len(platforms))

    def test_exact_buildx_docker_archives_are_the_only_scanned_inputs(self) -> None:
        build = read("scripts/ci/build-container-images.sh")
        scan = read("scripts/ci/scan-container-images.sh")
        makefile = read("Makefile")

        for script in (build, scan):
            self.assertIn("container-targets.txt", script)
            self.assertIn("container-platforms.txt", script)
            self.assertIn("$artifact-$platform_slug.docker.tar", script)

        self.assertIn("docker buildx build", build)
        self.assertIn('--output "type=docker,dest=$archive"', build)
        self.assertIn('test "$archive_count" -eq 6', build)
        self.assertNotRegex(build, r"docker\s+build(?!x)")
        self.assertNotRegex(scan, r"docker\s+build(?:x)?")
        self.assertIn('--input "/artifacts/images/$archive_name"', scan)
        self.assertIn('test "$scanned" -eq 6', scan)
        self.assertIn('test "$sbom_count" -eq 6', scan)
        self.assertIn("./scripts/ci/build-container-images.sh", makefile)
        self.assertIn("./scripts/ci/scan-container-images.sh", makefile)
        self.assertNotIn("docker buildx build", makefile)

    def test_vulnerability_policy_is_fail_closed_for_all_high_and_critical_findings(self) -> None:
        scan = read("scripts/ci/scan-container-images.sh")
        all_supply_chain_code = "\n".join(
            (
                scan,
                read("scripts/ci/build-container-images.sh"),
                read("Makefile"),
                read(".github/workflows/ci.yml"),
            )
        )

        self.assertIn("--severity HIGH,CRITICAL", scan)
        self.assertIn("--exit-code 1", scan)
        self.assertIn("set -eu", scan)
        self.assertNotIn("--ignore-unfixed", all_supply_chain_code)
        self.assertNotIn("--ignore-status", all_supply_chain_code)

    def test_scanner_build_and_dockerfile_frontend_images_are_immutable(self) -> None:
        makefile = read("Makefile")
        workflow = read(".github/workflows/ci.yml")

        for variable in ("GITLEAKS_IMAGE", "TRIVY_IMAGE"):
            self.assertIsNotNone(
                re.search(
                    rf"^{variable} := [^\s:]+:[^\s@]+@sha256:[0-9a-f]{{64}}$",
                    makefile,
                    flags=re.MULTILINE,
                ),
                f"{variable} must be version- and digest-pinned",
            )

        self.assertRegex(workflow, r"version: v\d+\.\d+\.\d+")
        self.assertRegex(
            workflow,
            r"image=moby/buildkit:v\d+\.\d+\.\d+@sha256:[0-9a-f]{64}",
        )
        self.assertIn("buildkitd-flags: --oci-worker-net bridge", workflow)
        self.assertIn("cache-binary: false", workflow)

        for path in (
            "docker/php/Dockerfile",
            "docker/web/Dockerfile",
            "docker/mariadb/Dockerfile",
            "docker/e2e/Dockerfile",
        ):
            dockerfile = read(path)
            first_line = dockerfile.splitlines()[0]
            self.assertRegex(
                first_line,
                r"^# syntax=docker/dockerfile:\d+\.\d+@sha256:[0-9a-f]{64}$",
            )
            for image in re.findall(r"^ARG \w+_IMAGE=(.+)$", dockerfile, re.MULTILINE):
                self.assertRegex(image, r"^[^\s:]+:[^\s@]+@sha256:[0-9a-f]{64}$")

    def test_alpine_packages_are_explicitly_versioned_and_never_upgraded_implicitly(self) -> None:
        expected_packages = {
            "docker/php/Dockerfile": {
                "c-ares",
                "icu-libs",
                "icu-dev",
                "autoconf",
                "dpkg-dev",
                "dpkg",
                "file",
                "g++",
                "gcc",
                "musl-dev",
                "make",
                "pkgconf",
                "re2c",
                "linux-headers",
            },
            "docker/web/Dockerfile": {
                "c-ares",
                "icu-libs",
                "icu-dev",
                "autoconf",
                "dpkg-dev",
                "dpkg",
                "file",
                "g++",
                "gcc",
                "musl-dev",
                "make",
                "pkgconf",
                "re2c",
            },
        }

        for path, expected in expected_packages.items():
            dockerfile = read(path)
            self.assertNotIn("apk upgrade", dockerfile)
            self.assertNotIn("$PHPIZE_DEPS", dockerfile)
            pinned = set(
                re.findall(r'"([a-z0-9+.-]+)=\$\{[A-Z0-9_]+\}"', dockerfile)
            )
            self.assertEqual(expected, pinned)

    def test_production_worker_and_web_exclude_tests_dev_config_and_composer(self) -> None:
        php = read("docker/php/Dockerfile")
        web = read("docker/web/Dockerfile")
        worker = php.split("FROM php-base AS worker\n", maxsplit=1)[1]
        production_web = web.split("FROM web-runtime AS web\n", maxsplit=1)[1].split(
            "FROM web-runtime AS web-e2e", maxsplit=1
        )[0]

        for stage in (worker, production_web):
            self.assertNotIn("COPY --from=composer-bin", stage)
            self.assertIn("test ! -e /usr/bin/composer", stage)
            self.assertIn("test ! -d /app/tests", stage)
            self.assertIn("test ! -d /app/tools", stage)
            self.assertIn("test ! -e /app/phpunit.coverage-core.xml.dist", stage)
            self.assertIn("test ! -e /app/phpunit.coverage-mariadb.xml.dist", stage)
            self.assertIn("test ! -e /app/phpunit.xml.dist", stage)
            self.assertIn("test ! -e /app/config/services_test.php", stage)
            self.assertIn("test ! -e /app/config/services_e2e.php", stage)

        for dockerfile in (php, web):
            production_cleanup = dockerfile.split("RUN composer dump-autoload --no-dev --classmap-authoritative", maxsplit=1)[1]
            self.assertIn("phpunit.coverage-core.xml.dist", production_cleanup)
            self.assertIn("phpunit.coverage-mariadb.xml.dist", production_cleanup)
            self.assertIn("config/services_test.php", production_cleanup)
            self.assertIn("config/services_e2e.php", production_cleanup)

    def test_coverage_runtime_includes_external_contract_baselines(self) -> None:
        php = read("docker/php/Dockerfile")
        coverage_runtime = php.split(
            "FROM backend-coverage-base AS backend-coverage-runtime\n", maxsplit=1
        )[1].split("FROM backend-coverage-runtime AS backend-mutation-runtime", maxsplit=1)[0]

        self.assertIn(
            "COPY --from=backend-test-runtime /docs/openapi-v1.json /docs/openapi-v1.json",
            coverage_runtime,
        )
        self.assertIn(
            "COPY --from=backend-test-runtime /docs/proxmox-official-cli-baseline.json "
            "/docs/proxmox-official-cli-baseline.json",
            coverage_runtime,
        )

    def test_workflow_runs_build_before_scan_and_never_publishes_or_deploys(self) -> None:
        workflow = read(".github/workflows/ci.yml")
        relevant = "\n".join(
            (
                workflow,
                read("Makefile"),
                read("scripts/ci/build-container-images.sh"),
                read("scripts/ci/scan-container-images.sh"),
            )
        )

        self.assertLess(
            workflow.index("make container-multiarch"),
            workflow.index("make container-security"),
        )
        self.assertNotRegex(relevant, r"(?i)docker\s+(?:image\s+)?push")
        self.assertNotRegex(relevant, r"(?i)docker\s+login")
        self.assertNotIn("--push", relevant)
        self.assertNotIn("type=registry", relevant)
        self.assertNotRegex(workflow, r"(?m)^\s*run:\s*make deploy\s*$")
        self.assertIn("permissions:\n  contents: read", workflow)
        for action_ref in re.findall(r"uses:\s*[^@\s]+@([^\s]+)", workflow):
            self.assertRegex(action_ref, r"^[0-9a-f]{40}$")

    def test_gitleaks_allowlist_is_exact_and_bounded(self) -> None:
        entries = set(matrix_lines(".gitleaksignore"))
        expected = {
            "a91589c3f2d350011cc1001e37332374fb2675f2:backend/tests/Unit/Infrastructure/Logging/SensitiveDataRedactorTest.php:generic-api-key:112",
            "a91589c3f2d350011cc1001e37332374fb2675f2:backend/tests/Unit/Infrastructure/Logging/SensitiveDataRedactorTest.php:generic-api-key:120",
            *{
                f"da6bd632c90ef55c5cc66c290de989d6cf301251:deployment/ansible/inventories/production/group_vars/hoddmimir_hosts/vault.yml.example:generic-api-key:{line}"
                for line in range(4, 11)
            },
        }

        self.assertLessEqual(len(entries), 9)
        self.assertEqual(expected, entries)


if __name__ == "__main__":
    unittest.main()
