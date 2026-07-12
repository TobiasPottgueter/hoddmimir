<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260712000600 extends AbstractMigration
{
    private const array TABLES = [
        'scheduler_evaluation_runs',
        'scheduler_decisions',
        'scheduler_decision_gates',
    ];

    public function getDescription(): string
    {
        return 'Add fenced append-only persistence for non-executable scheduler shadow evaluations.';
    }

    public function isTransactional(): bool
    {
        return false;
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
CREATE TABLE scheduler_evaluation_runs (
    id BINARY(16) NOT NULL,
    cycle_token BINARY(16) NOT NULL,
    collector_fencing_token BIGINT NOT NULL,
    evaluator_version SMALLINT UNSIGNED NOT NULL,
    payload_hash BINARY(32) NOT NULL,
    decision_count INT UNSIGNED NOT NULL,
    gate_count INT UNSIGNED NOT NULL,
    started_at DATETIME(6) NOT NULL,
    completed_at DATETIME(6) NOT NULL,
    persisted_at DATETIME(6) NOT NULL,
    PRIMARY KEY (id),
    CONSTRAINT uq_scheduler_evaluation_runs_cycle UNIQUE (cycle_token),
    CONSTRAINT fk_scheduler_evaluation_runs_cycle FOREIGN KEY (cycle_token, collector_fencing_token)
        REFERENCES collector_cycles (cycle_token, fencing_token) ON DELETE RESTRICT,
    CONSTRAINT chk_scheduler_evaluation_runs_fence CHECK (collector_fencing_token > 0),
    CONSTRAINT chk_scheduler_evaluation_runs_version CHECK (evaluator_version > 0),
    CONSTRAINT chk_scheduler_evaluation_runs_time CHECK (
        started_at <= completed_at AND completed_at <= persisted_at
    )
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

        $this->addSql(<<<'SQL'
CREATE TABLE scheduler_decisions (
    id BINARY(16) NOT NULL,
    evaluation_run_id BINARY(16) NOT NULL,
    decision_ordinal INT UNSIGNED NOT NULL,
    connection_id BINARY(16) NOT NULL,
    cluster_id BINARY(16) NOT NULL,
    guest_id BINARY(16) NOT NULL,
    node_id BINARY(16) NULL,
    placement_revision BIGINT UNSIGNED NULL,
    placement_observed_at DATETIME(6) NULL,
    outcome VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    reason VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NULL,
    priority SMALLINT UNSIGNED NULL,
    policy_id BINARY(16) NULL,
    policy_revision BIGINT UNSIGNED NULL,
    policy_snapshot_hash BINARY(32) NULL,
    target_id BINARY(16) NULL,
    target_revision BIGINT UNSIGNED NULL,
    inventory_observed_at DATETIME(6) NOT NULL,
    capacity_observed_at DATETIME(6) NULL,
    write_state_observed_at DATETIME(6) NULL,
    PRIMARY KEY (id),
    CONSTRAINT uq_scheduler_decisions_run_ordinal UNIQUE (evaluation_run_id, decision_ordinal),
    INDEX idx_scheduler_decisions_guest (guest_id, evaluation_run_id),
    CONSTRAINT fk_scheduler_decisions_run FOREIGN KEY (evaluation_run_id)
        REFERENCES scheduler_evaluation_runs (id) ON DELETE CASCADE,
    CONSTRAINT fk_scheduler_decisions_guest FOREIGN KEY (connection_id, cluster_id, guest_id)
        REFERENCES guests (connection_id, cluster_id, id) ON DELETE RESTRICT,
    CONSTRAINT fk_scheduler_decisions_node FOREIGN KEY (connection_id, cluster_id, node_id)
        REFERENCES pve_nodes (connection_id, cluster_id, id) ON DELETE RESTRICT,
    CONSTRAINT chk_scheduler_decisions_ordinal CHECK (decision_ordinal > 0),
    CONSTRAINT chk_scheduler_decisions_outcome CHECK (
        outcome IN ('eligible', 'blocked', 'not_due', 'deduplicated')
    ),
    CONSTRAINT chk_scheduler_decisions_reason_priority CHECK (
        (reason IS NULL) = (priority IS NULL)
        AND (
            reason IS NULL
            OR (reason = 'manual' AND priority = 400)
            OR (reason = 'never_backed_up' AND priority = 300)
            OR (reason = 'max_age' AND priority = 200)
            OR (reason = 'bytes_written' AND priority = 100)
        )
    ),
    CONSTRAINT chk_scheduler_decisions_placement CHECK (
        (node_id IS NULL) = (placement_revision IS NULL)
        AND (node_id IS NULL) = (placement_observed_at IS NULL)
        AND (node_id IS NULL OR placement_revision > 0)
    ),
    CONSTRAINT chk_scheduler_decisions_policy CHECK (
        (policy_id IS NULL) = (policy_revision IS NULL)
        AND (policy_id IS NULL) = (policy_snapshot_hash IS NULL)
        AND (policy_id IS NULL OR policy_revision > 0)
    ),
    CONSTRAINT chk_scheduler_decisions_target CHECK (
        (target_id IS NULL) = (target_revision IS NULL)
        AND (target_id IS NULL OR target_revision > 0)
    )
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

        $this->addSql(<<<'SQL'
CREATE TABLE scheduler_decision_gates (
    decision_id BINARY(16) NOT NULL,
    gate_ordinal INT UNSIGNED NOT NULL,
    code VARCHAR(40) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    passed TINYINT(1) NOT NULL,
    scope VARCHAR(24) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    subject_id BINARY(16) NOT NULL,
    observed_at DATETIME(6) NULL,
    detail_code VARCHAR(40) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    PRIMARY KEY (decision_id, gate_ordinal),
    CONSTRAINT fk_scheduler_decision_gates_decision FOREIGN KEY (decision_id)
        REFERENCES scheduler_decisions (id) ON DELETE CASCADE,
    CONSTRAINT chk_scheduler_decision_gates_ordinal CHECK (gate_ordinal > 0),
    CONSTRAINT chk_scheduler_decision_gates_code CHECK (code IN (
        'connection_enabled', 'cluster_enabled', 'node_enabled', 'guest_enabled',
        'policy_enabled', 'target_enabled', 'explicit_exclusion_absent', 'guest_active',
        'placement_present', 'placement_fresh', 'active_request_absent', 'target_node_allowed',
        'target_storage_enabled', 'target_storage_active', 'executor_authorized', 'capacity_fresh',
        'minimum_free_space', 'node_concurrency', 'target_concurrency', 'pbs_mapping_valid'
    )),
    CONSTRAINT chk_scheduler_decision_gates_passed CHECK (passed IN (0, 1)),
    CONSTRAINT chk_scheduler_decision_gates_scope CHECK (scope IN (
        'connection', 'cluster', 'node', 'guest', 'policy', 'target',
        'placement', 'capacity', 'concurrency', 'request', 'pbs_mapping'
    )),
    CONSTRAINT chk_scheduler_decision_gates_detail CHECK (detail_code IN (
        'passed', 'disabled', 'explicitly_excluded', 'archived', 'missing', 'stale',
        'not_allowed', 'inactive', 'unauthorized', 'insufficient_free_space',
        'concurrency_limit_reached', 'invalid_mapping', 'active_request_exists'
    )),
    CONSTRAINT chk_scheduler_decision_gates_result CHECK (
        (passed = 1 AND detail_code = 'passed') OR (passed = 0 AND detail_code <> 'passed')
    )
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

        $this->collectorPrivileges('GRANT');
    }

    public function down(Schema $schema): void
    {
        $this->collectorPrivileges('REVOKE');
        $this->addSql('DROP TABLE scheduler_decision_gates');
        $this->addSql('DROP TABLE scheduler_decisions');
        $this->addSql('DROP TABLE scheduler_evaluation_runs');
    }

    private function collectorPrivileges(string $operation): void
    {
        $database = $this->connection->getDatabase();
        if (!is_string($database)
            || 1 !== preg_match('/^[A-Za-z0-9_]+$/D', $database)
            || !in_array($operation, ['GRANT', 'REVOKE'], true)) {
            throw new \RuntimeException('A shadow-evaluation grant identifier is invalid.');
        }

        foreach (self::TABLES as $table) {
            $this->addSql(sprintf(
                "%s SELECT, INSERT ON `%s`.`%s` %s 'hoddmimir_collector'@'%%'",
                $operation,
                $database,
                $table,
                'GRANT' === $operation ? 'TO' : 'FROM',
            ));
        }
    }
}
