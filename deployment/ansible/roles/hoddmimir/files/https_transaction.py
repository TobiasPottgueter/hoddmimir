#!/usr/bin/env python3
"""Issue or renew the public certificate and reload host Caddy transactionally."""

from __future__ import annotations

import argparse
import fcntl
import grp
import json
import os
import pwd
import re
import shutil
import ssl
import stat
import subprocess
import sys
import tempfile
import urllib.error
import urllib.parse
import urllib.request
from contextlib import contextmanager
from dataclasses import dataclass
from pathlib import Path
from typing import Callable, Mapping, Sequence

DOMAIN_PATTERN = re.compile(r"\A(?=.{1,253}\Z)(?!.*\.\.)(?!-)[a-z0-9-]+(?:\.[a-z0-9-]+)+\Z")
EMAIL_PATTERN = re.compile(r"\A[A-Za-z0-9.!#$%&'*+/=?^_`{|}~-]+@[A-Za-z0-9-]+(?:\.[A-Za-z0-9-]+)+\Z")
MAXIMUM_TOKEN_BYTES = 4096


class HttpsTransactionError(RuntimeError):
    """A safe HTTPS transaction or its recovery failed."""


@dataclass(frozen=True)
class FileSnapshot:
    path: Path
    existed: bool
    contents: bytes | None
    mode: int | None
    uid: int | None
    gid: int | None


@dataclass(frozen=True)
class TransactionResult:
    changed: bool
    certificate_renewed: bool


@contextmanager
def transaction_lock(path: Path):
    if not path.is_absolute():
        raise HttpsTransactionError("The HTTPS transaction lock path is invalid.")

    descriptor: int | None = None
    try:
        flags = os.O_RDWR | os.O_CREAT | os.O_CLOEXEC
        if hasattr(os, "O_NOFOLLOW"):
            flags |= os.O_NOFOLLOW
        descriptor = os.open(path, flags, 0o600)
        metadata = os.fstat(descriptor)
        if not stat.S_ISREG(metadata.st_mode):
            raise HttpsTransactionError("The HTTPS transaction lock file is invalid.")
        os.fchmod(descriptor, 0o600)
        try:
            fcntl.flock(descriptor, fcntl.LOCK_EX | fcntl.LOCK_NB)
        except BlockingIOError:
            raise HttpsTransactionError("Another HTTPS transaction is already running.") from None
    except HttpsTransactionError:
        if descriptor is not None:
            os.close(descriptor)
        raise
    except OSError:
        if descriptor is not None:
            os.close(descriptor)
        raise HttpsTransactionError("The HTTPS transaction lock is unavailable.") from None

    assert descriptor is not None
    try:
        yield
    finally:
        try:
            fcntl.flock(descriptor, fcntl.LOCK_UN)
        except OSError:
            pass
        try:
            os.close(descriptor)
        except OSError:
            pass


def _safe_command(
    command: Sequence[str],
    *,
    error_message: str,
    environment: Mapping[str, str] | None = None,
    run_as_uid: int | None = None,
    run_as_gid: int | None = None,
) -> subprocess.CompletedProcess[bytes]:
    if (run_as_uid is None) != (run_as_gid is None):
        raise HttpsTransactionError(error_message)

    def drop_privileges() -> None:
        assert run_as_uid is not None
        assert run_as_gid is not None
        os.setgroups([])
        os.setgid(run_as_gid)
        os.setuid(run_as_uid)
        os.umask(0o077)

    def retain_test_identity() -> None:
        os.umask(0o077)

    privilege_setup = None
    if run_as_uid is not None:
        if os.geteuid() == 0:
            privilege_setup = drop_privileges
        elif run_as_uid == os.geteuid() and run_as_gid == os.getegid():
            privilege_setup = retain_test_identity
        else:
            raise HttpsTransactionError(error_message)

    try:
        result = subprocess.run(
            list(command),
            check=False,
            stdin=subprocess.DEVNULL,
            stdout=subprocess.PIPE,
            stderr=subprocess.PIPE,
            env=dict(environment) if environment is not None else None,
            preexec_fn=privilege_setup,
        )
    except OSError:
        raise HttpsTransactionError(error_message) from None
    if result.returncode != 0:
        raise HttpsTransactionError(error_message)

    return result


