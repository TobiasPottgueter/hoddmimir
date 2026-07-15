#!/usr/bin/env python3
"""Deterministic TLS fault proxy for isolated Hoddmímir Phase-7 lab tests."""

from __future__ import annotations

import argparse
import http.client
import http.server
import ipaddress
import json
import os
import re
import socket
import ssl
import stat
import struct
import tempfile
import threading
from dataclasses import dataclass
from enum import Enum
from pathlib import Path
from urllib.parse import SplitResult, urlsplit

ACTIVATION_ACK = "INJECT_AFTER_VERIFIED_UPSTREAM_RESPONSE"
MAXIMUM_REQUEST_BODY_BYTES = 16 * 1024 * 1024
MAXIMUM_RESPONSE_BODY_BYTES = 8 * 1024 * 1024
HOP_BY_HOP_HEADERS = frozenset(
    {
        "connection",
        "keep-alive",
        "proxy-authenticate",
        "proxy-authorization",
        "proxy-connection",
        "te",
        "trailer",
        "transfer-encoding",
        "upgrade",
    },
)
VZDUMP_PATH = re.compile(r"^/api2/json/nodes/[^/]+/vzdump$")
# PVE's canonical task-stop endpoint is DELETE .../tasks/{upid}. The optional
# /stop suffix keeps the harness useful for an explicitly routed lab adapter
# without broadening the match to task status/log requests.
TASK_STOP_PATH = re.compile(r"^/api2/json/nodes/[^/]+/tasks/[^/]+(?:/stop)?$")


class ConfigurationError(RuntimeError):
    """The lab harness configuration is unsafe or incomplete."""


class MetricsError(RuntimeError):
    """Sanitized evidence could not be persisted safely."""


class FaultRoute(str, Enum):
    POST_VZDUMP = "post-vzdump"
    DELETE_TASK_STOP = "delete-task-stop"

    @property
    def counter_label(self) -> str:
        if self is FaultRoute.POST_VZDUMP:
            return "POST /nodes/{node}/vzdump"
        return "DELETE /nodes/{node}/tasks/{task}/stop"


def classify_route(method: str, path: str) -> FaultRoute | None:
    """Return a sanitized exact route class without decoding path values."""
    if method == "POST" and VZDUMP_PATH.fullmatch(path):
        return FaultRoute.POST_VZDUMP
    if method == "DELETE" and TASK_STOP_PATH.fullmatch(path):
        return FaultRoute.DELETE_TASK_STOP
    return None


class FaultPlan:
    def __init__(self, routes: tuple[FaultRoute, ...], count: int) -> None:
        self._remaining = {route: count for route in routes}
        self._lock = threading.Lock()

    def claim(self, route: FaultRoute | None) -> bool:
        if route is None:
            return False
        with self._lock:
            remaining = self._remaining.get(route, 0)
            if remaining < 1:
                return False
            self._remaining[route] = remaining - 1
            return True


class SanitizedCounters:
    FIELDS = ("received", "upstreamResponses", "responsesForwarded", "faultsInjected")

    def __init__(self, destination: Path) -> None:
        self._destination = destination
        self._lock = threading.Lock()
        self._counters: dict[str, dict[str, int]] = {}
        self._validate_destination()
        self._persist_locked()

    def record(self, route: FaultRoute | None, field: str) -> None:
        if field not in self.FIELDS:
            raise MetricsError("Unknown sanitized counter field.")
        label = route.counter_label if route is not None else "OTHER /other"
        with self._lock:
            values = self._counters.setdefault(label, {name: 0 for name in self.FIELDS})
            values[field] += 1
            self._persist_locked()

    def _validate_destination(self) -> None:
        parent = self._destination.parent
        try:
            parent_stat = parent.lstat()
        except OSError as error:
            raise MetricsError("The metrics parent directory is unavailable.") from error
        if not stat.S_ISDIR(parent_stat.st_mode) or parent.is_symlink():
            raise MetricsError("The metrics parent directory is unsafe.")
        if parent_stat.st_uid != os.geteuid() or stat.S_IMODE(parent_stat.st_mode) & 0o022:
            raise MetricsError("The metrics parent directory must be private to the current user.")
        if self._destination.exists() or self._destination.is_symlink():
            try:
                destination_stat = self._destination.lstat()
            except OSError as error:
                raise MetricsError("The metrics file is unavailable.") from error
            if not stat.S_ISREG(destination_stat.st_mode) or self._destination.is_symlink():
                raise MetricsError("The metrics file is unsafe.")

    def _persist_locked(self) -> None:
        document = {
            "schemaVersion": 1,
            "counters": {key: self._counters[key] for key in sorted(self._counters)},
        }
        payload = (json.dumps(document, sort_keys=True, separators=(",", ":")) + "\n").encode("utf-8")
        descriptor = -1
        temporary_path: Path | None = None
        try:
            descriptor, temporary_name = tempfile.mkstemp(
                prefix=f".{self._destination.name}.",
                dir=self._destination.parent,
            )
            temporary_path = Path(temporary_name)
            os.fchmod(descriptor, 0o600)
            with os.fdopen(descriptor, "wb") as handle:
                descriptor = -1
                handle.write(payload)
                handle.flush()
                os.fsync(handle.fileno())
            os.replace(temporary_path, self._destination)
            temporary_path = None
        except OSError as error:
            raise MetricsError("The sanitized metrics file could not be written.") from error
        finally:
            if descriptor >= 0:
                os.close(descriptor)
            if temporary_path is not None:
                temporary_path.unlink(missing_ok=True)


