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
import select
import signal
import socket
import ssl
import stat
import struct
import tempfile
import threading
import time
from dataclasses import dataclass
from enum import Enum
from pathlib import Path
from urllib.parse import SplitResult, urlsplit

ACTIVATION_ACK = "INJECT_AFTER_VERIFIED_UPSTREAM_RESPONSE"
HOLD_ACTIVATION_ACK = "HOLD_AFTER_VERIFIED_UPSTREAM_RESPONSE"
HOLD_RELEASE_DOCUMENT = b"RELEASE\n"
HOLD_REQUIRED_UID = 0
MINIMUM_HOLD_SECONDS = 0.1
MAXIMUM_HOLD_SECONDS = 120.0
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


class HoldControlError(RuntimeError):
    """The deterministic response hold could not be controlled safely."""


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
    FIELDS = (
        "received",
        "upstreamResponses",
        "responsesForwarded",
        "faultsInjected",
        "upstream_complete",
        "hold_entered",
        "client_gone",
        "released",
        "fault",
    )

    def __init__(self, destination: Path) -> None:
        self._destination = destination
        self._lock = threading.Lock()
        self._counters: dict[str, dict[str, int]] = {}
        self._validate_destination()
        self._persist_locked()

    def record(self, route: FaultRoute | None, field: str) -> None:
        self.record_many(route, (field,))

    def record_many(self, route: FaultRoute | None, fields: tuple[str, ...]) -> None:
        if not fields or any(field not in self.FIELDS for field in fields):
            raise MetricsError("Unknown sanitized counter field.")
        label = route.counter_label if route is not None else "OTHER /other"
        with self._lock:
            values = self._counters.setdefault(label, {name: 0 for name in self.FIELDS})
            for field in fields:
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
            directory_descriptor = os.open(
                self._destination.parent,
                os.O_RDONLY | getattr(os, "O_DIRECTORY", 0) | getattr(os, "O_CLOEXEC", 0),
            )
            try:
                os.fsync(directory_descriptor)
            finally:
                os.close(directory_descriptor)
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
    hold_routes: tuple[FaultRoute, ...] = ()
    hold_max_seconds: float = 30.0
    hold_control_directory: Path | None = None

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

        hold_routes = tuple(dict.fromkeys(FaultRoute(value) for value in (arguments.hold_route or ())))
        hold_directory = Path(arguments.hold_control_directory) if arguments.hold_control_directory else None
        if hold_routes:
            if len(hold_routes) != 1:
                raise ConfigurationError("Select exactly one response-hold route for the one-shot harness.")
            if arguments.hold_activation_ack != HOLD_ACTIVATION_ACK:
                raise ConfigurationError("The separate response-hold acknowledgement is missing.")
            if hold_directory is None:
                raise ConfigurationError("The response-hold control directory is required.")
            if arguments.hold_count != 1:
                raise ConfigurationError("The response hold is one-shot; --hold-count must be exactly 1.")
            if not MINIMUM_HOLD_SECONDS <= arguments.hold_max_seconds <= MAXIMUM_HOLD_SECONDS:
                raise ConfigurationError("The response hold must be between 0.1 and 120 seconds.")
            _require_root_control_directory(hold_directory)
        elif arguments.hold_activation_ack is not None or hold_directory is not None or arguments.hold_count != 1:
            raise ConfigurationError("Response-hold options require one exact hold route.")

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
            hold_routes=hold_routes,
            hold_max_seconds=arguments.hold_max_seconds,
            hold_control_directory=hold_directory,
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


def _require_root_control_directory(path: Path) -> None:
    try:
        metadata = path.lstat()
    except OSError as error:
        raise ConfigurationError("The response-hold control directory is unavailable.") from error
    if (
        not stat.S_ISDIR(metadata.st_mode)
        or path.is_symlink()
        or os.geteuid() != HOLD_REQUIRED_UID
        or metadata.st_uid != HOLD_REQUIRED_UID
        or stat.S_IMODE(metadata.st_mode) != 0o700
    ):
        raise ConfigurationError("The response-hold control directory must be root-owned mode 0700.")
    try:
        if any(path.iterdir()):
            raise ConfigurationError("The response-hold control directory contains stale artifacts.")
    except OSError as error:
        raise ConfigurationError("The response-hold control directory cannot be enumerated safely.") from error


class HoldOutcome(str, Enum):
    RELEASED = "released"
    CLIENT_GONE = "client_gone"
    FAULT = "fault"


