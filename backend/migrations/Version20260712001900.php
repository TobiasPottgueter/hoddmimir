<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260712001900 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Make cancellation dispatch explicitly at-most-once and auditable after worker loss.';
    }

    public function isTransactional(): bool
    {
        return false;
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
ALTER TABLE backup_runs
    DROP CONSTRAINT chk_backup_runs_stop,
    MODIFY stop_attempt_status VARCHAR(24) CHARACTER SET ascii COLLATE ascii_bin NULL,
    ADD stop_attempt_resolved_at DATETIME(6) NULL AFTER stop_attempt_status
SQL);
        $this->addSql("UPDATE backup_runs SET stop_attempt_status='dispatching' WHERE stop_attempt_claimed_at IS NOT NULL AND stop_attempt_status IS NULL");
        $this->addSql("UPDATE backup_runs SET stop_attempt_status='definitive_rejection' WHERE stop_attempt_status='failed'");
        $this->addSql('UPDATE backup_runs SET stop_attempt_resolved_at=stop_attempt_claimed_at WHERE stop_attempt_status IN (\'requested\',\'ambiguous\',\'definitive_rejection\')');
        $this->addSql(<<<'SQL'
ALTER TABLE backup_runs
    ADD CONSTRAINT chk_backup_runs_stop CHECK (
        (stop_attempt_claimed_at IS NULL AND stop_attempt_status IS NULL
            AND stop_attempt_resolved_at IS NULL AND stop_failure_code IS NULL)
        OR (stop_attempt_claimed_at IS NOT NULL AND stop_attempt_status = 'dispatching'
            AND stop_attempt_resolved_at IS NULL AND stop_failure_code IS NULL)
        OR (stop_attempt_claimed_at IS NOT NULL AND stop_attempt_status IN ('requested','ambiguous')
            AND stop_attempt_resolved_at IS NOT NULL AND stop_failure_code IS NULL)
        OR (stop_attempt_claimed_at IS NOT NULL
            AND stop_attempt_status IN ('definitive_rejection','dispatch_unknown')
            AND stop_attempt_resolved_at IS NOT NULL AND stop_failure_code IS NOT NULL)
    )
SQL);
        $this->addSql(<<<'SQL'
ALTER TABLE backup_problem_states
    DROP CONSTRAINT chk_backup_problem_state_code,
    ADD CONSTRAINT chk_backup_problem_state_code CHECK (problem_code IN (
        'submission_rejected', 'task_failed', 'capacity_blocked', 'permission_blocked',
        'evidence_stale', 'placement_changed', 'configuration_blocked',
        'reconciliation_required', 'monitoring_unknown', 'cancel_dispatch_unknown'
    ))
SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql("UPDATE backup_problem_states SET problem_code='monitoring_unknown' WHERE problem_code='cancel_dispatch_unknown'");
        $this->addSql(<<<'SQL'
ALTER TABLE backup_problem_states
    DROP CONSTRAINT chk_backup_problem_state_code,
    ADD CONSTRAINT chk_backup_problem_state_code CHECK (problem_code IN (
        'submission_rejected', 'task_failed', 'capacity_blocked', 'permission_blocked',
        'evidence_stale', 'placement_changed', 'configuration_blocked',
        'reconciliation_required', 'monitoring_unknown'
    ))
SQL);
        $this->addSql('ALTER TABLE backup_runs DROP CONSTRAINT chk_backup_runs_stop');
        $this->addSql("UPDATE backup_runs SET stop_attempt_status='failed' WHERE stop_attempt_status='definitive_rejection'");
        $this->addSql("UPDATE backup_runs SET stop_attempt_status='ambiguous' WHERE stop_attempt_status='dispatch_unknown'");
        $this->addSql("UPDATE backup_runs SET stop_attempt_status=NULL WHERE stop_attempt_status='dispatching'");
        $this->addSql(<<<'SQL'
ALTER TABLE backup_runs
    DROP COLUMN stop_attempt_resolved_at,
    MODIFY stop_attempt_status VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NULL,
    ADD CONSTRAINT chk_backup_runs_stop CHECK (
        stop_attempt_status IS NULL OR stop_attempt_status IN ('requested','ambiguous','failed')
    )
SQL);
    }
}
