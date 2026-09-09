<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260711000300 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add fenced monitoring child runs plus positive-only PVE/PBS external jobs and task history.';
    }

    public function isTransactional(): bool
    {
        return false;
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
CREATE TABLE proxmox_monitoring_runs (
    id BINARY(16) NOT NULL,
    connection_id BINARY(16) NOT NULL,
    parent_sync_run_id BINARY(16) NOT NULL,
    product VARCHAR(8) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    binding_kind VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    binding_value VARCHAR(255) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    binding_legacy_endpoint_id BINARY(16) NULL,
    monitoring_kind VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    expected_connection_revision INT UNSIGNED NOT NULL,
    endpoint_id BINARY(16) NOT NULL,
    cycle_token BINARY(16) NOT NULL,
    collector_fencing_token BIGINT NOT NULL,
    status VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    started_at DATETIME(6) NOT NULL,
    heartbeat_at DATETIME(6) NOT NULL,
    finished_at DATETIME(6) NULL,
    applied_at DATETIME(6) NULL,
    scopes_seen INT UNSIGNED NOT NULL DEFAULT 0,
    pages_read INT UNSIGNED NOT NULL DEFAULT 0,
    rows_read INT UNSIGNED NOT NULL DEFAULT 0,
    items_seen INT UNSIGNED NOT NULL DEFAULT 0,
    objects_created INT UNSIGNED NOT NULL DEFAULT 0,
    objects_updated INT UNSIGNED NOT NULL DEFAULT 0,
    conflicts_seen INT UNSIGNED NOT NULL DEFAULT 0,
    error_code VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL,
    PRIMARY KEY (id),
    CONSTRAINT uq_proxmox_monitoring_parent_kind UNIQUE (parent_sync_run_id, monitoring_kind),
    CONSTRAINT uq_proxmox_monitoring_connection_id UNIQUE (connection_id, id),
    INDEX idx_proxmox_monitoring_connection_started (connection_id, started_at),
    INDEX idx_proxmox_monitoring_status_heartbeat (status, heartbeat_at),
    CONSTRAINT fk_proxmox_monitoring_parent FOREIGN KEY (connection_id, parent_sync_run_id)
        REFERENCES inventory_sync_runs (connection_id, id) ON DELETE RESTRICT,
    CONSTRAINT fk_proxmox_monitoring_connection FOREIGN KEY (connection_id, product)
        REFERENCES proxmox_connections (id, product) ON DELETE RESTRICT,
    CONSTRAINT fk_proxmox_monitoring_endpoint FOREIGN KEY (connection_id, endpoint_id)
        REFERENCES proxmox_connection_endpoints (connection_id, id) ON DELETE RESTRICT,
    CONSTRAINT fk_proxmox_monitoring_binding_legacy_endpoint
        FOREIGN KEY (connection_id, binding_legacy_endpoint_id)
        REFERENCES proxmox_connection_endpoints (connection_id, id) ON DELETE RESTRICT,
    CONSTRAINT fk_proxmox_monitoring_cycle FOREIGN KEY (cycle_token, collector_fencing_token)
        REFERENCES collector_cycles (cycle_token, fencing_token) ON DELETE RESTRICT,
    CONSTRAINT chk_proxmox_monitoring_kind CHECK (
        monitoring_kind IN ('external_jobs', 'observed_tasks')
    ),
    CONSTRAINT chk_proxmox_monitoring_product CHECK (product IN ('pve', 'pbs')),
    CONSTRAINT chk_proxmox_monitoring_binding CHECK (
        OCTET_LENGTH(binding_value) BETWEEN 1 AND 255
        AND (
            (product = 'pve' AND binding_kind IN ('pve_cluster', 'pve_standalone')
                AND binding_legacy_endpoint_id IS NULL)
            OR (product = 'pbs' AND binding_kind = 'pbs_instance'
                AND binding_legacy_endpoint_id IS NULL
                AND OCTET_LENGTH(binding_value) = 32
                AND binding_value REGEXP '^[0-9a-f]{32}$')
            OR (product = 'pbs' AND binding_kind = 'pbs_legacy_node'
                AND binding_legacy_endpoint_id = endpoint_id)
        )
    ),
    CONSTRAINT chk_proxmox_monitoring_revision CHECK (expected_connection_revision > 0),
    CONSTRAINT chk_proxmox_monitoring_fence CHECK (collector_fencing_token > 0),
    CONSTRAINT chk_proxmox_monitoring_status CHECK (
        status IN ('running', 'succeeded', 'partial', 'failed')
    ),
    CONSTRAINT chk_proxmox_monitoring_time CHECK (
        heartbeat_at >= started_at
        AND (
            (status = 'running' AND finished_at IS NULL AND applied_at IS NULL)
            OR (status IN ('succeeded', 'partial')
                AND finished_at IS NOT NULL AND finished_at >= heartbeat_at
                AND applied_at = finished_at)
            OR (status = 'failed' AND finished_at IS NOT NULL
                AND finished_at >= heartbeat_at AND applied_at IS NULL)
        )
    ),
    CONSTRAINT chk_proxmox_monitoring_error CHECK (
        (status = 'failed' AND error_code IS NOT NULL)
        OR (status <> 'failed' AND error_code IS NULL)
    )
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

        $this->addSql(<<<'SQL'
CREATE TABLE proxmox_monitoring_scope_results (
    id BINARY(16) NOT NULL,
    connection_id BINARY(16) NOT NULL,
    monitoring_run_id BINARY(16) NOT NULL,
    scope_type VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    scope_key VARCHAR(255) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    source_kind VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    filter_value VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    status VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    window_since DATETIME(6) NULL,
    window_until DATETIME(6) NULL,
    pages_read INT UNSIGNED NOT NULL DEFAULT 0,
    rows_read INT UNSIGNED NOT NULL DEFAULT 0,
    items_seen INT UNSIGNED NOT NULL DEFAULT 0,
    truncated TINYINT(1) NOT NULL DEFAULT 0,
    history_gap TINYINT(1) NOT NULL DEFAULT 0,
    error_code VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL,
    observed_at DATETIME(6) NOT NULL,
    PRIMARY KEY (id),
    CONSTRAINT uq_proxmox_monitoring_scope UNIQUE (
        monitoring_run_id, scope_type, scope_key, source_kind, filter_value
    ),
    INDEX idx_proxmox_monitoring_scope_connection (connection_id, scope_type, status),
    CONSTRAINT fk_proxmox_monitoring_scope_run FOREIGN KEY (connection_id, monitoring_run_id)
        REFERENCES proxmox_monitoring_runs (connection_id, id) ON DELETE CASCADE,
    CONSTRAINT chk_proxmox_monitoring_scope_type CHECK (
        scope_type IN (
            'pve_backup_jobs', 'pve_tasks_active', 'pve_tasks_archive',
            'pbs_prune_jobs', 'pbs_sync_jobs', 'pbs_verify_jobs',
            'pbs_tasks_running', 'pbs_tasks_window'
        )
    ),
    CONSTRAINT chk_proxmox_monitoring_scope_key CHECK (OCTET_LENGTH(scope_key) BETWEEN 1 AND 255),
    CONSTRAINT chk_proxmox_monitoring_scope_source CHECK (
        source_kind IN ('jobs', 'active', 'archive', 'running', 'history')
    ),
    CONSTRAINT chk_proxmox_monitoring_scope_status CHECK (
        status IN ('complete', 'partial', 'failed')
    ),
    CONSTRAINT chk_proxmox_monitoring_scope_window CHECK (
        (window_since IS NULL AND window_until IS NULL)
        OR (window_since IS NOT NULL AND window_until IS NOT NULL AND window_until >= window_since)
    ),
    CONSTRAINT chk_proxmox_monitoring_scope_flags CHECK (
        truncated IN (0, 1) AND history_gap IN (0, 1)
        AND (status <> 'complete' OR (truncated = 0 AND history_gap = 0))
        AND (history_gap = 0 OR scope_type IN ('pve_tasks_archive', 'pbs_tasks_window'))
    ),
    CONSTRAINT chk_proxmox_monitoring_scope_error CHECK (
        (status = 'complete' AND error_code IS NULL)
        OR (status <> 'complete' AND error_code IS NOT NULL)
    ),
    CONSTRAINT chk_proxmox_monitoring_scope_semantics CHECK (
        (scope_type = 'pve_backup_jobs' AND source_kind = 'jobs'
            AND filter_value = '@none' AND window_since IS NULL)
        OR (scope_type = 'pve_tasks_active' AND source_kind = 'active'
            AND filter_value = 'vzdump' AND window_since IS NULL)
        OR (scope_type = 'pve_tasks_archive' AND source_kind = 'archive'
            AND filter_value = 'vzdump' AND window_since IS NOT NULL)
        OR (scope_type = 'pbs_prune_jobs' AND source_kind = 'jobs'
            AND filter_value = 'prune' AND window_since IS NULL)
        OR (scope_type = 'pbs_sync_jobs' AND source_kind = 'jobs'
            AND filter_value = 'sync' AND window_since IS NULL)
        OR (scope_type = 'pbs_verify_jobs' AND source_kind = 'jobs'
            AND filter_value = 'verify' AND window_since IS NULL)
        OR (scope_type = 'pbs_tasks_running' AND source_kind = 'running'
            AND filter_value IN ('backup', 'prune', 'syncjob', 'verif') AND window_since IS NULL)
        OR (scope_type = 'pbs_tasks_window' AND source_kind = 'history'
            AND filter_value IN ('backup', 'prune', 'syncjob', 'verif') AND window_since IS NOT NULL)
    )
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

        $this->addSql(<<<'SQL'
CREATE TABLE proxmox_monitoring_cursors (
    connection_id BINARY(16) NOT NULL,
    product VARCHAR(8) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    cursor_kind VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    scope_key VARCHAR(255) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    completed_until DATETIME(6) NOT NULL,
    last_complete_run_id BINARY(16) NOT NULL,
    updated_at DATETIME(6) NOT NULL,
    PRIMARY KEY (connection_id, cursor_kind, scope_key),
    CONSTRAINT fk_proxmox_monitoring_cursor_connection FOREIGN KEY (connection_id, product)
        REFERENCES proxmox_connections (id, product) ON DELETE CASCADE,
    CONSTRAINT fk_proxmox_monitoring_cursor_run FOREIGN KEY (connection_id, last_complete_run_id)
        REFERENCES proxmox_monitoring_runs (connection_id, id) ON DELETE RESTRICT,
    CONSTRAINT chk_proxmox_monitoring_cursor_kind CHECK (
        (product = 'pve' AND cursor_kind = 'pve_tasks_archive')
        OR (product = 'pbs' AND cursor_kind = 'pbs_tasks_window')
    ),
    CONSTRAINT chk_proxmox_monitoring_cursor_key CHECK (OCTET_LENGTH(scope_key) BETWEEN 1 AND 255)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

        $this->addSql(<<<'SQL'
CREATE TABLE pve_external_backup_jobs (
    id BINARY(16) NOT NULL,
    connection_id BINARY(16) NOT NULL,
    external_job_id VARCHAR(190) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    raw_schedule VARCHAR(255) NULL,
    enabled TINYINT(1) NULL,
    repeat_missed TINYINT(1) NULL,
    comment VARCHAR(1024) NULL,
    next_run_at DATETIME(6) NULL,
    node_selector VARCHAR(255) NULL,
    storage_name VARCHAR(190) CHARACTER SET ascii COLLATE ascii_bin NULL,
    guest_ids TEXT NULL,
    all_guests TINYINT(1) NULL,
    backup_mode VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NULL,
    compression VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NULL,
    legacy_max_files INT UNSIGNED NULL,
    prune_json LONGTEXT NULL,
    config_hash BINARY(32) NOT NULL,
    first_seen_run_id BINARY(16) NOT NULL,
    last_seen_run_id BINARY(16) NOT NULL,
    first_seen_at DATETIME(6) NOT NULL,
    last_seen_at DATETIME(6) NOT NULL,
    PRIMARY KEY (id),
    CONSTRAINT uq_pve_external_jobs_identity UNIQUE (connection_id, external_job_id),
    CONSTRAINT fk_pve_external_jobs_connection FOREIGN KEY (connection_id)
        REFERENCES proxmox_connections (id) ON DELETE RESTRICT,
    CONSTRAINT fk_pve_external_jobs_first_run FOREIGN KEY (connection_id, first_seen_run_id)
        REFERENCES proxmox_monitoring_runs (connection_id, id) ON DELETE RESTRICT,
    CONSTRAINT fk_pve_external_jobs_last_run FOREIGN KEY (connection_id, last_seen_run_id)
        REFERENCES proxmox_monitoring_runs (connection_id, id) ON DELETE RESTRICT,
    CONSTRAINT chk_pve_external_jobs_flags CHECK (
        (enabled IS NULL OR enabled IN (0, 1))
        AND (repeat_missed IS NULL OR repeat_missed IN (0, 1))
        AND (all_guests IS NULL OR all_guests IN (0, 1))
    ),
    CONSTRAINT chk_pve_external_jobs_prune CHECK (prune_json IS NULL OR JSON_VALID(prune_json)),
    CONSTRAINT chk_pve_external_jobs_seen CHECK (last_seen_at >= first_seen_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

        $this->addSql(<<<'SQL'
CREATE TABLE pve_observed_backup_tasks (
    id BINARY(16) NOT NULL,
    connection_id BINARY(16) NOT NULL,
    upid_hash BINARY(32) NOT NULL,
    upid_raw VARBINARY(1024) NOT NULL,
    node_name VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    pid_hex CHAR(8) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    pstart_hex VARCHAR(9) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    starttime_hex CHAR(8) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    task_id VARBINARY(255) NOT NULL,
    auth_id VARBINARY(255) NOT NULL,
    seen_active TINYINT(1) NOT NULL DEFAULT 0,
    seen_archive TINYINT(1) NOT NULL DEFAULT 0,
    lifecycle VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    remote_status VARCHAR(255) CHARACTER SET ascii COLLATE ascii_bin NULL,
    started_at DATETIME(6) NOT NULL,
    finished_at DATETIME(6) NULL,
    first_seen_run_id BINARY(16) NOT NULL,
    last_seen_run_id BINARY(16) NOT NULL,
    first_seen_at DATETIME(6) NOT NULL,
    last_seen_at DATETIME(6) NOT NULL,
    PRIMARY KEY (id),
    CONSTRAINT uq_pve_observed_tasks_hash UNIQUE (connection_id, upid_hash),
    INDEX idx_pve_observed_tasks_started (connection_id, started_at, id),
    INDEX idx_pve_observed_tasks_lifecycle (connection_id, lifecycle, last_seen_at),
    CONSTRAINT fk_pve_observed_tasks_connection FOREIGN KEY (connection_id)
        REFERENCES proxmox_connections (id) ON DELETE RESTRICT,
    CONSTRAINT fk_pve_observed_tasks_first_run FOREIGN KEY (connection_id, first_seen_run_id)
        REFERENCES proxmox_monitoring_runs (connection_id, id) ON DELETE RESTRICT,
    CONSTRAINT fk_pve_observed_tasks_last_run FOREIGN KEY (connection_id, last_seen_run_id)
        REFERENCES proxmox_monitoring_runs (connection_id, id) ON DELETE RESTRICT,
    CONSTRAINT chk_pve_observed_tasks_upid CHECK (OCTET_LENGTH(upid_raw) BETWEEN 1 AND 1024),
    CONSTRAINT chk_pve_observed_tasks_hex CHECK (
        pid_hex REGEXP '^[0-9a-f]{8}$'
        AND pstart_hex REGEXP '^[0-9a-f]{8,9}$'
        AND starttime_hex REGEXP '^[0-9a-f]{8}$'
    ),
    CONSTRAINT chk_pve_observed_tasks_seen_source CHECK (
        seen_active IN (0, 1) AND seen_archive IN (0, 1)
        AND (seen_active = 1 OR seen_archive = 1)
    ),
    CONSTRAINT chk_pve_observed_tasks_lifecycle CHECK (
        lifecycle IN ('running', 'stopped', 'unknown')
        AND ((lifecycle = 'running' AND finished_at IS NULL) OR lifecycle <> 'running')
    ),
    CONSTRAINT chk_pve_observed_tasks_status CHECK (
        remote_status IS NULL OR (
            OCTET_LENGTH(remote_status) BETWEEN 1 AND 255 AND remote_status REGEXP '^[[:graph:] ]+$'
        )
    ),
    CONSTRAINT chk_pve_observed_tasks_seen CHECK (last_seen_at >= first_seen_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

        $this->addSql(<<<'SQL'
CREATE TABLE pbs_external_jobs (
    id BINARY(16) NOT NULL,
    connection_id BINARY(16) NOT NULL,
    server_id BINARY(16) NOT NULL,
    job_kind VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    external_job_id VARCHAR(190) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    enabled TINYINT(1) NULL,
    raw_schedule VARCHAR(256) NULL,
    target_store VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NULL,
    target_namespace VARCHAR(256) NULL,
    remote_name VARCHAR(190) CHARACTER SET ascii COLLATE ascii_bin NULL,
    remote_store VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NULL,
    sync_direction VARCHAR(8) CHARACTER SET ascii COLLATE ascii_bin NULL,
    last_run_upid VARBINARY(2048) NULL,
    last_run_state VARCHAR(255) CHARACTER SET ascii COLLATE ascii_bin NULL,
    last_run_end_at DATETIME(6) NULL,
    next_run_at DATETIME(6) NULL,
    config_digest BINARY(32) NULL,
    details_json LONGTEXT NOT NULL,
    config_hash BINARY(32) NOT NULL,
    first_seen_run_id BINARY(16) NOT NULL,
    last_seen_run_id BINARY(16) NOT NULL,
    first_seen_at DATETIME(6) NOT NULL,
    last_seen_at DATETIME(6) NOT NULL,
    PRIMARY KEY (id),
    CONSTRAINT uq_pbs_external_jobs_identity UNIQUE (server_id, job_kind, external_job_id),
    CONSTRAINT fk_pbs_external_jobs_server FOREIGN KEY (connection_id, server_id)
        REFERENCES pbs_servers (connection_id, id) ON DELETE RESTRICT,
    CONSTRAINT fk_pbs_external_jobs_first_run FOREIGN KEY (connection_id, first_seen_run_id)
        REFERENCES proxmox_monitoring_runs (connection_id, id) ON DELETE RESTRICT,
    CONSTRAINT fk_pbs_external_jobs_last_run FOREIGN KEY (connection_id, last_seen_run_id)
        REFERENCES proxmox_monitoring_runs (connection_id, id) ON DELETE RESTRICT,
    CONSTRAINT chk_pbs_external_jobs_kind CHECK (job_kind IN ('prune', 'sync', 'verify')),
    CONSTRAINT chk_pbs_external_jobs_enabled CHECK (enabled IS NULL OR enabled IN (0, 1)),
    CONSTRAINT chk_pbs_external_jobs_sync CHECK (
        (job_kind = 'sync' AND sync_direction IN ('pull', 'push') AND remote_store IS NOT NULL)
        OR (job_kind <> 'sync' AND sync_direction IS NULL AND remote_name IS NULL AND remote_store IS NULL)
    ),
    CONSTRAINT chk_pbs_external_jobs_last_run CHECK (
        (last_run_upid IS NULL AND last_run_state IS NULL AND last_run_end_at IS NULL)
        OR (last_run_upid IS NOT NULL AND last_run_state IS NOT NULL)
    ),
    CONSTRAINT chk_pbs_external_jobs_details CHECK (JSON_VALID(details_json)),
    CONSTRAINT chk_pbs_external_jobs_last_state CHECK (
        last_run_state IS NULL OR last_run_state IN ('ok', 'warning', 'error', 'unknown')
    ),
    CONSTRAINT chk_pbs_external_jobs_seen CHECK (last_seen_at >= first_seen_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

        $this->addSql(<<<'SQL'
CREATE TABLE pbs_observed_tasks (
    id BINARY(16) NOT NULL,
    connection_id BINARY(16) NOT NULL,
    server_id BINARY(16) NOT NULL,
    upid_hash BINARY(32) NOT NULL,
    upid_raw VARBINARY(2048) NOT NULL,
    upid_node_name VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    reported_node_name VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL,
    pid_hex CHAR(8) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    pstart_hex VARCHAR(9) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    task_id_hex VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    starttime_hex CHAR(8) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    worker_type VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    worker_id VARBINARY(1024) NULL,
    auth_id VARBINARY(255) NOT NULL,
    seen_running TINYINT(1) NOT NULL DEFAULT 0,
    seen_history TINYINT(1) NOT NULL DEFAULT 0,
    lifecycle VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    remote_status VARCHAR(255) CHARACTER SET ascii COLLATE ascii_bin NULL,
    started_at DATETIME(6) NOT NULL,
    finished_at DATETIME(6) NULL,
    first_seen_run_id BINARY(16) NOT NULL,
    last_seen_run_id BINARY(16) NOT NULL,
    first_seen_at DATETIME(6) NOT NULL,
    last_seen_at DATETIME(6) NOT NULL,
    PRIMARY KEY (id),
    CONSTRAINT uq_pbs_observed_tasks_hash UNIQUE (connection_id, upid_hash),
    INDEX idx_pbs_observed_tasks_started (connection_id, started_at, id),
    INDEX idx_pbs_observed_tasks_lifecycle (connection_id, lifecycle, last_seen_at),
    CONSTRAINT fk_pbs_observed_tasks_server FOREIGN KEY (connection_id, server_id)
        REFERENCES pbs_servers (connection_id, id) ON DELETE RESTRICT,
    CONSTRAINT fk_pbs_observed_tasks_first_run FOREIGN KEY (connection_id, first_seen_run_id)
        REFERENCES proxmox_monitoring_runs (connection_id, id) ON DELETE RESTRICT,
    CONSTRAINT fk_pbs_observed_tasks_last_run FOREIGN KEY (connection_id, last_seen_run_id)
        REFERENCES proxmox_monitoring_runs (connection_id, id) ON DELETE RESTRICT,
    CONSTRAINT chk_pbs_observed_tasks_upid CHECK (OCTET_LENGTH(upid_raw) BETWEEN 1 AND 2048),
    CONSTRAINT chk_pbs_observed_tasks_hex CHECK (
        pid_hex REGEXP '^[0-9a-f]{8}$'
        AND pstart_hex REGEXP '^[0-9a-f]{8,9}$'
        AND task_id_hex REGEXP '^[0-9a-f]{8,16}$'
        AND starttime_hex REGEXP '^[0-9a-f]{8}$'
    ),
    CONSTRAINT chk_pbs_observed_tasks_seen_source CHECK (
        seen_running IN (0, 1) AND seen_history IN (0, 1)
        AND (seen_running = 1 OR seen_history = 1)
    ),
    CONSTRAINT chk_pbs_observed_tasks_worker_type CHECK (
        worker_type IN (
            'backup', 'prune', 'prunejob', 'syncjob',
            'verificationjob', 'verify', 'verify_group', 'verify_snapshot'
        )
    ),
    CONSTRAINT chk_pbs_observed_tasks_lifecycle CHECK (
        lifecycle IN ('running', 'stopped')
        AND (lifecycle <> 'running' OR finished_at IS NULL)
    ),
    CONSTRAINT chk_pbs_observed_tasks_reported_node CHECK (
        reported_node_name IS NULL OR (
            OCTET_LENGTH(reported_node_name) BETWEEN 1 AND 64
            AND reported_node_name REGEXP '^[A-Za-z0-9][A-Za-z0-9.-]{0,63}$'
        )
    ),
    CONSTRAINT chk_pbs_observed_tasks_status CHECK (
        (lifecycle = 'running' AND remote_status IS NULL)
        OR (lifecycle = 'stopped' AND remote_status IN ('ok', 'warning', 'error', 'unknown'))
    ),
    CONSTRAINT chk_pbs_observed_tasks_seen CHECK (last_seen_at >= first_seen_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

        foreach (['proxmox_monitoring_runs', 'proxmox_monitoring_cursors'] as $table) {
            $this->grant('hoddmimir_collector', 'SELECT, INSERT, UPDATE', $table);
        }
        $this->grant('hoddmimir_collector', 'SELECT, INSERT', 'proxmox_monitoring_scope_results');
        foreach ([
            'pve_external_backup_jobs',
            'pve_observed_backup_tasks',
            'pbs_external_jobs',
            'pbs_observed_tasks',
        ] as $table) {
            $this->grant('hoddmimir_collector', 'SELECT, INSERT, UPDATE', $table);
        }
    }

    public function down(Schema $schema): void
    {
        foreach ([
            'pbs_observed_tasks',
            'pbs_external_jobs',
            'pve_observed_backup_tasks',
            'pve_external_backup_jobs',
        ] as $table) {
            $this->revoke('hoddmimir_collector', 'SELECT, INSERT, UPDATE', $table);
        }
        $this->revoke('hoddmimir_collector', 'SELECT, INSERT', 'proxmox_monitoring_scope_results');
        foreach (['proxmox_monitoring_cursors', 'proxmox_monitoring_runs'] as $table) {
            $this->revoke('hoddmimir_collector', 'SELECT, INSERT, UPDATE', $table);
        }

        $this->addSql('DROP TABLE pbs_observed_tasks');
        $this->addSql('DROP TABLE pbs_external_jobs');
        $this->addSql('DROP TABLE pve_observed_backup_tasks');
        $this->addSql('DROP TABLE pve_external_backup_jobs');
        $this->addSql('DROP TABLE proxmox_monitoring_cursors');
        $this->addSql('DROP TABLE proxmox_monitoring_scope_results');
        $this->addSql('DROP TABLE proxmox_monitoring_runs');
    }

    private function grant(string $user, string $privileges, string $table): void
    {
        $this->privilegeStatement('GRANT', $user, $privileges, $table);
    }

    private function revoke(string $user, string $privileges, string $table): void
    {
        $this->privilegeStatement('REVOKE', $user, $privileges, $table);
    }

    private function privilegeStatement(string $operation, string $user, string $privileges, string $table): void
    {
        $database = $this->connection->getDatabase();
        if (1 !== preg_match('/^[A-Za-z0-9_]+$/D', $database)
            || 1 !== preg_match('/^[a-z_]+$/D', $user)
            || 1 !== preg_match('/^[a-z_]+$/D', $table)) {
            throw new \RuntimeException('A monitoring runtime grant identifier is invalid.');
        }
        $this->addSql(sprintf(
            "%s %s ON `%s`.`%s` %s '%s'@'%%'",
            $operation,
            $privileges,
            $database,
            $table,
            'GRANT' === $operation ? 'TO' : 'FROM',
            $user,
        ));
    }
}
