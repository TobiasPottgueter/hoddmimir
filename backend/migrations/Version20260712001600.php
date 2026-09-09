<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260712001600 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add revisioned Proxmox connection, endpoint, and credential administration.';
    }

    public function isTransactional(): bool
    {
        return false;
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE configuration_command_idempotency ADD secret_replay_hash VARCHAR(255) CHARACTER SET ascii COLLATE ascii_bin NULL AFTER payload_hash');
        $this->addSql('ALTER TABLE configuration_command_idempotency DROP CONSTRAINT chk_configuration_idempotency_command');
        $this->addSql("ALTER TABLE configuration_command_idempotency ADD CONSTRAINT chk_configuration_idempotency_command CHECK (command_type IN ('target.create','target.update','target.enable','target.disable','policy.create','policy.update','policy.enable','policy.disable','selection.upsert','selection.disable','guest_override.upsert','guest_override.disable','connection.create','connection.update','connection.enable','connection.disable','endpoint.create','endpoint.update','endpoint.disable','credential.rotate'))");
        $this->addSql(<<<'SQL'
ALTER TABLE configuration_command_idempotency
ADD CONSTRAINT chk_configuration_idempotency_secret
CHECK (secret_replay_hash IS NULL OR secret_replay_hash LIKE '$argon2id$%')
SQL);
        $this->replaceAuditChecks(true);
        $this->privileges('GRANT');
    }

    public function down(Schema $schema): void
    {
        $this->privileges('REVOKE');
        $this->replaceAuditChecks(false);
        $this->addSql('ALTER TABLE configuration_command_idempotency DROP CONSTRAINT chk_configuration_idempotency_secret');
        $this->addSql('ALTER TABLE configuration_command_idempotency DROP CONSTRAINT chk_configuration_idempotency_command');
        $this->addSql("ALTER TABLE configuration_command_idempotency ADD CONSTRAINT chk_configuration_idempotency_command CHECK (command_type IN ('target.create','target.update','target.enable','target.disable','policy.create','policy.update','policy.enable','policy.disable','selection.upsert','selection.disable','guest_override.upsert','guest_override.disable'))");
        $this->addSql('ALTER TABLE configuration_command_idempotency DROP COLUMN secret_replay_hash');
    }

    private function replaceAuditChecks(bool $expanded): void
    {
        $this->addSql('ALTER TABLE audit_events DROP CONSTRAINT chk_audit_events_type');
        $this->addSql('ALTER TABLE audit_events DROP CONSTRAINT chk_audit_events_subject_type');
        $types = "'first_admin_created','user_created','user_updated','user_disabled','role_assigned','role_removed','login_succeeded','login_failed','session_created','session_revoked','target_created','target_updated','target_enabled','target_disabled','policy_created','policy_updated','policy_enabled','policy_disabled','selection_upserted','selection_disabled','guest_override_upserted','guest_override_disabled'";
        $subjects = "'user','session','role','target','policy','selection','guest_override'";
        if ($expanded) {
            $types .= ",'connection_created','connection_updated','connection_enabled','connection_disabled','endpoint_created','endpoint_updated','endpoint_disabled','credential_rotated'";
            $subjects .= ",'connection'";
        }
        $this->addSql(sprintf('ALTER TABLE audit_events ADD CONSTRAINT chk_audit_events_type CHECK (event_type IN (%s))', $types));
        $this->addSql(sprintf('ALTER TABLE audit_events ADD CONSTRAINT chk_audit_events_subject_type CHECK (subject_type IS NULL OR subject_type IN (%s))', $subjects));
    }

    private function privileges(string $operation): void
    {
        $database = $this->connection->getDatabase();
        if (!is_string($database) || 1 !== preg_match('/^[A-Za-z0-9_]+$/D', $database) || !in_array($operation, ['GRANT', 'REVOKE'], true)) {
            throw new \RuntimeException('A connection-administration grant identifier is invalid.');
        }
        $direction = 'GRANT' === $operation ? 'TO' : 'FROM';
        $grants = [
            'proxmox_connections' => 'SELECT (revision, created_at, updated_at), INSERT, UPDATE (display_name, enabled, revision, updated_at)',
            'proxmox_connection_endpoints' => 'SELECT (priority, tls_mode, custom_ca_pem, sha256_fingerprint, last_attempted_at, last_success_at, last_error_code), INSERT, UPDATE (host, port, priority, enabled, tls_mode, custom_ca_pem, sha256_fingerprint, updated_at)',
            'proxmox_credentials' => 'SELECT (id, connection_id, purpose, principal, token_name, revision, rotated_at, updated_at), INSERT, UPDATE (principal, token_name, secret_envelope, envelope_version, key_id, revision, rotated_at, updated_at)',
        ];
        foreach ($grants as $table => $privilege) {
            $this->addSql(sprintf("%s %s ON `%s`.`%s` %s 'hoddmimir_web'@'%%'", $operation, $privilege, $database, $table, $direction));
        }
    }
}
