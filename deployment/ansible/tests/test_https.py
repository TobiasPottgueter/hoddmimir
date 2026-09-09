from __future__ import annotations

import argparse
import io
import importlib.util
import json
import os
import shutil
import stat
import subprocess
import sys
import tempfile
import unittest
from pathlib import Path
from unittest import mock

ANSIBLE_ROOT = Path(__file__).resolve().parents[1]
TRANSACTION_SCRIPT = ANSIBLE_ROOT / "roles" / "hoddmimir" / "files" / "https_transaction.py"

SPEC = importlib.util.spec_from_file_location("https_transaction_under_test", TRANSACTION_SCRIPT)
if SPEC is None or SPEC.loader is None:
    raise RuntimeError("Cannot load HTTPS transaction module for tests.")
HTTPS = importlib.util.module_from_spec(SPEC)
sys.modules[SPEC.name] = HTTPS
SPEC.loader.exec_module(HTTPS)


class HttpsTransactionTest(unittest.TestCase):
    TOKEN = "TOKEN_SENTINEL_" + "x" * 48
    DOMAIN = "hoddmimir.example.test"
    EMAIL = "acme@example.test"

    def setUp(self) -> None:
        self.temporary = tempfile.TemporaryDirectory()
        self.root = Path(self.temporary.name)
        self.uid = os.getuid()
        self.gid = os.getgid()
        self.openssl = shutil.which("openssl")
        if self.openssl is None:
            self.skipTest("openssl is required")

        self.bin = self.root / "bin"
        self.bin.mkdir()
        self.lego = self.bin / "lego"
        self.caddy = self.bin / "caddy"
        self.rc_service = self.bin / "rc-service"
        self._write_executable(self.lego, self._fake_lego())
        self._write_executable(self.caddy, self._fake_caddy())
        self._write_executable(self.rc_service, self._fake_rc_service())

        self.token = self.root / "secrets" / "hetzner_dns_api_token"
        self.token.parent.mkdir()
        self.token.write_text(self.TOKEN + "\n", encoding="ascii")
        self.token.chmod(0o440)
        self.candidate = self.root / "candidate" / "Caddyfile.candidate"
        self.candidate.parent.mkdir()
        self.candidate.write_text("https://hoddmimir.example.test { respond 200 }\n", encoding="utf-8")
        self.candidate.chmod(0o640)
        self.caddyfile = self.root / "caddy" / "Caddyfile"
        self.certificate = self.root / "caddy" / "certificates" / f"{self.DOMAIN}.crt"
        self.private_key = self.root / "caddy" / "certificates" / f"{self.DOMAIN}.key"
        self.state = self.root / "acme-state" / "lego"
        self.lock = self.root / "https.lock"

    def tearDown(self) -> None:
        self.temporary.cleanup()

    def test_initial_issue_is_private_idempotent_and_never_exposes_token_value(self) -> None:
        result = self.execute()

        self.assertTrue(result.changed)
        self.assertTrue(result.certificate_renewed)
        self.assertEqual(0o644, stat.S_IMODE(self.certificate.stat().st_mode))
        self.assertEqual(0o640, stat.S_IMODE(self.private_key.stat().st_mode))
        self.assertEqual(0o640, stat.S_IMODE(self.caddyfile.stat().st_mode))
        self.assertEqual(0o700, stat.S_IMODE(self.state.stat().st_mode))
        self.assertEqual(self.candidate.read_bytes(), self.caddyfile.read_bytes())
        invocation = (self.root / "lego-invocation.json").read_text(encoding="utf-8")
        self.assertNotIn(self.TOKEN, invocation)
        logged = json.loads(invocation)
        self.assertEqual(str(self.token), logged["tokenFile"])
        self.assertNotIn(self.TOKEN, logged["argv"])

        invocation_before = invocation
        caddy_before = (self.root / "caddy-invocations").read_text(encoding="utf-8")
        second = self.execute()
        self.assertFalse(second.changed)
        self.assertFalse(second.certificate_renewed)
        self.assertEqual(
            invocation_before,
            (self.root / "lego-invocation.json").read_text(encoding="utf-8"),
        )
        self.assertEqual(caddy_before, (self.root / "caddy-invocations").read_text(encoding="utf-8"))

    def test_identical_files_recover_stopped_or_unhealthy_caddy_without_renewal(self) -> None:
        self.execute()
        invocation_before = (self.root / "lego-invocation.json").read_bytes()
        (self.root / "service-running").unlink()

        restarted = self.execute()
        self.assertTrue(restarted.changed)
        self.assertFalse(restarted.certificate_renewed)
        self.assertTrue((self.root / "service-running").exists())
        self.assertEqual(invocation_before, (self.root / "lego-invocation.json").read_bytes())

        health_calls = 0

        def recover_health(_url: str) -> None:
            nonlocal health_calls
            health_calls += 1
            if health_calls == 1:
                raise HTTPS.HttpsTransactionError("transient health failure")

        recovered = HTTPS.execute(
            self.arguments(),
            expected_uid=self.uid,
            caddy_gid=self.gid,
            acme_uid=self.uid,
            acme_gid=self.gid,
            health_checker=recover_health,
        )
        self.assertTrue(recovered.changed)
        self.assertFalse(recovered.certificate_renewed)
        self.assertEqual(2, health_calls)
        self.assertIn("reload", (self.root / "caddy-invocations").read_text(encoding="utf-8"))

        (self.root / "service-running").unlink()

        def fail_health(_url: str) -> None:
            raise HTTPS.HttpsTransactionError("persistent health failure")

        with self.assertRaises(HTTPS.HttpsTransactionError):
            HTTPS.execute(
                self.arguments(),
                expected_uid=self.uid,
                caddy_gid=self.gid,
                acme_uid=self.uid,
                acme_gid=self.gid,
                health_checker=fail_health,
            )
        self.assertFalse((self.root / "service-running").exists())
        self.assertEqual(invocation_before, (self.root / "lego-invocation.json").read_bytes())

    def test_failed_issuance_leaves_no_active_https_files_or_state_and_does_not_leak_token(self) -> None:
        (self.root / "lego-fail").touch()

        with self.assertRaises(HTTPS.HttpsTransactionError) as raised:
            self.execute()

        self.assertNotIn(self.TOKEN, str(raised.exception))
        self.assertFalse(self.caddyfile.exists())
        self.assertFalse(self.certificate.exists())
        self.assertFalse(self.private_key.exists())
        self.assertFalse(self.state.exists())
        self.assertFalse((self.root / "service-running").exists())

    def test_reload_failure_restores_configuration_certificate_key_and_acme_state(self) -> None:
        self.execute()
        self._generate_certificate(self.certificate, self.private_key, days=1)
        old_caddy = self.caddyfile.read_bytes()
        old_certificate = self.certificate.read_bytes()
        old_key = self.private_key.read_bytes()
        old_state_files = self._tree(self.state)
        self.candidate.write_text("https://hoddmimir.example.test { respond 503 }\n", encoding="utf-8")
        self.candidate.chmod(0o640)
        (self.root / "caddy-fail-reload-once").touch()

        with self.assertRaises(HTTPS.HttpsTransactionError):
            self.execute()

        self.assertEqual(old_caddy, self.caddyfile.read_bytes())
        self.assertEqual(old_certificate, self.certificate.read_bytes())
        self.assertEqual(old_key, self.private_key.read_bytes())
        self.assertEqual(old_state_files, self._tree(self.state))
        self.assertTrue((self.root / "service-running").exists())

    def test_due_certificate_is_renewed_and_failed_renewal_preserves_every_installed_byte(self) -> None:
        self.execute()
        self._generate_certificate(self.certificate, self.private_key, days=1)
        due_certificate = self.certificate.read_bytes()

        renewed = self.execute()
        self.assertTrue(renewed.changed)
        self.assertTrue(renewed.certificate_renewed)
        self.assertNotEqual(due_certificate, self.certificate.read_bytes())

        self._generate_certificate(self.certificate, self.private_key, days=1)
        due_certificate = self.certificate.read_bytes()
        due_key = self.private_key.read_bytes()
        previous_state = self._tree(self.state)
        (self.root / "lego-fail").touch()
        with self.assertRaises(HTTPS.HttpsTransactionError):
            self.execute()
        self.assertEqual(due_certificate, self.certificate.read_bytes())
        self.assertEqual(due_key, self.private_key.read_bytes())
        self.assertEqual(previous_state, self._tree(self.state))

    def test_state_swap_failure_after_old_state_move_restores_old_state_and_files(self) -> None:
        self.execute()
        self._generate_certificate(self.certificate, self.private_key, days=1)
        old_certificate = self.certificate.read_bytes()
        old_key = self.private_key.read_bytes()
        old_state = self._tree(self.state)
        real_replace = os.replace

        def fail_candidate_state_replace(source, destination):
            if Path(source).name == "candidate-lego" and Path(destination) == self.state:
                raise OSError("state swap sentinel")
            return real_replace(source, destination)

        with mock.patch.object(HTTPS.os, "replace", side_effect=fail_candidate_state_replace):
            with self.assertRaises(HTTPS.HttpsTransactionError):
                self.execute()

        self.assertEqual(old_certificate, self.certificate.read_bytes())
        self.assertEqual(old_key, self.private_key.read_bytes())
        self.assertEqual(old_state, self._tree(self.state))

    def test_public_health_failure_rolls_back_and_stops_failed_first_start(self) -> None:
        def fail_health(_url: str) -> None:
            raise HTTPS.HttpsTransactionError("health sentinel")

        with self.assertRaises(HTTPS.HttpsTransactionError):
            HTTPS.execute(
                self.arguments(),
                expected_uid=self.uid,
                caddy_gid=self.gid,
                acme_uid=self.uid,
                acme_gid=self.gid,
                health_checker=fail_health,
            )
        self.assertFalse(self.caddyfile.exists())
        self.assertFalse(self.certificate.exists())
        self.assertFalse(self.private_key.exists())
        self.assertFalse(self.state.exists())
        self.assertFalse((self.root / "service-running").exists())

    def test_public_health_failure_restores_and_reloads_running_configuration(self) -> None:
        self.execute()
        old_caddy = self.caddyfile.read_bytes()
        self.candidate.write_text("https://hoddmimir.example.test { respond 202 }\n", encoding="utf-8")
        self.candidate.chmod(0o640)

        def fail_health(_url: str) -> None:
            raise HTTPS.HttpsTransactionError("health sentinel")

        with self.assertRaises(HTTPS.HttpsTransactionError):
            HTTPS.execute(
                self.arguments(),
                expected_uid=self.uid,
                caddy_gid=self.gid,
                acme_uid=self.uid,
                acme_gid=self.gid,
                health_checker=fail_health,
            )
        self.assertEqual(old_caddy, self.caddyfile.read_bytes())
        self.assertTrue((self.root / "service-running").exists())

    def test_public_health_waits_for_caddy_startup_without_relaxing_tls(self) -> None:
        health_url = f"https://{self.DOMAIN}/api/health"
        response = mock.MagicMock()
        response.__enter__.return_value = response
        response.status = 200
        response.geturl.return_value = health_url
        response.read.return_value = b'{"status":"ok"}'
        opener = mock.Mock(
            side_effect=[
                HTTPS.urllib.error.URLError(ConnectionRefusedError("startup race sentinel")),
                response,
            ],
        )
        now = [0.0]

        HTTPS._check_https_health(
            health_url,
            opener=opener,
            clock=lambda: now[0],
            sleeper=lambda delay: now.__setitem__(0, now[0] + delay),
        )

        self.assertEqual(2, opener.call_count)
        for call in opener.call_args_list:
            self.assertIsInstance(call.kwargs["context"], HTTPS.ssl.SSLContext)
            self.assertEqual(HTTPS.ssl.CERT_REQUIRED, call.kwargs["context"].verify_mode)
            self.assertTrue(call.kwargs["context"].check_hostname)

    def test_public_health_certificate_failure_is_immediate_and_sanitized(self) -> None:
        health_url = f"https://{self.DOMAIN}/api/health"
        opener = mock.Mock(
            side_effect=HTTPS.urllib.error.URLError(
                HTTPS.ssl.SSLCertVerificationError("SECRET-CERTIFICATE-SENTINEL"),
            ),
        )
        sleeper = mock.Mock()

        with self.assertRaises(HTTPS.HttpsTransactionError) as raised:
            HTTPS._check_https_health(
                health_url,
                opener=opener,
                clock=lambda: 0.0,
                sleeper=sleeper,
            )

        self.assertEqual("The public HTTPS certificate verification failed.", str(raised.exception))
        self.assertNotIn("SECRET-CERTIFICATE-SENTINEL", str(raised.exception))
        self.assertEqual(1, opener.call_count)
        sleeper.assert_not_called()

    def test_public_health_startup_timeout_is_bounded_and_sanitized(self) -> None:
        health_url = f"https://{self.DOMAIN}/api/health"
        opener = mock.Mock(
            side_effect=HTTPS.urllib.error.URLError(ConnectionRefusedError("SECRET-CONNECTION-SENTINEL")),
        )
        now = [0.0]

        with self.assertRaises(HTTPS.HttpsTransactionError) as raised:
            HTTPS._check_https_health(
                health_url,
                opener=opener,
                clock=lambda: now[0],
                sleeper=lambda delay: now.__setitem__(0, now[0] + delay),
                startup_timeout_seconds=0.5,
                retry_interval_seconds=0.25,
            )

        self.assertEqual(
            "The public HTTPS endpoint did not become reachable within the startup window.",
            str(raised.exception),
        )
        self.assertNotIn("SECRET-CONNECTION-SENTINEL", str(raised.exception))
        self.assertEqual(2, opener.call_count)

    def test_public_health_http_error_and_redirect_fail_without_retry(self) -> None:
        health_url = f"https://{self.DOMAIN}/api/health"
        sleeper = mock.Mock()
        http_error = HTTPS.urllib.error.HTTPError(health_url, 503, "unavailable", {}, io.BytesIO())

        with self.assertRaises(HTTPS.HttpsTransactionError) as raised:
            HTTPS._check_https_health(
                health_url,
                opener=mock.Mock(side_effect=http_error),
                clock=lambda: 0.0,
                sleeper=sleeper,
            )
        http_error.close()
        self.assertEqual(
            "The public HTTPS health endpoint returned an unexpected status.",
            str(raised.exception),
        )
        sleeper.assert_not_called()

        response = mock.MagicMock()
        response.__enter__.return_value = response
        response.status = 200
        response.geturl.return_value = f"https://{self.DOMAIN}/login"
        response.read.return_value = b'{"status":"ok"}'
        with self.assertRaises(HTTPS.HttpsTransactionError) as raised:
            HTTPS._check_https_health(
                health_url,
                opener=mock.Mock(return_value=response),
                clock=lambda: 0.0,
                sleeper=sleeper,
            )
        self.assertEqual(
            "The public HTTPS health endpoint redirected unexpectedly.",
            str(raised.exception),
        )
        sleeper.assert_not_called()

    def test_token_and_candidate_permissions_fail_closed_before_lego(self) -> None:
        for target, unsafe_mode in ((self.token, 0o600), (self.candidate, 0o644)):
            with self.subTest(target=target.name):
                target.chmod(unsafe_mode)
                with self.assertRaises(HTTPS.HttpsTransactionError):
                    self.execute()
                self.assertFalse((self.root / "lego-invocation.json").exists())
                target.chmod(0o440 if target == self.token else 0o640)

        original = self.token
        replacement = self.root / "token-value"
        replacement.write_text(self.TOKEN + "\n", encoding="ascii")
        replacement.chmod(0o440)
        original.unlink()
        original.symlink_to(replacement)
        with self.assertRaises(HTTPS.HttpsTransactionError):
            self.execute()
        self.assertFalse((self.root / "lego-invocation.json").exists())

    def test_candidate_must_remain_separate_from_installed_caddyfile(self) -> None:
        arguments = self.arguments()
        arguments.candidate_caddyfile = arguments.caddyfile
        with self.assertRaises(HTTPS.HttpsTransactionError):
            HTTPS.execute(
                arguments,
                expected_uid=self.uid,
                caddy_gid=self.gid,
                acme_uid=self.uid,
                acme_gid=self.gid,
            )

    def execute(self):
        return HTTPS.execute(
            self.arguments(),
            expected_uid=self.uid,
            caddy_gid=self.gid,
            acme_uid=self.uid,
            acme_gid=self.gid,
            health_checker=lambda _url: None,
        )

    def arguments(self) -> argparse.Namespace:
        return argparse.Namespace(
            domain=self.DOMAIN,
            email=self.EMAIL,
            health_url=f"https://{self.DOMAIN}/api/health",
            token_file=str(self.token),
            candidate_caddyfile=str(self.candidate),
            caddyfile=str(self.caddyfile),
            certificate_file=str(self.certificate),
            certificate_key_file=str(self.private_key),
            lego_state_directory=str(self.state),
            renew_before_days=30,
            acme_user="hoddmimir-acme",
            acme_group="hoddmimir-acme",
            caddy_group="caddy",
            caddy_executable=str(self.caddy),
            lego_executable=str(self.lego),
            openssl_executable=str(self.openssl),
            rc_service_executable=str(self.rc_service),
            caddy_service="caddy",
            lock_file=str(self.lock),
        )

    @staticmethod
    def _write_executable(path: Path, content: str) -> None:
        path.write_text(content, encoding="utf-8")
        path.chmod(0o755)

    def _fake_lego(self) -> str:
        return f"""#!/usr/bin/env python3
import json
import os
import pathlib
import subprocess
import sys

arguments = sys.argv[1:]
state = pathlib.Path(arguments[arguments.index('--path') + 1])
domain = arguments[arguments.index('--domains') + 1]
root = pathlib.Path({str(self.root)!r})
log = root / 'lego-invocation.json'
log.write_text(json.dumps({{'argv': arguments, 'tokenFile': os.environ.get('HETZNER_API_TOKEN_FILE')}}), encoding='utf-8')
if (root / 'lego-fail').exists():
    raise SystemExit(9)
certificates = state / 'certificates'
certificates.mkdir(parents=True, exist_ok=True)
subprocess.run([
    {self.openssl!r}, 'req', '-x509', '-newkey', 'rsa:2048', '-nodes',
    '-keyout', str(certificates / (domain + '.key')),
    '-out', str(certificates / (domain + '.crt')),
    '-days', '90', '-subj', '/CN=' + domain,
    '-addext', 'subjectAltName=DNS:' + domain,
], check=True, stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
"""

    def _fake_caddy(self) -> str:
        return f"""#!/usr/bin/env python3
import pathlib
import sys

root = pathlib.Path({str(self.root)!r})
(root / 'caddy-invocations').open('a', encoding='utf-8').write(' '.join(sys.argv[1:]) + '\\n')
if len(sys.argv) > 1 and sys.argv[1] == 'reload' and (root / 'caddy-fail-reload-once').exists():
    (root / 'caddy-fail-reload-once').unlink()
    raise SystemExit(8)
raise SystemExit(0)
"""

    def _fake_rc_service(self) -> str:
        return f"""#!/usr/bin/env python3
import pathlib
import sys

marker = pathlib.Path({str(self.root / 'service-running')!r})
operation = sys.argv[-1]
(pathlib.Path({str(self.root)!r}) / 'service-invocations').open('a', encoding='utf-8').write(operation + '\\n')
if operation == 'status':
    raise SystemExit(0 if marker.exists() else 3)
if operation == 'start':
    marker.touch()
    raise SystemExit(0)
if operation == 'stop':
    marker.unlink(missing_ok=True)
    raise SystemExit(0)
raise SystemExit(2)
"""

    def _generate_certificate(self, certificate: Path, private_key: Path, *, days: int) -> None:
        subprocess.run(
            [
                str(self.openssl),
                "req",
                "-x509",
                "-newkey",
                "rsa:2048",
                "-nodes",
                "-keyout",
                str(private_key),
                "-out",
                str(certificate),
                "-days",
                str(days),
                "-subj",
                "/CN=" + self.DOMAIN,
                "-addext",
                "subjectAltName=DNS:" + self.DOMAIN,
            ],
            check=True,
            stdout=subprocess.DEVNULL,
            stderr=subprocess.DEVNULL,
        )
        certificate.chmod(0o644)
        private_key.chmod(0o640)

    @staticmethod
    def _tree(root: Path) -> dict[str, bytes]:
        return {
            str(path.relative_to(root)): path.read_bytes()
            for path in sorted(root.rglob("*"))
            if path.is_file()
        }


if __name__ == "__main__":
    unittest.main()
