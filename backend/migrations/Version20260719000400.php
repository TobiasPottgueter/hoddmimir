<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260719000400 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add precise scheduler evidence for configured PVE failure notifications.';
    }

    public function isTransactional(): bool
    {
        return false;
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
ALTER TABLE scheduler_decision_gates
    DROP CONSTRAINT chk_scheduler_decision_gates_code,
    DROP CONSTRAINT chk_scheduler_decision_gates_detail,
    ADD CONSTRAINT chk_scheduler_decision_gates_code CHECK (code IN (
        'connection_enabled', 'cluster_enabled', 'node_enabled', 'guest_enabled', 'policy_enabled',
        'policy_failure_notification_configured', 'policy_retention_compatible', 'target_enabled',
        'explicit_exclusion_absent', 'guest_active', 'placement_present', 'placement_fresh',
        'inventory_fresh', 'active_request_absent', 'target_node_allowed', 'target_storage_enabled',
        'target_storage_active', 'executor_authorized', 'executor_authorization_fresh', 'capacity_fresh',
        'minimum_free_space', 'node_concurrency', 'target_concurrency', 'pbs_mapping_valid',
        'higher_ranked_candidate_absent'
    )),
    ADD CONSTRAINT chk_scheduler_decision_gates_detail CHECK (detail_code IN (
        'passed', 'disabled', 'explicitly_excluded', 'archived', 'missing', 'stale', 'not_allowed',
        'inactive', 'unauthorized', 'insufficient_free_space', 'concurrency_limit_reached',
        'invalid_mapping', 'active_request_exists', 'incompatible', 'unconfigured',
        'higher_ranked_candidate'
    ))
SQL);
    }

    public function down(Schema $schema): void
    {
        // Expand-only: persisted gate evidence must remain readable during rolling rollback.
    }
}