def validate_token_file(path: Path, *, expected_uid: int = 0, expected_gid: int) -> None:
    try:
        metadata = path.lstat()
    except OSError:
        raise HttpsTransactionError("The Hetzner DNS API token file is unavailable.") from None
    if (
        not stat.S_ISREG(metadata.st_mode)
        or path.is_symlink()
        or metadata.st_uid != expected_uid
        or metadata.st_gid != expected_gid
        or stat.S_IMODE(metadata.st_mode) != 0o440
        or metadata.st_size < 32
        or metadata.st_size > MAXIMUM_TOKEN_BYTES
    ):
        raise HttpsTransactionError("The Hetzner DNS API token file is unsafe.")

    try:
        descriptor = os.open(path, os.O_RDONLY | os.O_CLOEXEC | getattr(os, "O_NOFOLLOW", 0))
        try:
            value = os.read(descriptor, MAXIMUM_TOKEN_BYTES + 1)
        finally:
            os.close(descriptor)
    except OSError:
        raise HttpsTransactionError("The Hetzner DNS API token file is unavailable.") from None
    stripped = value.rstrip(b"\r\n")
    if (
        not 32 <= len(stripped) <= 256
        or len(value) > MAXIMUM_TOKEN_BYTES
        or re.fullmatch(rb"[A-Za-z0-9_-]+", stripped) is None
        or value not in (stripped, stripped + b"\n", stripped + b"\r\n")
    ):
        raise HttpsTransactionError("The Hetzner DNS API token file is invalid.")


def _validate_inputs(arguments: argparse.Namespace) -> None:
    if DOMAIN_PATTERN.fullmatch(arguments.domain) is None or arguments.domain.endswith(".invalid"):
        raise HttpsTransactionError("The public HTTPS domain is invalid.")
    if EMAIL_PATTERN.fullmatch(arguments.email) is None or arguments.email.endswith("@example.invalid"):
        raise HttpsTransactionError("The ACME account email address is invalid.")
    try:
        health_url = urllib.parse.urlsplit(arguments.health_url)
    except ValueError:
        raise HttpsTransactionError("The public HTTPS health URL is invalid.") from None
    if (
        health_url.scheme != "https"
        or health_url.hostname != arguments.domain
        or health_url.port not in (None, 443)
        or health_url.path != "/api/health"
        or health_url.query
        or health_url.fragment
        or health_url.username is not None
        or health_url.password is not None
    ):
        raise HttpsTransactionError("The public HTTPS health URL is invalid.")
    if not 1 <= arguments.renew_before_days <= 60:
        raise HttpsTransactionError("The certificate renewal window is invalid.")

    absolute_paths = (
        arguments.token_file,
        arguments.candidate_caddyfile,
        arguments.caddyfile,
        arguments.certificate_file,
        arguments.certificate_key_file,
        arguments.lego_state_directory,
        arguments.caddy_executable,
        arguments.lego_executable,
        arguments.openssl_executable,
        arguments.rc_service_executable,
        arguments.lock_file,
    )
    if any(not Path(value).is_absolute() for value in absolute_paths):
        raise HttpsTransactionError("Every HTTPS transaction path must be absolute.")
    if arguments.certificate_file == arguments.certificate_key_file:
        raise HttpsTransactionError("The certificate and private-key paths must differ.")
    if arguments.candidate_caddyfile == arguments.caddyfile:
        raise HttpsTransactionError("The Caddy candidate must be separate from the installed configuration.")
    if not arguments.caddy_service or not arguments.caddy_service.replace("-", "").isalnum():
        raise HttpsTransactionError("The Caddy service name is invalid.")
    for identity in (arguments.acme_user, arguments.acme_group, arguments.caddy_group):
        if not identity or re.fullmatch(r"[a-z_][a-z0-9_-]{0,31}", identity) is None:
            raise HttpsTransactionError("An HTTPS runtime identity is invalid.")


