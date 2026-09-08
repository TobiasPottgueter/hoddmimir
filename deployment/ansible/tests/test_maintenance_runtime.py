"""Opt-in full application rehearsal in a disposable local Compose project.

Supply HODDMIMIR_MAINTENANCE_RUNTIME_IMAGES as a JSON file mapping worker, web,
and mariadb to digest-pinned images in a loopback-only local registry. No real
inventory, credentials, Proxmox systems, or notification targets are used.
A generated local TLS fixture exercises the production read adapters.
"""
from __future__ import annotations

import argparse
import json
import os
import secrets
import shutil
import signal
import socket
import subprocess
import sys
import tempfile
import traceback
import unittest
import uuid
from pathlib import Path

from test_deployment import (
    ANSIBLE_ROOT, SECRET_NAMES, TRANSACTION_MODULE as deployment, valid_variables,
)
from support.maintenance_tls_fixture import MaintenanceTlsFixture

maintenance = deployment.maintenance_module()


@unittest.skipUnless(os.environ.get('HODDMIMIR_MAINTENANCE_RUNTIME_IMAGES'),
                     'Requires explicit disposable local runtime image references.')
class MaintenanceRuntimeTest(unittest.TestCase):
    def test_real_application_upgrade_partial_ddl_crash_restore_and_release(self):
        images = json.loads(Path(os.environ['HODDMIMIR_MAINTENANCE_RUNTIME_IMAGES']).read_text())
        self.assertEqual({'worker', 'web', 'mariadb'}, set(images))
        for image in images.values():
            self.assertRegex(image, r'^127\.0\.0\.1:[0-9]+/hoddmimir-acceptance/[a-z]+@sha256:[0-9a-f]{64}$')
        with tempfile.TemporaryDirectory(prefix='hoddmimir-maintenance-runtime-') as temporary:
            self.root = Path(temporary)
            self.staging = self.root / 'staged'
            self.staging.mkdir(mode=0o700)
            secret_directory = self.root / 'secrets'
            secret_directory.mkdir(mode=0o700)
            with socket.socket() as listener:
                listener.bind(('127.0.0.1', 0))
                port = listener.getsockname()[1]
            self.arguments = argparse.Namespace(
                docker_executable='docker', staging_directory=str(self.staging),
                current_compose_file=str(self.root / 'compose.yaml'),
                current_migration_compose_file=str(self.root / 'compose.migration.yaml'),
                current_environment_file=str(self.root / 'runtime.env'),
                current_mariadb_init_script=str(self.root / '10-create-app-users.sh'),
                current_secrets_directory=str(secret_directory),
                transaction_lock_file=str(self.root / '.deployment-transaction.lock'),
                maintenance_directory=str(self.root / 'maintenance'),
                maintenance_timeout=180, maintenance_external_schedulers_paused=True,
                expected_service=['mariadb', *maintenance.SERVICES], wait_timeout=120,
                migration_service='schema-migration', database_service='mariadb',
                database_bootstrap_script='/usr/local/bin/hoddmimir-database-user-bootstrap',
                encryption_keyring_revision='1', health_url=f'http://127.0.0.1:{port}/api/health',
            )
            keyring = {'format': 1, 'revision': 1, 'primaryKeyId': 'runtime_test',
                       'keys': [{'id': 'runtime_test', 'material': secrets.token_hex(32)}]}
            for name in SECRET_NAMES:
                value = 'https://matrix.invalid.test/disabled' if name == 'matrix_webhook_url' else secrets.token_hex(32)
                maintenance.atomic(secret_directory / name, (value + '\n').encode())
            variables = valid_variables() | {
                'hoddmimir_project_name': 'hoddmimir-maintenance-runtime-' + uuid.uuid4().hex[:12],
                'hoddmimir_install_directory': str(self.root),
                'hoddmimir_secrets_directory': str(secret_directory),
                'hoddmimir_maintenance_directory': self.arguments.maintenance_directory,
                'hoddmimir_mariadb_init_script': self.arguments.current_mariadb_init_script,
                'hoddmimir_data_worker_image': images['worker'],
                'hoddmimir_backup_worker_image': images['worker'],
                'hoddmimir_webapp_image': images['web'],
                'hoddmimir_mariadb_image': images['mariadb'],
                'hoddmimir_encryption_keyring': keyring,
                'hoddmimir_web_port': port,
                'hoddmimir_deployment_profile': 'lab',
                'hoddmimir_manage_https': False,
                'hoddmimir_docker_subnet': '172.31.249.0/24',
                'hoddmimir_docker_gateway': '172.31.249.1',
                'hoddmimir_test_compose_output': self.arguments.current_compose_file,
                'hoddmimir_test_migration_compose_output': self.arguments.current_migration_compose_file,
                'hoddmimir_test_runtime_output': self.arguments.current_environment_file,
                'hoddmimir_test_mariadb_init_output': self.arguments.current_mariadb_init_script,
                'hoddmimir_test_encryption_key_output': str(secret_directory / 'encryption_key'),
                'hoddmimir_test_caddy_output': str(self.root / 'unused-caddy'),
                'hoddmimir_test_renewal_output': str(self.root / 'unused-renewal'),
            }
            variables_file = self.root / 'variables.json'
            maintenance.save(variables_file, variables)
            render = subprocess.run([
                'ansible-playbook', '--inventory', 'localhost,',
                'tests/playbooks/render-compose.yml', '--extra-vars', '@' + str(variables_file),
            ], cwd=ANSIBLE_ROOT, capture_output=True)
            self.assertEqual(0, render.returncode, 'Local template rendering failed.')
            variables_file.unlink()
            self.docker = deployment.DockerCompose('docker', 120)
            self.ops = maintenance.DockerMaintenance(self.docker, self.arguments)
            control = maintenance.Control(Path(self.arguments.maintenance_directory), 180)
            control.initialize(False)
            try:
                self.docker.up_database(Path(self.arguments.current_compose_file))
                self.docker.bootstrap_database_users(Path(self.arguments.current_compose_file), 'mariadb',
                                                    self.arguments.database_bootstrap_script)
                self.docker.migrate(Path(self.arguments.current_compose_file),
                                    Path(self.arguments.current_migration_compose_file), 'schema-migration')
                self.ops.validate()
                self.sql("CREATE TABLE hoddmimir.maintenance_acceptance_probe(id INT PRIMARY KEY, payload VARBINARY(255)) ENGINE=InnoDB; "
                         "INSERT INTO hoddmimir.maintenance_acceptance_probe VALUES(1,UNHEX('0027ff5c0a'));")
                self.ops.start_frozen()
                self.ops.wait_web()
                control.phase('open')
                deployment.verify_stack(self.docker, Path(self.arguments.current_compose_file),
                                        set(self.arguments.expected_service), self.arguments.health_url)
                self.stage('baseline')
                original_secrets = {name: (secret_directory / name).read_bytes() for name in SECRET_NAMES}

                self.remote_conditions()

                self.stage('successful-upgrade')
                self.assertEqual('deployed', self.upgrade()['status'])
                self.assert_open()

                self.stage('partial-ddl-failure')
                original_migrate = self.docker.migrate

                def fail_migration(*args):
                    original_migrate(*args)
                    self.sql('ALTER TABLE hoddmimir.maintenance_acceptance_probe DROP COLUMN payload; '
                             'CREATE TABLE hoddmimir.partial_ddl(id INT);')
                    raise maintenance.MaintenanceError('Injected partial DDL failure.')

                self.docker.migrate = fail_migration
                try:
                    with self.assertRaisesRegex(maintenance.MaintenanceError, 'were restored'):
                        self.upgrade()
                finally:
                    self.docker.migrate = original_migrate
                self.assert_probe_restored()
                self.assert_open()

                self.stage('process-kill-before-release')
                self.kill_at('mutating', 'before_release_marker')
                self.assertEqual('frozen', self.phase())
                self.assertEqual('restored', self.upgrade()['status'])
                self.assert_probe_restored()
                self.assert_open()

                self.stage('process-kill-at-release')
                self.kill_at('release_committed', 'after_release_marker')
                self.assertEqual('frozen', self.phase())
                self.assertEqual('deployed', self.upgrade()['status'])
                self.assertEqual('1', self.query("SELECT COUNT(*) FROM information_schema.TABLES "
                                                "WHERE TABLE_SCHEMA='hoddmimir' AND TABLE_NAME='after_release_marker'"))
                self.assert_open()
                for name, value in original_secrets.items():
                    self.assertEqual(value, (secret_directory / name).read_bytes())
                self.assertEqual('0027FF5C0A', self.query('SELECT HEX(payload) FROM hoddmimir.maintenance_acceptance_probe'))
            finally:
                # Only the random project rendered above, never an inventory.
                self.ops.compose_command(['down', '--volumes', '--remove-orphans'])

    def sql(self, statement):
        with tempfile.TemporaryFile() as stream:
            stream.write(statement.encode())
            stream.seek(0)
            self.ops.database_command(maintenance.RESTORE, source=stream)

    def remote_conditions(self):
        fixture = MaintenanceTlsFixture(self.root)
        helper = ANSIBLE_ROOT / 'tests' / 'support' / 'maintenance_remote_seed.php'
        configuration = self.root / 'remote-fixture.json'

        def seed(action):
            self.ops.compose_command([
                'run', '--rm', '--no-deps', '--entrypoint', '/usr/local/bin/hoddmimir-app-entrypoint',
                '-e', 'HODDMIMIR_MAINTENANCE_FIXTURE=LOCAL_TLS_FIXTURE_ONLY',
                '--volume', str(helper) + ':/tmp/remote-seed.php:ro',
                '--volume', str(configuration) + ':/tmp/remote-fixture.json:ro',
                'maintenance-check', 'php', '/tmp/remote-seed.php', action,
            ], both=True)

        try:
            fixture.start()
            configuration.write_text(json.dumps(fixture.configuration()))
            configuration.chmod(0o644)  # Public fixture CA and local address only.
            seed('insert')
            control = maintenance.Control(Path(self.arguments.maintenance_directory), 180)
            control.phase('draining')
            try:
                self.assertTrue(self.ops.quiet())
            except maintenance.MaintenanceError:
                print(f'Local fixture diagnostic: reads={len(fixture.methods)}, '
                      f'authenticated={fixture.authenticated_reads}, task_pages={len(fixture.task_queries)}',
                      file=sys.stderr)
                raise
            fixture.busy = True
            self.assertFalse(self.ops.quiet())
            control.phase('open')
            self.stage('foreign-task-blocks')
            self.assert_remote_blocks_migration()
            fixture.busy = False
            self.assertEqual('restored', self.upgrade()['status'])
            self.assert_open()

            fixture.stop()
            control.phase('draining')
            with self.assertRaisesRegex(maintenance.MaintenanceError, 'Remote quiescence is unavailable'):
                self.ops.quiet()
            control.phase('open')
            self.stage('unreachable-remote-blocks')
            self.assert_remote_blocks_migration()
            fixture.start()
            self.assertTrue(self.ops.quiet())
            self.assertEqual('restored', self.upgrade()['status'])
            self.assert_open()
            self.assertGreater(fixture.authenticated_reads, 0)
            self.assertEqual({'GET'}, set(fixture.methods))
            self.assertGreater(len(fixture.task_queries), 1)
            for query in fixture.task_queries:
                self.assertNotIn('userfilter', query)
            seed('remove')
        finally:
            fixture.stop()

    def assert_remote_blocks_migration(self):
        timeout = self.arguments.maintenance_timeout
        self.arguments.maintenance_timeout = 12
        try:
            with self.assertRaisesRegex(maintenance.MaintenanceError, 'did not reach migration'):
                self.upgrade()
        finally:
            self.arguments.maintenance_timeout = timeout
        self.assertEqual('draining', self.phase())
        active = json.loads((self.root / '.maintenance-transactions' / 'active.json').read_text())
        journal = json.loads((self.root / '.maintenance-transactions' / active['id'] / 'journal.json').read_text())
        self.assertFalse(journal.get('mutation_started', False))
        self.assertNotIn('database_sha256', journal)
        self.assert_probe_restored()

    def query(self, statement):
        with tempfile.TemporaryFile() as source, tempfile.TemporaryFile() as output:
            source.write(statement.encode())
            source.seek(0)
            self.ops.database_command(maintenance.ROOT_CLIENT +
                                      'mariadb --defaults-extra-file="$maintenance_client" --batch --skip-column-names\n',
                                      source=source, destination=output)
            output.seek(0)
            return output.read().decode().strip()

    def stage(self, label):
        for managed in deployment.build_managed_files(self.arguments):
            managed.staged.parent.mkdir(mode=0o700, exist_ok=True)
            shutil.copyfile(managed.current, managed.staged)
        with (self.staging / 'runtime.env').open('a') as stream:
            stream.write('\n# disposable acceptance: ' + label + '\n')

    def upgrade(self, kind=maintenance.Upgrade):
        with deployment.deployment_lock(Path(self.arguments.transaction_lock_file)):
            try:
                return kind(self.arguments, self.docker, deployment).run(deployment.build_managed_files(self.arguments))
            except maintenance.MaintenanceError as error:
                cause = error
                while cause is not None:
                    if isinstance(cause, maintenance.MaintenanceError):
                        print('Maintenance boundary: ' + str(cause), file=sys.stderr)
                        for frame in traceback.extract_tb(cause.__traceback__):
                            print(f'  {Path(frame.filename).name}:{frame.lineno} {frame.name}', file=sys.stderr)
                    cause = cause.__context__
                raise

    def phase(self):
        return (Path(self.arguments.maintenance_directory) / 'state').read_text().strip()

    def assert_open(self):
        self.assertEqual('open', self.phase())
        self.assertFalse((self.root / '.maintenance-transactions' / 'active.json').exists())

    def assert_probe_restored(self):
        self.assertEqual('0027FF5C0A', self.query('SELECT HEX(payload) FROM hoddmimir.maintenance_acceptance_probe'))
        self.assertEqual('0', self.query("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA='hoddmimir' "
                                        "AND TABLE_NAME IN ('partial_ddl','before_release_marker')"))

    def kill_at(self, boundary, table):
        self.assertRegex(table, r'^[a-z_]+$')
        outer = self

        class KillAtBoundary(maintenance.Upgrade):
            def checkpoint(self, phase):
                super().checkpoint(phase)
                if phase == boundary:
                    outer.sql('CREATE TABLE hoddmimir.' + table + '(id INT) ENGINE=InnoDB;')
                    os.kill(os.getpid(), signal.SIGKILL)

        child = os.fork()
        if child == 0:
            try:
                self.upgrade(KillAtBoundary)
            except BaseException:
                os._exit(84)
            os._exit(85)
        _, status = os.waitpid(child, 0)
        self.assertTrue(os.WIFSIGNALED(status), 'Rehearsal did not reach the requested durable boundary.')
        self.assertEqual(signal.SIGKILL, os.WTERMSIG(status))
