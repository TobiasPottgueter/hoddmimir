<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260715000100 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Fence automatic shadow promotion and explain per-guest winner deduplication.';
    }

    public function isTransactional(): bool
    {
        return false;
    }

    public function up(Schema $schema): void
    {
        $this->replaceGateChecks(true);
        $this->addSql('ALTER TABLE backup_requests ADD CONSTRAINT uq_backup_requests_shadow_decision UNIQUE (shadow_decision_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE backup_requests ADD INDEX fk_backup_requests_shadow (shadow_decision_id), DROP INDEX uq_backup_requests_shadow_decision');
        $this->replaceGateChecks(false);
    }

    private function replaceGateChecks(bool $expanded): void
    {
        $this->addSql('ALTER TABLE scheduler_decision_gates DROP CONSTRAINT chk_scheduler_decision_gates_code');
        $this->addSql('ALTER TABLE scheduler_decision_gates DROP CONSTRAINT chk_scheduler_decision_gates_detail');
        $codes = "'connection_enabled', 'cluster_enabled', 'node_enabled', 'guest_enabled', 'policy_enabled', 'target_enabled', 'explicit_exclusion_absent', 'guest_active', 'placement_present', 'placement_fresh', 'inventory_fresh', 'active_request_absent', 'target_node_allowed', 'target_storage_enabled', 'target_storage_active', 'executor_authorized', 'executor_authorization_fresh', 'capacity_fresh', 'minimum_free_space', 'node_concurrency', 'target_concurrency', 'pbs_mapping_valid'";
        $details = "'passed', 'disabled', 'explicitly_excluded', 'archived', 'missing', 'stale', 'not_allowed', 'inactive', 'unauthorized', 'insufficient_free_space', 'concurrency_limit_reached', 'invalid_mapping', 'active_request_exists'";
        if ($expanded) {
            $codes .= ", 'policy_retention_compatible', 'higher_ranked_candidate_absent'";
            $details .= ", 'incompatible', 'higher_ranked_candidate'";
        }
        $this->addSql(sprintf(
            'ALTER TABLE scheduler_decision_gates ADD CONSTRAINT chk_scheduler_decision_gates_code CHECK (code IN (%s))',
            $codes,
        ));
        $this->addSql(sprintf(
            'ALTER TABLE scheduler_decision_gates ADD CONSTRAINT chk_scheduler_decision_gates_detail CHECK (detail_code IN (%s))',
            $details,
        ));
    }
}