def _validate_candidate_caddyfile(path: Path, *, expected_uid: int, expected_gid: int) -> None:
    try:
        metadata = path.lstat()
    except OSError:
        raise HttpsTransactionError("The candidate Caddy configuration is unavailable.") from None
    if (
        not stat.S_ISREG(metadata.st_mode)
        or path.is_symlink()
        or metadata.st_uid != expected_uid
        or metadata.st_gid != expected_gid
        or stat.S_IMODE(metadata.st_mode) != 0o640
        or metadata.st_size == 0
        or metadata.st_size > 1024 * 1024
    ):
        raise HttpsTransactionError("The candidate Caddy configuration is unsafe.")


def _assert_safe_state_tree(path: Path, *, expected_uid: int, expected_gid: int) -> None:
    try:
        root_metadata = path.lstat()
    except OSError:
        raise HttpsTransactionError("The lego state directory is unavailable.") from None
    if (
        not stat.S_ISDIR(root_metadata.st_mode)
        or path.is_symlink()
        or root_metadata.st_uid != expected_uid
        or root_metadata.st_gid != expected_gid
        or stat.S_IMODE(root_metadata.st_mode) != 0o700
    ):
        raise HttpsTransactionError("The lego state directory is unsafe.")

    for directory, directory_names, file_names in os.walk(path, followlinks=False):
        directory_path = Path(directory)
        for name in [*directory_names, *file_names]:
            child = directory_path / name
            try:
                metadata = child.lstat()
            except OSError:
                raise HttpsTransactionError("The lego state directory is unsafe.") from None
            if child.is_symlink() or metadata.st_uid != expected_uid or metadata.st_gid != expected_gid:
                raise HttpsTransactionError("The lego state directory is unsafe.")
            if name in directory_names and not stat.S_ISDIR(metadata.st_mode):
                raise HttpsTransactionError("The lego state directory is unsafe.")
            if name in file_names and not stat.S_ISREG(metadata.st_mode):
                raise HttpsTransactionError("The lego state directory is unsafe.")


def _harden_state_tree(path: Path, *, uid: int, gid: int) -> None:
    for directory, directory_names, file_names in os.walk(path, topdown=False, followlinks=False):
        directory_path = Path(directory)
        for name in file_names:
            child = directory_path / name
            if child.is_symlink() or not child.is_file():
                raise HttpsTransactionError("The staged lego state is unsafe.")
            os.chown(child, uid, gid)
            os.chmod(child, 0o600)
        for name in directory_names:
            child = directory_path / name
            if child.is_symlink() or not child.is_dir():
                raise HttpsTransactionError("The staged lego state is unsafe.")
            os.chown(child, uid, gid)
            os.chmod(child, 0o700)
    os.chown(path, uid, gid)
    os.chmod(path, 0o700)


def _certificate_is_valid(
    certificate: Path,
    private_key: Path,
    domain: str,
    minimum_seconds: int,
    openssl: str,
) -> bool:
    if not certificate.is_file() or certificate.is_symlink() or not private_key.is_file() or private_key.is_symlink():
        return False

    try:
        _safe_command(
            [openssl, "x509", "-in", str(certificate), "-noout", "-checkend", str(minimum_seconds)],
            error_message="The certificate is expired or too close to expiry.",
        )
        names = _safe_command(
            [openssl, "x509", "-in", str(certificate), "-noout", "-ext", "subjectAltName"],
            error_message="The certificate subject alternative names are invalid.",
        ).stdout.decode("ascii", "strict")
        dns_names = {
            match.group(1).rstrip(".").lower()
            for match in re.finditer(r"DNS:([^,\s]+)", names)
        }
        if domain.lower() not in dns_names:
            return False
        certificate_key = _safe_command(
            [openssl, "x509", "-in", str(certificate), "-noout", "-pubkey"],
            error_message="The certificate public key is invalid.",
        ).stdout
        private_key_public = _safe_command(
            [openssl, "pkey", "-in", str(private_key), "-pubout"],
            error_message="The certificate private key is invalid.",
        ).stdout
        return certificate_key == private_key_public
    except (HttpsTransactionError, UnicodeDecodeError):
        return False


