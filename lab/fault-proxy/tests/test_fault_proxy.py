from __future__ import annotations

import contextlib
import http.client
import http.server
import importlib.util
import io
import json
import os
import socket
import ssl
import stat
import subprocess
import sys
import tempfile
import threading
import time
import unittest
from pathlib import Path
from types import ModuleType
from urllib.parse import urlsplit

HARNESS = Path(__file__).resolve().parents[1] / "hoddmimir_fault_proxy.py"
SPEC = importlib.util.spec_from_file_location("hoddmimir_fault_proxy_under_test", HARNESS)
if SPEC is None or SPEC.loader is None:
    raise RuntimeError("Cannot load the lab fault proxy.")
PROXY: ModuleType = importlib.util.module_from_spec(SPEC)
sys.modules[SPEC.name] = PROXY
SPEC.loader.exec_module(PROXY)


class QuietTlsServer(http.server.ThreadingHTTPServer):
    daemon_threads = True

    def handle_error(self, _request: object, _client_address: object) -> None:
        return


class UpstreamHandler(http.server.BaseHTTPRequestHandler):
    protocol_version = "HTTP/1.1"

    def do_GET(self) -> None:  # noqa: N802
        self._respond()

    def do_POST(self) -> None:  # noqa: N802
        self._respond()

    def do_PUT(self) -> None:  # noqa: N802
        self._respond()

    def do_DELETE(self) -> None:  # noqa: N802
        self._respond()

    def log_message(self, _format: str, *_arguments: object) -> None:
        return

    def _respond(self) -> None:
        length = int(self.headers.get("Content-Length", "0"))
        body = self.rfile.read(length)
        state = getattr(self.server, "state")
        state["requests"].append(
            {
                "method": self.command,
                "path": self.path,
                "authorization": self.headers.get("Authorization"),
                "body": body,
            },
        )
        response = json.dumps({"data": "UPSTREAM_RESPONSE_SENTINEL"}).encode("utf-8")
        self.send_response_only(state.get("status", 200))
        self.send_header("Content-Type", "application/json")
        declared_length = len(response) + 32 if state.get("truncate_response") else len(response)
        self.send_header("Content-Length", str(declared_length))
        self.end_headers()
        self.wfile.write(response)
        self.wfile.flush()
        if state.get("truncate_response"):
            self.close_connection = True
            return
        state["response_completed"].set()


