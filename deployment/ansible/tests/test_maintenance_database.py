"""Opt-in real MariaDB contract. Never uses an inventory or a production host."""
from __future__ import annotations

import importlib.util
import io
import os
import secrets
import tempfile
import time
import unittest
import uuid
from pathlib import Path
from types import SimpleNamespace

SOURCE = Path(__file__).resolve().parents[1] / 'roles/hoddmimir/files/maintenance_upgrade.py'
spec = importlib.util.spec_from_file_location('maintenance_database_tests', SOURCE)
module = importlib.util.module_from_spec(spec)
spec.loader.exec_module(module)


@unittest.skipUnless(os.environ.get('HODDMIMIR_MARIADB_TEST_IMAGE'), 'Run make maintenance-db-test for the isolated real MariaDB gate.')
class MaintenanceDatabaseTest(unittest.TestCase):
    def test_real_dump_restores_partial_ddl_data_binary_values_views_and_grants(self):
        with tempfile.TemporaryDirectory(prefix='hoddmimir-maintenance-test-') as temporary:
            root = Path(temporary)
            password = root / 'mariadb_root_password'
            password.write_text(secrets.token_hex(32) + '\n')
            password.chmod(0o600)
            name = 'hoddmimir-maintenance-test-' + uuid.uuid4().hex
            directory = root / uuid.uuid4().hex
            directory.mkdir(mode=0o700)
            image = os.environ['HODDMIMIR_MARIADB_TEST_IMAGE']
            compose = root / 'compose.json'
            compose.write_text(__import__('json').dumps({'name': name, 'services': {'mariadb': {
                'image': image, 'platform': 'linux/amd64', 'network_mode': 'none',
                'environment': {'MARIADB_ROOT_PASSWORD_FILE': '/run/secrets/mariadb_root_password', 'MARIADB_DATABASE': 'hoddmimir'},
                'volumes': [str(password) + ':/run/secrets/mariadb_root_password:ro'],
            }}}))
            args = SimpleNamespace(current_compose_file=str(compose), current_migration_compose_file=str(compose),
                                   current_secrets_directory=str(root), maintenance_timeout=120)
            operations = module.DockerMaintenance(SimpleNamespace(executable='docker', wait_timeout=60), args)
            # The separate PHP integration gate tests the real validator on all
            # four DB roles. This test isolates the dump/restore transport.
            validation_calls = []
            operations.validate = lambda: validation_calls.append(True)
            operations.quiet = lambda: True
            try:
                operations.compose_command(['up', '--detach'])
                deadline = time.monotonic() + 60
                while True:
                    try:
                        operations.database_command(module.PING)
                        break
                    except module.MaintenanceError:
                        if time.monotonic() >= deadline: raise
                        time.sleep(1)
                sql = b'''CREATE TABLE hoddmimir.probe (id BINARY(16) PRIMARY KEY, value VARBINARY(255) NOT NULL, revision INT NOT NULL CHECK (revision>0)) ENGINE=InnoDB;
INSERT INTO hoddmimir.probe VALUES (UNHEX('000102030405060708090a0b0c0d0e0f'),UNHEX('0027ff5c0a'),1);
CREATE VIEW hoddmimir.probe_view AS SELECT id,revision FROM hoddmimir.probe;
CREATE USER 'maintenance_fixture'@'%' IDENTIFIED BY 'sanitized-fixture-only';
GRANT SELECT ON hoddmimir.probe_view TO 'maintenance_fixture'@'%';
'''
                with (root / 'seed.sql').open('wb') as stream: stream.write(sql)
                with (root / 'seed.sql').open('rb') as stream: operations.database_command(module.RESTORE, source=stream)
                operations.database_preflight()
                snapshot = directory / 'database.sql'
                checksum = operations.dump(snapshot)
                operations.verify_snapshot(snapshot, checksum, {'database_image': image, 'database_name': 'hoddmimir'}, directory)
                self.assertEqual([True], validation_calls)
                mutation = root / 'mutation.sql'
                mutation.write_text("ALTER TABLE hoddmimir.probe DROP COLUMN value; CREATE TABLE hoddmimir.partial_migration(id INT); REVOKE SELECT ON hoddmimir.probe_view FROM 'maintenance_fixture'@'%';")
                with mutation.open('rb') as stream: operations.database_command(module.RESTORE, source=stream)
                operations.restore(snapshot, checksum)
                restored = directory / 'restored.sql'
                self.assertEqual(checksum, operations.dump(restored))
                self.assertEqual(0o600, snapshot.stat().st_mode & 0o777)
                snapshot.write_bytes(snapshot.read_bytes() + b'corrupt')
                with self.assertRaisesRegex(module.MaintenanceError, 'checksum'): operations.restore(snapshot, checksum)
            finally:
                # Anonymous test volume only; never a deployment Compose file.
                operations.compose_command(['down', '--volumes', '--remove-orphans'])


if __name__ == '__main__': unittest.main()