def _snapshot(path: Path) -> FileSnapshot:
    try:
        metadata = path.lstat()
    except FileNotFoundError:
        return FileSnapshot(path, False, None, None, None, None)
    except OSError:
        raise HttpsTransactionError("An installed HTTPS file could not be inspected.") from None
    if not stat.S_ISREG(metadata.st_mode) or path.is_symlink():
        raise HttpsTransactionError("An installed HTTPS file is unsafe.")
    try:
        contents = path.read_bytes()
    except OSError:
        raise HttpsTransactionError("An installed HTTPS file could not be read.") from None
    return FileSnapshot(
        path,
        True,
        contents,
        stat.S_IMODE(metadata.st_mode),
        metadata.st_uid,
        metadata.st_gid,
    )


def _has_exact_file_metadata(path: Path, *, mode: int, uid: int, gid: int) -> bool:
    try:
        metadata = path.lstat()
    except OSError:
        return False
    return (
        stat.S_ISREG(metadata.st_mode)
        and not path.is_symlink()
        and stat.S_IMODE(metadata.st_mode) == mode
        and metadata.st_uid == uid
        and metadata.st_gid == gid
    )


def _atomic_write(path: Path, contents: bytes, mode: int, uid: int, gid: int) -> None:
    path.parent.mkdir(parents=True, exist_ok=True)
    descriptor, temporary_name = tempfile.mkstemp(prefix=f".{path.name}.", dir=path.parent)
    temporary = Path(temporary_name)
    try:
        with os.fdopen(descriptor, "wb") as handle:
            handle.write(contents)
            handle.flush()
            os.fsync(handle.fileno())
        os.chown(temporary, uid, gid)
        os.chmod(temporary, mode)
        os.replace(temporary, path)
    except OSError:
        try:
            temporary.unlink(missing_ok=True)
        except OSError:
            pass
        raise HttpsTransactionError("An HTTPS file could not be installed safely.") from None


def _restore(snapshot: FileSnapshot) -> None:
    if snapshot.existed:
        assert snapshot.contents is not None
        assert snapshot.mode is not None
        assert snapshot.uid is not None
        assert snapshot.gid is not None
        _atomic_write(snapshot.path, snapshot.contents, snapshot.mode, snapshot.uid, snapshot.gid)
        return
    try:
        snapshot.path.unlink(missing_ok=True)
    except OSError:
        raise HttpsTransactionError("An HTTPS file could not be removed during recovery.") from None


def _is_running(arguments: argparse.Namespace) -> bool:
    try:
        result = subprocess.run(
            [arguments.rc_service_executable, arguments.caddy_service, "status"],
            check=False,
            stdin=subprocess.DEVNULL,
            stdout=subprocess.DEVNULL,
            stderr=subprocess.DEVNULL,
        )
    except OSError:
        raise HttpsTransactionError("The Caddy service status could not be read.") from None
    return result.returncode == 0


def _validate_caddy(arguments: argparse.Namespace) -> None:
    _safe_command(
        [arguments.caddy_executable, "validate", "--config", arguments.caddyfile, "--adapter", "caddyfile"],
        error_message="Caddy rejected the HTTPS configuration.",
    )


