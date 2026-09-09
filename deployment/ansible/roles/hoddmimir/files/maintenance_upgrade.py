#!/usr/bin/env python3
"""ADR 0006: durable maintenance, verified logical backup, pre-release restore.

Control files contain no secrets. Recovery snapshots are separate, root-only,
retained artifacts. A journal written before reopening forbids later rollback.
"""
from __future__ import annotations

import fcntl
import hashlib
import json
import os
import re
import shutil
import stat
import subprocess
import time
import uuid
import urllib.request
import urllib.error
from contextlib import contextmanager
from pathlib import Path

SERVICES = ('data-worker', 'backup-worker', 'webapp')
GRANT_TABLES = ('global_priv', 'db', 'tables_priv', 'columns_priv', 'procs_priv', 'proxies_priv', 'roles_mapping')
ROOT_CLIENT = r'''
set -eu
umask 077
maintenance_client=$(mktemp /tmp/hoddmimir-maintenance-client.XXXXXX)
trap 'rm -f "$maintenance_client"' EXIT HUP INT TERM
maintenance_password=$(cat /run/secrets/mariadb_root_password)
case "$maintenance_password" in *[!0-9a-f]*|'') exit 70 ;; esac
[ "${#maintenance_password}" -eq 64 ] || exit 70
printf '[client]\nuser=root\npassword=%s\nprotocol=socket\n' "$maintenance_password" > "$maintenance_client"
unset maintenance_password
'''
DUMP = ROOT_CLIENT + r'''
case "$MARIADB_DATABASE" in *[!A-Za-z0-9_]*|'') exit 70 ;; esac
mariadb-dump --defaults-extra-file="$maintenance_client" --single-transaction --skip-comments --skip-dump-date --skip-add-locks --skip-extended-insert --order-by-primary --hex-blob --routines --events --triggers --add-drop-database --databases "$MARIADB_DATABASE"
printf '\nUSE mysql;\n'
''' + '\n'.join(f"printf 'DELETE FROM `{table}`;\\n'" for table in GRANT_TABLES) + r'''
mariadb-dump --defaults-extra-file="$maintenance_client" --skip-comments --skip-dump-date --skip-add-locks --skip-extended-insert --order-by-primary --hex-blob --no-create-info --skip-triggers mysql ''' + ' '.join(GRANT_TABLES) + r'''
printf '\nFLUSH PRIVILEGES;\n'
'''
RESTORE = ROOT_CLIENT + 'mariadb --defaults-extra-file="$maintenance_client" --binary-mode\n'
PING = ROOT_CLIENT + 'mariadb --defaults-extra-file="$maintenance_client" --batch --skip-column-names -e "SELECT 1"\n'


class MaintenanceError(RuntimeError):
    pass


def digest(path: Path) -> str:
    with path.open('rb') as stream:
        return hashlib.file_digest(stream, 'sha256').hexdigest()


def sync_directory(path: Path) -> None:
    fd = os.open(path, os.O_RDONLY | os.O_DIRECTORY)
    try:
        os.fsync(fd)
    finally:
        os.close(fd)


def atomic(path: Path, data: bytes, mode: int = 0o600) -> None:
    temporary = path.with_name('.' + path.name + '.' + uuid.uuid4().hex)
    try:
        fd = os.open(temporary, os.O_WRONLY | os.O_CREAT | os.O_EXCL | os.O_NOFOLLOW, mode)
        with os.fdopen(fd, 'wb') as stream:
            stream.write(data)
            stream.flush()
            os.fsync(stream.fileno())
        os.replace(temporary, path)
        sync_directory(path.parent)
    finally:
        temporary.unlink(missing_ok=True)


def save(path: Path, document: dict) -> None:
    atomic(path, (json.dumps(document, sort_keys=True) + '\n').encode())


