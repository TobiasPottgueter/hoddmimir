<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
use RuntimeException;

final class Version20260712001300 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add fenced backup monitoring, cancellation, recovery, retry, and complete task logs.';
    }

    public function isTransactional(): bool
    {
        return false;
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE worker_heartbeats DROP CONSTRAINT chk_worker_heartbeats_cycle, ADD CONSTRAINT chk_worker_heartbeats_cycle CHECK ((worker_kind='collector' AND ((status='busy' AND current_cycle_token IS NOT NULL AND current_activity IS NOT NULL) OR (status<>'busy' AND current_cycle_token IS NULL))) OR (worker_kind='backup' AND current_cycle_token IS NULL AND (status<>'busy' OR current_activity IS NOT NULL)))");
        $this->addSql('ALTER TABLE backup_requests ADD cancel_requested_at DATETIME(6) NULL, ADD terminal_code VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL, ADD terminal_at DATETIME(6) NULL, ADD CONSTRAINT uq_backup_requests_root_attempt UNIQUE (root_request_id, attempt), ADD CONSTRAINT chk_backup_requests_terminal CHECK ((state IN (\'succeeded\',\'failed\',\'cancelled\',\'unknown\')) = (terminal_at IS NOT NULL))');
        $this->addSql(<<<'SQL'
ALTER TABLE backup_runs
    ADD next_log_offset BIGINT UNSIGNED NOT NULL DEFAULT 0,
    ADD cancel_requested_at DATETIME(6) NULL,
    ADD stop_attempt_claimed_at DATETIME(6) NULL,
    ADD stop_attempt_status VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NULL,
    ADD stop_failure_code VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NULL,
    ADD status_observed_at DATETIME(6) NULL,
    ADD status_failure_code VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NULL,
    ADD exit_status VARCHAR(255) NULL,
    ADD recovery_outcome VARCHAR(24) CHARACTER SET ascii COLLATE ascii_bin NULL,
    ADD recovery_checked_at DATETIME(6) NULL,
    ADD submission_node VARCHAR(190) NULL,
    ADD submission_vmid INT UNSIGNED NULL,
    ADD submission_user VARCHAR(255) NULL,
    ADD submission_window_start DATETIME(6) NULL,
    ADD submission_window_end DATETIME(6) NULL,
    ADD CONSTRAINT chk_backup_runs_stop CHECK (
        stop_attempt_status IS NULL OR stop_attempt_status IN ('requested','ambiguous','failed')
    ),
    ADD CONSTRAINT chk_backup_runs_recovery CHECK (
        recovery_outcome IS NULL OR recovery_outcome IN ('matched','proven_not_started','multiple_matches','inconclusive')
    ),
    ADD CONSTRAINT chk_backup_runs_submission_identity CHECK (
        (submission_node IS NULL AND submission_vmid IS NULL AND submission_user IS NULL
            AND submission_window_start IS NULL AND submission_window_end IS NULL)
        OR (submission_node IS NOT NULL AND submission_vmid BETWEEN 1 AND 999999999
            AND submission_user IS NOT NULL AND submission_window_start IS NOT NULL
            AND submission_window_end IS NOT NULL AND submission_window_end >= submission_window_start
            AND submission_window_end <= submission_window_start + INTERVAL 300 SECOND)
    )
SQL);
        $this->addSql('ALTER TABLE backup_run_log_entries MODIFY content MEDIUMTEXT NOT NULL');
        $this->addSql('ALTER TABLE backup_run_log_entries ADD CONSTRAINT chk_backup_run_logs_content CHECK (OCTET_LENGTH(content) BETWEEN 1 AND 65536)');
        $this->addSql(<<<'SQL'
CREATE SQL SECURITY DEFINER VIEW backup_credentials AS
SELECT id, connection_id, purpose, auth_scheme, principal, token_name,
       secret_envelope, envelope_version, key_id, revision, created_at,
       rotated_at, updated_at
FROM proxmox_credentials
WHERE purpose = 'backup'
SQL);
        $this->privilege('GRANT');
    }

    public function down(Schema $schema): void
    {
        $this->privilege('REVOKE');
        $this->addSql('DROP VIEW backup_credentials');
        $this->addSql('ALTER TABLE backup_run_log_entries DROP CONSTRAINT chk_backup_run_logs_content');
        $this->addSql('ALTER TABLE backup_run_log_entries MODIFY content VARCHAR(8192) NOT NULL');
        $this->addSql('ALTER TABLE backup_runs DROP CONSTRAINT chk_backup_runs_submission_identity, DROP CONSTRAINT chk_backup_runs_recovery, DROP CONSTRAINT chk_backup_runs_stop, DROP COLUMN submission_window_end, DROP COLUMN submission_window_start, DROP COLUMN submission_user, DROP COLUMN submission_vmid, DROP COLUMN submission_node, DROP COLUMN recovery_checked_at, DROP COLUMN recovery_outcome, DROP COLUMN exit_status, DROP COLUMN status_failure_code, DROP COLUMN status_observed_at, DROP COLUMN stop_failure_code, DROP COLUMN stop_attempt_status, DROP COLUMN stop_attempt_claimed_at, DROP COLUMN cancel_requested_at, DROP COLUMN next_log_offset');
        $this->addSql('ALTER TABLE backup_requests DROP CONSTRAINT chk_backup_requests_terminal, DROP INDEX uq_backup_requests_root_attempt, DROP COLUMN terminal_at, DROP COLUMN terminal_code, DROP COLUMN cancel_requested_at');
        $this->addSql("ALTER TABLE worker_heartbeats DROP CONSTRAINT chk_worker_heartbeats_cycle, ADD CONSTRAINT chk_worker_heartbeats_cycle CHECK ((status='busy' AND current_cycle_token IS NOT NULL AND current_activity IS NOT NULL) OR (status<>'busy' AND current_cycle_token IS NULL))");
    }

    private function privilege(string $operation): void
    {
        $database = $this->connection->getDatabase();
        if (!is_string($database) || 1 !== preg_match('/^[A-Za-z0-9_]+$/D', $database)
            || !in_array($operation, ['GRANT', 'REVOKE'], true)) {
            throw new RuntimeException('Invalid backup-monitoring grant identifier.');
        }
        $this->addSql(sprintf(
            "%s SELECT ON `%s`.`backup_credentials` %s 'hoddmimir_backup_worker'@'%%'",
            $operation,
            $database,
            'GRANT' === $operation ? 'TO' : 'FROM',
        ));
        $this->addSql(sprintf(
            "%s SELECT, INSERT, UPDATE ON `%s`.`worker_heartbeats` %s 'hoddmimir_backup_worker'@'%%'",
            $operation,
            $database,
            'GRANT' === $operation ? 'TO' : 'FROM',
        ));
    }
}