def _activate_caddy(arguments: argparse.Namespace, *, was_running: bool) -> None:
    if was_running:
        _safe_command(
            [arguments.caddy_executable, "reload", "--config", arguments.caddyfile, "--adapter", "caddyfile"],
            error_message="Caddy could not reload the HTTPS configuration.",
        )
        return
    _safe_command(
        [arguments.rc_service_executable, arguments.caddy_service, "start"],
        error_message="Caddy could not start with the HTTPS configuration.",
    )


def _recover_caddy(arguments: argparse.Namespace, *, was_running: bool) -> None:
    if was_running:
        _validate_caddy(arguments)
        _safe_command(
            [arguments.caddy_executable, "reload", "--config", arguments.caddyfile, "--adapter", "caddyfile"],
            error_message="Caddy could not reload the restored HTTPS configuration.",
        )
        return
    try:
        subprocess.run(
            [arguments.rc_service_executable, arguments.caddy_service, "stop"],
            check=False,
            stdin=subprocess.DEVNULL,
            stdout=subprocess.DEVNULL,
            stderr=subprocess.DEVNULL,
        )
    except OSError:
        raise HttpsTransactionError("Caddy could not be stopped during recovery.") from None


def _lego_environment(token_file: Path, state_parent: Path) -> dict[str, str]:
    return {
        "HOME": str(state_parent),
        "PATH": "/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin",
        "HETZNER_API_TOKEN_FILE": str(token_file),
    }


def _check_https_health(url: str) -> None:
    request = urllib.request.Request(
        url,
        headers={"Accept": "application/json", "User-Agent": "hoddmimir-https-transaction/1"},
        method="GET",
    )
    try:
        with urllib.request.urlopen(
            request,
            timeout=15,
            context=ssl.create_default_context(),
        ) as response:
            if response.status != 200:
                raise HttpsTransactionError("The public HTTPS health endpoint returned an unexpected status.")
            if response.geturl() != url:
                raise HttpsTransactionError("The public HTTPS health endpoint redirected unexpectedly.")
            body = response.read(65537)
    except HttpsTransactionError:
        raise
    except (OSError, ssl.SSLError, urllib.error.URLError, ValueError):
        raise HttpsTransactionError("The public HTTPS health request failed with strict certificate verification.") from None
    if len(body) > 65536:
        raise HttpsTransactionError("The public HTTPS health response is too large.")
    try:
        document = json.loads(body.decode("utf-8"))
    except (UnicodeDecodeError, json.JSONDecodeError):
        raise HttpsTransactionError("The public HTTPS health response is invalid.") from None
    if not isinstance(document, dict) or document.get("status") != "ok":
        raise HttpsTransactionError("The public HTTPS health response is not ready.")