class Control:
    def __init__(self, directory: Path, timeout: int) -> None:
        if not directory.is_absolute() or directory.is_symlink():
            raise MaintenanceError('Invalid maintenance control directory.')
        self.directory = directory
        if timeout < 1 or timeout > 86400:
            raise MaintenanceError('Maintenance timeout must be between 1 and 86400 seconds.')
        self.timeout = timeout

    def initialize(self, installed: bool) -> None:
        if not self.directory.exists():
            if installed:
                raise MaintenanceError('Installed application has no maintenance protocol; establish a maintenance-capable baseline before schema upgrades.')
            self.directory.mkdir(mode=0o755)
            atomic(self.directory / 'operations.lock', b'', 0o644)
            atomic(self.directory / 'state', b'frozen\n', 0o644)
        for name in ('operations.lock', 'state'):
            path = self.directory / name
            if path.is_symlink() or not path.is_file() or path.stat().st_uid != os.geteuid() or stat.S_IMODE(path.stat().st_mode) != 0o644:
                raise MaintenanceError('Maintenance control is incomplete; no application writes were enabled.')
        if (self.directory / 'state').read_bytes() not in (b'open\n', b'draining\n', b'frozen\n'):
            raise MaintenanceError('Maintenance control is invalid; recover it before deploying.')

    @contextmanager
    def exclusive(self):
        fd = os.open(self.directory / 'operations.lock', os.O_RDWR | os.O_NOFOLLOW)
        try:
            deadline = time.monotonic() + self.timeout
            while True:
                try:
                    fcntl.flock(fd, fcntl.LOCK_EX | fcntl.LOCK_NB)
                    break
                except BlockingIOError:
                    if time.monotonic() >= deadline:
                        raise MaintenanceError('In-flight application work did not drain before the deadline.') from None
                    time.sleep(0.1)
            yield
        finally:
            os.close(fd)

    def phase(self, phase: str) -> None:
        if phase not in ('open', 'draining', 'frozen'):
            raise MaintenanceError('Invalid maintenance phase.')
        with self.exclusive():
            atomic(self.directory / 'state', (phase + '\n').encode(), 0o644)


