<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
use RuntimeException;

final class Version20260712001200 extends AbstractMigration
{
    private const array TABLES = [
        'backup_requests',
        'backup_request_events',
        'backup_runs',
        'backup_run_events',
        'backup_run_log_entries',
        'backup_node_slots',
        'backup_target_slots',
        'backup_capacity_reservations',
        'executor_permission_evidence',
    ];

    public function getDescription(): string
    {
        return 'Add fenced backup queue, run history, slots, reservations, and executor evidence.';
    }

    public function isTransactional(): bool
    {
        return false;
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE guests ADD provisioned_size_bytes BIGINT UNSIGNED NULL');
        $this->addSql(<<<'SQL'
CREATE TABLE executor_permission_evidence (
    id BINARY(16) NOT NULL,
    connection_id BINARY(16) NOT NULL,
    cluster_id BINARY(16) NOT NULL,
    target_id BINARY(16) NOT NULL,
    node_id BINARY(16) NOT NULL,
    storage_id BINARY(16) NOT NULL,
    guest_id BINARY(16) NULL,
    guest_key BINARY(16) AS (COALESCE(guest_id, UNHEX('00000000000000000000000000000000'))) PERSISTENT,
    vm_backup_authorized TINYINT(1) NOT NULL,
    datastore_allocate_authorized TINYINT(1) NOT NULL,
    authorized TINYINT(1) NOT NULL,
    observed_at DATETIME(6) NOT NULL,
    revision BIGINT UNSIGNED NOT NULL DEFAULT 1,
    PRIMARY KEY (id),
    CONSTRAINT uq_executor_evidence_subject UNIQUE (connection_id, target_id, node_id, guest_key),
    CONSTRAINT fk_executor_evidence_target FOREIGN KEY (connection_id, cluster_id, target_id)
        REFERENCES backup_targets (connection_id, cluster_id, id) ON DELETE CASCADE,
    CONSTRAINT fk_executor_evidence_node FOREIGN KEY (connection_id, cluster_id, node_id)
        REFERENCES pve_nodes (connection_id, cluster_id, id) ON DELETE CASCADE,
    CONSTRAINT fk_executor_evidence_storage FOREIGN KEY (connection_id, cluster_id, storage_id)
        REFERENCES pve_storages (connection_id, cluster_id, id) ON DELETE CASCADE,
    CONSTRAINT fk_executor_evidence_guest FOREIGN KEY (connection_id, cluster_id, guest_id)
        REFERENCES guests (connection_id, cluster_id, id) ON DELETE CASCADE,
    CONSTRAINT chk_executor_evidence CHECK (
        vm_backup_authorized IN (0, 1) AND datastore_allocate_authorized IN (0, 1)
        AND authorized IN (0, 1)
        AND authorized = (vm_backup_authorized AND datastore_allocate_authorized)
        AND revision > 0
    )
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);
        $this->addSql(<<<'SQL'
CREATE TABLE backup_requests (
    id BINARY(16) NOT NULL,
    root_request_id BINARY(16) NOT NULL,
    attempt INT UNSIGNED NOT NULL,
    origin VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    state VARCHAR(24) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    reason VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    priority SMALLINT UNSIGNED NOT NULL,
    scheduled_at DATETIME(6) NOT NULL,
    available_at DATETIME(6) NOT NULL,
    connection_id BINARY(16) NOT NULL,
    cluster_id BINARY(16) NOT NULL,
    guest_id BINARY(16) NOT NULL,
    node_id BINARY(16) NOT NULL,
    placement_revision BIGINT UNSIGNED NOT NULL,
    placement_observed_at DATETIME(6) NOT NULL,
    policy_id BINARY(16) NOT NULL,
    policy_revision BIGINT UNSIGNED NOT NULL,
    target_id BINARY(16) NOT NULL,
    target_revision BIGINT UNSIGNED NOT NULL,
    shadow_decision_id BINARY(16) NULL,
    resolved_policy_json LONGTEXT NOT NULL,
    resolved_policy_hash BINARY(32) NOT NULL,
    expected_size_bytes BIGINT UNSIGNED NOT NULL,
    retry_disposition VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    submission_provenance VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'not_submitted',
    revision BIGINT UNSIGNED NOT NULL DEFAULT 1,
    claim_token BINARY(16) NULL,
    claim_fence BIGINT UNSIGNED NOT NULL DEFAULT 0,
    lease_owner BINARY(16) NULL,
    lease_issued_at DATETIME(6) NULL,
    lease_expires_at DATETIME(6) NULL,
    run_id BINARY(16) NULL,
    created_at DATETIME(6) NOT NULL,
    updated_at DATETIME(6) NOT NULL,
    PRIMARY KEY (id),
    CONSTRAINT uq_backup_requests_policy_guest_schedule UNIQUE (policy_id, guest_id, scheduled_at),
    INDEX idx_backup_requests_claim (state, available_at, priority DESC, scheduled_at, id),
    INDEX idx_backup_requests_expired_lease (state, lease_expires_at),
    INDEX idx_backup_requests_root (root_request_id),
    CONSTRAINT fk_backup_requests_root FOREIGN KEY (root_request_id) REFERENCES backup_requests (id) ON DELETE RESTRICT,
    CONSTRAINT fk_backup_requests_guest FOREIGN KEY (connection_id, cluster_id, guest_id)
        REFERENCES guests (connection_id, cluster_id, id) ON DELETE RESTRICT,
    CONSTRAINT fk_backup_requests_node FOREIGN KEY (connection_id, cluster_id, node_id)
        REFERENCES pve_nodes (connection_id, cluster_id, id) ON DELETE RESTRICT,
    CONSTRAINT fk_backup_requests_policy FOREIGN KEY (connection_id, cluster_id, policy_id)
        REFERENCES backup_policies (connection_id, cluster_id, id) ON DELETE RESTRICT,
    CONSTRAINT fk_backup_requests_target FOREIGN KEY (connection_id, cluster_id, target_id)
        REFERENCES backup_targets (connection_id, cluster_id, id) ON DELETE RESTRICT,
    CONSTRAINT fk_backup_requests_shadow FOREIGN KEY (shadow_decision_id)
        REFERENCES scheduler_decisions (id) ON DELETE RESTRICT,
    CONSTRAINT chk_backup_requests_attempt CHECK (
        revision > 0 AND placement_revision > 0 AND policy_revision > 0 AND target_revision > 0
        AND (
            (origin IN ('automatic', 'manual') AND attempt = 1 AND root_request_id = id)
            OR (origin = 'retry' AND attempt > 1 AND root_request_id <> id)
        )
    ),
    CONSTRAINT chk_backup_requests_origin CHECK (origin IN ('automatic', 'manual', 'retry')),
    CONSTRAINT chk_backup_requests_state CHECK (
        state IN ('pending', 'leased', 'starting', 'running', 'retry_wait', 'reconcile_required', 'succeeded', 'failed', 'cancelled', 'unknown')
    ),
    CONSTRAINT chk_backup_requests_reason CHECK (
        (reason = 'manual' AND priority = 400)
        OR (reason = 'never_backed_up' AND priority = 300)
        OR (reason = 'max_age' AND priority = 200)
        OR (reason = 'bytes_written' AND priority = 100)
    ),
    CONSTRAINT chk_backup_requests_retry CHECK (
        retry_disposition IN ('not_applicable', 'controlled_allowed', 'forbidden_ambiguous')
        AND (submission_provenance = 'ambiguous') = (retry_disposition = 'forbidden_ambiguous')
        AND (submission_provenance <> 'not_submitted' OR retry_disposition = 'not_applicable')
    ),
    CONSTRAINT chk_backup_requests_provenance CHECK (
        submission_provenance IN ('not_submitted', 'accepted', 'definitive_rejection', 'ambiguous')
        AND NOT (submission_provenance = 'ambiguous' AND state IN ('pending', 'retry_wait', 'leased'))
    ),
    CONSTRAINT chk_backup_requests_json CHECK (JSON_VALID(resolved_policy_json)),
    CONSTRAINT chk_backup_requests_schedule CHECK (available_at >= scheduled_at),
    CONSTRAINT chk_backup_requests_shadow_origin CHECK (
        (origin = 'automatic' AND shadow_decision_id IS NOT NULL)
        OR (origin IN ('manual', 'retry') AND shadow_decision_id IS NULL)
    ),
    CONSTRAINT chk_backup_requests_run_state CHECK (
        (state IN ('pending', 'leased', 'retry_wait') AND run_id IS NULL)
        OR (state IN ('starting', 'running', 'reconcile_required', 'succeeded', 'failed', 'unknown') AND run_id IS NOT NULL)
        OR state = 'cancelled'
    ),
    CONSTRAINT chk_backup_requests_lease CHECK (
        (
            state NOT IN ('leased', 'starting', 'running', 'reconcile_required')
            AND claim_token IS NULL AND lease_owner IS NULL AND lease_issued_at IS NULL AND lease_expires_at IS NULL
        )
        OR (
            state IN ('leased', 'starting', 'running', 'reconcile_required')
            AND claim_token IS NOT NULL AND lease_owner IS NOT NULL AND lease_issued_at IS NOT NULL
            AND lease_expires_at > lease_issued_at AND claim_fence > 0
        )
    )
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);
        $this->addSql(<<<'SQL'
CREATE TABLE backup_request_events (
    id BINARY(16) NOT NULL,
    request_id BINARY(16) NOT NULL,
    sequence_no BIGINT UNSIGNED NOT NULL,
    event_type VARCHAR(48) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    state VARCHAR(24) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    claim_fence BIGINT UNSIGNED NULL,
    occurred_at DATETIME(6) NOT NULL,
    detail_code VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL,
    PRIMARY KEY (id),
    CONSTRAINT uq_backup_request_events_sequence UNIQUE (request_id, sequence_no),
    CONSTRAINT fk_backup_request_events_request FOREIGN KEY (request_id)
        REFERENCES backup_requests (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);
        $this->addSql(<<<'SQL'
CREATE TABLE backup_runs (
    id BINARY(16) NOT NULL,
    request_id BINARY(16) NOT NULL,
    root_request_id BINARY(16) NOT NULL,
    attempt INT UNSIGNED NOT NULL,
    state VARCHAR(24) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    claim_token BINARY(16) NOT NULL,
    claim_fence BIGINT UNSIGNED NOT NULL,
    submission_provenance VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    upid VARCHAR(4096) NULL,
    upid_hash BINARY(32) NULL,
    started_at DATETIME(6) NOT NULL,
    finished_at DATETIME(6) NULL,
    revision BIGINT UNSIGNED NOT NULL DEFAULT 1,
    PRIMARY KEY (id),
    CONSTRAINT uq_backup_runs_request UNIQUE (request_id),
    CONSTRAINT uq_backup_runs_upid_hash UNIQUE (upid_hash),
    CONSTRAINT fk_backup_runs_request FOREIGN KEY (request_id) REFERENCES backup_requests (id) ON DELETE RESTRICT,
    CONSTRAINT fk_backup_runs_root FOREIGN KEY (root_request_id) REFERENCES backup_requests (id) ON DELETE RESTRICT,
    CONSTRAINT chk_backup_runs_state CHECK (
        state IN ('awaiting_submission', 'reconcile_required', 'running', 'cancel_requested', 'succeeded', 'failed', 'cancelled', 'unknown')
    ),
    CONSTRAINT chk_backup_runs_provenance CHECK (
        submission_provenance IN ('not_submitted', 'accepted', 'definitive_rejection', 'ambiguous')
        AND NOT (submission_provenance = 'ambiguous' AND state = 'awaiting_submission')
    ),
    CONSTRAINT chk_backup_runs_upid CHECK (
        (upid IS NULL) = (upid_hash IS NULL)
        AND (submission_provenance NOT IN ('accepted') OR upid IS NOT NULL)
        AND (submission_provenance NOT IN ('not_submitted', 'definitive_rejection') OR upid IS NULL)
        AND (state NOT IN ('running', 'cancel_requested', 'succeeded', 'cancelled') OR upid IS NOT NULL)
    ),
    CONSTRAINT chk_backup_runs_fence CHECK (attempt > 0 AND claim_fence > 0 AND revision > 0),
    CONSTRAINT chk_backup_runs_finish CHECK (
        (state IN ('succeeded', 'failed', 'cancelled', 'unknown') AND finished_at IS NOT NULL)
        OR (state NOT IN ('succeeded', 'failed', 'cancelled', 'unknown') AND finished_at IS NULL)
    )
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);
        $this->addSql('ALTER TABLE backup_requests ADD CONSTRAINT fk_backup_requests_run FOREIGN KEY (run_id) REFERENCES backup_runs (id) ON DELETE RESTRICT');
        $this->addSql(<<<'SQL'
CREATE TABLE backup_run_events (
    id BINARY(16) NOT NULL,
    run_id BINARY(16) NOT NULL,
    sequence_no BIGINT UNSIGNED NOT NULL,
    event_type VARCHAR(48) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    state VARCHAR(24) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    occurred_at DATETIME(6) NOT NULL,
    detail_code VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL,
    PRIMARY KEY (id),
    CONSTRAINT uq_backup_run_events_sequence UNIQUE (run_id, sequence_no),
    CONSTRAINT fk_backup_run_events_run FOREIGN KEY (run_id) REFERENCES backup_runs (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);
        $this->addSql(<<<'SQL'
CREATE TABLE backup_run_log_entries (
    run_id BINARY(16) NOT NULL,
    line_no BIGINT UNSIGNED NOT NULL,
    observed_at DATETIME(6) NOT NULL,
    content VARCHAR(8192) NOT NULL,
    PRIMARY KEY (run_id, line_no),
    CONSTRAINT fk_backup_run_logs_run FOREIGN KEY (run_id) REFERENCES backup_runs (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);
        $this->addSql(<<<'SQL'
CREATE TABLE backup_node_slots (
    node_id BINARY(16) NOT NULL,
    connection_id BINARY(16) NOT NULL,
    cluster_id BINARY(16) NOT NULL,
    slot_limit SMALLINT UNSIGNED NOT NULL DEFAULT 1,
    slots_used SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    revision BIGINT UNSIGNED NOT NULL DEFAULT 1,
    updated_at DATETIME(6) NOT NULL,
    PRIMARY KEY (node_id),
    CONSTRAINT uq_backup_node_slots_context UNIQUE (connection_id, cluster_id, node_id),
    CONSTRAINT fk_backup_node_slots_node FOREIGN KEY (connection_id, cluster_id, node_id)
        REFERENCES pve_nodes (connection_id, cluster_id, id) ON DELETE CASCADE,
    CONSTRAINT chk_backup_node_slots CHECK (slot_limit = 1 AND slots_used <= slot_limit AND revision > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);
        $this->addSql(<<<'SQL'
CREATE TABLE backup_target_slots (
    target_id BINARY(16) NOT NULL,
    connection_id BINARY(16) NOT NULL,
    cluster_id BINARY(16) NOT NULL,
    slot_limit SMALLINT UNSIGNED NOT NULL,
    slots_used SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    revision BIGINT UNSIGNED NOT NULL DEFAULT 1,
    updated_at DATETIME(6) NOT NULL,
    PRIMARY KEY (target_id),
    CONSTRAINT uq_backup_target_slots_context UNIQUE (connection_id, cluster_id, target_id),
    CONSTRAINT fk_backup_target_slots_target FOREIGN KEY (connection_id, cluster_id, target_id)
        REFERENCES backup_targets (connection_id, cluster_id, id) ON DELETE CASCADE,
    CONSTRAINT chk_backup_target_slots CHECK (slot_limit > 0 AND slots_used <= slot_limit AND revision > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);
        $this->addSql(<<<'SQL'
CREATE TABLE backup_capacity_reservations (
    request_id BINARY(16) NOT NULL,
    connection_id BINARY(16) NOT NULL,
    cluster_id BINARY(16) NOT NULL,
    target_id BINARY(16) NOT NULL,
    node_id BINARY(16) NOT NULL,
    reserved_bytes BIGINT UNSIGNED NOT NULL,
    capacity_observed_at DATETIME(6) NOT NULL,
    created_at DATETIME(6) NOT NULL,
    released_at DATETIME(6) NULL,
    PRIMARY KEY (request_id),
    INDEX idx_backup_capacity_reservations_active (target_id, released_at),
    CONSTRAINT fk_backup_capacity_request FOREIGN KEY (request_id) REFERENCES backup_requests (id) ON DELETE CASCADE,
    CONSTRAINT fk_backup_capacity_target FOREIGN KEY (connection_id, cluster_id, target_id)
        REFERENCES backup_targets (connection_id, cluster_id, id) ON DELETE RESTRICT,
    CONSTRAINT fk_backup_capacity_node FOREIGN KEY (connection_id, cluster_id, node_id)
        REFERENCES pve_nodes (connection_id, cluster_id, id) ON DELETE RESTRICT,
    CONSTRAINT chk_backup_capacity_release CHECK (released_at IS NULL OR released_at >= created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);
        $this->privileges('GRANT');
    }

    public function down(Schema $schema): void
    {
        $this->privileges('REVOKE');
        foreach (['backup_capacity_reservations', 'backup_run_log_entries', 'backup_run_events'] as $table) {
            $this->addSql('DROP TABLE '.$table);
        }
        $this->addSql('ALTER TABLE backup_requests DROP FOREIGN KEY fk_backup_requests_run');
        foreach (['backup_runs', 'backup_request_events', 'backup_requests', 'backup_target_slots', 'backup_node_slots', 'executor_permission_evidence'] as $table) {
            $this->addSql('DROP TABLE '.$table);
        }
        $this->addSql('ALTER TABLE guests DROP provisioned_size_bytes');
    }

    private function privileges(string $operation): void
    {
        $database = $this->connection->getDatabase();
        if (!is_string($database) || 1 !== preg_match('/^[A-Za-z0-9_]+$/D', $database)) {
            throw new RuntimeException('Invalid queue grant database.');
        }
        $direction = 'GRANT' === $operation ? 'TO' : 'FROM';
        $appendOnly = ['backup_request_events', 'backup_run_events', 'backup_run_log_entries'];
        foreach (self::TABLES as $table) {
            $workerPrivileges = in_array($table, $appendOnly, true) ? 'SELECT, INSERT' : 'SELECT, INSERT, UPDATE';
            $this->addSql(sprintf("%s %s ON `%s`.`%s` %s 'hoddmimir_backup_worker'@'%%'", $operation, $workerPrivileges, $database, $table, $direction));
            $this->addSql(sprintf("%s SELECT ON `%s`.`%s` %s 'hoddmimir_web'@'%%'", $operation, $database, $table, $direction));
        }
        foreach (['backup_requests', 'backup_request_events'] as $table) {
            $this->addSql(sprintf("%s SELECT, INSERT ON `%s`.`%s` %s 'hoddmimir_collector'@'%%'", $operation, $database, $table, $direction));
        }
    }
}
