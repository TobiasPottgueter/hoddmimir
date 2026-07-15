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
        $duplicateDecision = $this->connection->fetchOne(<<<'SQL'
SELECT shadow_decision_id
FROM backup_requests
WHERE shadow_decision_id IS NOT NULL
GROUP BY shadow_decision_id
HAVING COUNT(*) > 1
LIMIT 1
SQL);
        $this->abortIf(false !== $duplicateDecision, 'Duplicate automatic shadow promotions must be resolved before adding the uniqueness fence.');

        if (!$this->indexExists('backup_requests', 'uq_backup_requests_shadow_decision')) {
            $this->addSql('ALTER TABLE backup_requests ADD CONSTRAINT uq_backup_requests_shadow_decision UNIQUE (shadow_decision_id)');
        }
        $this->replaceGateChecks();
    }

    public function down(Schema $schema): void
    {
        // This is an expand-only compatibility migration. A rolling rollback
        // may already have persisted the added gate vocabulary and promoted
        // requests. Contracting either constraint would reject or weaken that
        // evidence, so cleanup belongs to a later, separately proven contract
        // migration after all old images have left the rollback window.
    }

    private function replaceGateChecks(): void
    {
        $codes = "'connection_enabled', 'cluster_enabled', 'node_enabled', 'guest_enabled', 'policy_enabled', 'target_enabled', 'explicit_exclusion_absent', 'guest_active', 'placement_present', 'placement_fresh', 'inventory_fresh', 'active_request_absent', 'target_node_allowed', 'target_storage_enabled', 'target_storage_active', 'executor_authorized', 'executor_authorization_fresh', 'capacity_fresh', 'minimum_free_space', 'node_concurrency', 'target_concurrency', 'pbs_mapping_valid'";
        $details = "'passed', 'disabled', 'explicitly_excluded', 'archived', 'missing', 'stale', 'not_allowed', 'inactive', 'unauthorized', 'insufficient_free_space', 'concurrency_limit_reached', 'invalid_mapping', 'active_request_exists'";
        $codes .= ", 'policy_retention_compatible', 'higher_ranked_candidate_absent'";
        $details .= ", 'incompatible', 'higher_ranked_candidate'";

        $clauses = [];
        foreach (['chk_scheduler_decision_gates_code', 'chk_scheduler_decision_gates_detail'] as $constraint) {
            if ($this->checkConstraintExists('scheduler_decision_gates', $constraint)) {
                $clauses[] = 'DROP CONSTRAINT '.$constraint;
            }
        }
        $clauses[] = sprintf(
            'ADD CONSTRAINT chk_scheduler_decision_gates_code CHECK (code IN (%s))',
            $codes,
        );
        $clauses[] = sprintf(
            'ADD CONSTRAINT chk_scheduler_decision_gates_detail CHECK (detail_code IN (%s))',
            $details,
        );

        // MariaDB DDL commits implicitly. Replacing both checks in one ALTER
        // keeps the table constrained even if the migration process dies.
        $this->addSql("ALTER TABLE scheduler_decision_gates\n    ".implode(",\n    ", $clauses));
    }

    private function indexExists(string $table, string $index): bool
    {
        return false !== $this->connection->fetchOne(
            <<<'SQL'
SELECT 1
FROM information_schema.STATISTICS
WHERE TABLE_SCHEMA = DATABASE()
  AND TABLE_NAME = :table_name
  AND INDEX_NAME = :index_name
LIMIT 1
SQL,
            ['table_name' => $table, 'index_name' => $index],
        );
    }

    private function checkConstraintExists(string $table, string $constraint): bool
    {
        return false !== $this->connection->fetchOne(
            <<<'SQL'
SELECT 1
FROM information_schema.TABLE_CONSTRAINTS
WHERE CONSTRAINT_SCHEMA = DATABASE()
  AND TABLE_NAME = :table_name
  AND CONSTRAINT_NAME = :constraint_name
  AND CONSTRAINT_TYPE = 'CHECK'
LIMIT 1
SQL,
            ['table_name' => $table, 'constraint_name' => $constraint],
        );
    }
}