@dataclass(frozen=True)
class ProxyConfiguration:
    listen_host: str
    listen_port: int
    server_certificate: Path
    server_private_key: Path
    upstream: SplitResult
    upstream_ca: Path | None
    upstream_timeout_seconds: float
    metrics_file: Path
    routes: tuple[FaultRoute, ...]
    fault_count: int

    @classmethod
    def from_arguments(cls, arguments: argparse.Namespace) -> "ProxyConfiguration":
        if arguments.activation_ack != ACTIVATION_ACK:
            raise ConfigurationError("The explicit lab fault-injection acknowledgement is missing.")
        if not arguments.fault_route:
            raise ConfigurationError("Select at least one exact fault route.")
        routes = tuple(dict.fromkeys(FaultRoute(value) for value in arguments.fault_route))
        if arguments.fault_count < 1 or arguments.fault_count > 100:
            raise ConfigurationError("The per-route fault count must be between 1 and 100.")
        if arguments.listen_port < 1 or arguments.listen_port > 65535:
            raise ConfigurationError("The listen port is invalid.")
        try:
            listen_address = ipaddress.ip_address(arguments.listen_host)
        except ValueError as error:
            raise ConfigurationError("The listen host must be an explicit private or loopback IP address.") from error
        private_networks = (
            ipaddress.ip_network("10.0.0.0/8"),
            ipaddress.ip_network("172.16.0.0/12"),
            ipaddress.ip_network("192.168.0.0/16"),
            ipaddress.ip_network("fc00::/7"),
        )
        if not listen_address.is_loopback and not any(
            listen_address.version == network.version and listen_address in network
            for network in private_networks
        ):
            raise ConfigurationError("The listen host must be an explicit private or loopback IP address.")
        if arguments.upstream_timeout_seconds <= 0 or arguments.upstream_timeout_seconds > 300:
            raise ConfigurationError("The upstream timeout must be between 0 and 300 seconds.")

        certificate = Path(arguments.server_certificate)
        private_key = Path(arguments.server_private_key)
        upstream_ca = Path(arguments.upstream_ca) if arguments.upstream_ca else None
        _require_regular_file(certificate, "server certificate")
        _require_regular_file(private_key, "server private key", private=True)
        if upstream_ca is not None:
            _require_regular_file(upstream_ca, "upstream CA bundle")

        upstream = urlsplit(arguments.upstream)
        if (
            upstream.scheme != "https"
            or not upstream.hostname
            or upstream.username is not None
            or upstream.password is not None
            or upstream.query
            or upstream.fragment
            or upstream.path not in ("", "/")
        ):
            raise ConfigurationError("The upstream must be one credential-free HTTPS authority.")
        try:
            upstream_port = upstream.port
        except ValueError as error:
            raise ConfigurationError("The upstream port is invalid.") from error
        if upstream_port is not None and (upstream_port < 1 or upstream_port > 65535):
            raise ConfigurationError("The upstream port is invalid.")

        return cls(
            listen_host=arguments.listen_host,
            listen_port=arguments.listen_port,
            server_certificate=certificate,
            server_private_key=private_key,
            upstream=upstream,
            upstream_ca=upstream_ca,
            upstream_timeout_seconds=arguments.upstream_timeout_seconds,
            metrics_file=Path(arguments.metrics_file),
            routes=routes,
            fault_count=arguments.fault_count,
        )


