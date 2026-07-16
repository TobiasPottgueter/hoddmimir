<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
use RuntimeException;

final class Version20260716000100 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add fenced, revision-bound executor permission evidence refresh and least-privilege runtime views.';
    }

    public function isTransactional(): bool
    {
        return false;
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
ALTER TABLE executor_permission_evidence
    ADD evidence_set_revision BIGINT UNSIGNED NOT NULL DEFAULT 0 AFTER guest_key,
    ADD endpoint_id BINARY(16) NULL AFTER evidence_set_revision,
    ADD connection_revision INT UNSIGNED NOT NULL DEFAULT 0 AFTER endpoint_id,
    ADD backup_credential_revision INT UNSIGNED NOT NULL DEFAULT 0 AFTER connection_revision,
    ADD scan_credential_revision INT UNSIGNED NOT NULL DEFAULT 0 AFTER backup_credential_revision,
    ADD CONSTRAINT fk_executor_evidence_endpoint
        FOREIGN KEY (connection_id, endpoint_id)
        REFERENCES proxmox_connection_endpoints (connection_id, id) ON DELETE CASCADE,
    ADD CONSTRAINT chk_executor_evidence_binding CHECK (
        (evidence_set_revision = 0 AND endpoint_id IS NULL
            AND connection_revision = 0 AND backup_credential_revision = 0 AND scan_credential_revision = 0)
        OR (evidence_set_revision > 0 AND endpoint_id IS NOT NULL
            AND connection_revision > 0 AND backup_credential_revision > 0 AND scan_credential_revision > 0)
    )
SQL);

        $this->addSql(<<<'SQL'
CREATE TABLE executor_evidence_refresh_state (
    connection_id BINARY(16) NOT NULL,
    next_due_at DATETIME(6) NOT NULL,
    lease_owner BINARY(16) NULL,
    lease_token BINARY(16) NULL,
    lease_fence BIGINT UNSIGNED NOT NULL DEFAULT 0,
    lease_issued_at DATETIME(6) NULL,
    lease_expires_at DATETIME(6) NULL,
    lease_connection_revision INT UNSIGNED NULL,
    lease_backup_credential_revision INT UNSIGNED NULL,
    lease_scan_credential_revision INT UNSIGNED NULL,
    lease_endpoint_id BINARY(16) NULL,
    lease_observed_at DATETIME(6) NULL,
    published_set_revision BIGINT UNSIGNED NOT NULL DEFAULT 0,
    published_endpoint_id BINARY(16) NULL,
    published_connection_revision INT UNSIGNED NULL,
    published_backup_credential_revision INT UNSIGNED NULL,
    published_scan_credential_revision INT UNSIGNED NULL,
    staged_subject_count INT UNSIGNED NULL,
    last_attempted_at DATETIME(6) NULL,
    last_success_at DATETIME(6) NULL,
    last_failure_code VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NULL,
    updated_at DATETIME(6) NOT NULL,
    PRIMARY KEY (connection_id),
    INDEX idx_executor_refresh_due (next_due_at, lease_expires_at, connection_id),
    CONSTRAINT fk_executor_refresh_state_connection FOREIGN KEY (connection_id)
        REFERENCES proxmox_connections (id) ON DELETE CASCADE,
    CONSTRAINT chk_executor_refresh_state_lease CHECK (
        (lease_owner IS NULL AND lease_token IS NULL AND lease_issued_at IS NULL
            AND lease_expires_at IS NULL AND lease_connection_revision IS NULL
            AND lease_backup_credential_revision IS NULL AND lease_scan_credential_revision IS NULL
            AND lease_endpoint_id IS NULL AND lease_observed_at IS NULL AND staged_subject_count IS NULL)
        OR (lease_owner IS NOT NULL AND lease_token IS NOT NULL AND lease_issued_at IS NOT NULL
            AND lease_expires_at > lease_issued_at AND lease_fence > 0
            AND lease_connection_revision > 0 AND lease_backup_credential_revision > 0
            AND lease_scan_credential_revision > 0 AND staged_subject_count IS NOT NULL
            AND ((lease_endpoint_id IS NULL AND lease_observed_at IS NULL)
              OR (lease_endpoint_id IS NOT NULL AND lease_observed_at IS NOT NULL)))
    ),
    CONSTRAINT chk_executor_refresh_state_published CHECK (
        (published_set_revision = 0 AND published_endpoint_id IS NULL AND published_connection_revision IS NULL
            AND published_backup_credential_revision IS NULL AND published_scan_credential_revision IS NULL)
        OR (published_set_revision > 0 AND published_endpoint_id IS NOT NULL AND published_connection_revision > 0
            AND published_backup_credential_revision > 0 AND published_scan_credential_revision > 0)
    ),
    CONSTRAINT chk_executor_refresh_state_failure CHECK (
        last_failure_code IS NULL OR last_failure_code IN (
            'credential_unavailable', 'authentication', 'permission_denied', 'tls', 'transport',
            'remote_unavailable', 'invalid_response', 'configuration_changed'
        )
    )
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

        $this->addSql(<<<'SQL'
CREATE TABLE executor_evidence_refresh_subject_stage (
    connection_id BINARY(16) NOT NULL,
    evidence_set_revision BIGINT UNSIGNED NOT NULL,
    cluster_id BINARY(16) NOT NULL,
    target_id BINARY(16) NOT NULL,
    node_id BINARY(16) NOT NULL,
    storage_id BINARY(16) NOT NULL,
    storage_name VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    guest_id BINARY(16) NULL,
    guest_key BINARY(16) NOT NULL,
    vmid INT UNSIGNED NULL,
    PRIMARY KEY (connection_id, evidence_set_revision, target_id, node_id, guest_key),
    INDEX idx_executor_subject_stage_cursor (connection_id, evidence_set_revision, target_id, node_id, guest_key),
    CONSTRAINT fk_executor_subject_stage_state FOREIGN KEY (connection_id)
        REFERENCES executor_evidence_refresh_state (connection_id) ON DELETE CASCADE,
    CONSTRAINT fk_executor_subject_stage_target FOREIGN KEY (connection_id, cluster_id, target_id)
        REFERENCES backup_targets (connection_id, cluster_id, id) ON DELETE CASCADE,
    CONSTRAINT fk_executor_subject_stage_node FOREIGN KEY (connection_id, cluster_id, node_id)
        REFERENCES pve_nodes (connection_id, cluster_id, id) ON DELETE CASCADE,
    CONSTRAINT fk_executor_subject_stage_storage FOREIGN KEY (connection_id, cluster_id, storage_id)
        REFERENCES pve_storages (connection_id, cluster_id, id) ON DELETE CASCADE,
    CONSTRAINT fk_executor_subject_stage_guest FOREIGN KEY (connection_id, cluster_id, guest_id)
        REFERENCES guests (connection_id, cluster_id, id) ON DELETE CASCADE,
    CONSTRAINT chk_executor_subject_stage CHECK (
        evidence_set_revision > 0
        AND ((guest_id IS NULL AND vmid IS NULL
              AND guest_key = UNHEX('00000000000000000000000000000000'))
          OR (guest_id IS NOT NULL AND guest_key = guest_id AND vmid BETWEEN 1 AND 999999999))
    )
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

        $this->addSql(<<<'SQL'
CREATE TABLE executor_evidence_refresh_projection_stage (
    connection_id BINARY(16) NOT NULL,
    evidence_set_revision BIGINT UNSIGNED NOT NULL,
    target_id BINARY(16) NOT NULL,
    node_id BINARY(16) NOT NULL,
    guest_key BINARY(16) NOT NULL,
    endpoint_id BINARY(16) NOT NULL,
    connection_revision INT UNSIGNED NOT NULL,
    backup_credential_revision INT UNSIGNED NOT NULL,
    scan_credential_revision INT UNSIGNED NOT NULL,
    vm_backup_authorized TINYINT(1) NOT NULL,
    datastore_allocate_authorized TINYINT(1) NOT NULL,
    authorized TINYINT(1) NOT NULL,
    PRIMARY KEY (connection_id, evidence_set_revision, target_id, node_id, guest_key),
    CONSTRAINT fk_executor_projection_stage_subject
        FOREIGN KEY (connection_id, evidence_set_revision, target_id, node_id, guest_key)
        REFERENCES executor_evidence_refresh_subject_stage
            (connection_id, evidence_set_revision, target_id, node_id, guest_key) ON DELETE CASCADE,
    CONSTRAINT fk_executor_projection_stage_endpoint
        FOREIGN KEY (connection_id, endpoint_id)
        REFERENCES proxmox_connection_endpoints (connection_id, id) ON DELETE CASCADE,
    CONSTRAINT chk_executor_projection_stage CHECK (
        evidence_set_revision > 0 AND connection_revision > 0
        AND backup_credential_revision > 0 AND scan_credential_revision > 0
        AND vm_backup_authorized IN (0, 1) AND datastore_allocate_authorized IN (0, 1)
        AND authorized IN (0, 1)
        AND authorized = (vm_backup_authorized AND datastore_allocate_authorized)
    )
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

        $this->addSql(<<<'SQL'
CREATE DEFINER=CURRENT_USER SQL SECURITY DEFINER VIEW executor_scan_credentials AS
SELECT credential.id, credential.connection_id, credential.principal, credential.token_name,
       credential.secret_envelope, credential.revision
FROM proxmox_credentials credential
INNER JOIN proxmox_connections connection
        ON connection.id = credential.connection_id
       AND connection.enabled = 1
       AND connection.product = 'pve'
WHERE credential.purpose = 'collector' AND credential.auth_scheme = 'api_token'
SQL);

        $this->addSql(<<<'SQL'
CREATE DEFINER=CURRENT_USER SQL SECURITY DEFINER VIEW executor_evidence_claim_catalog AS
SELECT connection.id AS connection_id, connection.revision AS connection_revision,
       backup.revision AS backup_credential_revision,
       scan.revision AS scan_credential_revision
FROM proxmox_connections connection
INNER JOIN proxmox_credentials backup
        ON backup.connection_id = connection.id
       AND backup.purpose = 'backup' AND backup.auth_scheme = 'api_token'
INNER JOIN proxmox_credentials scan
        ON scan.connection_id = connection.id
       AND scan.purpose = 'collector' AND scan.auth_scheme = 'api_token'
WHERE connection.enabled = 1 AND connection.product = 'pve'
SQL);

        $this->addSql(<<<'SQL'
CREATE DEFINER=CURRENT_USER SQL SECURITY DEFINER VIEW current_executor_permission_evidence AS
SELECT evidence.id, evidence.connection_id, evidence.cluster_id, evidence.target_id,
       evidence.node_id, evidence.storage_id, evidence.guest_id, evidence.guest_key,
       evidence.vm_backup_authorized, evidence.datastore_allocate_authorized,
       evidence.authorized, evidence.observed_at, evidence.revision,
       evidence.evidence_set_revision, evidence.endpoint_id,
       evidence.connection_revision, evidence.backup_credential_revision,
       evidence.scan_credential_revision
FROM executor_permission_evidence evidence
INNER JOIN executor_evidence_refresh_state state
        ON state.connection_id = evidence.connection_id
       AND state.published_set_revision = evidence.evidence_set_revision
       AND state.published_connection_revision = evidence.connection_revision
       AND state.published_backup_credential_revision = evidence.backup_credential_revision
       AND state.published_scan_credential_revision = evidence.scan_credential_revision
       AND state.published_endpoint_id = evidence.endpoint_id
INNER JOIN proxmox_connections connection
        ON connection.id = evidence.connection_id
       AND connection.enabled = 1 AND connection.product = 'pve'
       AND connection.revision = evidence.connection_revision
INNER JOIN proxmox_connection_endpoints endpoint
        ON endpoint.connection_id = evidence.connection_id
       AND endpoint.id = evidence.endpoint_id AND endpoint.enabled = 1
INNER JOIN proxmox_credentials backup
        ON backup.connection_id = evidence.connection_id
       AND backup.purpose = 'backup' AND backup.auth_scheme = 'api_token'
       AND backup.revision = evidence.backup_credential_revision
INNER JOIN proxmox_credentials scan
        ON scan.connection_id = evidence.connection_id
       AND scan.purpose = 'collector' AND scan.auth_scheme = 'api_token'
       AND scan.revision = evidence.scan_credential_revision
WHERE evidence.evidence_set_revision > 0
SQL);

        $this->addSql(<<<'ROUTINE'
CREATE DEFINER=CURRENT_USER PROCEDURE delete_executor_evidence_publish_rows(IN p_connection_id BINARY(16))
SQL SECURITY DEFINER
MODIFIES SQL DATA
DELETE FROM executor_permission_evidence WHERE connection_id = p_connection_id
ROUTINE);

        $this->addSql(<<<'SQL'
CREATE DEFINER=CURRENT_USER SQL SECURITY DEFINER VIEW executor_evidence_endpoint_catalog AS
SELECT connection.id AS connection_id, connection.revision AS connection_revision,
       endpoint.id AS endpoint_id, endpoint.priority, endpoint.host, endpoint.port,
       endpoint.tls_mode, endpoint.custom_ca_pem, endpoint.sha256_fingerprint,
       backup.id AS backup_credential_id, backup.revision AS backup_credential_revision,
       scan.id AS scan_credential_id, scan.revision AS scan_credential_revision,
       capability.version_major
FROM proxmox_connections connection
INNER JOIN proxmox_connection_endpoints endpoint
        ON endpoint.connection_id = connection.id AND endpoint.enabled = 1
INNER JOIN proxmox_credentials backup
        ON backup.connection_id = connection.id
       AND backup.purpose = 'backup' AND backup.auth_scheme = 'api_token'
INNER JOIN proxmox_credentials scan
        ON scan.connection_id = connection.id
       AND scan.purpose = 'collector' AND scan.auth_scheme = 'api_token'
INNER JOIN proxmox_capability_snapshots capability
        ON capability.id = (
            SELECT latest.id FROM proxmox_capability_snapshots latest
            WHERE latest.connection_id = connection.id AND latest.product = 'pve'
            ORDER BY latest.last_observed_at DESC, latest.id DESC LIMIT 1
        )
WHERE connection.enabled = 1 AND connection.product = 'pve'
SQL);

        $this->addSql(<<<'SQL'
CREATE DEFINER=CURRENT_USER SQL SECURITY DEFINER VIEW executor_evidence_subject_catalog AS
SELECT DISTINCT subject.connection_id, subject.cluster_id, subject.target_id,
       subject.node_id, subject.storage_id, subject.storage_name, subject.guest_id,
       COALESCE(subject.guest_id, UNHEX('00000000000000000000000000000000')) AS guest_key,
       subject.vmid
FROM (
    SELECT target.connection_id, target.cluster_id, target.id AS target_id,
           allowed.node_id, target.storage_id, storage.storage_name,
           NULL AS guest_id, NULL AS vmid
    FROM backup_targets target
    INNER JOIN backup_target_allowed_nodes allowed ON allowed.target_id = target.id
    INNER JOIN pve_nodes node
            ON node.connection_id = target.connection_id
           AND node.cluster_id = target.cluster_id AND node.id = allowed.node_id
    INNER JOIN pve_storages storage
            ON storage.connection_id = target.connection_id
           AND storage.cluster_id = target.cluster_id AND storage.id = target.storage_id

    UNION

    SELECT policy.connection_id, policy.cluster_id, target.id, placement.node_id,
           target.storage_id, storage.storage_name, guest.id, guest.vmid
    FROM backup_policies policy
    INNER JOIN backup_targets target
            ON target.connection_id = policy.connection_id
           AND target.cluster_id = policy.cluster_id AND target.id = policy.target_id
    INNER JOIN pve_storages storage
            ON storage.connection_id = target.connection_id
           AND storage.cluster_id = target.cluster_id AND storage.id = target.storage_id
    INNER JOIN guests guest
            ON guest.connection_id = policy.connection_id AND guest.cluster_id = policy.cluster_id
    INNER JOIN guest_placements placement ON placement.guest_id = guest.id
    INNER JOIN backup_target_allowed_nodes allowed
            ON allowed.target_id = target.id AND allowed.node_id = placement.node_id
    WHERE policy.status = 'enabled' AND target.status = 'enabled'
      AND EXISTS (
          SELECT 1 FROM backup_policy_assignments assignment
          WHERE assignment.policy_id = policy.id AND assignment.status = 'active'
            AND assignment.selection_value = 'include'
            AND (assignment.scope = 'global'
              OR (assignment.scope = 'connection' AND assignment.subject_connection_id = guest.connection_id)
              OR (assignment.scope = 'cluster' AND assignment.subject_cluster_id = guest.cluster_id)
              OR (assignment.scope = 'node' AND assignment.node_id = placement.node_id)
              OR (assignment.scope = 'guest' AND assignment.guest_id = guest.id))
      )
      AND NOT EXISTS (
          SELECT 1 FROM backup_policy_assignments assignment
          WHERE assignment.policy_id = policy.id AND assignment.status = 'active'
            AND assignment.selection_value = 'exclude'
            AND (assignment.scope = 'global'
              OR (assignment.scope = 'connection' AND assignment.subject_connection_id = guest.connection_id)
              OR (assignment.scope = 'cluster' AND assignment.subject_cluster_id = guest.cluster_id)
              OR (assignment.scope = 'node' AND assignment.node_id = placement.node_id)
              OR (assignment.scope = 'guest' AND assignment.guest_id = guest.id))
      )

    UNION

    SELECT request.connection_id, request.cluster_id, target.id, request.node_id,
           target.storage_id, storage.storage_name, guest.id, guest.vmid
    FROM backup_requests request
    INNER JOIN backup_targets target
            ON target.connection_id = request.connection_id
           AND target.cluster_id = request.cluster_id AND target.id = request.target_id
    INNER JOIN pve_storages storage
            ON storage.connection_id = target.connection_id
           AND storage.cluster_id = target.cluster_id AND storage.id = target.storage_id
    INNER JOIN guests guest
            ON guest.connection_id = request.connection_id
           AND guest.cluster_id = request.cluster_id AND guest.id = request.guest_id
    WHERE request.state IN ('pending', 'retry_wait', 'leased', 'starting', 'running', 'reconcile_required')
) subject
SQL);

        $this->addSql(<<<'SQL'
CREATE DEFINER=CURRENT_USER SQL SECURITY DEFINER VIEW backup_request_client_configurations AS
SELECT request.id AS request_id,
       endpoint.host, endpoint.port, endpoint.tls_mode, endpoint.custom_ca_pem,
       endpoint.sha256_fingerprint, credential.id AS credential_id,
       credential.principal, credential.token_name, credential.secret_envelope,
       capability.version_major, capability.version_minor, capability.version_patch,
       capability.release_name, capability.raw_version,
       endpoint.priority, endpoint.id AS endpoint_id
FROM backup_requests request
INNER JOIN proxmox_connections connection
        ON connection.id = request.connection_id
       AND connection.enabled = 1 AND connection.product = 'pve'
INNER JOIN proxmox_connection_endpoints endpoint
        ON endpoint.connection_id = connection.id AND endpoint.enabled = 1
INNER JOIN proxmox_credentials credential
        ON credential.connection_id = connection.id
       AND credential.purpose = 'backup' AND credential.auth_scheme = 'api_token'
INNER JOIN proxmox_capability_snapshots capability
        ON capability.id = (
            SELECT latest.id FROM proxmox_capability_snapshots latest
            WHERE latest.connection_id = connection.id AND latest.product = 'pve'
            ORDER BY latest.last_observed_at DESC, latest.id DESC LIMIT 1
        )
SQL);

        $this->legacyRawEvidencePrivileges('REVOKE');
        $this->privileges('GRANT');
    }

    public function down(Schema $schema): void
    {
        $this->privileges('REVOKE');
        $this->legacyRawEvidencePrivileges('GRANT');
        foreach ([
            'backup_request_client_configurations',
            'executor_evidence_subject_catalog',
            'executor_evidence_endpoint_catalog',
            'current_executor_permission_evidence',
            'executor_evidence_claim_catalog',
            'executor_scan_credentials',
        ] as $view) {
            $this->addSql('DROP VIEW '.$view);
        }
        $this->addSql('DROP PROCEDURE delete_executor_evidence_publish_rows');
        $this->addSql('DROP TABLE executor_evidence_refresh_projection_stage');
        $this->addSql('DROP TABLE executor_evidence_refresh_subject_stage');
        $this->addSql('DROP TABLE executor_evidence_refresh_state');
        $this->addSql(<<<'SQL'
ALTER TABLE executor_permission_evidence
    DROP FOREIGN KEY fk_executor_evidence_endpoint,
    DROP CONSTRAINT chk_executor_evidence_binding,
    DROP evidence_set_revision,
    DROP endpoint_id,
    DROP connection_revision,
    DROP backup_credential_revision,
    DROP scan_credential_revision
SQL);
    }

    private function privileges(string $operation): void
    {
        $database = $this->connection->getDatabase();
        if (!is_string($database) || 1 !== preg_match('/^[A-Za-z0-9_]+$/D', $database)
            || !in_array($operation, ['GRANT', 'REVOKE'], true)) {
            throw new RuntimeException('Invalid executor evidence grant database.');
        }
        $direction = 'GRANT' === $operation ? 'TO' : 'FROM';
        $worker = "'hoddmimir_backup_worker'@'%'";

        foreach (['executor_evidence_refresh_state'] as $table) {
            $this->addSql(sprintf('%s SELECT, INSERT, UPDATE ON `%s`.`%s` %s %s', $operation, $database, $table, $direction, $worker));
        }
        $this->addSql(sprintf(
            '%s SELECT, INSERT, DELETE ON `%s`.`executor_evidence_refresh_subject_stage` %s %s',
            $operation,
            $database,
            $direction,
            $worker,
        ));
        $this->addSql(sprintf(
            '%s SELECT, INSERT, UPDATE, DELETE ON `%s`.`executor_evidence_refresh_projection_stage` %s %s',
            $operation,
            $database,
            $direction,
            $worker,
        ));
        $evidenceInsertColumns = 'id, connection_id, cluster_id, target_id, node_id, storage_id, guest_id, evidence_set_revision, endpoint_id, connection_revision, backup_credential_revision, scan_credential_revision, vm_backup_authorized, datastore_allocate_authorized, authorized, observed_at, revision';
        $this->addSql(sprintf(
            '%s INSERT (%s) ON `%s`.`executor_permission_evidence` %s %s',
            $operation,
            $evidenceInsertColumns,
            $database,
            $direction,
            $worker,
        ));
        $this->addSql(sprintf(
            '%s EXECUTE ON PROCEDURE `%s`.`delete_executor_evidence_publish_rows` %s %s',
            $operation,
            $database,
            $direction,
            $worker,
        ));
        foreach ([
            'executor_scan_credentials', 'executor_evidence_claim_catalog',
            'executor_evidence_endpoint_catalog', 'executor_evidence_subject_catalog',
            'backup_request_client_configurations',
        ] as $view) {
            $this->addSql(sprintf('%s SELECT ON `%s`.`%s` %s %s', $operation, $database, $view, $direction, $worker));
        }
        foreach (['hoddmimir_backup_worker', 'hoddmimir_collector', 'hoddmimir_web'] as $user) {
            $this->addSql(sprintf(
                "%s SELECT ON `%s`.`current_executor_permission_evidence` %s '%s'@'%%'",
                $operation,
                $database,
                $direction,
                $user,
            ));
        }

    }

    private function legacyRawEvidencePrivileges(string $operation): void
    {
        $database = $this->connection->getDatabase();
        if (!is_string($database) || 1 !== preg_match('/^[A-Za-z0-9_]+$/D', $database)
            || !in_array($operation, ['GRANT', 'REVOKE'], true)) {
            throw new RuntimeException('Invalid executor evidence legacy privilege database.');
        }
        $direction = 'GRANT' === $operation ? 'TO' : 'FROM';
        $this->addSql(sprintf(
            "%s SELECT, INSERT, UPDATE ON `%s`.`executor_permission_evidence` %s 'hoddmimir_backup_worker'@'%%'",
            $operation,
            $database,
            $direction,
        ));
        $this->addSql(sprintf(
            "%s SELECT ON `%s`.`executor_permission_evidence` %s 'hoddmimir_web'@'%%'",
            $operation,
            $database,
            $direction,
        ));
    }
}
