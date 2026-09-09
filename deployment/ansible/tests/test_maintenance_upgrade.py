from __future__ import annotations

import argparse
import importlib.util
import json
import os
import tempfile
import unittest
from pathlib import Path
from types import SimpleNamespace
from unittest.mock import patch

SOURCE = Path(__file__).resolve().parents[1] / 'roles/hoddmimir/files/maintenance_upgrade.py'
spec = importlib.util.spec_from_file_location('maintenance_upgrade_tests', SOURCE)
module = importlib.util.module_from_spec(spec)
spec.loader.exec_module(module)


class PowerLoss(BaseException):
    pass


class RestoreVerificationComposeTest(unittest.TestCase):
    def test_worker_readiness_keeps_the_maintenance_service_definition_in_the_restore_overlay(self):
        arguments = SimpleNamespace(current_compose_file='/test/compose.yaml',
                                    current_migration_compose_file='/test/compose.migration.yaml')
        operations = module.DockerMaintenance(SimpleNamespace(), arguments)
        operations.validation_override = Path('/test/restore-check.compose.json')
        with patch.object(operations, 'command') as command:
            operations.compose_command(['run', '--rm', '--no-deps', 'data-worker', 'hoddmimir:worker:readiness', 'collector'])
        self.assertEqual(['compose', '--file', '/test/compose.yaml', '--file', '/test/compose.migration.yaml',
                          '--file', '/test/restore-check.compose.json', 'run', '--rm', '--no-deps',
                          'data-worker', 'hoddmimir:worker:readiness', 'collector'], command.call_args.args[0])


class FakeOperations:
    def __init__(self, test):
        self.test = test
        self.database = b'original database and exact grants\n'
        self.events = []
        self.failures = set()
        self.migrations = 0
        self.restores = 0
        self.running = {'mariadb', *module.SERVICES}

    def record(self, name):
        self.events.append(name)
        if name in self.failures:
            raise module.MaintenanceError('Injected failure, no secret values.')

    def configuration(self, path):
        return self.test.config('candidate' if 'staged' in str(path) else 'old')

    def require_protocol(self): self.record('protocol')
    def stop(self, services=module.SERVICES):
        self.record('stop:' + ','.join(services))
        self.running.difference_update(services)
    def start_monitor(self):
        assert self.test.phase() == 'draining'
        self.record('monitor')
        self.running.add('backup-worker')
    def drain(self): self.record('drain')
    def quiet(self):
        self.record('quiet')
        return 'busy' not in self.failures
    def database_preflight(self): self.record('database_preflight')
    def validate(self):
        self.record('validate_candidate' if self.database == b'candidate\n' else 'validate_old')
    def wait_web(self): self.record('http_maintenance')
    def start_frozen(self):
        assert self.test.phase() == 'frozen'
        self.record('start_frozen')
        self.running.update(module.SERVICES)
    def dump(self, path):
        assert self.test.phase() == 'frozen'
        assert self.running == {'mariadb'}
        self.record('dump')
        path.write_bytes(self.database)
        os.chmod(path, 0o600)
        return module.digest(path)
    def restore(self, path, checksum):
        assert self.test.phase() == 'frozen'
        assert self.running == {'mariadb'}
        self.record('restore')
        if module.digest(path) != checksum: raise module.MaintenanceError('Checksum mismatch.')
        self.database = path.read_bytes()
        self.restores += 1
    def verify_snapshot(self, path, checksum, metadata, directory, *, require_quiet=False):
        self.record('rehearse_restore_and_remote' if require_quiet else 'rehearse_restore')
        assert module.digest(path) == checksum


