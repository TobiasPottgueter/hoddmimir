from __future__ import annotations

import unittest
from pathlib import Path


REPOSITORY_ROOT = Path(__file__).resolve().parents[3]
ANSIBLE_ROOT = REPOSITORY_ROOT / "deployment" / "ansible"


class LabDeploymentContractTest(unittest.TestCase):
    def test_lab_profile_is_fixed_and_uses_a_distinct_acknowledgement(self) -> None:
        profile = (ANSIBLE_ROOT / "roles/hoddmimir/tasks/lab-profile.yml").read_text(encoding="utf-8")
        for value in (
            "hoddmimir-lab",
            "/opt/hoddmimir-lab",
            "/etc/hoddmimir-lab/secrets",
            "hoddmimir_lab",
            "ENABLE_LAB_BACKUPS",
        ):
            self.assertIn(value, profile)
        self.assertIn("not (hoddmimir_manage_https | bool)", profile)
        self.assertIn("hoddmimir_web_port | int != 8080", profile)

    def test_lab_down_never_deletes_volumes(self) -> None:
        down = (ANSIBLE_ROOT / "playbooks/lab-down.yml").read_text(encoding="utf-8")
        self.assertIn("tasks_from: lab-profile", down)
        self.assertIn("--remove-orphans", down)
        self.assertNotIn("--volumes", down)

    def test_lab_scale_only_targets_one_or_two_backup_workers(self) -> None:
        scale = (ANSIBLE_ROOT / "playbooks/lab-scale.yml").read_text(encoding="utf-8")
        self.assertIn("tasks_from: preflight", scale)
        self.assertIn("lookup('ansible.builtin.env', 'ENABLE_LAB_BACKUPS')", scale)
        self.assertIn("hoddmimir_lab_backup_worker_replicas | int in [1, 2]", scale)
        self.assertIn('"backup-worker={{ hoddmimir_lab_backup_worker_replicas | int }}"', scale)
        self.assertNotIn("--scale\n          - data-worker", scale)
        self.assertNotIn("--scale\n          - webapp", scale)
        self.assertIn("State.Health.Status", scale)

    def test_make_targets_use_only_the_lab_inventory_and_lab_vault(self) -> None:
        makefile = (REPOSITORY_ROOT / "Makefile").read_text(encoding="utf-8")
        for target in ("lab-inventory", "lab-secrets", "lab-deploy", "lab-verify", "lab-down", "lab-scale"):
            self.assertIn(f"{target}:", makefile)
        self.assertIn("ANSIBLE_LAB_INVENTORY", makefile)
        self.assertIn("ANSIBLE_LAB_VAULT_ARGS", makefile)
        self.assertIn("LAB_BACKUP_WORKER_REPLICAS ?= 2", makefile)

    def test_example_lab_syntax_uses_the_optional_local_lab_vault(self) -> None:
        makefile = (REPOSITORY_ROOT / "Makefile").read_text(encoding="utf-8")
        syntax_target = makefile.split("syntax:", 1)[1].split("deployment-test:", 1)[0]

        self.assertIn("ANSIBLE_LAB_LOCAL_VAULT_ARGS =", makefile)
        example_commands = [
            line
            for line in syntax_target.splitlines()
            if "$(ANSIBLE_LAB_EXAMPLE_INVENTORY)" in line
        ]
        self.assertEqual(5, len(example_commands))
        for command in example_commands:
            self.assertIn("$(ANSIBLE_LAB_LOCAL_VAULT_ARGS)", command)


if __name__ == "__main__":
    unittest.main()