class FaultProxyTest(unittest.TestCase):
    @classmethod
    def setUpClass(cls) -> None:
        cls.temporary_directory = tempfile.TemporaryDirectory()
        cls.root = Path(cls.temporary_directory.name)
        cls.upstream_certificate, cls.upstream_key = cls.generate_certificate("upstream")
        cls.proxy_certificate, cls.proxy_key = cls.generate_certificate("proxy")

        upstream_tls = ssl.SSLContext(ssl.PROTOCOL_TLS_SERVER)
        upstream_tls.minimum_version = ssl.TLSVersion.TLSv1_2
        upstream_tls.load_cert_chain(cls.upstream_certificate, cls.upstream_key)
        cls.upstream = QuietTlsServer(("127.0.0.1", 0), UpstreamHandler)
        cls.upstream.state = {"requests": [], "response_completed": threading.Event()}
        cls.upstream.socket = upstream_tls.wrap_socket(cls.upstream.socket, server_side=True)
        cls.upstream_thread = threading.Thread(target=cls.upstream.serve_forever, daemon=True)
        cls.upstream_thread.start()

    @classmethod
    def tearDownClass(cls) -> None:
        cls.upstream.shutdown()
        cls.upstream.server_close()
        cls.upstream_thread.join()
        cls.temporary_directory.cleanup()

    def setUp(self) -> None:
        self.upstream.state["requests"].clear()
        self.upstream.state["response_completed"].clear()
        self.upstream.state["status"] = 200
        self.upstream.state["truncate_response"] = False
        self.case_directory = Path(tempfile.mkdtemp(dir=self.root))

    def test_vzdump_fault_occurs_after_complete_upstream_response_exactly_once(self) -> None:
        secret_header = "PVEAPIToken=identity=HEADER_SECRET_SENTINEL"
        secret_body = b"vmid=101&BODY_SECRET_SENTINEL=yes"
        with self.running_proxy((PROXY.FaultRoute.POST_VZDUMP,), fault_count=1) as running:
            stdout = io.StringIO()
            stderr = io.StringIO()
            with contextlib.redirect_stdout(stdout), contextlib.redirect_stderr(stderr):
                with self.assertRaises((http.client.RemoteDisconnected, ConnectionResetError, ssl.SSLError, OSError)):
                    self.request(
                        running.port,
                        "POST",
                        "/api2/json/nodes/lab-node-a/vzdump",
                        body=secret_body,
                        headers={"Authorization": secret_header, "Content-Type": "application/x-www-form-urlencoded"},
                    )

            self.assertTrue(self.upstream.state["response_completed"].is_set())
            self.assertEqual(1, len(self.upstream.state["requests"]))
            self.assertEqual(secret_header, self.upstream.state["requests"][0]["authorization"])
            self.assertEqual(secret_body, self.upstream.state["requests"][0]["body"])
            status, response = self.request(
                running.port,
                "POST",
                "/api2/json/nodes/lab-node-a/vzdump",
                body=secret_body,
                headers={"Authorization": secret_header},
            )
            self.assertEqual(200, status)
            self.assertIn(b"UPSTREAM_RESPONSE_SENTINEL", response)

            self.wait_for_counter(running.metrics, "POST /nodes/{node}/vzdump", "responsesForwarded", 1)
            metrics_text = running.metrics.read_text(encoding="utf-8")
            metrics = json.loads(metrics_text)
            counter = metrics["counters"]["POST /nodes/{node}/vzdump"]
            self.assertEqual(2, counter["received"])
            self.assertEqual(2, counter["upstreamResponses"])
            self.assertEqual(1, counter["faultsInjected"])
            self.assertEqual(1, counter["responsesForwarded"])
            self.assertEqual(0o600, stat.S_IMODE(running.metrics.stat().st_mode))
            for forbidden in ("lab-node-a", secret_header, "HEADER_SECRET_SENTINEL", "BODY_SECRET_SENTINEL"):
                self.assertNotIn(forbidden, metrics_text + stdout.getvalue() + stderr.getvalue())

    def test_task_stop_matches_canonical_pve_and_explicit_stop_suffix_only(self) -> None:
        with self.running_proxy((PROXY.FaultRoute.DELETE_TASK_STOP,), fault_count=2) as running:
            for path in (
                "/api2/json/nodes/lab-node/tasks/UPID%3Alab%3Asecret",
                "/api2/json/nodes/lab-node/tasks/opaque-task/stop",
            ):
                with self.assertRaises((http.client.RemoteDisconnected, ConnectionResetError, ssl.SSLError, OSError)):
                    self.request(running.port, "DELETE", path)

            status, _ = self.request(
                running.port,
                "DELETE",
                "/api2/json/nodes/lab-node/tasks/opaque-task/status",
            )
            self.assertEqual(200, status)
            self.wait_for_counter(running.metrics, "OTHER /other", "responsesForwarded", 1)
            metrics_text = running.metrics.read_text(encoding="utf-8")
            metrics = json.loads(metrics_text)["counters"]
            self.assertEqual(2, metrics["DELETE /nodes/{node}/tasks/{task}/stop"]["faultsInjected"])
            self.assertEqual(1, metrics["OTHER /other"]["responsesForwarded"])
            self.assertNotIn("UPID", metrics_text)
            self.assertNotIn("opaque-task", metrics_text)

    def test_nearby_methods_and_paths_are_forwarded_without_faults(self) -> None:
        with self.running_proxy(
            (PROXY.FaultRoute.POST_VZDUMP, PROXY.FaultRoute.DELETE_TASK_STOP),
            fault_count=10,
        ) as running:
            cases = (
                ("GET", "/api2/json/nodes/node-a/vzdump"),
                ("PUT", "/api2/json/nodes/node-a/vzdump"),
                ("POST", "/api2/json/nodes/node-a/vzdump/extra"),
                ("POST", "/api2/json/nodes/node-a/vzdump?unexpected=1"),
                ("DELETE", "/api2/json/nodes/node-a/tasks/task-a/status"),
                ("DELETE", "/api2/json/nodes/node-a/tasks/task-a/log"),
            )
            for method, path in cases:
                with self.subTest(method=method, path=path):
                    status, _ = self.request(running.port, method, path)
                    self.assertEqual(200, status)

            self.wait_for_counter(running.metrics, "OTHER /other", "responsesForwarded", len(cases))
            metrics = json.loads(running.metrics.read_text(encoding="utf-8"))["counters"]
            self.assertEqual(len(cases), metrics["OTHER /other"]["responsesForwarded"])
            self.assertEqual(0, metrics["OTHER /other"]["faultsInjected"])

    def test_upstream_certificate_is_verified_and_failure_never_injects(self) -> None:
        with self.running_proxy(
            (PROXY.FaultRoute.POST_VZDUMP,),
            fault_count=1,
            upstream_ca=self.proxy_certificate,
        ) as running:
            status, body = self.request(
                running.port,
                "POST",
                "/api2/json/nodes/node-a/vzdump",
                body=b"vmid=101",
            )
            self.assertEqual(502, status)
            self.assertEqual(b"", body)
            self.assertEqual([], self.upstream.state["requests"])
            counter = json.loads(running.metrics.read_text(encoding="utf-8"))["counters"][
                "POST /nodes/{node}/vzdump"
            ]
            self.assertEqual(1, counter["received"])
            self.assertEqual(0, counter["upstreamResponses"])
            self.assertEqual(0, counter["faultsInjected"])

    def test_non_successful_upstream_response_is_forwarded_without_fault(self) -> None:
        self.upstream.state["status"] = 403
        with self.running_proxy((PROXY.FaultRoute.POST_VZDUMP,), fault_count=1) as running:
            status, body = self.request(
                running.port,
                "POST",
                "/api2/json/nodes/node-a/vzdump",
                body=b"vmid=101",
            )
            self.assertEqual(403, status)
            self.assertIn(b"UPSTREAM_RESPONSE_SENTINEL", body)
            self.wait_for_counter(running.metrics, "POST /nodes/{node}/vzdump", "responsesForwarded", 1)
            counter = json.loads(running.metrics.read_text(encoding="utf-8"))["counters"][
                "POST /nodes/{node}/vzdump"
            ]
            self.assertEqual(1, counter["upstreamResponses"])
            self.assertEqual(0, counter["faultsInjected"])

    def test_truncated_success_response_is_never_counted_or_used_for_fault_injection(self) -> None:
        self.upstream.state["truncate_response"] = True
        with self.running_proxy((PROXY.FaultRoute.POST_VZDUMP,), fault_count=1) as running:
            status, body = self.request(
                running.port,
                "POST",
                "/api2/json/nodes/node-a/vzdump",
                body=b"vmid=101",
            )

            self.assertEqual(502, status)
            self.assertEqual(b"", body)
            counter = json.loads(running.metrics.read_text(encoding="utf-8"))["counters"][
                "POST /nodes/{node}/vzdump"
            ]
            self.assertEqual(1, counter["received"])
            self.assertEqual(0, counter["upstreamResponses"])
            self.assertEqual(0, counter["faultsInjected"])
            self.assertEqual(0, counter["responsesForwarded"])

            self.upstream.state["truncate_response"] = False
            with self.assertRaises((http.client.RemoteDisconnected, ConnectionResetError, ssl.SSLError, OSError)):
                self.request(
                    running.port,
                    "POST",
                    "/api2/json/nodes/node-a/vzdump",
                    body=b"vmid=101",
                )

            self.wait_for_counter(running.metrics, "POST /nodes/{node}/vzdump", "faultsInjected", 1)
            counter = json.loads(running.metrics.read_text(encoding="utf-8"))["counters"][
                "POST /nodes/{node}/vzdump"
            ]
            self.assertEqual(2, counter["received"])
            self.assertEqual(1, counter["upstreamResponses"])
            self.assertEqual(1, counter["faultsInjected"])
            self.assertEqual(0, counter["responsesForwarded"])

    def test_activation_is_fail_closed(self) -> None:
        base = [
            "--server-certificate",
            str(self.proxy_certificate),
            "--server-private-key",
            str(self.proxy_key),
            "--upstream",
            f"https://localhost:{self.upstream.server_address[1]}",
            "--upstream-ca",
            str(self.upstream_certificate),
            "--metrics-file",
            str(self.case_directory / "metrics.json"),
            "--fault-route",
            "post-vzdump",
            "--activation-ack",
            PROXY.ACTIVATION_ACK,
        ]
        parser = PROXY.argument_parser()
        self.assertEqual(
            (PROXY.FaultRoute.POST_VZDUMP,),
            PROXY.ProxyConfiguration.from_arguments(parser.parse_args(base)).routes,
        )
        invalid_cases = (
            self.replace_argument(base, "--activation-ack", "NOT_ARMED"),
            self.remove_argument(base, "--fault-route"),
            self.replace_argument(base, "--upstream", "http://localhost:8006"),
            self.replace_argument(base, "--upstream", "https://user:secret@localhost:8006"),
            self.replace_argument(base, "--fault-count", "0", append=True),
            self.replace_argument(base, "--listen-host", "0.0.0.0", append=True),
            self.replace_argument(base, "--listen-host", "203.0.113.10", append=True),
            self.replace_argument(base, "--listen-host", "proxy.example.invalid", append=True),
        )
        for arguments in invalid_cases:
            with self.subTest(arguments=arguments), self.assertRaises(PROXY.ConfigurationError):
                PROXY.ProxyConfiguration.from_arguments(parser.parse_args(arguments))

        self.proxy_key.chmod(0o644)
        try:
            with self.assertRaises(PROXY.ConfigurationError):
                PROXY.ProxyConfiguration.from_arguments(parser.parse_args(base))
        finally:
            self.proxy_key.chmod(0o600)

    def test_metrics_destination_rejects_symlinks(self) -> None:
        target = self.case_directory / "target.json"
        target.write_text("{}\n", encoding="utf-8")
        link = self.case_directory / "metrics.json"
        link.symlink_to(target)
        with self.assertRaises(PROXY.MetricsError):
            PROXY.SanitizedCounters(link)

    @contextlib.contextmanager
    def running_proxy(
        self,
        routes: tuple[object, ...],
        *,
        fault_count: int,
        upstream_ca: Path | None = None,
    ):
        metrics = self.case_directory / "metrics.json"
        configuration = PROXY.ProxyConfiguration(
            listen_host="127.0.0.1",
            listen_port=0,
            server_certificate=self.proxy_certificate,
            server_private_key=self.proxy_key,
            upstream=urlsplit(f"https://localhost:{self.upstream.server_address[1]}"),
            upstream_ca=upstream_ca or self.upstream_certificate,
            upstream_timeout_seconds=5.0,
            metrics_file=metrics,
            routes=routes,
            fault_count=fault_count,
        )
        server = PROXY.build_server(configuration)
        thread = threading.Thread(target=server.serve_forever, daemon=True)
        thread.start()
        running = type("RunningProxy", (), {"port": server.server_address[1], "metrics": metrics})()
        try:
            yield running
        finally:
            server.shutdown()
            server.server_close()
            thread.join()

    def request(
        self,
        port: int,
        method: str,
        path: str,
        *,
        body: bytes = b"",
        headers: dict[str, str] | None = None,
    ) -> tuple[int, bytes]:
        context = ssl.create_default_context(cafile=str(self.proxy_certificate))
        connection = http.client.HTTPSConnection("localhost", port, timeout=5, context=context)
        try:
            connection.request(method, path, body=body, headers=headers or {})
            response = connection.getresponse()
            return response.status, response.read()
        finally:
            connection.close()

    @classmethod
    def generate_certificate(cls, name: str) -> tuple[Path, Path]:
        certificate = cls.root / f"{name}.crt"
        private_key = cls.root / f"{name}.key"
        configuration = cls.root / f"{name}.cnf"
        configuration.write_text(
            """[req]
prompt = no
distinguished_name = subject
x509_extensions = extensions
[subject]
CN = localhost
[extensions]
subjectAltName = @names
basicConstraints = critical,CA:TRUE
keyUsage = critical,digitalSignature,keyEncipherment,keyCertSign
[names]
DNS.1 = localhost
IP.1 = 127.0.0.1
""",
            encoding="utf-8",
        )
        subprocess.run(
            [
                "openssl",
                "req",
                "-x509",
                "-newkey",
                "rsa:2048",
                "-nodes",
                "-days",
                "2",
                "-config",
                str(configuration),
                "-keyout",
                str(private_key),
                "-out",
                str(certificate),
            ],
            check=True,
            stdout=subprocess.DEVNULL,
            stderr=subprocess.DEVNULL,
        )
        private_key.chmod(0o600)
        return certificate, private_key

    @staticmethod
    def replace_argument(arguments: list[str], option: str, value: str, *, append: bool = False) -> list[str]:
        result = list(arguments)
        if append:
            result.extend((option, value))
            return result
        index = result.index(option)
        result[index + 1] = value
        return result

    @staticmethod
    def remove_argument(arguments: list[str], option: str) -> list[str]:
        result = list(arguments)
        index = result.index(option)
        del result[index : index + 2]
        return result

    @staticmethod
    def wait_for_counter(metrics_file: Path, label: str, field: str, expected: int) -> None:
        deadline = time.monotonic() + 2
        while time.monotonic() < deadline:
            document = json.loads(metrics_file.read_text(encoding="utf-8"))
            if document.get("counters", {}).get(label, {}).get(field) == expected:
                return
            time.sleep(0.01)
        raise AssertionError(f"counter {label!r}/{field!r} did not reach {expected}")


class RouteClassificationTest(unittest.TestCase):
    def test_only_exact_sanitized_write_routes_match(self) -> None:
        self.assertEqual(
            PROXY.FaultRoute.POST_VZDUMP,
            PROXY.classify_route("POST", "/api2/json/nodes/node-a/vzdump"),
        )
        self.assertEqual(
            PROXY.FaultRoute.DELETE_TASK_STOP,
            PROXY.classify_route("DELETE", "/api2/json/nodes/node-a/tasks/opaque"),
        )
        self.assertIsNone(PROXY.classify_route("GET", "/api2/json/nodes/node-a/vzdump"))
        self.assertIsNone(PROXY.classify_route("POST", "/api2/json/nodes/node-a/vzdump/extra"))
        self.assertIsNone(PROXY.classify_route("DELETE", "/api2/json/nodes/node-a/tasks/opaque/status"))


if __name__ == "__main__":
    unittest.main()
