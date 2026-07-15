from __future__ import annotations

import re
import subprocess
import unittest
from pathlib import Path

REPOSITORY_ROOT = Path(__file__).resolve().parents[3]
IGNORED_PRODUCTION_MAIN = Path(
    "deployment/ansible/inventories/production/group_vars/hoddmimir_hosts/main.yml",
)
IGNORED_LAB_FILES = (
    Path("deployment/ansible/inventories/lab/hosts.yml"),
    Path("deployment/ansible/inventories/lab/group_vars/hoddmimir_hosts/main.yml"),
    Path("deployment/ansible/inventories/lab/group_vars/hoddmimir_hosts/vault.yml"),
)


class RepositoryProductionHygieneTest(unittest.TestCase):
    def test_real_production_coordinates_are_absent_from_tracked_surfaces(self) -> None:
        files = self.repository_files()
        surfaces = [
            path
            for path in files
            if path.as_posix().startswith("deployment/ansible/")
            or path.as_posix() in {
                "docs/phase-7-live-acceptance.md",
                "docs/rewrite-plan.md",
            }
        ]
        forbidden = (
            re.compile(r"\.netzkultur\.cloud\b", re.IGNORECASE),
            re.compile(r"@[A-Za-z0-9.-]*netzkultur\.[A-Za-z]{2,}\b", re.IGNORECASE),
            re.compile(r"\bautomation\.hosting\b", re.IGNORECASE),
            re.compile(r"\bhosting\.wc1\b", re.IGNORECASE),
        )

        findings: list[str] = []
        for relative in surfaces:
            text = (REPOSITORY_ROOT / relative).read_text(encoding="utf-8")
            for pattern in forbidden:
                if pattern.search(text):
                    findings.append(f"{relative}: {pattern.pattern}")
        self.assertEqual([], findings)

    def test_production_main_is_ignored_and_example_images_are_immutable(self) -> None:
        tracked = subprocess.run(
            ["git", "ls-files", "--error-unmatch", str(IGNORED_PRODUCTION_MAIN)],
            cwd=REPOSITORY_ROOT,
            capture_output=True,
            text=True,
            check=False,
        )
        self.assertNotEqual(0, tracked.returncode, "the real production main.yml must not be tracked")
        ignored = subprocess.run(
            ["git", "check-ignore", "--quiet", str(IGNORED_PRODUCTION_MAIN)],
            cwd=REPOSITORY_ROOT,
            check=False,
        )
        self.assertEqual(0, ignored.returncode)

        example = REPOSITORY_ROOT / "deployment/ansible/inventories/production/group_vars/hoddmimir_hosts/main.example.yml"
        text = example.read_text(encoding="utf-8")
        image_lines = [line for line in text.splitlines() if re.match(r"hoddmimir_.*_image:", line)]
        self.assertEqual(4, len(image_lines))
        for line in image_lines:
            self.assertRegex(line, r"@sha256:[0-9a-f]{64}$")
        self.assertIn(".example.invalid", text)
        self.assertNotRegex(text, r":(?:latest|dev|[0-9]+(?:\.[0-9]+)*)$")

    def test_real_lab_configuration_is_ignored_and_examples_are_isolated(self) -> None:
        for relative in IGNORED_LAB_FILES:
            with self.subTest(path=relative):
                tracked = subprocess.run(
                    ["git", "ls-files", "--error-unmatch", str(relative)],
                    cwd=REPOSITORY_ROOT,
                    capture_output=True,
                    check=False,
                )
                self.assertNotEqual(0, tracked.returncode)
                ignored = subprocess.run(
                    ["git", "check-ignore", "--quiet", str(relative)],
                    cwd=REPOSITORY_ROOT,
                    check=False,
                )
                self.assertEqual(0, ignored.returncode)

        example = REPOSITORY_ROOT / "deployment/ansible/inventories/lab/group_vars/hoddmimir_hosts/main.example.yml"
        text = example.read_text(encoding="utf-8")
        self.assertIn("hoddmimir_deployment_profile: lab", text)
        self.assertIn("hoddmimir_manage_https: false", text)
        self.assertIn("hoddmimir_project_name: hoddmimir-lab", text)
        self.assertIn("hoddmimir_database_name: hoddmimir_lab", text)
        self.assertIn("hoddmimir_backup_execution_required_ack: ENABLE_LAB_BACKUPS", text)
        image_lines = [line for line in text.splitlines() if re.match(r"hoddmimir_.*_image:", line)]
        self.assertEqual(4, len(image_lines))
        for line in image_lines:
            self.assertRegex(line, r"@sha256:[0-9a-f]{64}$")

    @staticmethod
    def repository_files() -> list[Path]:
        result = subprocess.run(
            ["git", "ls-files", "--cached", "--others", "--exclude-standard", "-z"],
            cwd=REPOSITORY_ROOT,
            capture_output=True,
            check=True,
        )
        return [Path(value.decode("utf-8")) for value in result.stdout.split(b"\0") if value]


if __name__ == "__main__":
    unittest.main()