class HoldLatch:
    """One bounded, sanitized post-upstream response gate."""

    def __init__(
        self,
        directory: Path,
        maximum_seconds: float,
        counters: SanitizedCounters,
        shutdown: threading.Event,
    ) -> None:
        _require_root_control_directory(directory)
        directory_stat = directory.lstat()
        self._directory = directory
        self._directory_identity = (directory_stat.st_dev, directory_stat.st_ino)
        try:
            self._directory_fd = os.open(
                directory,
                os.O_RDONLY | getattr(os, "O_DIRECTORY", 0) | getattr(os, "O_CLOEXEC", 0) | getattr(os, "O_NOFOLLOW", 0),
            )
        except OSError as error:
            raise HoldControlError("The response-hold control directory could not be bound.") from error
        opened = os.fstat(self._directory_fd)
        if (opened.st_dev, opened.st_ino) != self._directory_identity:
            os.close(self._directory_fd)
            raise HoldControlError("The response-hold control directory changed during binding.")
        self._closed = False
        self._state_descriptor: int | None = None
        self._state_identity: tuple[int, int] | None = None
        self._maximum_seconds = maximum_seconds
        self._counters = counters
        self._shutdown = shutdown
        self._hold_lock = threading.Lock()
        self._sequence_lock = threading.Lock()
        self._sequence = 0

    def hold(self, route: FaultRoute, client: socket.socket) -> HoldOutcome:
        deadline = time.monotonic() + self._maximum_seconds
        self._counters.record(route, "upstream_complete")
        remaining = max(0.0, deadline - time.monotonic())
        if not self._hold_lock.acquire(timeout=remaining):
            self._record_fault(route)
            return HoldOutcome.FAULT
        try:
            sequence = self._next_sequence()
            if self._entry_exists("release"):
                self._record_fault(route, sequence)
                return HoldOutcome.FAULT
            self._write_state(route, "hold_entered", sequence)
            self._counters.record(route, "hold_entered")
            client_gone = False
            while True:
                if self._shutdown.is_set():
                    self._record_fault(route, sequence)
                    return HoldOutcome.FAULT
                if not client_gone and self._client_is_gone(client):
                    client_gone = True
                    self._write_state(route, "client_gone", sequence)
                    self._counters.record(route, "client_gone")
                if self._entry_exists("release"):
                    self._consume_release()
                    self._write_state(route, "released", sequence)
                    self._counters.record(route, "released")
                    return HoldOutcome.CLIENT_GONE if client_gone else HoldOutcome.RELEASED
                remaining = deadline - time.monotonic()
                if remaining <= 0:
                    self._record_fault(route, sequence)
                    return HoldOutcome.FAULT
                self._shutdown.wait(min(0.02, remaining))
        finally:
            self._hold_lock.release()

    def close(self) -> None:
        self._shutdown.set()
        acquired = self._hold_lock.acquire(timeout=self._maximum_seconds + 1.0)
        if not acquired:
            return
        try:
            # Signal/control evidence is intentionally retained.  Deleting an
            # externally replaceable directory entry cannot be made inode-
            # conditional with portable stdlib primitives; retaining it is the
            # only fail-closed response to a concurrent swap.
            if not self._closed:
                if self._state_descriptor is not None:
                    os.close(self._state_descriptor)
                    self._state_descriptor = None
                os.close(self._directory_fd)
                self._closed = True
        finally:
            self._hold_lock.release()

    def _record_fault(self, route: FaultRoute, sequence: int | None = None) -> None:
        if sequence is not None:
            self._write_state(route, "fault", sequence)
        self._counters.record(route, "fault")

    def _next_sequence(self) -> int:
        with self._sequence_lock:
            self._sequence += 1
            return self._sequence

    def _write_state(self, route: FaultRoute, state: str, sequence: int) -> None:
        _require_active_control_directory(self._directory, self._directory_identity)
        document = {
            "schemaVersion": 1,
            "route": route.counter_label,
            "sequence": sequence,
            "state": state,
        }
        payload = (json.dumps(document, sort_keys=True, separators=(",", ":")) + "\n").encode("utf-8")
        descriptor = self._bound_state_descriptor()
        try:
            self._require_bound_state_entry(descriptor, expected_size=None)
            os.ftruncate(descriptor, 0)
            os.lseek(descriptor, 0, os.SEEK_SET)
            offset = 0
            while offset < len(payload):
                written = os.write(descriptor, payload[offset:])
                if written <= 0:
                    raise OSError("short state write")
                offset += written
            os.fsync(descriptor)
            self._require_bound_state_entry(descriptor, expected_size=len(payload))
            self._fsync_directory()
        except (OSError, HoldControlError) as error:
            raise HoldControlError("The sanitized response-hold state could not be persisted.") from error

    def _bound_state_descriptor(self) -> int:
        if self._state_descriptor is not None:
            return self._state_descriptor
        flags = (
            os.O_RDWR
            | os.O_CREAT
            | os.O_EXCL
            | getattr(os, "O_CLOEXEC", 0)
            | getattr(os, "O_NOFOLLOW", 0)
        )
        try:
            descriptor = os.open("state.json", flags, 0o600, dir_fd=self._directory_fd)
        except OSError as error:
            raise HoldControlError("The sanitized response-hold state could not be bound.") from error
        metadata = os.fstat(descriptor)
        try:
            _require_control_metadata(metadata, expected_size=0)
        except HoldControlError:
            os.close(descriptor)
            raise
        self._state_descriptor = descriptor
        self._state_identity = (metadata.st_dev, metadata.st_ino)
        return descriptor

    def _require_bound_state_entry(self, descriptor: int, expected_size: int | None) -> None:
        bound = os.fstat(descriptor)
        _require_control_metadata(bound, expected_size=expected_size)
        current = self._entry_stat("state.json")
        if current is None:
            raise HoldControlError("The sanitized response-hold state directory entry disappeared.")
        _require_control_metadata(current, expected_size=expected_size)
        identity = (bound.st_dev, bound.st_ino)
        if self._state_identity != identity or (current.st_dev, current.st_ino) != identity:
            raise HoldControlError("The sanitized response-hold state directory entry changed during use.")

    def _consume_release(self) -> None:
        _require_active_control_directory(self._directory, self._directory_identity)
        flags = os.O_RDONLY | getattr(os, "O_CLOEXEC", 0) | getattr(os, "O_NOFOLLOW", 0)
        try:
            descriptor = os.open("release", flags, dir_fd=self._directory_fd)
            try:
                before = os.fstat(descriptor)
                if (
                    not stat.S_ISREG(before.st_mode)
                    or stat.S_IMODE(before.st_mode) != 0o600
                    or before.st_uid != HOLD_REQUIRED_UID
                    or before.st_nlink != 1
                    or before.st_size != len(HOLD_RELEASE_DOCUMENT)
                ):
                    raise HoldControlError("The response-hold release control is unsafe.")
                document = os.read(descriptor, len(HOLD_RELEASE_DOCUMENT) + 1)
                after = os.fstat(descriptor)
                if (
                    (before.st_dev, before.st_ino, before.st_size, before.st_mtime_ns, before.st_ctime_ns)
                    != (after.st_dev, after.st_ino, after.st_size, after.st_mtime_ns, after.st_ctime_ns)
                ):
                    raise HoldControlError("The response-hold release control changed during use.")
                current = os.stat("release", dir_fd=self._directory_fd, follow_symlinks=False)
                if (current.st_dev, current.st_ino) != (before.st_dev, before.st_ino):
                    raise HoldControlError("The response-hold release directory entry changed during use.")
            finally:
                os.close(descriptor)
        except OSError as error:
            raise HoldControlError("The response-hold release control is unsafe.") from error
        if document != HOLD_RELEASE_DOCUMENT:
            raise HoldControlError("The response-hold release control is invalid.")

    @staticmethod
    def _client_is_gone(client: socket.socket) -> bool:
        try:
            readable, _, exceptional = select.select((client,), (), (client,), 0)
            if exceptional:
                return True
            if not readable:
                return False
            previous_timeout = client.gettimeout()
            try:
                client.settimeout(0.0)
                return client.recv(1) == b""
            except (BlockingIOError, ssl.SSLWantReadError, ssl.SSLWantWriteError):
                return False
            finally:
                try:
                    client.settimeout(previous_timeout)
                except OSError:
                    pass
        except (OSError, ssl.SSLError, ValueError):
            return True

    def _fsync_directory(self) -> None:
        metadata = os.fstat(self._directory_fd)
        if (metadata.st_dev, metadata.st_ino) != self._directory_identity:
            raise HoldControlError("The response-hold control directory identity changed.")
        os.fsync(self._directory_fd)

    def _entry_stat(self, name: str) -> os.stat_result | None:
        try:
            return os.stat(name, dir_fd=self._directory_fd, follow_symlinks=False)
        except FileNotFoundError:
            return None
        except OSError as error:
            raise HoldControlError("The response-hold control entry is unavailable.") from error

    def _entry_exists(self, name: str) -> bool:
        return self._entry_stat(name) is not None