class DockerMaintenance:
    """All output that can include credentials or dump contents stays private."""
    def __init__(self, docker, arguments) -> None:
        self.docker = docker
        self.arguments = arguments
        self.compose = Path(arguments.current_compose_file)
        self.migration = Path(arguments.current_migration_compose_file)
        self.validation_override = None

    def command(self, args, *, source=None, destination=None, allowed=(0,), timeout=None):
        try:
            result = subprocess.run(
                [self.docker.executable, *args], stdin=source,
                stdout=destination if destination is not None else subprocess.PIPE,
                stderr=subprocess.PIPE, check=False, timeout=timeout or self.arguments.maintenance_timeout,
            )
        except (OSError, subprocess.TimeoutExpired):
            raise MaintenanceError('Maintenance Docker operation could not complete.') from None
        if result.returncode not in allowed:
            raise MaintenanceError('Maintenance Docker operation failed; application remains protected.')
        return result

    def compose_command(self, args, *, both=False, allowed=(0,)):
        prefix = ['compose', '--file', str(self.compose)]
        if both or self.validation_override is not None:
            prefix += ['--file', str(self.migration)]
        if self.validation_override is not None:
            prefix += ['--file', str(self.validation_override)]
        return self.command(prefix + list(args), allowed=allowed)

    def configuration(self, path: Path) -> dict:
        result = self.command(['compose', '--file', str(path), 'config', '--format', 'json'])
        try:
            return json.loads(result.stdout)
        except (ValueError, TypeError):
            raise MaintenanceError('Invalid Compose configuration.') from None

    def schema_pending(self) -> bool:
        result = self.compose_command(['run', '--rm', '--no-deps', 'schema-migration',
                                       'doctrine:migrations:up-to-date', '--no-interaction', '--no-ansi'], both=True, allowed=(0, 1))
        return result.returncode != 0

    def require_protocol(self) -> None:
        response = self.compose_command(['run', '--rm', '--no-deps', 'maintenance-check', 'hoddmimir:maintenance:probe', '--capabilities'], both=True)
        try:
            if json.loads(response.stdout) != {'maintenanceProtocol': 1}:
                raise ValueError()
        except (ValueError, TypeError):
            raise MaintenanceError('Installed application does not implement maintenance protocol 1.') from None

    def stop(self, services=SERVICES) -> None:
        self.compose_command(['stop', '--timeout', '75', *services])

    def start_monitor(self) -> None:
        self.compose_command(['up', '--detach', '--no-deps', 'backup-worker'])

    def start_frozen(self) -> None:
        self.compose_command(['up', '--detach', '--no-deps', '--force-recreate', *SERVICES])

    def quiet(self) -> bool:
        response = self.compose_command(['run', '--rm', '--no-deps', 'maintenance-check', 'hoddmimir:maintenance:probe'], both=True, allowed=(0, 1, 2))
        try:
            status = json.loads(response.stdout).get('status')
        except (ValueError, TypeError, AttributeError):
            raise MaintenanceError('The remote quiescence probe returned invalid evidence.') from None
        if response.returncode == 0 and status == 'quiet':
            return True
        if response.returncode == 2 and status == 'busy':
            return False
        raise MaintenanceError('Remote quiescence is unavailable; no migration is allowed.')

    def drain(self) -> None:
        deadline = time.monotonic() + self.arguments.maintenance_timeout
        while not self.quiet():
            if time.monotonic() >= deadline:
                raise MaintenanceError('Backups remain active; maintenance is still draining.')
            time.sleep(5)

    def validate(self) -> None:
        for user, secret in (
            ('hoddmimir_migration', 'mariadb_migration_password'),
            ('hoddmimir_collector', 'mariadb_collector_password'),
            ('hoddmimir_backup_worker', 'mariadb_backup_worker_password'),
            ('hoddmimir_web', 'mariadb_web_password'),
        ):
            self.compose_command(['run', '--rm', '--no-deps', '-e', 'DATABASE_USER=' + user,
                                  '-e', 'DATABASE_PASSWORD_FILE=/run/secrets/' + secret,
                                  'maintenance-check', 'hoddmimir:maintenance:validate'], both=True)
        for service, worker in (('data-worker', 'collector'), ('backup-worker', 'backup')):
            self.compose_command(['run', '--rm', '--no-deps', service, 'hoddmimir:worker:readiness', worker])

    def wait_web(self) -> None:
        deadline = time.monotonic() + self.docker.wait_timeout
        while True:
            try:
                with urllib.request.urlopen(self.arguments.health_url, timeout=5) as response:
                    if response.status != 200:
                        raise MaintenanceError('Candidate health check failed.')
                # Prove that the actual HTTP kernel keeps administrative APIs closed.
                api_url = self.arguments.health_url.removesuffix('/api/health') + '/api/v1/connections'
                try:
                    urllib.request.urlopen(api_url, timeout=5).close()
                except urllib.error.HTTPError as error:
                    if error.code == 503:
                        return
                raise MaintenanceError('Application API did not enforce maintenance.')
            except (OSError, urllib.error.URLError, MaintenanceError):
                if time.monotonic() >= deadline:
                    raise MaintenanceError('Candidate HTTP/API validation failed.') from None
                time.sleep(1)

    def database_command(self, script: str, *, container=None, source=None, destination=None) -> None:
        prefix = ['exec', '-i', '--user', '0', container] if container else ['compose', '--file', str(self.compose), 'exec', '-T', '--user', '0', 'mariadb']
        self.command(prefix + ['sh', '-c', script], source=source, destination=destination)

    def dump(self, path: Path, *, container=None) -> str:
        fd = os.open(path, os.O_WRONLY | os.O_CREAT | os.O_EXCL | os.O_NOFOLLOW, 0o600)
        try:
            with os.fdopen(fd, 'wb') as stream:
                self.database_command(DUMP, container=container, destination=stream)
                stream.flush()
                os.fsync(stream.fileno())
            if path.stat().st_size == 0:
                raise MaintenanceError('The database snapshot is empty.')
            return digest(path)
        except BaseException:
            path.unlink(missing_ok=True)
            raise

    def restore(self, path: Path, expected_hash: str, *, container=None) -> None:
        if path.is_symlink() or digest(path) != expected_hash:
            raise MaintenanceError('The database snapshot checksum does not match; restore refused.')
        with path.open('rb') as stream:
            self.database_command(RESTORE, container=container, source=stream)

    def verify_snapshot(self, snapshot: Path, checksum: str, metadata: dict, directory: Path, *, require_quiet=False) -> None:
        name = 'hoddmimir-restore-check-' + directory.name
        volume = name + '-data'
        network = name + '-network'
        comparison = directory / 'restore-check.sql'
        # Deterministic names allow an interrupted rehearsal to be cleaned up
        # and repeated without touching any production container or volume.
        self.command(['rm', '--force', name], allowed=(0, 1))
        self.command(['volume', 'rm', volume], allowed=(0, 1))
        self.command(['network', 'rm', network], allowed=(0, 1))
        self.command(['network', 'create', '--internal', '--label', 'hoddmimir.maintenance=true', network])
        self.command(['volume', 'create', '--label', 'hoddmimir.maintenance=true', volume])
        try:
            self.command(['run', '--detach', '--name', name, '--network', network, '--platform', 'linux/amd64',
                          '--label', 'hoddmimir.maintenance=true', '--mount', 'type=volume,src=' + volume + ',dst=/var/lib/mysql',
                          '--mount', 'type=bind,src=' + str(Path(self.arguments.current_secrets_directory) / 'mariadb_root_password') + ',dst=/run/secrets/mariadb_root_password,readonly',
                          '-e', 'MARIADB_ROOT_PASSWORD_FILE=/run/secrets/mariadb_root_password',
                          '-e', 'MARIADB_DATABASE=' + metadata['database_name'], metadata['database_image']])
            deadline = time.monotonic() + self.docker.wait_timeout
            while True:
                try:
                    self.database_command(PING, container=name)
                    break
                except MaintenanceError:
                    if time.monotonic() >= deadline:
                        raise
                    time.sleep(1)
            self.restore(snapshot, checksum, container=name)
            comparison.unlink(missing_ok=True)
            if self.dump(comparison, container=name) != checksum:
                raise MaintenanceError('Restored schema, data or grants differ from the snapshot.')
            override = directory / 'restore-check.compose.json'
            save(override, {
                'services': {service: {
                    'environment': {'DATABASE_HOST': name, 'BACKUP_EXECUTION_ENABLED': 'false', 'MATRIX_NOTIFICATION_ENABLED': 'false'},
                    'networks': ['internal', 'maintenance-verification'],
                } for service in ('maintenance-check', 'data-worker', 'backup-worker')},
                'networks': {'maintenance-verification': {'external': True, 'name': network}},
            })
            self.validation_override = override
            self.validate()
            if require_quiet and not self.quiet():
                raise MaintenanceError('Remote backups are active; production database restore is blocked.')
        finally:
            self.validation_override = None
            self.command(['rm', '--force', name], allowed=(0, 1))
            self.command(['volume', 'rm', volume], allowed=(0, 1))
            self.command(['network', 'rm', network], allowed=(0, 1))
            comparison.unlink(missing_ok=True)

    def database_preflight(self) -> None:
        self.database_command(ROOT_CLIENT + r'''
case "$MARIADB_DATABASE" in *[!A-Za-z0-9_]*|'') exit 70 ;; esac
maintenance_unsafe=$(mariadb --defaults-extra-file="$maintenance_client" --batch --skip-column-names -e "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA='$MARIADB_DATABASE' AND TABLE_TYPE='BASE TABLE' AND ENGINE <> 'InnoDB'")
[ "$maintenance_unsafe" = 0 ] || exit 71
maintenance_events=$(mariadb --defaults-extra-file="$maintenance_client" --batch --skip-column-names -e 'SELECT @@GLOBAL.event_scheduler')
[ "$maintenance_events" = OFF ] || exit 71
''')