def execute(
    arguments: argparse.Namespace,
    *,
    expected_uid: int = 0,
    caddy_gid: int | None = None,
    acme_uid: int | None = None,
    acme_gid: int | None = None,
    health_checker: Callable[[str], None] = _check_https_health,
) -> TransactionResult:
    _validate_inputs(arguments)
    token_file = Path(arguments.token_file)
    candidate_caddyfile = Path(arguments.candidate_caddyfile)
    caddyfile = Path(arguments.caddyfile)
    certificate = Path(arguments.certificate_file)
    private_key = Path(arguments.certificate_key_file)
    state = Path(arguments.lego_state_directory)
    if caddy_gid is None:
        try:
            caddy_gid = grp.getgrnam(arguments.caddy_group).gr_gid
        except KeyError:
            raise HttpsTransactionError("The Caddy runtime group is unavailable.") from None
    if acme_uid is None:
        try:
            acme_uid = pwd.getpwnam(arguments.acme_user).pw_uid
        except KeyError:
            raise HttpsTransactionError("The dedicated ACME runtime user is unavailable.") from None
    if acme_gid is None:
        try:
            acme_gid = grp.getgrnam(arguments.acme_group).gr_gid
        except KeyError:
            raise HttpsTransactionError("The dedicated ACME runtime group is unavailable.") from None

    validate_token_file(token_file, expected_uid=expected_uid, expected_gid=acme_gid)
    _validate_candidate_caddyfile(candidate_caddyfile, expected_uid=expected_uid, expected_gid=caddy_gid)

    state.parent.mkdir(parents=True, exist_ok=True)
    os.chown(state.parent, acme_uid, acme_gid)
    os.chmod(state.parent, 0o700)
    certificate.parent.mkdir(parents=True, exist_ok=True)
    os.chown(certificate.parent, expected_uid, caddy_gid)
    os.chmod(certificate.parent, 0o750)
    if state.exists():
        _assert_safe_state_tree(state, expected_uid=acme_uid, expected_gid=acme_gid)

    minimum_seconds = arguments.renew_before_days * 86400
    candidate_contents = candidate_caddyfile.read_bytes()
    current_contents = caddyfile.read_bytes() if caddyfile.is_file() and not caddyfile.is_symlink() else None
    certificate_current = _certificate_is_valid(
        certificate,
        private_key,
        arguments.domain,
        minimum_seconds,
        arguments.openssl_executable,
    )
    installed_permissions_valid = (
        _has_exact_file_metadata(caddyfile, mode=0o640, uid=expected_uid, gid=caddy_gid)
        and _has_exact_file_metadata(certificate, mode=0o644, uid=expected_uid, gid=caddy_gid)
        and _has_exact_file_metadata(private_key, mode=0o640, uid=expected_uid, gid=caddy_gid)
    )
    caddyfile_changed = current_contents != candidate_contents or not installed_permissions_valid
    if certificate_current and not caddyfile_changed:
        was_running = _is_running(arguments)
        if not was_running:
            try:
                _validate_caddy(arguments)
                _activate_caddy(arguments, was_running=False)
                health_checker(arguments.health_url)
                return TransactionResult(True, False)
            except HttpsTransactionError:
                _recover_caddy(arguments, was_running=False)
                raise
        try:
            health_checker(arguments.health_url)
            return TransactionResult(False, False)
        except HttpsTransactionError:
            _validate_caddy(arguments)
            _activate_caddy(arguments, was_running=True)
            health_checker(arguments.health_url)
            return TransactionResult(True, False)

    was_running = _is_running(arguments)
    snapshots = [_snapshot(path) for path in (caddyfile, certificate, private_key)]
    transaction_directory = Path(tempfile.mkdtemp(prefix=".https-transaction-", dir=state.parent))
    os.chown(transaction_directory, acme_uid, acme_gid)
    os.chmod(transaction_directory, 0o700)
    previous_state = transaction_directory / "previous-lego"
    staged_state = transaction_directory / "candidate-lego"
    state_was_replaced = False
    previous_state_was_moved = False
    certificate_renewed = False

    try:
        candidate_certificate = certificate
        candidate_private_key = private_key
        if not certificate_current:
            if state.exists():
                shutil.copytree(state, staged_state, symlinks=True)
                operation = "renew"
            else:
                staged_state.mkdir(mode=0o700)
                operation = "run"
            _harden_state_tree(staged_state, uid=acme_uid, gid=acme_gid)
            lego_command = [
                arguments.lego_executable,
                "--path",
                str(staged_state),
                "--email",
                arguments.email,
                "--dns",
                "hetzner",
                "--domains",
                arguments.domain,
                "--accept-tos",
                operation,
            ]
            if operation == "renew":
                lego_command.extend(["--days", str(arguments.renew_before_days)])
            _safe_command(
                lego_command,
                error_message="The ACME DNS-01 certificate operation failed.",
                environment=_lego_environment(token_file, state.parent),
                run_as_uid=acme_uid,
                run_as_gid=acme_gid,
            )
            _harden_state_tree(staged_state, uid=acme_uid, gid=acme_gid)
            candidate_certificate = staged_state / "certificates" / f"{arguments.domain}.crt"
            candidate_private_key = staged_state / "certificates" / f"{arguments.domain}.key"
            if not _certificate_is_valid(
                candidate_certificate,
                candidate_private_key,
                arguments.domain,
                minimum_seconds,
                arguments.openssl_executable,
            ):
                raise HttpsTransactionError("The ACME result did not contain a valid matching certificate.")
            certificate_renewed = True

        certificate_contents = candidate_certificate.read_bytes()
        private_key_contents = candidate_private_key.read_bytes()
        _atomic_write(certificate, certificate_contents, 0o644, expected_uid, caddy_gid)
        _atomic_write(private_key, private_key_contents, 0o640, expected_uid, caddy_gid)
        _atomic_write(caddyfile, candidate_contents, 0o640, expected_uid, caddy_gid)

        if certificate_renewed:
            if state.exists():
                os.replace(state, previous_state)
                previous_state_was_moved = True
            os.replace(staged_state, state)
            state_was_replaced = True

        _validate_caddy(arguments)
        _activate_caddy(arguments, was_running=was_running)
        health_checker(arguments.health_url)
        return TransactionResult(True, certificate_renewed)
    except (HttpsTransactionError, OSError) as failure:
        try:
            for snapshot in snapshots:
                _restore(snapshot)
            if state_was_replaced or previous_state_was_moved:
                if state.exists():
                    shutil.rmtree(state)
                if previous_state.exists():
                    os.replace(previous_state, state)
            _recover_caddy(arguments, was_running=was_running)
        except (HttpsTransactionError, OSError):
            raise HttpsTransactionError(
                "The HTTPS deployment failed and automatic recovery also failed; manual recovery is required.",
            ) from None
        if isinstance(failure, HttpsTransactionError):
            raise failure
        raise HttpsTransactionError("The HTTPS deployment failed and the previous state was restored.") from None
    finally:
        shutil.rmtree(transaction_directory, ignore_errors=True)


