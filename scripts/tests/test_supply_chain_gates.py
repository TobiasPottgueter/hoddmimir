from __future__ import annotations

import json
import os
import pathlib
import re
import shutil
import subprocess
import tempfile
import unittest


ROOT = pathlib.Path(__file__).resolve().parents[2]
TARGETS = {
    ("worker", "docker/php/Dockerfile", "worker"),
    ("web", "docker/web/Dockerfile", "web"),
    ("mariadb", "docker/mariadb/Dockerfile", ""),
}
PLATFORMS = {"linux/amd64"}


def read(path: str) -> str:
    return (ROOT / path).read_text(encoding="utf-8")


def matrix_lines(path: str) -> list[str]:
    return [
        line
        for raw in read(path).splitlines()
        if (line := raw.strip()) and not line.startswith("#")
    ]


class SupplyChainGateContractTest(unittest.TestCase):
    def test_phase_7_fault_harness_is_a_release_blocking_ci_gate(self) -> None:
        workflow = read(".github/workflows/ci.yml")
        makefile = read("Makefile")
        job = workflow.split("\n  fault-harness:\n", maxsplit=1)[1].split(
            "\n  ansible:\n",
            maxsplit=1,
        )[0]
        publication = workflow.split("\n  publish-image:\n", maxsplit=1)[1].split(
            "\n  publish-images:\n",
            maxsplit=1,
        )[0]

        self.assertIn('python-version: "3.14"', job)
        self.assertIn("run: make fault-harness-test", job)
        self.assertIn("fault-harness-test:", makefile)
        self.assertIn("python3 -m py_compile lab/fault-proxy/", makefile)
        self.assertIn("python3 -m unittest discover -s lab/fault-proxy/tests", makefile)
        self.assertIn("- fault-harness", publication)

    def test_matrix_defines_exactly_three_amd64_artifacts(self) -> None:
        targets = {
            tuple(line.split("|", maxsplit=2))
            for line in matrix_lines("scripts/ci/container-targets.txt")
        }
        platforms = set(matrix_lines("scripts/ci/container-platforms.txt"))

        self.assertEqual(TARGETS, targets)
        self.assertEqual(PLATFORMS, platforms)
        self.assertEqual(3, len(targets) * len(platforms))

    def test_exact_buildx_docker_archives_are_the_only_scanned_inputs(self) -> None:
        build = read("scripts/ci/build-container-images.sh")
        scan = read("scripts/ci/scan-container-images.sh")
        makefile = read("Makefile")

        for script in (build, scan):
            self.assertIn("container-targets.txt", script)
            self.assertIn("container-platforms.txt", script)
            self.assertIn("$artifact-$platform_slug.docker.tar", script)
            self.assertIn("release_platform=linux/amd64", script)
            self.assertIn(
                'test "$selected_platform" != "$release_platform"', script
            )

        self.assertIn("docker buildx build", build)
        self.assertIn('--output "type=docker,dest=$archive"', build)
        self.assertIn('test "$archive_count" -eq "$expected_count"', build)
        self.assertNotRegex(build, r"docker\s+build(?!x)")
        self.assertNotRegex(scan, r"docker\s+build(?:x)?")
        self.assertIn('--input "/artifacts/images/$archive_name"', scan)
        self.assertIn('test "$scanned" -eq "$expected_count"', scan)
        self.assertIn('test "$sbom_count" -eq "$expected_count"', scan)
        for script in (build, scan):
            self.assertIn("CONTAINER_ARTIFACT", script)
            self.assertIn("CONTAINER_PLATFORM", script)
        self.assertIn("./scripts/ci/build-container-images.sh", makefile)
        self.assertIn("./scripts/ci/scan-container-images.sh", makefile)
        self.assertNotIn("docker buildx build", makefile)

    def test_production_dockerfiles_remain_architecture_neutral(self) -> None:
        for path in (
            "docker/php/Dockerfile",
            "docker/web/Dockerfile",
            "docker/mariadb/Dockerfile",
        ):
            dockerfile = read(path)
            self.assertNotRegex(dockerfile, r"(?i)(?:amd64|arm64|aarch64|x86_64)")
            self.assertNotRegex(dockerfile, r"(?i)^FROM\s+--platform=", msg=path)

    def test_scripts_reject_non_release_platform_before_external_io(self) -> None:
        environment = os.environ.copy()
        environment.update(
            {
                "CONTAINER_ARTIFACT": "worker",
                "CONTAINER_PLATFORM": "linux/arm64",
                "TRIVY_IMAGE": "example.invalid/trivy:1@sha256:"
                + ("a" * 64),
            }
        )

        for path in (
            "scripts/ci/build-container-images.sh",
            "scripts/ci/scan-container-images.sh",
        ):
            result = subprocess.run(
                [str(ROOT / path)],
                cwd=ROOT,
                env=environment,
                capture_output=True,
                text=True,
                check=False,
            )
            self.assertEqual(2, result.returncode, msg=path)
            self.assertIn(
                "CONTAINER_PLATFORM must be linux/amd64 for production validation.",
                result.stderr,
            )

    def test_workflow_parallelizes_exactly_three_scanned_amd64_images(self) -> None:
        workflow = read(".github/workflows/ci.yml")
        image_job = workflow.split("\n  container-images:\n", maxsplit=1)[1].split(
            "\n  containers:\n", maxsplit=1
        )[0]
        final_job = workflow.split("\n  containers:\n", maxsplit=1)[1].split(
            "\n  publish-images:\n", maxsplit=1
        )[0]

        self.assertEqual(3, image_job.count("          - artifact:"))
        self.assertIn("fail-fast: false", image_job)
        self.assertIn("make container-images", image_job)
        self.assertIn("make container-security", image_job)
        self.assertIn("BUILDX_CACHE_SCOPE_PREFIX: container", image_job)
        self.assertEqual(3, image_job.count("platform: linux/amd64"))
        self.assertNotIn("linux/arm64", image_job)
        self.assertNotIn("docker/setup-qemu-action", image_job)
        self.assertIn("needs.container-images.result", final_job)
        self.assertIn('"$actual" = "$expected"', final_job)
        self.assertIn('" -eq 3', final_job)

    def test_scheduled_mutation_uses_one_foundation_and_disjoint_shards(self) -> None:
        workflow = read(".github/workflows/ci.yml")
        foundation = workflow.split("\n  mutation-foundation:\n", maxsplit=1)[1].split(
            "\n  mutation-shard:\n", maxsplit=1
        )[0]
        shards = workflow.split("\n  mutation-shard:\n", maxsplit=1)[1].split(
            "\n  mutation-full:\n", maxsplit=1
        )[0]
        aggregate = workflow.split("\n  mutation-full:\n", maxsplit=1)[1].split(
            "\n  frontend:\n", maxsplit=1
        )[0]

        self.assertEqual(1, foundation.count("run-mutation.sh coverage"))
        self.assertEqual(12, len(re.findall(r"^          - (?:critical|global-rest)-\d$", shards, re.MULTILINE)))
        self.assertIn("REUSE_MUTATION_COVERAGE: \"1\"", shards)
        self.assertIn("run-mutation.sh shard", shards)
        self.assertIn("mkdir -p var/mutation && tar -xzf foundation.tgz -C var/mutation", shards)
        self.assertIn("mutation-shards.php aggregate", aggregate)
        self.assertIn("needs.mutation-shard.result", aggregate)
        self.assertIn("mkdir -p var/mutation && tar -xzf foundation.tgz -C var/mutation plan", aggregate)

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
                "curl",
                "icu-libs",
                "libcurl",
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
                "su-exec",
            },
            "docker/web/Dockerfile": {
                "c-ares",
                "curl",
                "icu-libs",
                "libcurl",
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
                "su-exec",
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

    def test_secret_staging_uses_an_immutable_alpine_helper(self) -> None:
        staging = read("scripts/tests/test-app-secret-staging.sh")

        self.assertRegex(
            staging,
            r"alpine:3\.23\.\d+@sha256:[0-9a-f]{64}",
        )
        self.assertNotRegex(staging, r"docker run[^\n]+\salpine:[^@\s]+(?:\s|$)")

    def test_production_runtime_curl_packages_are_pinned_to_security_fixed_releases(self) -> None:
        php = read("docker/php/Dockerfile")
        web = read("docker/web/Dockerfile")

        self.assertIn("ARG CURL_VERSION=8.20.0-r0", php)
        self.assertIn("ARG LIBCURL_VERSION=8.20.0-r0", php)
        self.assertIn("ARG CURL_VERSION=8.21.0-r0", web)
        self.assertIn("ARG LIBCURL_VERSION=8.21.0-r0", web)

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
        ordinary_workflow = workflow.split("\n  publish-image:\n", maxsplit=1)[0]
        relevant = "\n".join(
            (
                ordinary_workflow,
                read("Makefile"),
                read("scripts/ci/build-container-images.sh"),
                read("scripts/ci/scan-container-images.sh"),
            )
        )

        self.assertLess(
            workflow.index("make container-images"),
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
            *{
                f"3602c69ae01f16d621b405f2bb57a37d854895a4:deployment/ansible/inventories/lab/group_vars/hoddmimir_hosts/vault.yml.example:generic-api-key:{line}"
                for line in (3, 11, 12, 13, 14, 15)
            },
        }

        self.assertLessEqual(len(entries), 15)
        self.assertEqual(expected, entries)


class ImagePublicationWorkflowContractTest(unittest.TestCase):
    def setUp(self) -> None:
        self.workflow = read(".github/workflows/ci.yml")
        self.build_job = self.workflow.split("\n  publish-image:\n", maxsplit=1)[1].split(
            "\n  publish-images:\n", maxsplit=1
        )[0]
        self.final_job = self.workflow.split("\n  publish-images:\n", maxsplit=1)[1]

    def test_publication_is_manual_explicit_and_has_minimal_write_permission(self) -> None:
        self.assertIn("workflow_dispatch:", self.workflow)
        self.assertIn("type: boolean", self.workflow)
        self.assertIn('image_tag:\n        description:', self.workflow)
        self.assertIn('required: false\n        default: ""\n        type: string', self.workflow)
        self.assertIn(
            "if: github.event_name == 'workflow_dispatch' && inputs.publish_images == true",
            self.build_job,
        )
        self.assertIn("contents: read", self.build_job)
        self.assertIn("packages: write", self.build_job)
        self.assertNotIn("id-token: write", self.build_job)
        self.assertIn("contents: read", self.final_job)
        self.assertNotIn("packages: write", self.final_job)
        self.assertIn(
            'test "$CONFIRMATION" = "PUBLISH_AMD64_IMAGES"',
            self.build_job,
        )
        self.assertIn("persist-credentials: false", self.build_job)
        self.assertIn(
            "cancel-in-progress: ${{ !(github.event_name == 'workflow_dispatch' && inputs.publish_images) }}",
            self.workflow,
        )
        for required_gate in (
            "- ansible",
            "- containers",
            "- fault-harness",
            "- mutation-full",
            "- proxmox-schema-policy",
        ):
            self.assertIn(required_gate, self.build_job)
        self.assertIn("needs: publish-image", self.final_job)
        self.assertIn("needs.publish-image.result", self.final_job)

    def test_actions_and_build_infrastructure_are_immutable(self) -> None:
        publication = self.build_job + self.final_job
        for action_ref in re.findall(r"uses:\s*[^@\s]+@([^\s]+)", publication):
            self.assertRegex(action_ref, r"^[0-9a-f]{40}$")
        self.assertRegex(publication, r"version: v\d+\.\d+\.\d+")
        self.assertRegex(
            publication,
            r"image=moby/buildkit:v\d+\.\d+\.\d+@sha256:[0-9a-f]{64}",
        )
        self.assertNotIn("docker/setup-qemu-action", self.build_job)
        self.assertNotIn("tonistiigi/binfmt", self.build_job)

    def test_exact_worker_and_web_amd64_images_are_published(self) -> None:
        self.assertEqual(2, self.build_job.count("          - target:"))
        self.assertIn("target: worker", self.build_job)
        self.assertIn("dockerfile: docker/php/Dockerfile", self.build_job)
        self.assertIn("target: web", self.build_job)
        self.assertIn("dockerfile: docker/web/Dockerfile", self.build_job)
        self.assertEqual(1, self.build_job.count("docker buildx build"))
        self.assertEqual(1, self.build_job.count("--platform linux/amd64"))
        self.assertNotIn("linux/arm64", self.build_job)
        self.assertEqual(1, self.build_job.count("--push"))
        self.assertNotIn("docker/mariadb/Dockerfile", self.build_job)
        self.assertIn("--provenance=mode=max", self.build_job)
        self.assertIn("--sbom=true", self.build_job)
        for scope in (
            "container-$TARGET-linux-amd64",
            "publish-$TARGET-linux-amd64",
        ):
            self.assertIn(scope, self.build_job)

    def test_image_digests_are_validated_and_exported_for_deployment(self) -> None:
        self.assertEqual(
            1,
            self.build_job.count(
                'select(test("^sha256:[0-9a-f]{64}$"))'
            ),
        )
        self.assertIn('([.[].target] | sort) == ["web", "worker"]', self.final_job)
        self.assertIn("EXPECTED_REPOSITORY: ${{ github.repository }}", self.final_job)
        self.assertIn("artifacts/published-images.json", self.final_job)
        self.assertIn(".images.worker.reference", self.final_job)
        self.assertIn(".images.web.reference", self.final_job)
        self.assertIn("artifact-digest", self.final_job)
        self.assertIn("Use only the `@sha256:` references for deployment.", self.final_job)

    def test_combined_publication_manifest_is_valid_jq_and_has_expected_shape(self) -> None:
        jq = shutil.which("jq")
        self.assertIsNotNone(jq, "jq is required to validate the publication manifest")

        start = "          jq -s '\n"
        end = "\n          ' artifacts/publication/*.json > artifacts/published-images.json"
        self.assertIn(start, self.final_job)
        program, separator, _ = self.final_job.split(start, maxsplit=1)[1].partition(end)
        self.assertEqual(end, separator)

        digest_worker = "sha256:" + ("1" * 64)
        digest_web = "sha256:" + ("2" * 64)
        inputs = [
            {
                "target": "worker",
                "imageTag": "phase7-test",
                "sourceRef": "refs/heads/codex/test",
                "sourceSha": "a" * 40,
                "tag": "ghcr.io/example/hoddmimir/worker:phase7-test",
                "digest": digest_worker,
                "reference": f"ghcr.io/example/hoddmimir/worker@{digest_worker}",
            },
            {
                "target": "web",
                "imageTag": "phase7-test",
                "sourceRef": "refs/heads/codex/test",
                "sourceSha": "a" * 40,
                "tag": "ghcr.io/example/hoddmimir/web:phase7-test",
                "digest": digest_web,
                "reference": f"ghcr.io/example/hoddmimir/web@{digest_web}",
            },
        ]

        with tempfile.TemporaryDirectory() as temporary_directory:
            paths = []
            for publication in reversed(inputs):
                path = pathlib.Path(temporary_directory) / f"{publication['target']}.json"
                path.write_text(json.dumps(publication), encoding="utf-8")
                paths.append(str(path))

            result = subprocess.run(
                [jq, "-s", program, *paths],
                cwd=ROOT,
                capture_output=True,
                text=True,
                check=False,
            )

        self.assertEqual(0, result.returncode, msg=result.stderr)
        self.assertEqual(
            {
                "imageTag": "phase7-test",
                "sourceRef": "refs/heads/codex/test",
                "sourceSha": "a" * 40,
                "images": {
                    publication["target"]: {
                        "tag": publication["tag"],
                        "digest": publication["digest"],
                        "reference": publication["reference"],
                    }
                    for publication in inputs
                },
            },
            json.loads(result.stdout),
        )

    def test_anonymous_digest_pull_is_proved_before_references_are_exported(self) -> None:
        proof = self.final_job.index("docker buildx imagetools inspect")
        export = self.final_job.index("artifacts/published-images.json")
        self.assertLess(proof, export)
        self.assertEqual(1, self.final_job.count("docker buildx imagetools inspect"))
        self.assertIn('DOCKER_CONFIG="$anonymous_config"', self.final_job)
        self.assertNotIn("docker/login-action", self.final_job)
        self.assertIn(
            "org.opencontainers.image.source=https://github.com/$GITHUB_REPOSITORY",
            self.build_job,
        )

    def test_untrusted_dispatch_inputs_are_not_interpolated_into_shell_programs(self) -> None:
        run_blocks = re.findall(
            r"(?ms)^\s+run: \|\n(.*?)(?=^\s{6}- name:|\Z)",
            self.build_job + self.final_job,
        )
        self.assertGreater(len(run_blocks), 0)
        for run_block in run_blocks:
            self.assertNotIn("${{ inputs.", run_block)
        self.assertIn("REQUESTED_IMAGE_TAG: ${{ inputs.image_tag }}", self.build_job)
        self.assertIn("printf 'source_ref=%s\\n' \"$GITHUB_REF\"", self.build_job)


if __name__ == "__main__":
    unittest.main()
