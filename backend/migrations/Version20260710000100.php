<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260710000100 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create the Hoddmímir V2 connection, collector scheduling, sync, and PVE inventory foundation.';
    }

    public function isTransactional(): bool
    {
        return false;
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
CREATE TABLE proxmox_connections (
    id BINARY(16) NOT NULL,
    display_name VARCHAR(190) NOT NULL,
    product VARCHAR(8) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    enabled TINYINT(1) NOT NULL DEFAULT 1,
    revision INT UNSIGNED NOT NULL DEFAULT 1,
    created_at DATETIME(6) NOT NULL,
    updated_at DATETIME(6) NOT NULL,
    PRIMARY KEY (id),
    CONSTRAINT uq_proxmox_connections_id_product UNIQUE (id, product),
    CONSTRAINT uq_proxmox_connections_display_name UNIQUE (display_name),
    INDEX idx_proxmox_connections_enabled_product (enabled, product),
    CONSTRAINT chk_proxmox_connections_product CHECK (product IN ('pve', 'pbs')),
    CONSTRAINT chk_proxmox_connections_enabled CHECK (enabled IN (0, 1)),
    CONSTRAINT chk_proxmox_connections_revision CHECK (revision > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

        $this->addSql(<<<'SQL'
CREATE TABLE proxmox_connection_endpoints (
    id BINARY(16) NOT NULL,
    connection_id BINARY(16) NOT NULL,
    host VARCHAR(255) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    port SMALLINT UNSIGNED NOT NULL,
    priority SMALLINT UNSIGNED NOT NULL DEFAULT 100,
    enabled TINYINT(1) NOT NULL DEFAULT 1,
    tls_mode VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    custom_ca_pem MEDIUMTEXT NULL,
    sha256_fingerprint BINARY(32) NULL,
    last_attempted_at DATETIME(6) NULL,
    last_success_at DATETIME(6) NULL,
    last_error_code VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL,
    created_at DATETIME(6) NOT NULL,
    updated_at DATETIME(6) NOT NULL,
    PRIMARY KEY (id),
    CONSTRAINT uq_proxmox_endpoints_address UNIQUE (connection_id, host, port),
    CONSTRAINT uq_proxmox_endpoints_connection_id UNIQUE (connection_id, id),
    INDEX idx_proxmox_endpoints_selection (connection_id, enabled, priority, id),
    CONSTRAINT fk_proxmox_endpoints_connection FOREIGN KEY (connection_id)
        REFERENCES proxmox_connections (id) ON DELETE CASCADE,
    CONSTRAINT chk_proxmox_endpoints_port CHECK (port BETWEEN 1 AND 65535),
    CONSTRAINT chk_proxmox_endpoints_enabled CHECK (enabled IN (0, 1)),
    CONSTRAINT chk_proxmox_endpoints_tls_mode CHECK (
        (tls_mode = 'system_ca' AND custom_ca_pem IS NULL AND sha256_fingerprint IS NULL)
        OR (tls_mode = 'custom_ca'
            AND OCTET_LENGTH(custom_ca_pem) BETWEEN 1 AND 262144
            AND sha256_fingerprint IS NULL)
        OR (tls_mode = 'sha256_fingerprint' AND custom_ca_pem IS NULL AND sha256_fingerprint IS NOT NULL)
    )
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

        $this->addSql(<<<'SQL'
CREATE TABLE proxmox_credentials (
    id BINARY(16) NOT NULL,
    connection_id BINARY(16) NOT NULL,
    purpose VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    auth_scheme VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    principal VARCHAR(190) NOT NULL,
    token_name VARCHAR(190) NOT NULL,
    secret_envelope VARBINARY(8192) NOT NULL,
    envelope_version SMALLINT UNSIGNED NOT NULL,
    key_id VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    revision INT UNSIGNED NOT NULL DEFAULT 1,
    created_at DATETIME(6) NOT NULL,
    rotated_at DATETIME(6) NULL,
    updated_at DATETIME(6) NOT NULL,
    PRIMARY KEY (id),
    CONSTRAINT uq_proxmox_credentials_purpose UNIQUE (connection_id, purpose),
    INDEX idx_proxmox_credentials_key (key_id, envelope_version),
    CONSTRAINT fk_proxmox_credentials_connection FOREIGN KEY (connection_id)
        REFERENCES proxmox_connections (id) ON DELETE CASCADE,
    CONSTRAINT chk_proxmox_credentials_purpose CHECK (purpose IN ('collector', 'backup')),
    CONSTRAINT chk_proxmox_credentials_auth CHECK (auth_scheme = 'api_token'),
    CONSTRAINT chk_proxmox_credentials_envelope CHECK (
        OCTET_LENGTH(secret_envelope) BETWEEN 1 AND 8192
        AND envelope_version > 0
        AND revision > 0
    )
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

        $this->addSql(<<<'SQL'
CREATE SQL SECURITY DEFINER VIEW collector_credentials AS
SELECT id, connection_id, purpose, auth_scheme, principal, token_name,
       secret_envelope, envelope_version, key_id, revision, created_at,
       rotated_at, updated_at
FROM proxmox_credentials
WHERE purpose = 'collector'
SQL);

        $this->addSql(<<<'SQL'
CREATE SQL SECURITY DEFINER VIEW credential_key_usage AS
SELECT DISTINCT key_id
FROM proxmox_credentials
SQL);

        $this->addSql(<<<'SQL'
CREATE TABLE collector_schedule (
    schedule_name VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    grid_started_at DATETIME(6) NOT NULL,
    interval_seconds INT UNSIGNED NOT NULL,
    next_scan_at DATETIME(6) NOT NULL,
    lease_owner BINARY(16) NULL,
    lease_token BINARY(16) NULL,
    lease_fencing_token BIGINT NOT NULL DEFAULT 0,
    lease_acquired_at DATETIME(6) NULL,
    lease_expires_at DATETIME(6) NULL,
    last_cycle_started_at DATETIME(6) NULL,
    last_cycle_finished_at DATETIME(6) NULL,
    updated_at DATETIME(6) NOT NULL,
    PRIMARY KEY (schedule_name),
    INDEX idx_collector_schedule_due (next_scan_at, lease_expires_at),
    CONSTRAINT chk_collector_schedule_interval CHECK (interval_seconds > 0),
    CONSTRAINT chk_collector_schedule_lease CHECK (
        (lease_owner IS NULL AND lease_token IS NULL AND lease_acquired_at IS NULL AND lease_expires_at IS NULL)
        OR (lease_owner IS NOT NULL AND lease_token IS NOT NULL AND lease_acquired_at IS NOT NULL
            AND lease_expires_at IS NOT NULL AND lease_fencing_token > 0)
    ),
    CONSTRAINT chk_collector_schedule_fencing CHECK (lease_fencing_token >= 0),
    CONSTRAINT chk_collector_schedule_lease_time CHECK (
        lease_expires_at IS NULL OR lease_expires_at > lease_acquired_at
    ),
    CONSTRAINT chk_collector_schedule_grid_time CHECK (next_scan_at >= grid_started_at),
    CONSTRAINT chk_collector_schedule_cycle_time CHECK (
        last_cycle_finished_at IS NULL OR (
            last_cycle_started_at IS NOT NULL AND last_cycle_finished_at >= last_cycle_started_at
        )
    )
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

        $this->addSql(<<<'SQL'
CREATE TABLE worker_heartbeats (
    worker_instance_id BINARY(16) NOT NULL,
    worker_kind VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    status VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    started_at DATETIME(6) NOT NULL,
    heartbeat_at DATETIME(6) NOT NULL,
    expires_at DATETIME(6) NOT NULL,
    current_activity VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL,
    current_cycle_token BINARY(16) NULL,
    next_action_at DATETIME(6) NULL,
    build_version VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    PRIMARY KEY (worker_instance_id),
    CONSTRAINT uq_worker_heartbeats_id_kind UNIQUE (worker_instance_id, worker_kind),
    INDEX idx_worker_heartbeats_kind_seen (worker_kind, heartbeat_at),
    INDEX idx_worker_heartbeats_kind_expiry (worker_kind, expires_at),
    CONSTRAINT chk_worker_heartbeats_kind CHECK (worker_kind IN ('collector', 'backup')),
    CONSTRAINT chk_worker_heartbeats_status CHECK (status IN ('starting', 'ready', 'busy', 'degraded', 'stopping')),
    CONSTRAINT chk_worker_heartbeats_expiry CHECK (
        started_at <= heartbeat_at AND expires_at > heartbeat_at
    ),
    CONSTRAINT chk_worker_heartbeats_cycle CHECK (
        (status = 'busy' AND current_cycle_token IS NOT NULL AND current_activity IS NOT NULL)
        OR (status <> 'busy' AND current_cycle_token IS NULL)
    )
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

        $this->addSql(<<<'SQL'
CREATE TABLE collector_cycles (
    cycle_token BINARY(16) NOT NULL,
    schedule_name VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    worker_instance_id BINARY(16) NOT NULL,
    worker_kind VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    fencing_token BIGINT NOT NULL,
    scheduled_for DATETIME(6) NOT NULL,
    started_at DATETIME(6) NOT NULL,
    heartbeat_at DATETIME(6) NOT NULL,
    finished_at DATETIME(6) NULL,
    duration_ms BIGINT UNSIGNED NULL,
    status VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    PRIMARY KEY (cycle_token),
    CONSTRAINT uq_collector_cycles_token_fence UNIQUE (cycle_token, fencing_token),
    CONSTRAINT uq_collector_cycles_schedule_fence UNIQUE (schedule_name, fencing_token),
    INDEX idx_collector_cycles_schedule_started (schedule_name, started_at),
    INDEX idx_collector_cycles_status_heartbeat (status, heartbeat_at),
    CONSTRAINT fk_collector_cycles_schedule FOREIGN KEY (schedule_name)
        REFERENCES collector_schedule (schedule_name) ON DELETE RESTRICT,
    CONSTRAINT fk_collector_cycles_worker FOREIGN KEY (worker_instance_id, worker_kind)
        REFERENCES worker_heartbeats (worker_instance_id, worker_kind) ON DELETE RESTRICT,
    CONSTRAINT chk_collector_cycles_worker_kind CHECK (worker_kind = 'collector'),
    CONSTRAINT chk_collector_cycles_fencing CHECK (fencing_token > 0),
    CONSTRAINT chk_collector_cycles_status CHECK (
        status IN ('running', 'succeeded', 'partial', 'failed', 'cancelled', 'abandoned')
    ),
    CONSTRAINT chk_collector_cycles_time CHECK (
        scheduled_for <= started_at AND started_at <= heartbeat_at
        AND (
            (status = 'running' AND finished_at IS NULL AND duration_ms IS NULL)
            OR (status <> 'running' AND finished_at IS NOT NULL AND finished_at >= heartbeat_at)
        )
    )
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

        $this->addSql(<<<'SQL'
CREATE TABLE proxmox_capability_snapshots (
    id BINARY(16) NOT NULL,
    connection_id BINARY(16) NOT NULL,
    endpoint_id BINARY(16) NULL,
    product VARCHAR(8) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    version_major SMALLINT UNSIGNED NOT NULL,
    version_minor SMALLINT UNSIGNED NOT NULL,
    version_patch SMALLINT UNSIGNED NULL,
    release_name VARCHAR(128) NULL,
    raw_version VARCHAR(255) NOT NULL,
    profile_version SMALLINT UNSIGNED NOT NULL,
    capabilities_json LONGTEXT NOT NULL,
    snapshot_hash BINARY(32) NOT NULL,
    first_observed_at DATETIME(6) NOT NULL,
    last_observed_at DATETIME(6) NOT NULL,
    PRIMARY KEY (id),
    CONSTRAINT uq_capability_snapshots_hash UNIQUE (connection_id, snapshot_hash),
    CONSTRAINT uq_capability_snapshots_connection_id UNIQUE (connection_id, id),
    CONSTRAINT fk_capability_snapshots_connection FOREIGN KEY (connection_id)
        REFERENCES proxmox_connections (id) ON DELETE RESTRICT,
    CONSTRAINT fk_capability_snapshots_endpoint FOREIGN KEY (connection_id, endpoint_id)
        REFERENCES proxmox_connection_endpoints (connection_id, id) ON DELETE RESTRICT,
    CONSTRAINT chk_capability_snapshots_product CHECK (product IN ('pve', 'pbs')),
    CONSTRAINT chk_capability_snapshots_version CHECK (
        version_major > 0 AND profile_version > 0 AND last_observed_at >= first_observed_at
    ),
    CONSTRAINT chk_capability_snapshots_json CHECK (JSON_VALID(capabilities_json))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

        $this->addSql(<<<'SQL'
CREATE TABLE inventory_sync_runs (
    id BINARY(16) NOT NULL,
    cycle_token BINARY(16) NOT NULL,
    collector_fencing_token BIGINT NOT NULL,
    connection_id BINARY(16) NOT NULL,
    expected_connection_revision INT UNSIGNED NOT NULL,
    endpoint_id BINARY(16) NULL,
    capability_snapshot_id BINARY(16) NULL,
    status VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    authoritative TINYINT(1) NOT NULL DEFAULT 0,
    started_at DATETIME(6) NOT NULL,
    heartbeat_at DATETIME(6) NOT NULL,
    finished_at DATETIME(6) NULL,
    applied_at DATETIME(6) NULL,
    nodes_seen INT UNSIGNED NOT NULL DEFAULT 0,
    guests_seen INT UNSIGNED NOT NULL DEFAULT 0,
    storages_seen INT UNSIGNED NOT NULL DEFAULT 0,
    objects_created INT UNSIGNED NOT NULL DEFAULT 0,
    objects_updated INT UNSIGNED NOT NULL DEFAULT 0,
    objects_archived INT UNSIGNED NOT NULL DEFAULT 0,
    error_code VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL,
    error_summary VARCHAR(1024) NULL,
    PRIMARY KEY (id),
    CONSTRAINT uq_inventory_sync_runs_cycle_connection UNIQUE (cycle_token, connection_id),
    CONSTRAINT uq_inventory_sync_runs_connection_id UNIQUE (connection_id, id),
    INDEX idx_inventory_sync_runs_connection_started (connection_id, started_at),
    INDEX idx_inventory_sync_runs_status_heartbeat (status, heartbeat_at),
    CONSTRAINT fk_inventory_sync_runs_connection FOREIGN KEY (connection_id)
        REFERENCES proxmox_connections (id) ON DELETE RESTRICT,
    CONSTRAINT fk_inventory_sync_runs_cycle_fence FOREIGN KEY (cycle_token, collector_fencing_token)
        REFERENCES collector_cycles (cycle_token, fencing_token) ON DELETE RESTRICT,
    CONSTRAINT fk_inventory_sync_runs_endpoint FOREIGN KEY (connection_id, endpoint_id)
        REFERENCES proxmox_connection_endpoints (connection_id, id) ON DELETE RESTRICT,
    CONSTRAINT fk_inventory_sync_runs_capability FOREIGN KEY (connection_id, capability_snapshot_id)
        REFERENCES proxmox_capability_snapshots (connection_id, id) ON DELETE RESTRICT,
    CONSTRAINT chk_inventory_sync_runs_status CHECK (
        status IN ('running', 'succeeded', 'partial', 'failed', 'cancelled', 'abandoned')
    ),
    CONSTRAINT chk_inventory_sync_runs_fencing CHECK (collector_fencing_token > 0),
    CONSTRAINT chk_inventory_sync_runs_revision CHECK (expected_connection_revision > 0),
    CONSTRAINT chk_inventory_sync_runs_authority CHECK (
        (status = 'succeeded' AND authoritative = 1)
        OR (status <> 'succeeded' AND authoritative = 0)
    ),
    CONSTRAINT chk_inventory_sync_runs_finished CHECK (
        (status = 'running' AND finished_at IS NULL)
        OR (status <> 'running' AND finished_at IS NOT NULL)
    ),
    CONSTRAINT chk_inventory_sync_runs_applied CHECK (
        applied_at IS NULL OR (finished_at IS NOT NULL AND applied_at = finished_at)
    )
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

        $this->addSql(<<<'SQL'
CREATE TABLE proxmox_installation_bindings (
    connection_id BINARY(16) NOT NULL,
    product VARCHAR(8) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    identity_kind VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    identity_value VARCHAR(255) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    first_bound_run_id BINARY(16) NOT NULL,
    last_verified_run_id BINARY(16) NOT NULL,
    first_bound_at DATETIME(6) NOT NULL,
    last_verified_at DATETIME(6) NOT NULL,
    PRIMARY KEY (connection_id),
    CONSTRAINT uq_proxmox_installation_bindings_connection_id UNIQUE (connection_id, product),
    CONSTRAINT fk_proxmox_installation_bindings_connection FOREIGN KEY (connection_id, product)
        REFERENCES proxmox_connections (id, product) ON DELETE RESTRICT,
    CONSTRAINT fk_proxmox_installation_bindings_first_run FOREIGN KEY (connection_id, first_bound_run_id)
        REFERENCES inventory_sync_runs (connection_id, id) ON DELETE RESTRICT,
    CONSTRAINT fk_proxmox_installation_bindings_last_run FOREIGN KEY (connection_id, last_verified_run_id)
        REFERENCES inventory_sync_runs (connection_id, id) ON DELETE RESTRICT,
    CONSTRAINT chk_proxmox_installation_bindings_identity CHECK (
        OCTET_LENGTH(identity_value) BETWEEN 1 AND 255
        AND (
            (product = 'pve' AND identity_kind IN ('pve_cluster', 'pve_standalone'))
            OR (product = 'pbs' AND identity_kind IN ('pbs_instance', 'pbs_node'))
        )
    ),
    CONSTRAINT chk_proxmox_installation_bindings_time CHECK (last_verified_at >= first_bound_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

        $this->addSql(<<<'SQL'
CREATE TABLE inventory_sync_scope_results (
    connection_id BINARY(16) NOT NULL,
    sync_run_id BINARY(16) NOT NULL,
    scope_type VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    scope_key VARCHAR(255) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    status VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    observed_at DATETIME(6) NOT NULL,
    PRIMARY KEY (sync_run_id, scope_type, scope_key),
    INDEX idx_inventory_sync_scope_connection (connection_id, scope_type, status),
    CONSTRAINT fk_inventory_sync_scope_run FOREIGN KEY (connection_id, sync_run_id)
        REFERENCES inventory_sync_runs (connection_id, id) ON DELETE CASCADE,
    CONSTRAINT chk_inventory_sync_scope_type CHECK (scope_type IN ('pve_topology', 'pve_guests')),
    CONSTRAINT chk_inventory_sync_scope_key CHECK (OCTET_LENGTH(scope_key) BETWEEN 1 AND 255),
    CONSTRAINT chk_inventory_sync_scope_status CHECK (status IN ('complete', 'partial', 'failed'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

        $this->addSql(<<<'SQL'
CREATE TABLE inventory_sync_endpoint_attempts (
    id BINARY(16) NOT NULL,
    connection_id BINARY(16) NOT NULL,
    sync_run_id BINARY(16) NOT NULL,
    endpoint_id BINARY(16) NOT NULL,
    attempt_number SMALLINT UNSIGNED NOT NULL,
    outcome VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    error_code VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL,
    selected_sync_run_id BINARY(16)
        GENERATED ALWAYS AS (CASE WHEN outcome = 'selected' THEN sync_run_id ELSE NULL END) PERSISTENT,
    started_at DATETIME(6) NOT NULL,
    finished_at DATETIME(6) NOT NULL,
    PRIMARY KEY (id),
    CONSTRAINT uq_inventory_sync_endpoint_attempt_number UNIQUE (sync_run_id, attempt_number),
    CONSTRAINT uq_inventory_sync_endpoint_attempt_endpoint UNIQUE (sync_run_id, endpoint_id),
    CONSTRAINT uq_inventory_sync_endpoint_attempt_selected UNIQUE (selected_sync_run_id),
    CONSTRAINT fk_inventory_sync_endpoint_attempt_run FOREIGN KEY (connection_id, sync_run_id)
        REFERENCES inventory_sync_runs (connection_id, id) ON DELETE CASCADE,
    CONSTRAINT fk_inventory_sync_endpoint_attempt_endpoint FOREIGN KEY (connection_id, endpoint_id)
        REFERENCES proxmox_connection_endpoints (connection_id, id) ON DELETE RESTRICT,
    CONSTRAINT chk_inventory_sync_endpoint_attempt_number CHECK (attempt_number > 0),
    CONSTRAINT chk_inventory_sync_endpoint_attempt_time CHECK (finished_at >= started_at),
    CONSTRAINT chk_inventory_sync_endpoint_attempt_outcome CHECK (
        (outcome = 'selected' AND error_code IS NULL)
        OR (outcome IN ('failover', 'terminal') AND error_code IS NOT NULL
            AND OCTET_LENGTH(error_code) BETWEEN 1 AND 64)
    )
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

        $this->addSql(<<<'SQL'
CREATE TABLE inventory_sync_failures (
    id BINARY(16) NOT NULL,
    sync_run_id BINARY(16) NOT NULL,
    scope_type VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    scope_key VARCHAR(255) NOT NULL,
    error_code VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    retryable TINYINT(1) NOT NULL DEFAULT 0,
    sanitized_message VARCHAR(1024) NOT NULL,
    recorded_at DATETIME(6) NOT NULL,
    PRIMARY KEY (id),
    INDEX idx_inventory_sync_failures_run_scope (sync_run_id, scope_type),
    CONSTRAINT fk_inventory_sync_failures_run FOREIGN KEY (sync_run_id)
        REFERENCES inventory_sync_runs (id) ON DELETE CASCADE,
    CONSTRAINT chk_inventory_sync_failures_scope CHECK (
        scope_type IN ('endpoint', 'cluster', 'node', 'guest', 'storage')
    ),
    CONSTRAINT chk_inventory_sync_failures_retry CHECK (retryable IN (0, 1))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

        $this->addSql(<<<'SQL'
CREATE TABLE pve_clusters (
    id BINARY(16) NOT NULL,
    connection_id BINARY(16) NOT NULL,
    external_name VARCHAR(190) NULL,
    topology VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    inventory_state VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'active',
    first_seen_run_id BINARY(16) NOT NULL,
    last_seen_run_id BINARY(16) NOT NULL,
    first_seen_at DATETIME(6) NOT NULL,
    last_seen_at DATETIME(6) NOT NULL,
    archived_at DATETIME(6) NULL,
    PRIMARY KEY (id),
    CONSTRAINT uq_pve_clusters_connection UNIQUE (connection_id),
    CONSTRAINT uq_pve_clusters_connection_id UNIQUE (connection_id, id),
    CONSTRAINT fk_pve_clusters_connection FOREIGN KEY (connection_id)
        REFERENCES proxmox_connections (id) ON DELETE RESTRICT,
    CONSTRAINT fk_pve_clusters_first_seen FOREIGN KEY (connection_id, first_seen_run_id)
        REFERENCES inventory_sync_runs (connection_id, id) ON DELETE RESTRICT,
    CONSTRAINT fk_pve_clusters_last_seen FOREIGN KEY (connection_id, last_seen_run_id)
        REFERENCES inventory_sync_runs (connection_id, id) ON DELETE RESTRICT,
    CONSTRAINT chk_pve_clusters_topology CHECK (topology IN ('clustered', 'standalone')),
    CONSTRAINT chk_pve_clusters_state CHECK (inventory_state IN ('active', 'archived')),
    CONSTRAINT chk_pve_clusters_archive CHECK (
        (inventory_state = 'active' AND archived_at IS NULL)
        OR (inventory_state = 'archived' AND archived_at IS NOT NULL)
    ),
    CONSTRAINT chk_pve_clusters_seen_time CHECK (last_seen_at >= first_seen_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

        $this->addSql(<<<'SQL'
CREATE TABLE pve_nodes (
    id BINARY(16) NOT NULL,
    connection_id BINARY(16) NOT NULL,
    cluster_id BINARY(16) NOT NULL,
    node_name VARCHAR(190) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    api_status VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    inventory_state VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'active',
    first_seen_run_id BINARY(16) NOT NULL,
    last_seen_run_id BINARY(16) NOT NULL,
    first_seen_at DATETIME(6) NOT NULL,
    last_seen_at DATETIME(6) NOT NULL,
    archived_at DATETIME(6) NULL,
    PRIMARY KEY (id),
    CONSTRAINT uq_pve_nodes_name UNIQUE (cluster_id, node_name),
    CONSTRAINT uq_pve_nodes_cluster_id UNIQUE (cluster_id, id),
    CONSTRAINT uq_pve_nodes_connection_cluster_id UNIQUE (connection_id, cluster_id, id),
    CONSTRAINT fk_pve_nodes_cluster FOREIGN KEY (connection_id, cluster_id)
        REFERENCES pve_clusters (connection_id, id) ON DELETE RESTRICT,
    CONSTRAINT fk_pve_nodes_first_seen FOREIGN KEY (connection_id, first_seen_run_id)
        REFERENCES inventory_sync_runs (connection_id, id) ON DELETE RESTRICT,
    CONSTRAINT fk_pve_nodes_last_seen FOREIGN KEY (connection_id, last_seen_run_id)
        REFERENCES inventory_sync_runs (connection_id, id) ON DELETE RESTRICT,
    CONSTRAINT chk_pve_nodes_api_status CHECK (api_status IN ('online', 'offline', 'unknown')),
    CONSTRAINT chk_pve_nodes_state CHECK (inventory_state IN ('active', 'archived')),
    CONSTRAINT chk_pve_nodes_archive CHECK (
        (inventory_state = 'active' AND archived_at IS NULL)
        OR (inventory_state = 'archived' AND archived_at IS NOT NULL)
    ),
    CONSTRAINT chk_pve_nodes_seen_time CHECK (last_seen_at >= first_seen_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

        $this->addSql(<<<'SQL'
CREATE TABLE guests (
    id BINARY(16) NOT NULL,
    connection_id BINARY(16) NOT NULL,
    cluster_id BINARY(16) NOT NULL,
    guest_type VARCHAR(8) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    vmid INT UNSIGNED NOT NULL,
    name VARCHAR(255) NULL,
    is_template TINYINT(1) NULL,
    inventory_state VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'active',
    first_seen_run_id BINARY(16) NOT NULL,
    last_seen_run_id BINARY(16) NOT NULL,
    first_seen_at DATETIME(6) NOT NULL,
    last_seen_at DATETIME(6) NOT NULL,
    archived_at DATETIME(6) NULL,
    PRIMARY KEY (id),
    CONSTRAINT uq_guests_cluster_type_vmid UNIQUE (cluster_id, guest_type, vmid),
    CONSTRAINT uq_guests_cluster_id UNIQUE (cluster_id, id),
    CONSTRAINT uq_guests_connection_cluster_id UNIQUE (connection_id, cluster_id, id),
    CONSTRAINT fk_guests_cluster FOREIGN KEY (connection_id, cluster_id)
        REFERENCES pve_clusters (connection_id, id) ON DELETE RESTRICT,
    CONSTRAINT fk_guests_first_seen FOREIGN KEY (connection_id, first_seen_run_id)
        REFERENCES inventory_sync_runs (connection_id, id) ON DELETE RESTRICT,
    CONSTRAINT fk_guests_last_seen FOREIGN KEY (connection_id, last_seen_run_id)
        REFERENCES inventory_sync_runs (connection_id, id) ON DELETE RESTRICT,
    CONSTRAINT chk_guests_type CHECK (guest_type IN ('qemu', 'lxc')),
    CONSTRAINT chk_guests_template CHECK (is_template IS NULL OR is_template IN (0, 1)),
    CONSTRAINT chk_guests_state CHECK (inventory_state IN ('active', 'archived')),
    CONSTRAINT chk_guests_archive CHECK (
        (inventory_state = 'active' AND archived_at IS NULL)
        OR (inventory_state = 'archived' AND archived_at IS NOT NULL)
    ),
    CONSTRAINT chk_guests_seen_time CHECK (last_seen_at >= first_seen_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

        $this->addSql(<<<'SQL'
CREATE TABLE guest_placements (
    guest_id BINARY(16) NOT NULL,
    connection_id BINARY(16) NOT NULL,
    cluster_id BINARY(16) NOT NULL,
    node_id BINARY(16) NOT NULL,
    observed_at DATETIME(6) NOT NULL,
    sync_run_id BINARY(16) NOT NULL,
    PRIMARY KEY (guest_id),
    INDEX idx_guest_placements_node (node_id, guest_id),
    CONSTRAINT fk_guest_placements_guest FOREIGN KEY (connection_id, cluster_id, guest_id)
        REFERENCES guests (connection_id, cluster_id, id) ON DELETE CASCADE,
    CONSTRAINT fk_guest_placements_node FOREIGN KEY (connection_id, cluster_id, node_id)
        REFERENCES pve_nodes (connection_id, cluster_id, id) ON DELETE RESTRICT,
    CONSTRAINT fk_guest_placements_run FOREIGN KEY (connection_id, sync_run_id)
        REFERENCES inventory_sync_runs (connection_id, id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

        $this->addSql(<<<'SQL'
CREATE TABLE pve_storages (
    id BINARY(16) NOT NULL,
    connection_id BINARY(16) NOT NULL,
    cluster_id BINARY(16) NOT NULL,
    storage_name VARCHAR(190) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    storage_type VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    supports_backup TINYINT(1) NOT NULL,
    shared TINYINT(1) NOT NULL,
    inventory_state VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'active',
    first_seen_run_id BINARY(16) NOT NULL,
    last_seen_run_id BINARY(16) NOT NULL,
    archived_at DATETIME(6) NULL,
    PRIMARY KEY (id),
    CONSTRAINT uq_pve_storages_name UNIQUE (cluster_id, storage_name),
    CONSTRAINT uq_pve_storages_cluster_id UNIQUE (cluster_id, id),
    CONSTRAINT uq_pve_storages_connection_cluster_id UNIQUE (connection_id, cluster_id, id),
    CONSTRAINT fk_pve_storages_cluster FOREIGN KEY (connection_id, cluster_id)
        REFERENCES pve_clusters (connection_id, id) ON DELETE RESTRICT,
    CONSTRAINT fk_pve_storages_first_seen FOREIGN KEY (connection_id, first_seen_run_id)
        REFERENCES inventory_sync_runs (connection_id, id) ON DELETE RESTRICT,
    CONSTRAINT fk_pve_storages_last_seen FOREIGN KEY (connection_id, last_seen_run_id)
        REFERENCES inventory_sync_runs (connection_id, id) ON DELETE RESTRICT,
    CONSTRAINT chk_pve_storages_backup CHECK (supports_backup IN (0, 1)),
    CONSTRAINT chk_pve_storages_shared CHECK (shared IN (0, 1)),
    CONSTRAINT chk_pve_storages_state CHECK (inventory_state IN ('active', 'archived')),
    CONSTRAINT chk_pve_storages_archive CHECK (
        (inventory_state = 'active' AND archived_at IS NULL)
        OR (inventory_state = 'archived' AND archived_at IS NOT NULL)
    )
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

        $this->addSql(<<<'SQL'
CREATE TABLE pve_node_storage_state (
    connection_id BINARY(16) NOT NULL,
    cluster_id BINARY(16) NOT NULL,
    node_id BINARY(16) NOT NULL,
    storage_id BINARY(16) NOT NULL,
    enabled TINYINT(1) NOT NULL,
    active TINYINT(1) NOT NULL,
    total_bytes BIGINT UNSIGNED NULL,
    used_bytes BIGINT UNSIGNED NULL,
    available_bytes BIGINT UNSIGNED NULL,
    observed_at DATETIME(6) NOT NULL,
    sync_run_id BINARY(16) NOT NULL,
    PRIMARY KEY (node_id, storage_id),
    INDEX idx_pve_node_storage_freshness (storage_id, observed_at),
    CONSTRAINT fk_pve_node_storage_node FOREIGN KEY (connection_id, cluster_id, node_id)
        REFERENCES pve_nodes (connection_id, cluster_id, id) ON DELETE CASCADE,
    CONSTRAINT fk_pve_node_storage_storage FOREIGN KEY (connection_id, cluster_id, storage_id)
        REFERENCES pve_storages (connection_id, cluster_id, id) ON DELETE CASCADE,
    CONSTRAINT fk_pve_node_storage_run FOREIGN KEY (connection_id, sync_run_id)
        REFERENCES inventory_sync_runs (connection_id, id) ON DELETE RESTRICT,
    CONSTRAINT chk_pve_node_storage_enabled CHECK (enabled IN (0, 1)),
    CONSTRAINT chk_pve_node_storage_active CHECK (active IN (0, 1)),
    CONSTRAINT chk_pve_node_storage_capacity CHECK (
        (total_bytes IS NULL AND used_bytes IS NULL AND available_bytes IS NULL)
        OR (total_bytes IS NOT NULL AND used_bytes IS NOT NULL AND available_bytes IS NOT NULL
            AND used_bytes <= total_bytes AND available_bytes <= total_bytes)
    )
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

        foreach (['hoddmimir_web', 'hoddmimir_backup_worker'] as $readinessUser) {
            $this->grant($readinessUser, 'SELECT', 'doctrine_migration_versions');
            $this->grant($readinessUser, 'SELECT', 'credential_key_usage');
        }

        foreach ([
            'doctrine_migration_versions',
            'credential_key_usage',
            'collector_credentials',
            'proxmox_connections',
            'proxmox_connection_endpoints',
        ] as $readTable) {
            $this->grant('hoddmimir_collector', 'SELECT', $readTable);
        }
        foreach ([
            'collector_schedule',
            'worker_heartbeats',
            'collector_cycles',
            'inventory_sync_runs',
            'proxmox_installation_bindings',
            'pve_clusters',
            'pve_nodes',
            'guests',
        ] as $aggregateTable) {
            $this->grant('hoddmimir_collector', 'SELECT, INSERT, UPDATE', $aggregateTable);
        }
        foreach (['inventory_sync_scope_results', 'inventory_sync_endpoint_attempts'] as $appendTable) {
            $this->grant('hoddmimir_collector', 'SELECT, INSERT', $appendTable);
        }
        $this->grant('hoddmimir_collector', 'SELECT, INSERT, UPDATE, DELETE', 'guest_placements');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE pve_node_storage_state');
        $this->addSql('DROP TABLE pve_storages');
        $this->addSql('DROP TABLE guest_placements');
        $this->addSql('DROP TABLE guests');
        $this->addSql('DROP TABLE pve_nodes');
        $this->addSql('DROP TABLE pve_clusters');
        $this->addSql('DROP TABLE inventory_sync_failures');
        $this->addSql('DROP TABLE inventory_sync_endpoint_attempts');
        $this->addSql('DROP TABLE inventory_sync_scope_results');
        $this->addSql('DROP TABLE proxmox_installation_bindings');
        $this->addSql('DROP TABLE inventory_sync_runs');
        $this->addSql('DROP TABLE proxmox_capability_snapshots');
        $this->addSql('DROP TABLE collector_cycles');
        $this->addSql('DROP TABLE worker_heartbeats');
        $this->addSql('DROP TABLE collector_schedule');
        $this->addSql('DROP VIEW credential_key_usage');
        $this->addSql('DROP VIEW collector_credentials');
        $this->addSql('DROP TABLE proxmox_credentials');
        $this->addSql('DROP TABLE proxmox_connection_endpoints');
        $this->addSql('DROP TABLE proxmox_connections');
    }

    private function grant(string $user, string $privileges, string $table): void
    {
        $database = $this->connection->getDatabase();
        if (1 !== preg_match('/^[A-Za-z0-9_]+$/D', $database)) {
            throw new \RuntimeException('The migration database name is unsafe for runtime grants.');
        }
        if (1 !== preg_match('/^[a-z_]+$/D', $user) || 1 !== preg_match('/^[a-z_]+$/D', $table)) {
            throw new \RuntimeException('A runtime grant identifier is invalid.');
        }

        $this->addSql(sprintf(
            "GRANT %s ON `%s`.`%s` TO '%s'@'%%'",
            $privileges,
            $database,
            $table,
            $user,
        ));
    }
}