def _arguments(argv: Sequence[str] | None = None) -> argparse.Namespace:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--domain", required=True)
    parser.add_argument("--email", required=True)
    parser.add_argument("--health-url", required=True)
    parser.add_argument("--token-file", required=True)
    parser.add_argument("--candidate-caddyfile", required=True)
    parser.add_argument("--caddyfile", required=True)
    parser.add_argument("--certificate-file", required=True)
    parser.add_argument("--certificate-key-file", required=True)
    parser.add_argument("--lego-state-directory", required=True)
    parser.add_argument("--renew-before-days", type=int, required=True)
    parser.add_argument("--acme-user", required=True)
    parser.add_argument("--acme-group", required=True)
    parser.add_argument("--caddy-group", required=True)
    parser.add_argument("--caddy-executable", required=True)
    parser.add_argument("--lego-executable", required=True)
    parser.add_argument("--openssl-executable", required=True)
    parser.add_argument("--rc-service-executable", required=True)
    parser.add_argument("--caddy-service", required=True)
    parser.add_argument("--lock-file", required=True)
    return parser.parse_args(argv)


def main(argv: Sequence[str] | None = None) -> int:
    try:
        arguments = _arguments(argv)
        if os.geteuid() != 0:
            raise HttpsTransactionError("The HTTPS transaction must run as root.")
        with transaction_lock(Path(arguments.lock_file)):
            result = execute(arguments)
        print(json.dumps(
            {"certificateRenewed": result.certificate_renewed, "changed": result.changed},
            sort_keys=True,
            separators=(",", ":"),
        ))
        return 0
    except HttpsTransactionError as error:
        print(str(error), file=sys.stderr)
        return 1
    except (OSError, ValueError):
        print("The HTTPS transaction failed safely.", file=sys.stderr)
        return 1


if __name__ == "__main__":
    raise SystemExit(main())
