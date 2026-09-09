<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260712002200 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Exclude credentials owned by disabled connections at the database view boundary.';
    }

    public function isTransactional(): bool
    {
        return false;
    }

    public function up(Schema $schema): void
    {
        $this->replaceView('collector_credentials', 'collector');
        $this->replaceView('backup_credentials', 'backup');
    }

    public function down(Schema $schema): void
    {
        $this->restoreView('collector_credentials', 'collector');
        $this->restoreView('backup_credentials', 'backup');
    }

    private function replaceView(string $view, string $purpose): void
    {
        $this->addSql(sprintf(<<<'SQL'
CREATE OR REPLACE SQL SECURITY DEFINER VIEW %s AS
SELECT credential.id, credential.connection_id, credential.purpose, credential.auth_scheme,
       credential.principal, credential.token_name, credential.secret_envelope,
       credential.envelope_version, credential.key_id, credential.revision,
       credential.created_at, credential.rotated_at, credential.updated_at
FROM proxmox_credentials credential
INNER JOIN proxmox_connections connection
        ON connection.id = credential.connection_id AND connection.enabled = 1
WHERE credential.purpose = '%s'
SQL, $view, $purpose));
    }

    private function restoreView(string $view, string $purpose): void
    {
        $this->addSql(sprintf(<<<'SQL'
CREATE OR REPLACE SQL SECURITY DEFINER VIEW %s AS
SELECT id, connection_id, purpose, auth_scheme, principal, token_name,
       secret_envelope, envelope_version, key_id, revision, created_at,
       rotated_at, updated_at
FROM proxmox_credentials
WHERE purpose = '%s'
SQL, $view, $purpose));
    }
}
