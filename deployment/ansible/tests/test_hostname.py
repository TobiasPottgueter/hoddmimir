from __future__ import annotations

import json
import os
import re
import stat
import subprocess
import tempfile
import unittest
from pathlib import Path

ANSIBLE_ROOT = Path(__file__).resolve().parents[1]
PLAYBOOK = ANSIBLE_ROOT / "tests" / "playbooks" / "configure-hostname.yml"
RETIRED_SHORT_HOSTNAME = "retired-backup-host"
RETIRED_FQDN = f"{RETIRED_SHORT_HOSTNAME}.example.invalid"
HODDMIMIR_FQDN = "hoddmimir.example.invalid"


def run_playbook(variables: dict[str, object]) -> subprocess.CompletedProcess[str]:
    with tempfile.TemporaryDirectory() as local_temp:
        environment = os.environ.copy()
        environment["ANSIBLE_LOCAL_TEMP"] = local_temp
        return subprocess.run(
            [
                "ansible-playbook",
                "--inventory",
                "localhost,",
                str(PLAYBOOK),
                "--extra-vars",
                json.dumps(variables),
            ],
            cwd=ANSIBLE_ROOT,
            env=environment,
            capture_output=True,
            text=True,
            check=False,
        )


class HostIdentityRoleTest(unittest.TestCase):
    def test_uses_safe_defaults_preserves_unrelated_hosts_and_is_idempotent(self) -> None:
        with tempfile.TemporaryDirectory() as temporary_directory:
            root = Path(temporary_directory)
            hostname_file = root / "hostname"
            hosts_file = root / "hosts"
            hostname_file.write_text(f"{RETIRED_SHORT_HOSTNAME}\n", encoding="utf-8")
            hosts_file.write_text(
                f"127.0.0.1\t{RETIRED_SHORT_HOSTNAME} localhost.localdomain localhost\n"
                "::1\t\tlocalhost localhost.localdomain\n"
                "10.0.0.10\tunrelated.example unrelated\n"
                f"192.0.2.1\t{RETIRED_FQDN} retired-alias\n",
                encoding="utf-8",
            )
            variables = {
                "alpine_base_hoddmimir_fqdn": HODDMIMIR_FQDN,
                "alpine_base_hoddmimir_hostname_file": str(hostname_file),
                "alpine_base_hoddmimir_hosts_file": str(hosts_file),
                "alpine_base_hoddmimir_manage_runtime_hostname": False,
                "alpine_base_hoddmimir_retired_hostnames": [RETIRED_SHORT_HOSTNAME, RETIRED_FQDN],
            }

            first = run_playbook(variables)
            self.assertEqual(0, first.returncode, first.stderr)
            self.assertEqual("hoddmimir\n", hostname_file.read_text(encoding="utf-8"))
            self.assertEqual(0o644, stat.S_IMODE(hostname_file.stat().st_mode))

            hosts = hosts_file.read_text(encoding="utf-8")
            self.assertIn(
                f"127.0.0.1\t{HODDMIMIR_FQDN} hoddmimir localhost.localdomain localhost\n",
                hosts,
            )
            self.assertIn("::1\t\tlocalhost localhost.localdomain\n", hosts)
            self.assertIn("10.0.0.10\tunrelated.example unrelated\n", hosts)
            self.assertIn("192.0.2.1\t retired-alias\n", hosts)
            self.assertNotIn(RETIRED_SHORT_HOSTNAME, hosts)
            self.assertEqual(0o644, stat.S_IMODE(hosts_file.stat().st_mode))

            second = run_playbook(variables)
            self.assertEqual(0, second.returncode, second.stderr)
            self.assertRegex(second.stdout, re.compile(r"changed=0\b"))

    def test_rejects_unsafe_hostname_before_changing_files(self) -> None:
        with tempfile.TemporaryDirectory() as temporary_directory:
            root = Path(temporary_directory)
            hostname_file = root / "hostname"
            hosts_file = root / "hosts"
            hostname_file.write_text("original\n", encoding="utf-8")
            hosts_file.write_text("127.0.0.1 localhost\n", encoding="utf-8")
            variables = {
                "alpine_base_hoddmimir_hostname": "hoddmimir;touch-pwned",
                "alpine_base_hoddmimir_fqdn": HODDMIMIR_FQDN,
                "alpine_base_hoddmimir_hostname_file": str(hostname_file),
                "alpine_base_hoddmimir_hosts_file": str(hosts_file),
                "alpine_base_hoddmimir_manage_runtime_hostname": False,
            }

            result = run_playbook(variables)

            self.assertNotEqual(0, result.returncode)
            self.assertEqual("original\n", hostname_file.read_text(encoding="utf-8"))
            self.assertEqual("127.0.0.1 localhost\n", hosts_file.read_text(encoding="utf-8"))


if __name__ == "__main__":
    unittest.main()