def validate_configuration(old: dict, candidate: dict, directory: Path) -> dict:
    if old.get('name') != candidate.get('name'):
        raise MaintenanceError('A maintenance upgrade cannot change the Compose project.')
    for config in (old, candidate):
        for name in ('mariadb', *SERVICES):
            service = config.get('services', {}).get(name, {})
            if re.fullmatch(r'.+@sha256:[0-9a-f]{64}', service.get('image', '')) is None:
                raise MaintenanceError('Maintenance requires digest-pinned database and application images.')
            if name != 'mariadb':
                if service.get('environment', {}).get('HODDMIMIR_MAINTENANCE_DIRECTORY') != '/run/hoddmimir-maintenance':
                    raise MaintenanceError('All application services must honor persistent maintenance.')
                mounts = service.get('volumes', [])
                if not any(mount.get('type') == 'bind' and mount.get('source') == str(directory)
                           and mount.get('target') == '/run/hoddmimir-maintenance' and mount.get('read_only') is True
                           for mount in mounts):
                    raise MaintenanceError('Maintenance control must be the same read-only host directory for every application.')
    previous = old['services']['mariadb']
    proposed = candidate['services']['mariadb']
    if previous['image'] != proposed['image'] or previous.get('volumes') != proposed.get('volumes'):
        raise MaintenanceError('Database engine/image or volume changes require a separate database maintenance procedure.')
    database = previous.get('environment', {}).get('MARIADB_DATABASE')
    if not isinstance(database, str) or re.fullmatch(r'[A-Za-z0-9_]+', database) is None or database != proposed.get('environment', {}).get('MARIADB_DATABASE'):
        raise MaintenanceError('The maintenance database identity changed.')
    return {'database_image': previous['image'], 'database_name': database,
            'old_images': {name: old['services'][name]['image'] for name in SERVICES},
            'candidate_images': {name: candidate['services'][name]['image'] for name in SERVICES}}


