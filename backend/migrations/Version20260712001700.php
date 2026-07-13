<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
use RuntimeException;

final class Version20260712001700 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add idempotent audited WebApp backup-operation commands with least-privilege grants.';
    }

    public function isTransactional(): bool
    {
        return false;
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
CREATE TABLE backup_operation_commands (
    actor_user_id BINARY(16) NOT NULL,
    idempotency_key VARCHAR(128) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    command_type VARCHAR(24) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    subject_id BINARY(16) NOT NULL,
    payload_hash BINARY(32) NOT NULL,
    result_status VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    result_revision BIGINT UNSIGNED NULL,
    blocker_code VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL,
    created_at DATETIME(6) NOT NULL,
    PRIMARY KEY (actor_user_id, idempotency_key),
    CONSTRAINT fk_backup_operation_actor FOREIGN KEY (actor_user_id) REFERENCES users (id) ON DELETE RESTRICT,
    CONSTRAINT chk_backup_operation_type CHECK (command_type IN ('manual_request','cancel_request')),
    CONSTRAINT chk_backup_operation_result CHECK (
        result_status IN ('applied','conflict','blocked')
        AND ((result_status IN ('applied','conflict') AND result_revision IS NOT NULL AND blocker_code IS NULL)
          OR (result_status = 'blocked' AND result_revision IS NULL AND blocker_code IS NOT NULL))
        AND (result_revision IS NULL OR result_revision > 0)
    )
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);
        $this->replaceAuditChecks(true);
        $this->privileges('GRANT');
    }

    public function down(Schema $schema): void
    {
        $this->privileges('REVOKE');
        $this->replaceAuditChecks(false);
        $this->addSql('DROP TABLE backup_operation_commands');
    }

    private function privileges(string $operation): void
    {
        $database = $this->connection->getDatabase();
        if (!is_string($database) || 1 !== preg_match('/^[A-Za-z0-9_]+$/D', $database) || !in_array($operation, ['GRANT', 'REVOKE'], true)) {
            throw new RuntimeException('Invalid operations grant identifier.');
        }
        $direction = 'GRANT' === $operation ? 'TO' : 'FROM';
        $grants = [
            'backup_operation_commands' => 'SELECT, INSERT',
            'guests' => 'SELECT (provisioned_size_bytes)',
            'guest_placements' => 'SELECT (placement_revision)',
            'backup_requests' => 'INSERT (id, root_request_id, attempt, origin, state, reason, priority, scheduled_at, available_at, connection_id, cluster_id, guest_id, node_id, placement_revision, placement_observed_at, policy_id, policy_revision, target_id, target_revision, resolved_policy_json, resolved_policy_hash, expected_size_bytes, retry_disposition, created_at, updated_at), UPDATE (state, cancel_requested_at, terminal_code, terminal_at, revision, updated_at)',
            'backup_request_events' => 'INSERT',
        ];
        foreach ($grants as $table => $privilege) {
            $this->addSql(sprintf("%s %s ON `%s`.`%s` %s 'hoddmimir_web'@'%%'", $operation, $privilege, $database, $table, $direction));
        }
    }

    private function replaceAuditChecks(bool $operations): void
    {
        $this->addSql('ALTER TABLE audit_events DROP CONSTRAINT chk_audit_events_type');
        $this->addSql('ALTER TABLE audit_events DROP CONSTRAINT chk_audit_events_subject_type');
        $types = "'first_admin_created','user_created','user_updated','user_disabled','role_assigned','role_removed','login_succeeded','login_failed','session_created','session_revoked','target_created','target_updated','target_enabled','target_disabled','policy_created','policy_updated','policy_enabled','policy_disabled','selection_upserted','selection_disabled','guest_override_upserted','guest_override_disabled','connection_created','connection_updated','connection_enabled','connection_disabled','endpoint_created','endpoint_updated','endpoint_disabled','credential_rotated'";
        $subjects = "'user','session','role','target','policy','selection','guest_override','connection'";
        if ($operations) {
            $types .= ",'manual_backup_requested','backup_cancel_requested'";
            $subjects .= ",'backup_request'";
        }
        $this->addSql(sprintf('ALTER TABLE audit_events ADD CONSTRAINT chk_audit_events_type CHECK (event_type IN (%s))', $types));
        $this->addSql(sprintf('ALTER TABLE audit_events ADD CONSTRAINT chk_audit_events_subject_type CHECK (subject_type IS NULL OR subject_type IN (%s))', $subjects));
    }
}
