<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
use RuntimeException;

final class Version20260712001500 extends AbstractMigration
{
    private const array TABLES = ['backup_problem_states', 'backup_notification_outbox'];

    public function getDescription(): string
    {
        return 'Add durable per-attempt backup notification outbox and recovery state.';
    }

    public function isTransactional(): bool
    {
        return false;
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
CREATE TABLE backup_problem_states (
    root_request_id BINARY(16) NOT NULL,
    problem_code VARCHAR(48) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    consecutive_failures INT UNSIGNED NOT NULL,
    opened_at DATETIME(6) NOT NULL,
    last_occurred_at DATETIME(6) NOT NULL,
    last_notified_at DATETIME(6) NOT NULL,
    revision BIGINT UNSIGNED NOT NULL DEFAULT 1,
    PRIMARY KEY (root_request_id),
    CONSTRAINT fk_backup_problem_state_root FOREIGN KEY (root_request_id)
        REFERENCES backup_requests (id) ON DELETE CASCADE,
    CONSTRAINT chk_backup_problem_state_code CHECK (problem_code IN (
        'submission_rejected', 'task_failed', 'capacity_blocked', 'permission_blocked',
        'evidence_stale', 'placement_changed', 'configuration_blocked',
        'reconciliation_required', 'monitoring_unknown'
    )),
    CONSTRAINT chk_backup_problem_state_count CHECK (consecutive_failures > 0 AND revision > 0),
    CONSTRAINT chk_backup_problem_state_time CHECK (
        last_occurred_at >= opened_at AND last_notified_at >= opened_at
    )
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);
        $this->addSql(<<<'SQL'
CREATE TABLE backup_notification_outbox (
    id BINARY(16) NOT NULL,
    root_request_id BINARY(16) NOT NULL,
    request_id BINARY(16) NOT NULL,
    run_id BINARY(16) NOT NULL,
    notification_kind VARCHAR(24) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    event_key VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    attempt INT UNSIGNED NOT NULL,
    payload_json LONGTEXT NOT NULL,
    state VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'pending',
    delivery_attempts INT UNSIGNED NOT NULL DEFAULT 0,
    available_at DATETIME(6) NOT NULL,
    claim_token BINARY(16) NULL,
    claimed_at DATETIME(6) NULL,
    lease_expires_at DATETIME(6) NULL,
    last_error_code VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NULL,
    created_at DATETIME(6) NOT NULL,
    sent_at DATETIME(6) NULL,
    revision BIGINT UNSIGNED NOT NULL DEFAULT 1,
    PRIMARY KEY (id),
    CONSTRAINT uq_backup_notification_run_event UNIQUE (run_id, event_key),
    INDEX idx_backup_notification_delivery (state, available_at, lease_expires_at, id),
    CONSTRAINT fk_backup_notification_root FOREIGN KEY (root_request_id)
        REFERENCES backup_requests (id) ON DELETE CASCADE,
    CONSTRAINT fk_backup_notification_request FOREIGN KEY (request_id)
        REFERENCES backup_requests (id) ON DELETE CASCADE,
    CONSTRAINT fk_backup_notification_run FOREIGN KEY (run_id)
        REFERENCES backup_runs (id) ON DELETE CASCADE,
    CONSTRAINT chk_backup_notification_kind CHECK (notification_kind IN ('failure', 'attention_required', 'recovery')),
    CONSTRAINT chk_backup_notification_event_key CHECK (event_key REGEXP '^[a-z0-9][a-z0-9._-]{0,63}$'),
    CONSTRAINT chk_backup_notification_state CHECK (state IN ('pending', 'claimed', 'sent')),
    CONSTRAINT chk_backup_notification_payload CHECK (JSON_VALID(payload_json)),
    CONSTRAINT chk_backup_notification_attempt CHECK (attempt > 0 AND revision > 0),
    CONSTRAINT chk_backup_notification_delivery_attempt CHECK (
        (state = 'pending' AND claim_token IS NULL AND claimed_at IS NULL AND lease_expires_at IS NULL AND sent_at IS NULL)
        OR (state = 'claimed' AND claim_token IS NOT NULL AND claimed_at IS NOT NULL
            AND lease_expires_at > claimed_at AND sent_at IS NULL AND delivery_attempts > 0)
        OR (state = 'sent' AND claim_token IS NULL AND claimed_at IS NULL
            AND lease_expires_at IS NULL AND sent_at IS NOT NULL AND delivery_attempts > 0)
    ),
    CONSTRAINT chk_backup_notification_time CHECK (available_at >= created_at AND (sent_at IS NULL OR sent_at >= created_at)),
    CONSTRAINT chk_backup_notification_error CHECK (
        last_error_code IS NULL OR last_error_code IN ('configuration', 'transport', 'rejected', 'invalid_response')
    )
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);
        $this->privileges('GRANT');
    }

    public function down(Schema $schema): void
    {
        $this->privileges('REVOKE');
        $this->addSql('DROP TABLE backup_notification_outbox');
        $this->addSql('DROP TABLE backup_problem_states');
    }

    private function privileges(string $operation): void
    {
        $database = $this->connection->getDatabase();
        if (!is_string($database) || 1 !== preg_match('/^[A-Za-z0-9_]+$/D', $database)) {
            throw new RuntimeException('Invalid notification grant database.');
        }
        $direction = 'GRANT' === $operation ? 'TO' : 'FROM';
        foreach (self::TABLES as $table) {
            $workerPrivileges = 'backup_problem_states' === $table
                ? 'SELECT, INSERT, UPDATE, DELETE'
                : 'SELECT, INSERT, UPDATE';
            $this->addSql(sprintf(
                "%s %s ON `%s`.`%s` %s 'hoddmimir_backup_worker'@'%%'",
                $operation,
                $workerPrivileges,
                $database,
                $table,
                $direction,
            ));
            $this->addSql(sprintf(
                "%s SELECT ON `%s`.`%s` %s 'hoddmimir_web'@'%%'",
                $operation,
                $database,
                $table,
                $direction,
            ));
        }
    }
}