class Upgrade:
    def __init__(self, arguments, docker, api, operations=None) -> None:
        self.arguments, self.docker, self.api = arguments, docker, api
        self.control = Control(Path(arguments.maintenance_directory), arguments.maintenance_timeout)
        self.root = Path(arguments.current_compose_file).parent / '.maintenance-transactions'
        self.active = self.root / 'active.json'
        self.ops = operations or DockerMaintenance(docker, arguments)
        self.journal: dict = {}
        self.directory: Path | None = None

    def checkpoint(self, phase: str) -> None:
        self.journal['phase'] = phase
        assert self.directory is not None
        save(self.directory / 'journal.json', self.journal)

    def load(self) -> None:
        active = json.loads(self.active.read_bytes())
        identifier = active.get('id', '')
        if re.fullmatch(r'[0-9a-f]{32}', identifier) is None:
            raise MaintenanceError('Invalid active maintenance identifier.')
        self.directory = self.root / identifier
        if self.directory.is_symlink():
            raise MaintenanceError('Invalid maintenance recovery directory.')
        self.journal = json.loads((self.directory / 'journal.json').read_bytes())
        if self.journal.get('id') != identifier:
            raise MaintenanceError('Maintenance recovery identity does not match.')

    def snapshot_files(self, managed_files) -> None:
        assert self.directory is not None
        records = []
        for index, managed in enumerate(managed_files):
            current = managed.current
            if current.is_symlink():
                raise MaintenanceError('Managed deployment files must not be symlinks.')
            record = {'destination': str(current), 'existed': current.is_file()}
            if record['existed']:
                backup = self.directory / ('file-' + str(index))
                atomic(backup, current.read_bytes())
                record.update({'backup': backup.name, 'mode': stat.S_IMODE(current.stat().st_mode), 'sha256': digest(backup)})
            records.append(record)
        self.journal['files'] = records

    def restore_files(self) -> None:
        assert self.directory is not None
        permitted = {str(managed.current) for managed in self.api.build_managed_files(self.arguments)}
        records = self.journal['files']
        if {record['destination'] for record in records} != permitted:
            raise MaintenanceError('Recovery file destinations differ from this installation.')
        for record in records:
            if record['existed']:
                name = record['backup']
                if re.fullmatch(r'file-[0-9]+', name) is None:
                    raise MaintenanceError('Invalid recovery snapshot filename.')
                source = self.directory / name
                if source.is_symlink() or digest(source) != record['sha256']:
                    raise MaintenanceError('A configuration or secret snapshot checksum does not match.')
        for record in reversed(records):
            destination = Path(record['destination'])
            if record['existed']:
                atomic(destination, (self.directory / record['backup']).read_bytes(), record['mode'])
            else:
                destination.unlink(missing_ok=True)

    def finish(self, recovery: bool) -> dict:
        # Commit the no-restore boundary DURABLY BEFORE opening the gate. If the
        # process dies between these writes, resume can only complete release.
        self.checkpoint('recovery_release_committed' if recovery else 'release_committed')
        intended = set(self.journal['running']) if recovery else {'mariadb', *SERVICES}
        if recovery:
            stopped = [service for service in SERVICES if service not in intended]
            if stopped:
                self.ops.stop(stopped)
        self.control.phase('open')
        if intended == {'mariadb', *SERVICES}:
            self.api.verify_stack(self.docker, Path(self.arguments.current_compose_file), intended, self.arguments.health_url)
        self.checkpoint('rolled_back' if recovery else 'completed')
        self.active.unlink()
        sync_directory(self.root)
        return {'changed': True, 'status': 'restored' if recovery else 'deployed', 'snapshot': str(self.directory)}

    def recover(self) -> dict:
        assert self.directory is not None
        phase = self.journal['phase']
        if phase in ('release_committed', 'completed', 'recovery_release_committed', 'rolled_back'):
            return self.finish(phase in ('recovery_release_committed', 'rolled_back'))
        # No candidate can run while database and files are being restored.
        if phase in ('prepared', 'draining'):
            self.control.phase('draining')
            self.ops.start_monitor()
            self.ops.drain()
        self.ops.stop()
        self.control.phase('frozen')
        needs_database = self.journal.get('mutation_started', False)
        self.checkpoint('restoring')
        self.restore_files()
        if needs_database:
            snapshot = self.directory / 'database.sql'
            self.ops.verify_snapshot(snapshot, self.journal['database_sha256'], self.journal, self.directory, require_quiet=True)
            self.ops.restore(snapshot, self.journal['database_sha256'])
            comparison = self.directory / 'recovered.sql'
            comparison.unlink(missing_ok=True)
            if self.ops.dump(comparison) != self.journal['database_sha256']:
                raise MaintenanceError('Recovered database or grants do not match the snapshot.')
            comparison.unlink()
        self.ops.validate()
        if not self.ops.quiet():
            raise MaintenanceError('Remote backups became active during recovery; maintenance remains frozen.')
        self.ops.start_frozen()
        self.ops.wait_web()
        self.checkpoint('recovery_validated')
        return self.finish(True)

    def run(self, managed_files) -> dict:
        installed = Path(self.arguments.current_compose_file).is_file()
        self.control.initialize(installed)
        if self.root.is_symlink():
            raise MaintenanceError('Invalid maintenance snapshot directory.')
        self.root.mkdir(mode=0o700, exist_ok=True)
        os.chmod(self.root, 0o700)
        if self.active.exists():
            self.load()
            return self.recover()
        if not installed:
            raise MaintenanceError('Maintenance upgrade requires an installed baseline.')
        if (self.control.directory / 'state').read_bytes() != b'open\n':
            raise MaintenanceError('Maintenance is closed without an active journal; manual recovery is required.')
        old = self.ops.configuration(Path(self.arguments.current_compose_file))
        candidate = self.ops.configuration(Path(self.arguments.staging_directory) / 'compose.yaml')
        if all(managed.current.is_file() and not managed.current.is_symlink()
               and managed.current.read_bytes() == managed.staged.read_bytes()
               and stat.S_IMODE(managed.current.stat().st_mode) == managed.mode
               for managed in managed_files) and not self.ops.schema_pending():
            self.api.verify_stack(self.docker, Path(self.arguments.current_compose_file), {'mariadb', *SERVICES}, self.arguments.health_url)
            return {'changed': False, 'status': 'verified-unchanged'}
        if not getattr(self.arguments, 'maintenance_external_schedulers_paused', False):
            raise MaintenanceError('Pause external Proxmox backup schedules and administrator starts for the maintenance window, then supply the maintenance acknowledgement.')
        metadata = validate_configuration(old, candidate, self.control.directory)
        self.ops.require_protocol()
        self.docker.pull(Path(self.arguments.staging_directory) / 'compose.yaml')
        identifier = uuid.uuid4().hex
        self.directory = self.root / identifier
        self.directory.mkdir(mode=0o700)
        self.journal = {'id': identifier, 'protocol': 1, 'running': sorted(self.docker.running_services(Path(self.arguments.current_compose_file))), **metadata}
        self.snapshot_files(managed_files)
        self.checkpoint('prepared')
        save(self.active, {'id': identifier})
        try:
            self.control.phase('draining')
            self.checkpoint('draining')
            self.ops.stop(('data-worker', 'webapp'))
            self.ops.start_monitor()
            self.ops.drain()
            self.ops.stop()
            self.control.phase('frozen')
            self.checkpoint('frozen')
            if not self.ops.quiet():
                raise MaintenanceError('Remote backups became active at the freeze boundary.')
            self.ops.database_preflight()
            self.ops.validate()
            checksum = self.ops.dump(self.directory / 'database.sql')
            self.journal['database_sha256'] = checksum
            self.ops.verify_snapshot(self.directory / 'database.sql', checksum, metadata, self.directory)
            self.checkpoint('snapshot_verified')
            # Recheck after the potentially long dump/restore rehearsal, before DDL.
            if not self.ops.quiet():
                raise MaintenanceError('Remote backups became active before migration.')
            self.journal['mutation_started'] = True
            self.checkpoint('mutating')
            for managed in managed_files:
                atomic(managed.current, managed.staged.read_bytes(), managed.mode)
            self.docker.bootstrap_database_users(Path(self.arguments.current_compose_file), self.arguments.database_service, self.arguments.database_bootstrap_script)
            self.docker.migrate(Path(self.arguments.current_compose_file), Path(self.arguments.current_migration_compose_file), self.arguments.migration_service)
            self.ops.validate()
            self.ops.start_frozen()
            self.ops.wait_web()
            self.checkpoint('candidate_validated')
            return self.finish(False)
        except (Exception, KeyboardInterrupt):
            # A pre-backup failure keeps draining/closed, with a durable recovery
            # entry. Never reopen merely because the caller lost its terminal.
            if self.journal.get('mutation_started') and self.journal.get('phase') not in ('release_committed', 'completed'):
                try:
                    self.recover()
                except (Exception, KeyboardInterrupt):
                    raise MaintenanceError('Upgrade and recovery could not finish; maintenance remains closed. Rerun deployment to resume recovery.') from None
                raise MaintenanceError('Upgrade failed; the verified database and previous application were restored.') from None
            if self.journal.get('phase') in ('release_committed', 'completed'):
                raise MaintenanceError('Post-release verification failed; database rollback is forbidden. Rerun deployment to complete verification.') from None
            raise MaintenanceError('Upgrade did not reach migration; maintenance remains active. Rerun deployment to recover the previous operation.') from None
