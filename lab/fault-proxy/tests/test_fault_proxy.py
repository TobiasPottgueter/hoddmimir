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
from types import ModuleType, SimpleNamespace
from urllib.parse import urlsplit
from unittest import mock

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

    def test_pre_upstream_drop_never_submits_and_next_attempt_is_forwarded(self) -> None:
        with self.running_proxy((PROXY.FaultRoute.POST_VZDUMP,), fault_count=1, fault_timing="before-upstream") as running:
            self.assertEqual(200, self.request(running.port, "GET", "/api2/json/nodes/lab/tasks")[0])
            self.upstream.state["requests"].clear()
            with self.assertRaises((http.client.RemoteDisconnected, ConnectionResetError, ssl.SSLError, OSError)):
                self.request(running.port, "POST", "/api2/json/nodes/lab/vzdump", body=b"vmid=101&PRIVATE_SENTINEL=1")
            self.assertEqual([], self.upstream.state["requests"])
            counters=json.loads(running.metrics.read_text())["counters"]["POST /nodes/{node}/vzdump"]
            self.assertEqual(1,counters["received"])
            self.assertEqual(1,counters["faultsInjected"])
            self.assertEqual(0,counters["upstreamResponses"])
            self.assertEqual(0,counters["responsesForwarded"])
            self.assertEqual(200,self.request(running.port,"POST","/api2/json/nodes/lab/vzdump",body=b"vmid=101")[0])
            self.assertEqual(1,len(self.upstream.state["requests"]))
            self.wait_for_counter(running.metrics,"POST /nodes/{node}/vzdump","responsesForwarded",1)
            self.assertNotIn("PRIVATE_SENTINEL",running.metrics.read_text())

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

    def test_hold_is_after_complete_upstream_and_before_any_client_response_byte(self) -> None:
        secret_header = "PVEAPIToken=identity=HOLD_HEADER_SECRET"
        path = "/api2/json/nodes/lab-node/tasks/UPID%3AHOLD_SECRET"
        with self.running_proxy(
            (PROXY.FaultRoute.POST_VZDUMP,),
            fault_count=1,
            hold_routes=(PROXY.FaultRoute.DELETE_TASK_STOP,),
        ) as running:
            result: dict[str, object] = {}

            def request() -> None:
                try:
                    result["response"] = self.request(
                        running.port,
                        "DELETE",
                        path,
                        headers={"Authorization": secret_header},
                    )
                except BaseException as error:
                    result["error"] = error

            thread = threading.Thread(target=request)
            thread.start()
            state = self.wait_for_hold_state(running.control, "hold_entered")
            self.assertTrue(self.upstream.state["response_completed"].is_set())
            self.assertTrue(thread.is_alive(), "the client received a response before release")
            self.assertEqual("DELETE /nodes/{node}/tasks/{task}/stop", state["route"])
            self.write_release(running.control)
            thread.join(3)
            self.assertFalse(thread.is_alive())
            self.assertNotIn("error", result)
            status, body = result["response"]  # type: ignore[misc]
            self.assertEqual(200, status)
            self.assertIn(b"UPSTREAM_RESPONSE_SENTINEL", body)
            second_status, _ = self.request(running.port, "DELETE", path, headers={"Authorization": secret_header})
            self.assertEqual(200, second_status)
            self.wait_for_counter(running.metrics, "DELETE /nodes/{node}/tasks/{task}/stop", "released", 1)
            self.wait_for_counter(running.metrics, "DELETE /nodes/{node}/tasks/{task}/stop", "responsesForwarded", 2)
            counter = json.loads(running.metrics.read_text())["counters"]["DELETE /nodes/{node}/tasks/{task}/stop"]
            self.assertEqual(2, counter["received"])
            self.assertEqual(1, counter["upstream_complete"])
            self.assertEqual(1, counter["hold_entered"])
            self.assertEqual(1, counter["released"])
            self.assertEqual(2, counter["responsesForwarded"])
            self.assertEqual(PROXY.HOLD_RELEASE_DOCUMENT, (running.control / "release").read_bytes())
            evidence = running.metrics.read_text() + (running.control / "state.json").read_text()
            for forbidden in ("lab-node", "UPID", "HOLD_SECRET", secret_header):
                self.assertNotIn(forbidden, evidence)
            self.assertEqual(0o600, stat.S_IMODE((running.control / "state.json").stat().st_mode))

    def test_hold_observes_client_gone_then_releases_without_response_or_second_delete(self) -> None:
        path = "/api2/json/nodes/lab-node/tasks/opaque-cancel-task"
        with self.running_proxy(
            (PROXY.FaultRoute.POST_VZDUMP,),
            fault_count=1,
            hold_routes=(PROXY.FaultRoute.DELETE_TASK_STOP,),
        ) as running:
            context = ssl.create_default_context(cafile=str(self.proxy_certificate))
            connection = context.wrap_socket(socket.create_connection(("127.0.0.1", running.port), timeout=3), server_hostname="localhost")
            request = (
                f"DELETE {path} HTTP/1.1\r\n"
                "Host: localhost\r\n"
                "Authorization: PVEAPIToken=CLIENT_GONE_SECRET\r\n"
                "Content-Length: 0\r\nConnection: close\r\n\r\n"
            ).encode()
            connection.sendall(request)
            self.wait_for_hold_state(running.control, "hold_entered")
            self.assertEqual(1, len(self.upstream.state["requests"]))
            connection.close()
            self.wait_for_counter(running.metrics, "DELETE /nodes/{node}/tasks/{task}/stop", "client_gone", 1)
            self.write_release(running.control)
            self.wait_for_counter(running.metrics, "DELETE /nodes/{node}/tasks/{task}/stop", "released", 1)
            counter = json.loads(running.metrics.read_text())["counters"]["DELETE /nodes/{node}/tasks/{task}/stop"]
            self.assertEqual(1, counter["received"])
            self.assertEqual(1, counter["upstreamResponses"])
            self.assertEqual(1, counter["client_gone"])
            self.assertEqual(0, counter["responsesForwarded"])
            self.assertEqual(0, counter["faultsInjected"])
            self.assertNotIn("CLIENT_GONE_SECRET", running.metrics.read_text())

    def test_hold_timeout_is_bounded_and_fails_without_forwarding(self) -> None:
        started = time.monotonic()
        with self.running_proxy(
            (PROXY.FaultRoute.POST_VZDUMP,),
            fault_count=1,
            hold_routes=(PROXY.FaultRoute.DELETE_TASK_STOP,),
            hold_max_seconds=0.15,
        ) as running:
            with self.assertRaises((http.client.RemoteDisconnected, ConnectionResetError, ssl.SSLError, OSError)):
                self.request(running.port, "DELETE", "/api2/json/nodes/node/tasks/task")
            elapsed = time.monotonic() - started
            self.assertLess(elapsed, 2.0)
            self.wait_for_counter(running.metrics, "DELETE /nodes/{node}/tasks/{task}/stop", "fault", 1)
            counter = json.loads(running.metrics.read_text())["counters"]["DELETE /nodes/{node}/tasks/{task}/stop"]
            self.assertEqual(1, counter["upstream_complete"])
            self.assertEqual(1, counter["hold_entered"])
            self.assertEqual(1, counter["fault"])
            self.assertEqual(0, counter["responsesForwarded"])

    def test_hold_never_matches_nearby_route_or_non_success_response(self) -> None:
        with self.running_proxy(
            (PROXY.FaultRoute.POST_VZDUMP,),
            fault_count=1,
            hold_routes=(PROXY.FaultRoute.DELETE_TASK_STOP,),
            hold_max_seconds=0.1,
        ) as running:
            status, _ = self.request(running.port, "DELETE", "/api2/json/nodes/node/tasks/task/status")
            self.assertEqual(200, status)
            self.assertFalse(os.path.lexists(running.control / "state.json"))

        self.upstream.state["status"] = 403
        with self.running_proxy(
            (PROXY.FaultRoute.POST_VZDUMP,),
            fault_count=1,
            hold_routes=(PROXY.FaultRoute.DELETE_TASK_STOP,),
            hold_max_seconds=0.1,
        ) as running:
            status, _ = self.request(running.port, "DELETE", "/api2/json/nodes/node/tasks/task")
            self.assertEqual(403, status)
            self.assertFalse(os.path.lexists(running.control / "state.json"))

    def test_symlink_release_fails_closed_without_following_target(self) -> None:
        target = self.case_directory / "do-not-touch"
        target.write_text("sentinel", encoding="utf-8")
        with self.running_proxy(
            (PROXY.FaultRoute.POST_VZDUMP,),
            fault_count=1,
            hold_routes=(PROXY.FaultRoute.DELETE_TASK_STOP,),
        ) as running:
            result: dict[str, object] = {}

            def request() -> None:
                try:
                    result["response"] = self.request(running.port, "DELETE", "/api2/json/nodes/node/tasks/task")
                except BaseException as error:
                    result["error"] = error

            thread = threading.Thread(target=request)
            thread.start()
            self.wait_for_hold_state(running.control, "hold_entered")
            (running.control / "release").symlink_to(target)
            thread.join(3)
            self.assertFalse(thread.is_alive())
            self.assertIn("error", result)
            self.assertEqual("sentinel", target.read_text())
            self.wait_for_counter(running.metrics, "DELETE /nodes/{node}/tasks/{task}/stop", "fault", 1)

    def test_invalid_release_mode_and_content_fail_closed(self) -> None:
        cases = ((PROXY.HOLD_RELEASE_DOCUMENT, 0o644), (b"NOT_RELEASE\n", 0o600))
        for document, mode in cases:
            with self.subTest(mode=oct(mode), document=document):
                with self.running_proxy(
                    (PROXY.FaultRoute.POST_VZDUMP,),
                    fault_count=1,
                    hold_routes=(PROXY.FaultRoute.DELETE_TASK_STOP,),
                ) as running:
                    result: dict[str, object] = {}

                    def request() -> None:
                        try:
                            result["response"] = self.request(running.port, "DELETE", "/api2/json/nodes/node/tasks/task")
                        except BaseException as error:
                            result["error"] = error

                    thread = threading.Thread(target=request)
                    thread.start()
                    self.wait_for_hold_state(running.control, "hold_entered")
                    release = running.control / "release"
                    release.write_bytes(document)
                    release.chmod(mode)
                    thread.join(3)
                    self.assertFalse(thread.is_alive())
                    self.assertIn("error", result)
                    self.wait_for_counter(running.metrics, "DELETE /nodes/{node}/tasks/{task}/stop", "fault", 1)

    def test_release_created_before_hold_entered_is_rejected(self) -> None:
        with self.running_proxy(
            (PROXY.FaultRoute.POST_VZDUMP,),
            fault_count=1,
            hold_routes=(PROXY.FaultRoute.DELETE_TASK_STOP,),
        ) as running:
            self.write_release(running.control)
            with self.assertRaises((http.client.RemoteDisconnected, ConnectionResetError, ssl.SSLError, OSError)):
                self.request(running.port, "DELETE", "/api2/json/nodes/node/tasks/task")
            self.wait_for_counter(running.metrics, "DELETE /nodes/{node}/tasks/{task}/stop", "fault", 1)
            state = self.wait_for_hold_state(running.control, "fault")
            self.assertEqual("fault", state["state"])
            counter = json.loads(running.metrics.read_text())["counters"]["DELETE /nodes/{node}/tasks/{task}/stop"]
            self.assertEqual(0, counter["hold_entered"])
            self.assertEqual(0, counter["responsesForwarded"])

    def test_shutdown_wakes_hold_and_retains_sanitized_fault_evidence(self) -> None:
        control = self.case_directory / "shutdown-control"
        control.mkdir(mode=0o700)
        metrics = self.case_directory / "shutdown-metrics.json"
        shutdown = threading.Event()
        left, right = socket.socketpair()
        try:
            with mock.patch.object(PROXY, "HOLD_REQUIRED_UID", os.geteuid()):
                counters = PROXY.SanitizedCounters(metrics)
                latch = PROXY.HoldLatch(control, 5.0, counters, shutdown)
                result: dict[str, object] = {}
                thread = threading.Thread(
                    target=lambda: result.setdefault("outcome", latch.hold(PROXY.FaultRoute.DELETE_TASK_STOP, left)),
                )
                thread.start()
                self.wait_for_hold_state(control, "hold_entered")
                latch.close()
                thread.join(2)
                self.assertFalse(thread.is_alive())
                self.assertEqual(PROXY.HoldOutcome.FAULT, result["outcome"])
                state = json.loads((control / "state.json").read_text(encoding="utf-8"))
                self.assertEqual("fault", state["state"])
                self.assertEqual(0o600, stat.S_IMODE((control / "state.json").stat().st_mode))
                self.assertFalse(os.path.lexists(control / "release"))
                self.wait_for_counter(metrics, "DELETE /nodes/{node}/tasks/{task}/stop", "fault", 1)
        finally:
            left.close()
            right.close()

    def test_release_inode_swap_fails_closed_without_deleting_either_entry(self) -> None:
        control = self.case_directory / "release-swap-control"
        control.mkdir(mode=0o700)
        metrics = self.case_directory / "release-swap-metrics.json"
        shutdown = threading.Event()
        with mock.patch.object(PROXY, "HOLD_REQUIRED_UID", os.geteuid()):
            latch = PROXY.HoldLatch(control, 1.0, PROXY.SanitizedCounters(metrics), shutdown)
            release = control / "release"
            replacement = control / "replacement"
            release.write_bytes(PROXY.HOLD_RELEASE_DOCUMENT)
            replacement.write_bytes(PROXY.HOLD_RELEASE_DOCUMENT)
            release.chmod(0o600)
            replacement.chmod(0o600)
            original_release_inode = release.stat().st_ino
            replacement_inode = replacement.stat().st_ino
            real_stat = os.stat
            swapped = False

            def swap_before_entry_validation(path: object, *args: object, **kwargs: object) -> os.stat_result:
                nonlocal swapped
                if path == "release" and kwargs.get("dir_fd") == latch._directory_fd and not swapped:
                    swapped = True
                    os.replace(
                        "release",
                        "opened-release",
                        src_dir_fd=latch._directory_fd,
                        dst_dir_fd=latch._directory_fd,
                    )
                    os.replace(
                        "replacement",
                        "release",
                        src_dir_fd=latch._directory_fd,
                        dst_dir_fd=latch._directory_fd,
                    )
                return real_stat(path, *args, **kwargs)

            try:
                with mock.patch.object(PROXY.os, "stat", side_effect=swap_before_entry_validation):
                    with self.assertRaisesRegex(PROXY.HoldControlError, "directory entry changed"):
                        latch._consume_release()
            finally:
                latch.close()

            self.assertTrue(swapped)
            self.assertEqual(replacement_inode, release.stat().st_ino)
            self.assertEqual(original_release_inode, (control / "opened-release").stat().st_ino)
            self.assertEqual(PROXY.HOLD_RELEASE_DOCUMENT, release.read_bytes())
            self.assertEqual(PROXY.HOLD_RELEASE_DOCUMENT, (control / "opened-release").read_bytes())

    def test_control_directory_swap_fails_closed_and_close_never_touches_replacement(self) -> None:
        control = self.case_directory / "directory-swap-control"
        displaced = self.case_directory / "directory-swap-original"
        control.mkdir(mode=0o700)
        metrics = self.case_directory / "directory-swap-metrics.json"
        shutdown = threading.Event()
        left, right = socket.socketpair()
        try:
            with mock.patch.object(PROXY, "HOLD_REQUIRED_UID", os.geteuid()):
                latch = PROXY.HoldLatch(control, 0.2, PROXY.SanitizedCounters(metrics), shutdown)
                control.rename(displaced)
                control.mkdir(mode=0o700)
                replacement_state = control / "state.json"
                replacement_release = control / "release"
                replacement_state.write_text("replacement-state\n", encoding="utf-8")
                replacement_release.write_text("replacement-release\n", encoding="utf-8")
                replacement_state.chmod(0o600)
                replacement_release.chmod(0o600)

                with self.assertRaisesRegex(PROXY.HoldControlError, "directory changed unsafely"):
                    latch.hold(PROXY.FaultRoute.DELETE_TASK_STOP, left)
                latch.close()

                self.assertEqual("replacement-state\n", replacement_state.read_text(encoding="utf-8"))
                self.assertEqual("replacement-release\n", replacement_release.read_text(encoding="utf-8"))
                self.assertEqual([], list(displaced.iterdir()))
        finally:
            left.close()
            right.close()

    def test_bound_state_inode_swap_fails_closed_without_touching_replacement(self) -> None:
        for swap_timing in ("before-validation", "between-validations"):
            with self.subTest(swap_timing=swap_timing):
                control = self.case_directory / f"state-swap-{swap_timing}"
                control.mkdir(mode=0o700)
                metrics = self.case_directory / f"state-swap-{swap_timing}-metrics.json"
                shutdown = threading.Event()
                with mock.patch.object(PROXY, "HOLD_REQUIRED_UID", os.geteuid()):
                    latch = PROXY.HoldLatch(control, 0.2, PROXY.SanitizedCounters(metrics), shutdown)
                    latch._write_state(PROXY.FaultRoute.DELETE_TASK_STOP, "hold_entered", 1)
                    original_payload = (control / "state.json").read_bytes()
                    target_document = {
                        "schemaVersion": 1,
                        "route": PROXY.FaultRoute.DELETE_TASK_STOP.counter_label,
                        "sequence": 1,
                        "state": "fault",
                    }
                    target_payload = (
                        json.dumps(target_document, sort_keys=True, separators=(",", ":")) + "\n"
                    ).encode("utf-8")
                    replacement_payload = b"R" * len(target_payload)
                    replacement = control / "replacement"
                    replacement.write_bytes(replacement_payload)
                    replacement.chmod(0o600)

                    def install_replacement() -> None:
                        os.replace(
                            "state.json",
                            "bound-state",
                            src_dir_fd=latch._directory_fd,
                            dst_dir_fd=latch._directory_fd,
                        )
                        os.replace(
                            "replacement",
                            "state.json",
                            src_dir_fd=latch._directory_fd,
                            dst_dir_fd=latch._directory_fd,
                        )

                    if swap_timing == "before-validation":
                        install_replacement()
                        with self.assertRaises(PROXY.HoldControlError):
                            latch._write_state(PROXY.FaultRoute.DELETE_TASK_STOP, "fault", 1)
                    else:
                        real_entry_stat = latch._entry_stat
                        validations = 0

                        def swap_after_first_validation(name: str) -> os.stat_result | None:
                            nonlocal validations
                            if name == "state.json":
                                validations += 1
                                if validations == 2:
                                    install_replacement()
                            return real_entry_stat(name)

                        with mock.patch.object(latch, "_entry_stat", side_effect=swap_after_first_validation):
                            with self.assertRaises(PROXY.HoldControlError):
                                latch._write_state(PROXY.FaultRoute.DELETE_TASK_STOP, "fault", 1)

                    latch.close()
                    self.assertEqual(replacement_payload, (control / "state.json").read_bytes())
                    if swap_timing == "before-validation":
                        self.assertEqual(original_payload, (control / "bound-state").read_bytes())
                    else:
                        self.assertEqual(target_payload, (control / "bound-state").read_bytes())

    def test_readable_partial_tls_record_cannot_extend_hold_deadline(self) -> None:
        class ReadablePartialTlsClient:
            def __init__(self) -> None:
                self.timeout: float | None = 5.0
                self.recv_calls = 0

            def gettimeout(self) -> float | None:
                return self.timeout

            def settimeout(self, value: float | None) -> None:
                self.timeout = value

            def recv(self, _size: int) -> bytes:
                self.recv_calls += 1
                if self.timeout != 0.0:
                    raise AssertionError("TLS EOF probe was allowed to block")
                raise ssl.SSLWantReadError(ssl.SSL_ERROR_WANT_READ, "partial TLS record")

        control = self.case_directory / "partial-tls-control"
        control.mkdir(mode=0o700)
        metrics = self.case_directory / "partial-tls-metrics.json"
        shutdown = threading.Event()
        client = ReadablePartialTlsClient()
        with mock.patch.object(PROXY, "HOLD_REQUIRED_UID", os.geteuid()):
            latch = PROXY.HoldLatch(control, 0.1, PROXY.SanitizedCounters(metrics), shutdown)
            started = time.monotonic()
            try:
                with mock.patch.object(PROXY.select, "select", return_value=([client], [], [])):
                    outcome = latch.hold(PROXY.FaultRoute.DELETE_TASK_STOP, client)  # type: ignore[arg-type]
            finally:
                latch.close()
            elapsed = time.monotonic() - started

        self.assertEqual(PROXY.HoldOutcome.FAULT, outcome)
        self.assertGreater(client.recv_calls, 1)
        self.assertEqual(5.0, client.timeout)
        self.assertLess(elapsed, 0.75)
        state = json.loads((control / "state.json").read_text(encoding="utf-8"))
        self.assertEqual("fault", state["state"])
        self.wait_for_counter(metrics, "DELETE /nodes/{node}/tasks/{task}/stop", "fault", 1)

    def test_main_sigterm_sets_shutdown_and_closes_hold_latch(self) -> None:
        shutdown = threading.Event()
        latch = mock.Mock()
        server = mock.Mock(runtime=SimpleNamespace(shutdown=shutdown, hold_latch=latch))
        server.serve_forever.side_effect = lambda poll_interval: os.kill(os.getpid(), PROXY.signal.SIGTERM)
        parser = mock.Mock()
        parser.parse_args.return_value = object()
        with (
            mock.patch.object(PROXY, "argument_parser", return_value=parser),
            mock.patch.object(PROXY.ProxyConfiguration, "from_arguments", return_value=object()),
            mock.patch.object(PROXY, "build_server", return_value=server),
            contextlib.redirect_stdout(io.StringIO()),
            contextlib.redirect_stderr(io.StringIO()),
        ):
            self.assertEqual(0, PROXY.main())
        self.assertTrue(shutdown.is_set())
        latch.close.assert_called_once_with()
        server.server_close.assert_called_once_with()

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

        before = base + ["--fault-timing", "before-upstream"]
        with self.assertRaises(PROXY.ConfigurationError):
            PROXY.ProxyConfiguration.from_arguments(parser.parse_args(before))
        before = self.replace_argument(before, "--activation-ack", PROXY.PRE_UPSTREAM_ACTIVATION_ACK)
        self.assertEqual("before-upstream",PROXY.ProxyConfiguration.from_arguments(parser.parse_args(before)).fault_timing)
        for invalid in [
            self.replace_argument(before,"--fault-route","delete-task-stop"),
            before+["--hold-route","post-vzdump"],
            self.replace_argument(base,"--activation-ack",PROXY.PRE_UPSTREAM_ACTIVATION_ACK),
        ]:
            with self.subTest(arguments=invalid), self.assertRaises(PROXY.ConfigurationError):
                PROXY.ProxyConfiguration.from_arguments(parser.parse_args(invalid))

        self.proxy_key.chmod(0o644)
        try:
            with self.assertRaises(PROXY.ConfigurationError):
                PROXY.ProxyConfiguration.from_arguments(parser.parse_args(base))
        finally:
            self.proxy_key.chmod(0o600)

    def test_hold_activation_requires_separate_exact_ack_one_shot_count_timeout_and_root_control(self) -> None:
        control = self.case_directory / "hold-control-activation"
        control.mkdir(mode=0o700)
        base = [
            "--server-certificate", str(self.proxy_certificate),
            "--server-private-key", str(self.proxy_key),
            "--upstream", f"https://localhost:{self.upstream.server_address[1]}",
            "--upstream-ca", str(self.upstream_certificate),
            "--metrics-file", str(self.case_directory / "metrics.json"),
            "--fault-route", "post-vzdump",
            "--activation-ack", PROXY.ACTIVATION_ACK,
            "--hold-route", "delete-task-stop",
            "--hold-count", "1",
            "--hold-max-seconds", "2",
            "--hold-control-directory", str(control),
            "--hold-activation-ack", PROXY.HOLD_ACTIVATION_ACK,
        ]
        parser = PROXY.argument_parser()
        with mock.patch.object(PROXY, "HOLD_REQUIRED_UID", os.geteuid()):
            configuration = PROXY.ProxyConfiguration.from_arguments(parser.parse_args(base))
            self.assertEqual((PROXY.FaultRoute.DELETE_TASK_STOP,), configuration.hold_routes)
            invalid = (
                self.replace_argument(base, "--hold-activation-ack", "NOT_ARMED"),
                self.remove_argument(base, "--hold-activation-ack"),
                self.remove_argument(base, "--hold-route"),
                self.remove_argument(base, "--hold-control-directory"),
                self.replace_argument(base, "--hold-count", "0"),
                self.replace_argument(base, "--hold-count", "2"),
                [*base, "--hold-route", "post-vzdump"],
                self.replace_argument(base, "--hold-max-seconds", "0"),
                self.replace_argument(base, "--hold-max-seconds", "121"),
            )
            for arguments in invalid:
                with self.subTest(arguments=arguments), self.assertRaises(PROXY.ConfigurationError):
                    PROXY.ProxyConfiguration.from_arguments(parser.parse_args(arguments))

            stale = control / "state.json"
            stale.symlink_to(self.case_directory / "missing-target")
            try:
                with self.assertRaises(PROXY.ConfigurationError):
                    PROXY.ProxyConfiguration.from_arguments(parser.parse_args(base))
            finally:
                stale.unlink()

            control.chmod(0o755)
            try:
                with self.assertRaises(PROXY.ConfigurationError):
                    PROXY.ProxyConfiguration.from_arguments(parser.parse_args(base))
            finally:
                control.chmod(0o700)

            linked = self.case_directory / "linked-control"
            linked.symlink_to(control, target_is_directory=True)
            linked_base = self.replace_argument(base, "--hold-control-directory", str(linked))
            with self.assertRaises(PROXY.ConfigurationError):
                PROXY.ProxyConfiguration.from_arguments(parser.parse_args(linked_base))

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
        hold_routes: tuple[object, ...] = (),
        hold_max_seconds: float = 2.0,
        fault_timing: str = "after-upstream",
    ):
        metrics = self.case_directory / "metrics.json"
        control = (
            Path(tempfile.mkdtemp(prefix="hold-control-", dir=self.case_directory))
            if hold_routes
            else self.case_directory / "hold-control-unused"
        )
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
            hold_routes=hold_routes,
            hold_max_seconds=hold_max_seconds,
            hold_control_directory=control if hold_routes else None,
            fault_timing=fault_timing,
        )
        owner_patch = mock.patch.object(PROXY, "HOLD_REQUIRED_UID", os.geteuid())
        owner_patch.start()
        try:
            server = PROXY.build_server(configuration)
        except BaseException:
            owner_patch.stop()
            raise
        thread = threading.Thread(target=server.serve_forever, daemon=True)
        thread.start()
        running = type(
            "RunningProxy",
            (),
            {"port": server.server_address[1], "metrics": metrics, "control": control, "server": server},
        )()
        try:
            yield running
        finally:
            server.shutdown()
            if server.runtime.hold_latch is not None:
                server.runtime.hold_latch.close()
            server.server_close()
            thread.join()
            owner_patch.stop()

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

    @staticmethod
    def wait_for_hold_state(control: Path, expected: str) -> dict[str, object]:
        state = control / "state.json"
        deadline = time.monotonic() + 3
        while time.monotonic() < deadline:
            try:
                document = json.loads(state.read_text(encoding="utf-8"))
            except (OSError, json.JSONDecodeError):
                time.sleep(0.01)
                continue
            if document.get("state") == expected:
                return document
            time.sleep(0.01)
        raise AssertionError(f"hold state did not reach {expected!r}")

    @staticmethod
    def write_release(control: Path) -> None:
        path = control / "release"
        descriptor = os.open(path, os.O_WRONLY | os.O_CREAT | os.O_EXCL, 0o600)
        try:
            os.write(descriptor, PROXY.HOLD_RELEASE_DOCUMENT)
            os.fsync(descriptor)
        finally:
            os.close(descriptor)


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