def _require_regular_file(path: Path, label: str, *, private: bool = False) -> None:
    try:
        metadata = path.lstat()
    except OSError as error:
        raise ConfigurationError(f"The {label} is unavailable.") from error
    if not stat.S_ISREG(metadata.st_mode) or path.is_symlink():
        raise ConfigurationError(f"The {label} is unsafe.")
    if private and stat.S_IMODE(metadata.st_mode) & 0o077:
        raise ConfigurationError(f"The {label} must not be group- or world-accessible.")


@dataclass
class ProxyRuntime:
    configuration: ProxyConfiguration
    fault_plan: FaultPlan
    counters: SanitizedCounters
    upstream_tls: ssl.SSLContext


class FaultProxyHandler(http.server.BaseHTTPRequestHandler):
    protocol_version = "HTTP/1.1"
    server_version = ""
    sys_version = ""

    def do_GET(self) -> None:  # noqa: N802
        self._proxy()

    def do_POST(self) -> None:  # noqa: N802
        self._proxy()

    def do_PUT(self) -> None:  # noqa: N802
        self._proxy()

    def do_DELETE(self) -> None:  # noqa: N802
        self._proxy()

    def do_HEAD(self) -> None:  # noqa: N802
        self._proxy()

    def log_message(self, _format: str, *_arguments: object) -> None:
        return

    @property
    def runtime(self) -> ProxyRuntime:
        runtime = getattr(self.server, "runtime", None)
        if not isinstance(runtime, ProxyRuntime):
            raise RuntimeError("Fault proxy runtime is unavailable.")
        return runtime

    def _proxy(self) -> None:
        split_path = urlsplit(self.path)
        if split_path.scheme or split_path.netloc or not split_path.path.startswith("/"):
            self._send_empty(400)
            return
        route = classify_route(self.command, split_path.path) if not split_path.query and not split_path.fragment else None
        try:
            self.runtime.counters.record(route, "received")
        except MetricsError:
            self._send_empty(503)
            return

        body = self._read_request_body()
        if body is None:
            return
        upstream = self.runtime.configuration.upstream
        connection = http.client.HTTPSConnection(
            upstream.hostname,
            upstream.port or 443,
            timeout=self.runtime.configuration.upstream_timeout_seconds,
            context=self.runtime.upstream_tls,
        )
        response: http.client.HTTPResponse | None = None
        response_body: bytes | None = None
        try:
            connection.request(
                self.command,
                self.path,
                body=body,
                headers=self._upstream_headers(),
            )
            response = connection.getresponse()
            declared_length = response.length
            response_body = response.read(MAXIMUM_RESPONSE_BODY_BYTES + 1)
            if declared_length is not None and len(response_body) != declared_length:
                raise http.client.IncompleteRead(
                    response_body,
                    max(0, declared_length - len(response_body)),
                )
        except (OSError, ssl.SSLError, http.client.HTTPException):
            connection.close()
            self._send_empty(502)
            return

        assert response is not None
        assert response_body is not None
        if len(response_body) > MAXIMUM_RESPONSE_BODY_BYTES:
            response.close()
            connection.close()
            self._send_empty(502)
            return
        try:
            self.runtime.counters.record(route, "upstreamResponses")
        except MetricsError:
            self._forward_response(response, response_body, route)
            connection.close()
            return

        should_inject = 200 <= response.status < 300 and self.runtime.fault_plan.claim(route)
        if should_inject:
            try:
                self.runtime.counters.record(route, "faultsInjected")
            except MetricsError:
                self._forward_response(response, response_body, route)
                connection.close()
            else:
                response.close()
                connection.close()
                self._abort_client_connection()
            return

        self._forward_response(response, response_body, route)
        connection.close()

    def _read_request_body(self) -> bytes | None:
        transfer_encoding = self.headers.get("Transfer-Encoding")
        if transfer_encoding and transfer_encoding.lower() != "identity":
            self._send_empty(400)
            return None
        raw_length = self.headers.get("Content-Length", "0")
        try:
            length = int(raw_length, 10)
        except ValueError:
            self._send_empty(400)
            return None
        if length < 0 or length > MAXIMUM_REQUEST_BODY_BYTES:
            self._send_empty(413)
            return None
        body = self.rfile.read(length)
        if len(body) != length:
            self.close_connection = True
            return None
        return body

    def _upstream_headers(self) -> dict[str, str]:
        return {
            name: value
            for name, value in self.headers.items()
            if name.lower() not in HOP_BY_HOP_HEADERS and name.lower() not in {"host", "content-length"}
        }

    def _forward_response(
        self,
        response: http.client.HTTPResponse,
        body: bytes,
        route: FaultRoute | None,
    ) -> None:
        try:
            self.send_response_only(response.status, response.reason)
            for name, value in response.getheaders():
                if name.lower() not in HOP_BY_HOP_HEADERS and name.lower() != "content-length":
                    self.send_header(name, value)
            self.send_header("Content-Length", str(len(body)))
            self.send_header("Connection", "close")
            self.end_headers()
            if self.command != "HEAD":
                self.wfile.write(body)
            self.wfile.flush()
            self.runtime.counters.record(route, "responsesForwarded")
        except (BrokenPipeError, ConnectionResetError, OSError, MetricsError):
            pass
        finally:
            response.close()
            self.close_connection = True

    def _send_empty(self, status: int) -> None:
        try:
            self.send_response_only(status)
            self.send_header("Content-Length", "0")
            self.send_header("Connection", "close")
            self.end_headers()
        except OSError:
            pass
        self.close_connection = True

    def _abort_client_connection(self) -> None:
        self.close_connection = True
        try:
            self.connection.setsockopt(socket.SOL_SOCKET, socket.SO_LINGER, struct.pack("ii", 1, 0))
            self.connection.shutdown(socket.SHUT_RDWR)
        except OSError:
            pass
        try:
            self.connection.close()
        except OSError:
            pass


