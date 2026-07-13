<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260712002000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add atomic verified Proxmox onboarding, secret-safe replay, and automatic inventory evidence.';
    }

    public function isTransactional(): bool
    {
        return false;
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
ALTER TABLE proxmox_credentials
    ADD secret_verification_hash VARCHAR(255) CHARACTER SET ascii COLLATE ascii_bin NULL AFTER secret_envelope,
    ADD CONSTRAINT chk_proxmox_credentials_verification_hash CHECK (
        secret_verification_hash IS NULL OR secret_verification_hash LIKE '$argon2id$%'
    )
SQL);

        $this->addSql(<<<'SQL'
CREATE TABLE proxmox_connection_onboarding_state (
    connection_id BINARY(16) NOT NULL,
    state VARCHAR(48) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    tls_verified TINYINT(1) NOT NULL,
    product_supported TINYINT(1) NOT NULL,
    scan_permissions_verified TINYINT(1) NOT NULL,
    backup_permissions_verified TINYINT(1) NULL,
    detected_product VARCHAR(8) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    detected_version VARCHAR(255) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    warnings_json LONGTEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
    verified_at DATETIME(6) NOT NULL,
    inventory_status_changed_at DATETIME(6) NOT NULL,
    last_inventory_run_id BINARY(16) NULL,
    PRIMARY KEY (connection_id),
    INDEX idx_onboarding_state (state, inventory_status_changed_at),
    CONSTRAINT fk_onboarding_state_connection FOREIGN KEY (connection_id)
        REFERENCES proxmox_connections (id) ON DELETE CASCADE,
    CONSTRAINT chk_onboarding_state_state CHECK (state IN (
        'first_automatic_scan_pending', 'inventory_verified', 'inventory_partial', 'inventory_failed'
    )),
    CONSTRAINT chk_onboarding_state_checks CHECK (
        tls_verified = 1 AND product_supported = 1 AND scan_permissions_verified = 1
        AND (backup_permissions_verified IS NULL OR backup_permissions_verified = 1)
        AND detected_product IN ('pve', 'pbs')
        AND ((detected_product = 'pve' AND backup_permissions_verified = 1)
            OR (detected_product = 'pbs' AND backup_permissions_verified IS NULL))
    ),
    CONSTRAINT chk_onboarding_state_warnings CHECK (
        JSON_VALID(warnings_json) AND JSON_TYPE(warnings_json) = 'ARRAY'
    )
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

        $this->addSql(<<<'SQL'
CREATE TABLE proxmox_onboarding_commands (
    actor_user_id BINARY(16) NOT NULL,
    idempotency_key VARCHAR(128) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    connection_id BINARY(16) NOT NULL,
    mode VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    payload_hash BINARY(32) NOT NULL,
    secret_replay_hash VARCHAR(255) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    result_status VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    result_revision INT UNSIGNED NULL,
    verification_json LONGTEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NULL,
    created_at DATETIME(6) NOT NULL,
    PRIMARY KEY (actor_user_id, idempotency_key),
    INDEX idx_onboarding_commands_connection (connection_id, created_at),
    CONSTRAINT fk_onboarding_commands_actor FOREIGN KEY (actor_user_id)
        REFERENCES users (id) ON DELETE RESTRICT,
    CONSTRAINT chk_onboarding_commands_key CHECK (
        idempotency_key REGEXP '^[A-Za-z0-9][A-Za-z0-9._:-]{0,127}$'
    ),
    CONSTRAINT chk_onboarding_commands_mode CHECK (mode IN ('activate', 'rotate', 'endpoint_add', 'endpoint_update')),
    CONSTRAINT chk_onboarding_commands_secret CHECK (secret_replay_hash LIKE '$argon2id$%'),
    CONSTRAINT chk_onboarding_commands_status CHECK (
        (result_status IN ('applied', 'replayed') AND result_revision IS NOT NULL AND verification_json IS NOT NULL)
        OR (result_status = 'conflict' AND result_revision IS NOT NULL AND verification_json IS NULL)
        OR (result_status IN ('rejected', 'denied') AND result_revision IS NULL AND verification_json IS NOT NULL)
    ),
    CONSTRAINT chk_onboarding_commands_verification CHECK (
        verification_json IS NULL
        OR (JSON_VALID(verification_json) AND JSON_TYPE(verification_json) = 'OBJECT')
    )
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

        $this->addSql(<<<'SQL'
CREATE TABLE proxmox_endpoint_onboarding_evidence (
    endpoint_id BINARY(16) NOT NULL,
    connection_id BINARY(16) NOT NULL,
    endpoint_config_hash BINARY(32) NOT NULL,
    detected_product VARCHAR(8) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    detected_version VARCHAR(255) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    scan_permissions_verified TINYINT(1) NOT NULL,
    backup_permissions_verified TINYINT(1) NULL,
    warnings_json LONGTEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
    verified_at DATETIME(6) NOT NULL,
    PRIMARY KEY (endpoint_id),
    CONSTRAINT uq_endpoint_onboarding_connection UNIQUE (connection_id, endpoint_id),
    INDEX idx_endpoint_onboarding_verified (connection_id, verified_at),
    CONSTRAINT fk_endpoint_onboarding_endpoint FOREIGN KEY (connection_id, endpoint_id)
        REFERENCES proxmox_connection_endpoints (connection_id, id) ON DELETE CASCADE,
    CONSTRAINT chk_endpoint_onboarding_checks CHECK (
        scan_permissions_verified = 1
        AND detected_product IN ('pve', 'pbs')
        AND ((detected_product = 'pve' AND backup_permissions_verified = 1)
            OR (detected_product = 'pbs' AND backup_permissions_verified IS NULL))
    ),
    CONSTRAINT chk_endpoint_onboarding_warnings CHECK (
        JSON_VALID(warnings_json) AND JSON_TYPE(warnings_json) = 'ARRAY'
    )
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

        $this->privileges('GRANT');
    }

    public function down(Schema $schema): void
    {
        $this->privileges('REVOKE');
        $this->addSql('DROP TABLE proxmox_endpoint_onboarding_evidence');
        $this->addSql('DROP TABLE proxmox_onboarding_commands');
        $this->addSql('DROP TABLE proxmox_connection_onboarding_state');
        $this->addSql('ALTER TABLE proxmox_credentials DROP CONSTRAINT chk_proxmox_credentials_verification_hash, DROP COLUMN secret_verification_hash');
    }

    private function privileges(string $operation): void
    {
        $database = $this->connection->getDatabase();
        if (!is_string($database) || 1 !== preg_match('/^[A-Za-z0-9_]+$/D', $database)
            || !in_array($operation, ['GRANT', 'REVOKE'], true)) {
            throw new \RuntimeException('An onboarding grant identifier is invalid.');
        }
        $direction = 'GRANT' === $operation ? 'TO' : 'FROM';
        $this->addSql(sprintf(
            "%s SELECT, INSERT ON `%s`.`proxmox_onboarding_commands` %s 'hoddmimir_web'@'%%'",
            $operation,
            $database,
            $direction,
        ));
        $this->addSql(sprintf(
            "%s SELECT, INSERT, UPDATE ON `%s`.`proxmox_connection_onboarding_state` %s 'hoddmimir_web'@'%%'",
            $operation,
            $database,
            $direction,
        ));
        $this->addSql(sprintf(
            "%s SELECT, INSERT, UPDATE ON `%s`.`proxmox_endpoint_onboarding_evidence` %s 'hoddmimir_web'@'%%'",
            $operation,
            $database,
            $direction,
        ));
        // The verified transaction's connection identity and endpoint address
        // reads are already covered by Version20260712000200 and
        // Version20260712000500. Do not re-grant those columns here:
        // overlapping column grants cannot be rolled back independently by
        // MariaDB.
        $this->addSql(sprintf(
            "%s SELECT (secret_verification_hash), UPDATE (secret_verification_hash) ON `%s`.`proxmox_credentials` %s 'hoddmimir_web'@'%%'",
            $operation,
            $database,
            $direction,
        ));
        $this->addSql(sprintf(
            "%s UPDATE (last_attempted_at, last_success_at, last_error_code) ON `%s`.`proxmox_connection_endpoints` %s 'hoddmimir_web'@'%%'",
            $operation,
            $database,
            $direction,
        ));
        $this->addSql(sprintf(
            "%s SELECT, UPDATE (state, inventory_status_changed_at, last_inventory_run_id) ON `%s`.`proxmox_connection_onboarding_state` %s 'hoddmimir_collector'@'%%'",
            $operation,
            $database,
            $direction,
        ));
    }
}