class MaintenanceUpgradeTest(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory()
        self.root = Path(self.temp.name)
        self.staged = self.root / 'staged'
        self.staged.mkdir()
        self.control = self.root / 'maintenance'
        module.Control(self.control, 1).initialize(False)
        module.Control(self.control, 1).phase('open')
        self.files = []
        for name in ('compose.yaml', 'compose.migration.yaml', 'runtime.env', 'encryption_key'):
            current = self.root / name
            staged = self.staged / name
            current.write_text('original:' + name)
            staged.write_text('candidate:' + name)
            os.chmod(current, 0o600)
            self.files.append(SimpleNamespace(current=current, staged=staged, mode=0o600))
        self.arguments = argparse.Namespace(
            current_compose_file=str(self.root / 'compose.yaml'),
            current_migration_compose_file=str(self.root / 'compose.migration.yaml'),
            current_secrets_directory=str(self.root), staging_directory=str(self.staged),
            maintenance_directory=str(self.control), maintenance_timeout=1,
            maintenance_external_schedulers_paused=True,
            database_service='mariadb', database_bootstrap_script='/bootstrap',
            migration_service='schema-migration', health_url='http://127.0.0.1:8080/api/health',
        )
        self.ops = FakeOperations(self)
        self.docker = SimpleNamespace(
            wait_timeout=1, running_services=lambda path: self.ops.running.copy(),
            pull=lambda path: self.ops.record('pull'),
            bootstrap_database_users=lambda *args: self.ops.record('bootstrap'),
            migrate=self.migrate,
        )
        self.api = SimpleNamespace(build_managed_files=lambda args: self.files, verify_stack=self.verify)

    def tearDown(self): self.temp.cleanup()
    def phase(self): return (self.control / 'state').read_text().strip()
    def config(self, version):
        services = {}
        for name in ('mariadb', *module.SERVICES):
            entry = {'image': name + '@sha256:' + ('a' if name == 'mariadb' or version == 'old' else 'b') * 64}
            if name == 'mariadb':
                entry.update({'environment': {'MARIADB_DATABASE': 'hoddmimir'}, 'volumes': [{'type': 'volume', 'source': 'data', 'target': '/var/lib/mysql'}]})
            else:
                entry.update({'environment': {'HODDMIMIR_MAINTENANCE_DIRECTORY': '/run/hoddmimir-maintenance'},
                              'volumes': [{'type': 'bind', 'source': str(self.control), 'target': '/run/hoddmimir-maintenance', 'read_only': True}]})
            services[name] = entry
        return {'name': 'hoddmimir', 'services': services}
    def migrate(self, *args):
        assert self.phase() == 'frozen'
        assert self.ops.running == {'mariadb'}
        self.ops.migrations += 1
        self.ops.database = b'candidate\n'
        self.ops.record('migrate')
    def verify(self, *args):
        assert self.phase() == 'open'
        self.ops.record('verify_released')
    def upgrade(self, kind=module.Upgrade): return kind(self.arguments, self.docker, self.api, self.ops)

    def test_success_orders_protection_backup_rehearsal_ddl_validation_and_release(self):
        result = self.upgrade().run(self.files)
        self.assertEqual('deployed', result['status'])
        self.assertEqual('open', self.phase())
        events = self.ops.events
        for before, after in [('drain', 'dump'), ('dump', 'rehearse_restore'), ('rehearse_restore', 'migrate'), ('migrate', 'validate_candidate'), ('http_maintenance', 'verify_released')]:
            self.assertLess(events.index(before), events.index(after))
        self.assertEqual(0, self.ops.restores)
        directory = Path(result['snapshot'])
        self.assertEqual(0o700, directory.stat().st_mode & 0o777)
        self.assertEqual(0o600, (directory / 'database.sql').stat().st_mode & 0o777)
        self.assertFalse((directory.parent / 'active.json').exists())

    def test_partial_migration_and_candidate_validation_failure_restore_database_files_and_grants(self):
        for failure in ('migrate', 'validate_candidate'):
            with self.subTest(failure=failure):
                self.ops.failures = {failure}
                with self.assertRaisesRegex(module.MaintenanceError, 'were restored'):
                    self.upgrade().run(self.files)
                self.assertEqual('open', self.phase())
                self.assertEqual(b'original database and exact grants\n', self.ops.database)
                for managed in self.files:
                    self.assertEqual('original:' + managed.current.name, managed.current.read_text())
                self.assertIn('rehearse_restore_and_remote', self.ops.events)

    def test_backup_remote_and_rehearsal_failure_never_migrate_or_reopen(self):
        for failure in ('drain', 'quiet', 'dump', 'rehearse_restore'):
            with self.subTest(failure=failure):
                self.ops.failures = {failure}
                with self.assertRaises(module.MaintenanceError): self.upgrade().run(self.files)
                self.assertEqual(0, self.ops.migrations)
                self.assertNotEqual('open', self.phase())
                self.ops.failures.clear()
                self.assertEqual('restored', self.upgrade().run(self.files)['status'])
                self.assertEqual('open', self.phase())

    def test_restore_failure_remains_closed_and_rerun_resumes_recovery(self):
        self.ops.failures = {'migrate', 'restore'}
        with self.assertRaisesRegex(module.MaintenanceError, 'recovery could not finish'):
            self.upgrade().run(self.files)
        self.assertEqual('frozen', self.phase())
        self.ops.failures.clear()
        self.assertEqual('restored', self.upgrade().run(self.files)['status'])
        self.assertEqual(b'original database and exact grants\n', self.ops.database)
        self.assertEqual(1, self.ops.migrations)

    def test_process_loss_is_recoverable_at_each_durable_boundary(self):
        for phase in ('prepared', 'draining', 'frozen', 'snapshot_verified', 'mutating', 'candidate_validated'):
            with self.subTest(phase=phase):
                class Interrupted(module.Upgrade):
                    def checkpoint(inner, value):
                        super().checkpoint(value)
                        if value == phase: raise PowerLoss()
                if phase == 'prepared':
                    # The active pointer follows preparation. A loss before it
                    # has no side effects and the abandoned snapshot is retained.
                    with self.assertRaises(PowerLoss): self.upgrade(Interrupted).run(self.files)
                    self.assertEqual('open', self.phase())
                    continue
                with self.assertRaises(PowerLoss): self.upgrade(Interrupted).run(self.files)
                self.assertEqual('restored', self.upgrade().run(self.files)['status'])
                self.assertEqual('open', self.phase())
                self.assertEqual(b'original database and exact grants\n', self.ops.database)

    def test_release_commit_forbids_restore_even_when_process_dies_before_open(self):
        class Interrupted(module.Upgrade):
            def checkpoint(inner, value):
                super().checkpoint(value)
                if value == 'release_committed': raise PowerLoss()
        with self.assertRaises(PowerLoss): self.upgrade(Interrupted).run(self.files)
        self.assertEqual('frozen', self.phase())
        self.assertEqual('deployed', self.upgrade().run(self.files)['status'])
        self.assertEqual(0, self.ops.restores)
        self.assertEqual(b'candidate\n', self.ops.database)

    def test_failure_after_reopening_never_restores_database(self):
        self.ops.failures = {'verify_released'}
        with self.assertRaisesRegex(module.MaintenanceError, 'rollback is forbidden'):
            self.upgrade().run(self.files)
        self.assertEqual('open', self.phase())
        self.assertEqual(0, self.ops.restores)
        self.ops.failures.clear()
        self.assertEqual('deployed', self.upgrade().run(self.files)['status'])
        self.assertEqual(0, self.ops.restores)

    def test_recovery_restores_previously_stopped_services_before_opening(self):
        self.ops.running = {'mariadb', 'webapp'}
        self.ops.failures = {'migrate'}
        with self.assertRaises(module.MaintenanceError): self.upgrade().run(self.files)
        self.assertEqual({'mariadb', 'webapp'}, self.ops.running)
        self.assertEqual('open', self.phase())

    def test_missing_external_schedule_acknowledgement_blocks_before_any_mutation(self):
        self.arguments.maintenance_external_schedulers_paused = False
        with self.assertRaisesRegex(module.MaintenanceError, 'acknowledgement'):
            self.upgrade().run(self.files)
        self.assertEqual([], self.ops.events)
        self.assertEqual('open', self.phase())

    def test_changed_database_image_or_missing_application_gate_is_rejected(self):
        old, candidate = self.config('old'), self.config('candidate')
        candidate['services']['mariadb']['image'] = 'db@sha256:' + 'c' * 64
        with self.assertRaises(module.MaintenanceError): module.validate_configuration(old, candidate, self.control)
        candidate = self.config('candidate')
        candidate['services']['webapp']['volumes'] = []
        with self.assertRaises(module.MaintenanceError): module.validate_configuration(old, candidate, self.control)

    def test_corrupt_file_snapshot_prevents_restoring_any_file(self):
        class Interrupted(module.Upgrade):
            def checkpoint(inner, value):
                super().checkpoint(value)
                if value == 'mutating': raise PowerLoss()
        upgrade = self.upgrade(Interrupted)
        with self.assertRaises(PowerLoss): upgrade.run(self.files)
        (upgrade.directory / 'file-0').write_bytes(b'corrupt')
        with self.assertRaises(module.MaintenanceError): self.upgrade().run(self.files)
        self.assertEqual('frozen', self.phase())
        self.assertEqual(0, self.ops.restores)


if __name__ == '__main__': unittest.main()