def _require_active_control_directory(path: Path, expected_identity: tuple[int, int]) -> None:
    try:
        metadata = path.lstat()
    except OSError as error:
        raise HoldControlError("The response-hold control directory disappeared.") from error
    if (
        not stat.S_ISDIR(metadata.st_mode)
        or path.is_symlink()
        or os.geteuid() != HOLD_REQUIRED_UID
        or metadata.st_uid != HOLD_REQUIRED_UID
        or stat.S_IMODE(metadata.st_mode) != 0o700
        or (metadata.st_dev, metadata.st_ino) != expected_identity
    ):
        raise HoldControlError("The response-hold control directory changed unsafely.")


def _require_control_metadata(metadata: os.stat_result, expected_size: int | None) -> None:
    if (
        not stat.S_ISREG(metadata.st_mode)
        or metadata.st_uid != HOLD_REQUIRED_UID
        or stat.S_IMODE(metadata.st_mode) != 0o600
        or metadata.st_nlink != 1
        or (expected_size is not None and metadata.st_size != expected_size)
        or metadata.st_size > 4096
    ):
        raise HoldControlError("The response-hold control file is unsafe.")


@dataclass
class ProxyRuntime:
    configuration: ProxyConfiguration
    fault_plan: FaultPlan
    counters: SanitizedCounters
    upstream_tls: ssl.SSLContext
    hold_plan: FaultPlan | None = None
    hold_latch: HoldLatch | None = None
    shutdown: threading.Event | None = None


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

        successful = 200 <= response.status < 300
        should_hold = successful and self.runtime.hold_plan is not None and self.runtime.hold_plan.claim(route)
        if should_hold:
            latch = self.runtime.hold_latch
            if latch is None or route is None:
                response.close()
                connection.close()
                self._abort_client_connection()
                return
            try:
                hold_outcome = latch.hold(route, self.connection)
            except (HoldControlError, MetricsError, OSError):
                try:
                    self.runtime.counters.record(route, "fault")
                except MetricsError:
                    pass
                response.close()
                connection.close()
                self._abort_client_connection()
                return
            if hold_outcome is not HoldOutcome.RELEASED:
                response.close()
                connection.close()
                self._abort_client_connection()
                return

        should_inject = successful and self.runtime.fault_plan.claim(route)
        if should_inject:
            try:
                self.runtime.counters.record_many(route, ("faultsInjected", "fault"))
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
    counters = SanitizedCounters(configuration.metrics_file)
    shutdown = threading.Event()
    hold_plan = FaultPlan(configuration.hold_routes, 1) if configuration.hold_routes else None
    hold_latch = (
        HoldLatch(configuration.hold_control_directory, configuration.hold_max_seconds, counters, shutdown)
        if configuration.hold_routes and configuration.hold_control_directory is not None
        else None
    )
    runtime = ProxyRuntime(
        configuration=configuration,
        fault_plan=FaultPlan(configuration.routes, configuration.fault_count),
        counters=counters,
        upstream_tls=upstream_tls,
        hold_plan=hold_plan,
        hold_latch=hold_latch,
        shutdown=shutdown,
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
    parser.add_argument("--hold-route", action="append", choices=[route.value for route in FaultRoute])
    parser.add_argument("--hold-count", type=int, default=1, help="one-shot hold count; must be exactly 1")
    parser.add_argument("--hold-max-seconds", type=float, default=30.0)
    parser.add_argument("--hold-control-directory")
    parser.add_argument("--hold-activation-ack")
    return parser


def main() -> int:
    try:
        configuration = ProxyConfiguration.from_arguments(argument_parser().parse_args())
        server = build_server(configuration)
    except (ConfigurationError, MetricsError, OSError, ssl.SSLError, ValueError) as error:
        print(f"fault proxy refused to start: {error}", file=os.sys.stderr)
        return 2
    previous_handlers: dict[int, object] = {}

    def request_shutdown(_signum: int, _frame: object) -> None:
        if server.runtime.shutdown is not None:
            server.runtime.shutdown.set()
        raise KeyboardInterrupt

    try:
        for signum in (signal.SIGINT, signal.SIGTERM):
            previous_handlers[signum] = signal.getsignal(signum)
            signal.signal(signum, request_shutdown)
        print("fault proxy armed; only sanitized counters are persisted", flush=True)
        server.serve_forever(poll_interval=0.2)
    except KeyboardInterrupt:
        pass
    finally:
        if server.runtime.shutdown is not None:
            server.runtime.shutdown.set()
        if server.runtime.hold_latch is not None:
            server.runtime.hold_latch.close()
        server.server_close()
        for signum, handler in previous_handlers.items():
            signal.signal(signum, handler)
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