class FaultProxyServer(http.server.ThreadingHTTPServer):
    daemon_threads = True
    allow_reuse_address = True

    def __init__(
        self,
        address: tuple[str, int],
        runtime: ProxyRuntime,
        server_tls: ssl.SSLContext,
    ) -> None:
        super().__init__(address, FaultProxyHandler, bind_and_activate=False)
        self.runtime = runtime
        self.server_bind()
        self.server_activate()
        self.socket = server_tls.wrap_socket(self.socket, server_side=True)

    def handle_error(self, _request: object, _client_address: object) -> None:
        return


def build_server(configuration: ProxyConfiguration) -> FaultProxyServer:
    server_tls = ssl.SSLContext(ssl.PROTOCOL_TLS_SERVER)
    server_tls.minimum_version = ssl.TLSVersion.TLSv1_2
    server_tls.load_cert_chain(configuration.server_certificate, configuration.server_private_key)

    upstream_tls = ssl.create_default_context(
        cafile=str(configuration.upstream_ca) if configuration.upstream_ca is not None else None,
    )
    upstream_tls.minimum_version = ssl.TLSVersion.TLSv1_2
    upstream_tls.check_hostname = True
    upstream_tls.verify_mode = ssl.CERT_REQUIRED
    runtime = ProxyRuntime(
        configuration=configuration,
        fault_plan=FaultPlan(configuration.routes, configuration.fault_count),
        counters=SanitizedCounters(configuration.metrics_file),
        upstream_tls=upstream_tls,
    )
    return FaultProxyServer((configuration.listen_host, configuration.listen_port), runtime, server_tls)


def argument_parser() -> argparse.ArgumentParser:
    parser = argparse.ArgumentParser(description="Hoddmímir Phase-7 deterministic PVE fault proxy")
    parser.add_argument("--listen-host", default="127.0.0.1")
    parser.add_argument("--listen-port", type=int, default=18443)
    parser.add_argument("--server-certificate", required=True)
    parser.add_argument("--server-private-key", required=True)
    parser.add_argument("--upstream", required=True)
    parser.add_argument("--upstream-ca")
    parser.add_argument("--upstream-timeout-seconds", type=float, default=65.0)
    parser.add_argument("--metrics-file", required=True)
    parser.add_argument("--fault-route", action="append", choices=[route.value for route in FaultRoute])
    parser.add_argument("--fault-count", type=int, default=1)
    parser.add_argument("--activation-ack", required=True)
    return parser


def main() -> int:
    try:
        configuration = ProxyConfiguration.from_arguments(argument_parser().parse_args())
        server = build_server(configuration)
    except (ConfigurationError, MetricsError, OSError, ssl.SSLError, ValueError) as error:
        print(f"fault proxy refused to start: {error}", file=os.sys.stderr)
        return 2
    try:
        print("fault proxy armed; only sanitized counters are persisted", flush=True)
        server.serve_forever(poll_interval=0.2)
    except KeyboardInterrupt:
        pass
    finally:
        server.server_close()
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
