<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260712000900 extends AbstractMigration
{
    private const array TABLES = [
        'backup_policies',
        'backup_policy_assignments',
        'backup_policy_guest_overrides',
    ];

    public function getDescription(): string
    {
        return 'Add fail-closed policy and selection persistence plus real shadow policy references.';
    }

    public function isTransactional(): bool
    {
        return false;
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
CREATE TABLE backup_policies (
    id BINARY(16) NOT NULL,
    connection_id BINARY(16) NOT NULL,
    cluster_id BINARY(16) NOT NULL,
    target_id BINARY(16) NULL,
    display_name VARCHAR(190) NOT NULL,
    status VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'draft',
    revision BIGINT UNSIGNED NOT NULL DEFAULT 1,
    policy_priority SMALLINT UNSIGNED NULL,
    backup_mode VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NULL,
    compression VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NULL,
    maximum_age_seconds BIGINT UNSIGNED NULL,
    bytes_written_threshold BIGINT UNSIGNED NULL,
    cooldown_seconds BIGINT UNSIGNED NULL,
    schedule VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NULL,
    legacy_maxfiles INT UNSIGNED NULL,
    keep_all TINYINT(1) NULL,
    keep_last INT UNSIGNED NULL,
    keep_hourly INT UNSIGNED NULL,
    keep_daily INT UNSIGNED NULL,
    keep_weekly INT UNSIGNED NULL,
    keep_monthly INT UNSIGNED NULL,
    keep_yearly INT UNSIGNED NULL,
    retention_execution_enabled TINYINT(1) NOT NULL DEFAULT 0,
    created_at DATETIME(6) NOT NULL,
    updated_at DATETIME(6) NOT NULL,
    disabled_at DATETIME(6) NULL,
    PRIMARY KEY (id),
    CONSTRAINT uq_backup_policies_context_id UNIQUE (connection_id, cluster_id, id),
    CONSTRAINT uq_backup_policies_context_name UNIQUE (connection_id, cluster_id, display_name),
    INDEX idx_backup_policies_list (display_name, id),
    INDEX idx_backup_policies_target (target_id, id),
    CONSTRAINT fk_backup_policies_cluster FOREIGN KEY (connection_id, cluster_id)
        REFERENCES pve_clusters (connection_id, id) ON DELETE RESTRICT,
    CONSTRAINT fk_backup_policies_target FOREIGN KEY (connection_id, cluster_id, target_id)
        REFERENCES backup_targets (connection_id, cluster_id, id) ON DELETE RESTRICT,
    CONSTRAINT chk_backup_policies_name CHECK (CHAR_LENGTH(TRIM(display_name)) BETWEEN 1 AND 190),
    CONSTRAINT chk_backup_policies_status CHECK (status IN ('draft', 'enabled', 'disabled')),
    CONSTRAINT chk_backup_policies_revision CHECK (revision > 0),
    CONSTRAINT chk_backup_policies_priority CHECK (policy_priority IS NULL OR policy_priority <= 1000),
    CONSTRAINT chk_backup_policies_mode CHECK (backup_mode IS NULL OR backup_mode IN ('snapshot', 'suspend', 'stop')),
    CONSTRAINT chk_backup_policies_compression CHECK (compression IS NULL OR compression IN ('0', 'gzip', 'lzo', 'zstd')),
    CONSTRAINT chk_backup_policies_thresholds CHECK (
        (maximum_age_seconds IS NULL OR maximum_age_seconds > 0)
        AND ((bytes_written_threshold IS NULL) = (cooldown_seconds IS NULL))
        AND (bytes_written_threshold IS NULL OR bytes_written_threshold > 0)
    ),
    CONSTRAINT chk_backup_policies_schedule CHECK (schedule IS NULL OR schedule = 'collector_cycle'),
    CONSTRAINT chk_backup_policies_retention CHECK (
        (legacy_maxfiles IS NULL OR legacy_maxfiles BETWEEN 1 AND 1000000)
        AND (keep_all IS NULL OR keep_all IN (0, 1))
        AND (keep_last IS NULL OR keep_last BETWEEN 1 AND 1000000)
        AND (keep_hourly IS NULL OR keep_hourly BETWEEN 1 AND 1000000)
        AND (keep_daily IS NULL OR keep_daily BETWEEN 1 AND 1000000)
        AND (keep_weekly IS NULL OR keep_weekly BETWEEN 1 AND 1000000)
        AND (keep_monthly IS NULL OR keep_monthly BETWEEN 1 AND 1000000)
        AND (keep_yearly IS NULL OR keep_yearly BETWEEN 1 AND 1000000)
        AND (legacy_maxfiles IS NULL OR (keep_all IS NULL AND keep_last IS NULL AND keep_hourly IS NULL
            AND keep_daily IS NULL AND keep_weekly IS NULL AND keep_monthly IS NULL AND keep_yearly IS NULL))
        AND (keep_all <> 1 OR (keep_last IS NULL AND keep_hourly IS NULL AND keep_daily IS NULL
            AND keep_weekly IS NULL AND keep_monthly IS NULL AND keep_yearly IS NULL))
        AND (keep_all <> 0 OR keep_last IS NOT NULL OR keep_hourly IS NOT NULL OR keep_daily IS NOT NULL
            OR keep_weekly IS NOT NULL OR keep_monthly IS NOT NULL OR keep_yearly IS NOT NULL)
    ),
    CONSTRAINT chk_backup_policies_retention_effect CHECK (
        retention_execution_enabled IN (0, 1)
        AND (retention_execution_enabled = 0 OR legacy_maxfiles IS NOT NULL OR keep_all IS NOT NULL
            OR keep_last IS NOT NULL OR keep_hourly IS NOT NULL OR keep_daily IS NOT NULL
            OR keep_weekly IS NOT NULL OR keep_monthly IS NOT NULL OR keep_yearly IS NOT NULL)
    ),
    CONSTRAINT chk_backup_policies_activation CHECK (
        status <> 'enabled' OR (
            target_id IS NOT NULL AND policy_priority IS NOT NULL AND backup_mode IS NOT NULL
            AND compression IS NOT NULL AND schedule = 'collector_cycle'
            AND (maximum_age_seconds IS NOT NULL OR bytes_written_threshold IS NOT NULL)
            AND (legacy_maxfiles IS NOT NULL OR keep_all IS NOT NULL OR keep_last IS NOT NULL
                OR keep_hourly IS NOT NULL OR keep_daily IS NOT NULL OR keep_weekly IS NOT NULL
                OR keep_monthly IS NOT NULL OR keep_yearly IS NOT NULL)
        )
    ),
    CONSTRAINT chk_backup_policies_time CHECK (
        created_at <= updated_at
        AND ((status = 'disabled' AND disabled_at BETWEEN created_at AND updated_at)
            OR (status <> 'disabled' AND disabled_at IS NULL))
    )
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

        $this->addSql(<<<'SQL'
CREATE TABLE backup_policy_assignments (
    id BINARY(16) NOT NULL,
    policy_id BINARY(16) NOT NULL,
    connection_id BINARY(16) NOT NULL,
    cluster_id BINARY(16) NOT NULL,
    scope VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    subject_connection_id BINARY(16) NULL,
    subject_cluster_id BINARY(16) NULL,
    node_id BINARY(16) NULL,
    guest_id BINARY(16) NULL,
    subject_key BINARY(16) AS (COALESCE(guest_id, node_id, subject_cluster_id, subject_connection_id,
        UNHEX('00000000000000000000000000000000'))) PERSISTENT,
    selection_value VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    status VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'active',
    revision BIGINT UNSIGNED NOT NULL DEFAULT 1,
    created_at DATETIME(6) NOT NULL,
    updated_at DATETIME(6) NOT NULL,
    disabled_at DATETIME(6) NULL,
    PRIMARY KEY (id),
    CONSTRAINT uq_backup_policy_assignments_subject UNIQUE (policy_id, scope, subject_key),
    INDEX idx_backup_policy_assignments_page (policy_id, scope, id),
    CONSTRAINT fk_backup_policy_assignments_policy FOREIGN KEY (connection_id, cluster_id, policy_id)
        REFERENCES backup_policies (connection_id, cluster_id, id) ON DELETE CASCADE,
    CONSTRAINT fk_backup_policy_assignments_connection FOREIGN KEY (subject_connection_id)
        REFERENCES proxmox_connections (id) ON DELETE RESTRICT,
    CONSTRAINT fk_backup_policy_assignments_cluster FOREIGN KEY (subject_connection_id, subject_cluster_id)
        REFERENCES pve_clusters (connection_id, id) ON DELETE RESTRICT,
    CONSTRAINT fk_backup_policy_assignments_node FOREIGN KEY (subject_connection_id, subject_cluster_id, node_id)
        REFERENCES pve_nodes (connection_id, cluster_id, id) ON DELETE RESTRICT,
    CONSTRAINT fk_backup_policy_assignments_guest FOREIGN KEY (subject_connection_id, subject_cluster_id, guest_id)
        REFERENCES guests (connection_id, cluster_id, id) ON DELETE RESTRICT,
    CONSTRAINT chk_backup_policy_assignments_scope CHECK (
        (scope = 'global' AND subject_connection_id IS NULL AND subject_cluster_id IS NULL AND node_id IS NULL AND guest_id IS NULL)
        OR (scope = 'connection' AND subject_connection_id = connection_id AND subject_cluster_id IS NULL AND node_id IS NULL AND guest_id IS NULL)
        OR (scope = 'cluster' AND subject_connection_id = connection_id AND subject_cluster_id = cluster_id AND node_id IS NULL AND guest_id IS NULL)
        OR (scope = 'node' AND subject_connection_id = connection_id AND subject_cluster_id = cluster_id AND node_id IS NOT NULL AND guest_id IS NULL)
        OR (scope = 'guest' AND subject_connection_id = connection_id AND subject_cluster_id = cluster_id AND node_id IS NULL AND guest_id IS NOT NULL)
    ),
    CONSTRAINT chk_backup_policy_assignments_value CHECK (selection_value IN ('include', 'exclude')),
    CONSTRAINT chk_backup_policy_assignments_status CHECK (status IN ('active', 'disabled')),
    CONSTRAINT chk_backup_policy_assignments_revision CHECK (revision > 0),
    CONSTRAINT chk_backup_policy_assignments_time CHECK (
        created_at <= updated_at
        AND ((status = 'disabled' AND disabled_at BETWEEN created_at AND updated_at)
            OR (status = 'active' AND disabled_at IS NULL))
    )
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

        $this->addSql(<<<'SQL'
CREATE TABLE backup_policy_guest_overrides (
    id BINARY(16) NOT NULL,
    policy_id BINARY(16) NOT NULL,
    connection_id BINARY(16) NOT NULL,
    cluster_id BINARY(16) NOT NULL,
    guest_id BINARY(16) NOT NULL,
    backup_mode VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NULL,
    compression VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NULL,
    legacy_maxfiles INT UNSIGNED NULL,
    keep_all TINYINT(1) NULL,
    keep_last INT UNSIGNED NULL,
    keep_hourly INT UNSIGNED NULL,
    keep_daily INT UNSIGNED NULL,
    keep_weekly INT UNSIGNED NULL,
    keep_monthly INT UNSIGNED NULL,
    keep_yearly INT UNSIGNED NULL,
    status VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'active',
    revision BIGINT UNSIGNED NOT NULL DEFAULT 1,
    created_at DATETIME(6) NOT NULL,
    updated_at DATETIME(6) NOT NULL,
    disabled_at DATETIME(6) NULL,
    PRIMARY KEY (id),
    CONSTRAINT uq_backup_policy_guest_overrides_guest UNIQUE (policy_id, guest_id),
    INDEX idx_backup_policy_guest_overrides_page (policy_id, guest_id, id),
    CONSTRAINT fk_backup_policy_guest_overrides_policy FOREIGN KEY (connection_id, cluster_id, policy_id)
        REFERENCES backup_policies (connection_id, cluster_id, id) ON DELETE CASCADE,
    CONSTRAINT fk_backup_policy_guest_overrides_guest FOREIGN KEY (connection_id, cluster_id, guest_id)
        REFERENCES guests (connection_id, cluster_id, id) ON DELETE RESTRICT,
    CONSTRAINT chk_backup_policy_guest_overrides_mode CHECK (backup_mode IS NULL OR backup_mode IN ('snapshot', 'suspend', 'stop')),
    CONSTRAINT chk_backup_policy_guest_overrides_compression CHECK (compression IS NULL OR compression IN ('0', 'gzip', 'lzo', 'zstd')),
    CONSTRAINT chk_backup_policy_guest_overrides_retention CHECK (
        (legacy_maxfiles IS NULL OR legacy_maxfiles BETWEEN 1 AND 1000000)
        AND (keep_all IS NULL OR keep_all IN (0, 1))
        AND (keep_last IS NULL OR keep_last BETWEEN 1 AND 1000000)
        AND (keep_hourly IS NULL OR keep_hourly BETWEEN 1 AND 1000000)
        AND (keep_daily IS NULL OR keep_daily BETWEEN 1 AND 1000000)
        AND (keep_weekly IS NULL OR keep_weekly BETWEEN 1 AND 1000000)
        AND (keep_monthly IS NULL OR keep_monthly BETWEEN 1 AND 1000000)
        AND (keep_yearly IS NULL OR keep_yearly BETWEEN 1 AND 1000000)
        AND (legacy_maxfiles IS NULL OR (keep_all IS NULL AND keep_last IS NULL AND keep_hourly IS NULL
            AND keep_daily IS NULL AND keep_weekly IS NULL AND keep_monthly IS NULL AND keep_yearly IS NULL))
        AND (keep_all <> 1 OR (keep_last IS NULL AND keep_hourly IS NULL AND keep_daily IS NULL
            AND keep_weekly IS NULL AND keep_monthly IS NULL AND keep_yearly IS NULL))
        AND (keep_all <> 0 OR keep_last IS NOT NULL OR keep_hourly IS NOT NULL OR keep_daily IS NOT NULL
            OR keep_weekly IS NOT NULL OR keep_monthly IS NOT NULL OR keep_yearly IS NOT NULL)
    ),
    CONSTRAINT chk_backup_policy_guest_overrides_content CHECK (
        backup_mode IS NOT NULL OR compression IS NOT NULL OR legacy_maxfiles IS NOT NULL OR keep_all IS NOT NULL
        OR keep_last IS NOT NULL OR keep_hourly IS NOT NULL OR keep_daily IS NOT NULL OR keep_weekly IS NOT NULL
        OR keep_monthly IS NOT NULL OR keep_yearly IS NOT NULL
    ),
    CONSTRAINT chk_backup_policy_guest_overrides_status CHECK (status IN ('active', 'disabled')),
    CONSTRAINT chk_backup_policy_guest_overrides_revision CHECK (revision > 0),
    CONSTRAINT chk_backup_policy_guest_overrides_time CHECK (
        created_at <= updated_at
        AND ((status = 'disabled' AND disabled_at BETWEEN created_at AND updated_at)
            OR (status = 'active' AND disabled_at IS NULL))
    )
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

        $this->addSql(<<<'SQL'
ALTER TABLE scheduler_decisions
    ADD CONSTRAINT fk_scheduler_decisions_policy FOREIGN KEY (connection_id, cluster_id, policy_id)
        REFERENCES backup_policies (connection_id, cluster_id, id) ON DELETE RESTRICT
SQL);
        $this->replaceGateChecks(true);
        $this->readPrivileges('GRANT');
    }

    public function down(Schema $schema): void
    {
        $this->readPrivileges('REVOKE');
        $this->replaceGateChecks(false);
        $this->addSql('ALTER TABLE scheduler_decisions DROP FOREIGN KEY fk_scheduler_decisions_policy');
        $this->addSql('DROP TABLE backup_policy_guest_overrides');
        $this->addSql('DROP TABLE backup_policy_assignments');
        $this->addSql('DROP TABLE backup_policies');
    }

    private function replaceGateChecks(bool $expanded): void
    {
        $this->addSql('ALTER TABLE scheduler_decision_gates DROP CONSTRAINT chk_scheduler_decision_gates_code');
        $this->addSql('ALTER TABLE scheduler_decision_gates DROP CONSTRAINT chk_scheduler_decision_gates_scope');
        $codes = $expanded
            ? "'connection_enabled', 'cluster_enabled', 'node_enabled', 'guest_enabled', 'policy_enabled', 'target_enabled', 'explicit_exclusion_absent', 'guest_active', 'placement_present', 'placement_fresh', 'inventory_fresh', 'active_request_absent', 'target_node_allowed', 'target_storage_enabled', 'target_storage_active', 'executor_authorized', 'executor_authorization_fresh', 'capacity_fresh', 'minimum_free_space', 'node_concurrency', 'target_concurrency', 'pbs_mapping_valid'"
            : "'connection_enabled', 'cluster_enabled', 'node_enabled', 'guest_enabled', 'policy_enabled', 'target_enabled', 'explicit_exclusion_absent', 'guest_active', 'placement_present', 'placement_fresh', 'active_request_absent', 'target_node_allowed', 'target_storage_enabled', 'target_storage_active', 'executor_authorized', 'capacity_fresh', 'minimum_free_space', 'node_concurrency', 'target_concurrency', 'pbs_mapping_valid'";
        $scopes = $expanded
            ? "'connection', 'cluster', 'node', 'guest', 'policy', 'target', 'placement', 'inventory', 'authorization', 'capacity', 'concurrency', 'request', 'pbs_mapping'"
            : "'connection', 'cluster', 'node', 'guest', 'policy', 'target', 'placement', 'capacity', 'concurrency', 'request', 'pbs_mapping'";
        $this->addSql(sprintf(
            'ALTER TABLE scheduler_decision_gates ADD CONSTRAINT chk_scheduler_decision_gates_code CHECK (code IN (%s))',
            $codes,
        ));
        $this->addSql(sprintf(
            'ALTER TABLE scheduler_decision_gates ADD CONSTRAINT chk_scheduler_decision_gates_scope CHECK (scope IN (%s))',
            $scopes,
        ));
    }

    private function readPrivileges(string $operation): void
    {
        $database = $this->connection->getDatabase();
        if (!is_string($database)
            || 1 !== preg_match('/^[A-Za-z0-9_]+$/D', $database)
            || !in_array($operation, ['GRANT', 'REVOKE'], true)) {
            throw new \RuntimeException('A policy grant identifier is invalid.');
        }
        foreach (['hoddmimir_web', 'hoddmimir_collector'] as $reader) {
            foreach (self::TABLES as $table) {
                $this->addSql(sprintf(
                    "%s SELECT ON `%s`.`%s` %s '%s'@'%%'",
                    $operation,
                    $database,
                    $table,
                    'GRANT' === $operation ? 'TO' : 'FROM',
                    $reader,
                ));
            }
        }
    }
}
