from __future__ import annotations

import pathlib
import re
import unittest


ROOT = pathlib.Path(__file__).resolve().parents[2]
WORKFLOW = ROOT / ".github" / "workflows" / "ci.yml"


class BackendCoverageWorkflowContractTest(unittest.TestCase):
    @classmethod
    def setUpClass(cls) -> None:
        cls.workflow = WORKFLOW.read_text(encoding="utf-8")

    def job(self, job_id: str) -> str:
        match = re.search(
            rf"^  {re.escape(job_id)}:\n(?P<body>.*?)(?=^  [a-z0-9-]+:\n|\Z)",
            self.workflow,
            flags=re.MULTILINE | re.DOTALL,
        )
        self.assertIsNotNone(match, f"missing workflow job {job_id}")
        return match.group(0) if match is not None else ""

    def test_validation_and_foundation_can_run_in_parallel(self) -> None:
        validation = self.job("backend-validation")
        foundation = self.job("backend-coverage-foundation")
        self.assertNotIn("needs:", validation)
        self.assertNotIn("run-backend-coverage.sh", validation)
        self.assertNotIn("needs:", foundation)
        self.assertIn("make backend-coverage-wrapper-test", foundation)
        self.assertIn("run-backend-coverage.sh foundation", foundation)

    def test_foundation_is_exact_pinned_and_uncompressed(self) -> None:
        foundation = self.job("backend-coverage-foundation")
        self.assertIn("docker/setup-buildx-action@e468171a9de216ec08956ac3ada2f0791b6bd435", foundation)
        self.assertIn("moby/buildkit:v0.31.1@sha256:", foundation)
        self.assertIn("HODDMIMIR_BACKEND_COVERAGE_CACHE_SCOPE: backend-coverage-runtime-linux-amd64", foundation)
        for artifact in (
            "coverage-image.docker.tar",
            "coverage-image.id",
            "foundation-manifest.json",
        ):
            self.assertIn(f"backend/coverage/{artifact}", foundation)
        self.assertIn("compression-level: 0", foundation)
        self.assertIn("if-no-files-found: error", foundation)

    def test_owner_jobs_are_independent_and_phase_scoped(self) -> None:
        core = self.job("backend-coverage-core")
        mariadb = self.job("backend-coverage-mariadb")
        for job in (core, mariadb):
            self.assertIn("needs: backend-coverage-foundation", job)
            self.assertIn("name: hoddmimir-backend-coverage-foundation", job)
            self.assertIn("path: backend/coverage", job)
        self.assertIn("run-backend-coverage.sh core", core)
        self.assertNotIn("run-backend-coverage.sh mariadb", core)
        self.assertIn("core.clover.xml", core)
        self.assertIn("core-manifest.json", core)
        self.assertIn("run-backend-coverage.sh mariadb", mariadb)
        self.assertNotIn("run-backend-coverage.sh core", mariadb)
        self.assertIn("integration.clover.xml", mariadb)
        self.assertIn("integration-manifest.json", mariadb)

    def test_stable_backend_gate_requires_and_composes_every_producer(self) -> None:
        backend = self.job("backend")
        self.assertIn("name: Backend", backend)
        self.assertIn("if: always()", backend)
        for producer in (
            "backend-validation",
            "backend-coverage-core",
            "backend-coverage-mariadb",
        ):
            self.assertIn(f"- {producer}", backend)
            self.assertIn(f'needs.{producer}.result }}}}" = success', backend)
        for artifact in (
            "hoddmimir-backend-coverage-foundation",
            "hoddmimir-backend-coverage-core",
            "hoddmimir-backend-coverage-mariadb",
        ):
            self.assertIn(f"name: {artifact}", backend)
        self.assertIn("run-backend-coverage.sh compose", backend)
        self.assertIn("path: backend/coverage/clover.xml", backend)
        self.assertIn("if-no-files-found: error", backend)


if __name__ == "__main__":
    unittest.main()
