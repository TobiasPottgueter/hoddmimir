<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
use RuntimeException;

final class Version20260908000200 extends AbstractMigration
{
    public function getDescription(): string { return 'Add explicit backup target defaults with policy inheritance.'; }
    public function isTransactional(): bool { return false; }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
ALTER TABLE backup_targets
    ADD COLUMN default_backup_mode VARCHAR(16) NULL,
    ADD COLUMN default_compression VARCHAR(8) NULL,
    ADD COLUMN default_legacy_maxfiles INT UNSIGNED NULL,
    ADD COLUMN default_keep_all TINYINT(1) NULL,
    ADD COLUMN default_keep_last INT UNSIGNED NULL,
    ADD COLUMN default_keep_hourly INT UNSIGNED NULL,
    ADD COLUMN default_keep_daily INT UNSIGNED NULL,
    ADD COLUMN default_keep_weekly INT UNSIGNED NULL,
    ADD COLUMN default_keep_monthly INT UNSIGNED NULL,
    ADD COLUMN default_keep_yearly INT UNSIGNED NULL,
    ADD CONSTRAINT chk_target_defaults_mode CHECK (default_backup_mode IS NULL OR default_backup_mode IN ('snapshot', 'suspend', 'stop')),
    ADD CONSTRAINT chk_target_defaults_compression CHECK (default_compression IS NULL OR default_compression IN ('0', 'gzip', 'lzo', 'zstd')),
    ADD CONSTRAINT chk_target_defaults_retention CHECK (
        (default_legacy_maxfiles IS NULL OR default_legacy_maxfiles BETWEEN 1 AND 1000000)
        AND (default_keep_all IS NULL OR default_keep_all IN (0, 1))
        AND (default_keep_last IS NULL OR default_keep_last BETWEEN 1 AND 1000000)
        AND (default_keep_hourly IS NULL OR default_keep_hourly BETWEEN 1 AND 1000000)
        AND (default_keep_daily IS NULL OR default_keep_daily BETWEEN 1 AND 1000000)
        AND (default_keep_weekly IS NULL OR default_keep_weekly BETWEEN 1 AND 1000000)
        AND (default_keep_monthly IS NULL OR default_keep_monthly BETWEEN 1 AND 1000000)
        AND (default_keep_yearly IS NULL OR default_keep_yearly BETWEEN 1 AND 1000000)
        AND (default_legacy_maxfiles IS NULL OR (default_keep_all IS NULL AND default_keep_last IS NULL AND default_keep_hourly IS NULL
            AND default_keep_daily IS NULL AND default_keep_weekly IS NULL AND default_keep_monthly IS NULL AND default_keep_yearly IS NULL))
        AND (default_keep_all <> 1 OR (default_keep_last IS NULL AND default_keep_hourly IS NULL AND default_keep_daily IS NULL
            AND default_keep_weekly IS NULL AND default_keep_monthly IS NULL AND default_keep_yearly IS NULL))
        AND (default_keep_all <> 0 OR default_keep_last IS NOT NULL OR default_keep_hourly IS NOT NULL OR default_keep_daily IS NOT NULL
            OR default_keep_weekly IS NOT NULL OR default_keep_monthly IS NOT NULL OR default_keep_yearly IS NOT NULL)

    )
SQL);
        // Cross-table completeness is checked while locking the policy and its target.
        $this->addSql(<<<'SQL'
ALTER TABLE backup_policies
    DROP CONSTRAINT chk_backup_policies_activation,
    DROP CONSTRAINT chk_backup_policies_retention_effect,
    ADD CONSTRAINT chk_backup_policies_activation CHECK (
        status <> 'enabled' OR (target_id IS NOT NULL AND policy_priority IS NOT NULL
            AND schedule = 'collector_cycle'
            AND (maximum_age_seconds IS NOT NULL OR bytes_written_threshold IS NOT NULL))
    ),
    ADD CONSTRAINT chk_backup_policies_retention_effect CHECK (retention_execution_enabled IN (0, 1))
SQL);
        $database = $this->connection->getDatabase();
        if (!is_string($database) || 1 !== preg_match('/^[A-Za-z0-9_]+$/D', $database)) throw new RuntimeException('Invalid target defaults database.');
        $this->addSql(sprintf("GRANT UPDATE (default_backup_mode, default_compression, default_legacy_maxfiles, default_keep_all, default_keep_last, default_keep_hourly, default_keep_daily, default_keep_weekly, default_keep_monthly, default_keep_yearly) ON `%s`.`backup_targets` TO 'hoddmimir_web'@'%%'", $database));
    }

    public function down(Schema $schema): void
    {
        if (0 !== (int) $this->connection->fetchOne('SELECT COUNT(*) FROM backup_policies')) {
            $this->throwIrreversibleMigrationException('Restore the pre-upgrade database under maintenance; configured policies cannot be downgraded in place.');
        }
        $this->addSql(<<<'SQL'
ALTER TABLE backup_policies DROP CONSTRAINT chk_backup_policies_activation,
    DROP CONSTRAINT chk_backup_policies_retention_effect,
    ADD CONSTRAINT chk_backup_policies_activation CHECK (
        status <> 'enabled' OR (
            target_id IS NOT NULL AND policy_priority IS NOT NULL AND backup_mode IS NOT NULL
            AND compression IS NOT NULL AND schedule = 'collector_cycle'
            AND (maximum_age_seconds IS NOT NULL OR bytes_written_threshold IS NOT NULL)
            AND (legacy_maxfiles IS NOT NULL OR keep_all IS NOT NULL OR keep_last IS NOT NULL
                OR keep_hourly IS NOT NULL OR keep_daily IS NOT NULL OR keep_weekly IS NOT NULL
                OR keep_monthly IS NOT NULL OR keep_yearly IS NOT NULL)
        )
    ),
    ADD CONSTRAINT chk_backup_policies_retention_effect CHECK (
        retention_execution_enabled IN (0, 1)
        AND (retention_execution_enabled = 0 OR legacy_maxfiles IS NOT NULL OR keep_all IS NOT NULL
            OR keep_last IS NOT NULL OR keep_hourly IS NOT NULL OR keep_daily IS NOT NULL
            OR keep_weekly IS NOT NULL OR keep_monthly IS NOT NULL OR keep_yearly IS NOT NULL)
    )
SQL);
        $this->addSql('ALTER TABLE backup_targets DROP CONSTRAINT chk_target_defaults_mode, DROP CONSTRAINT chk_target_defaults_compression, DROP CONSTRAINT chk_target_defaults_retention, DROP COLUMN default_backup_mode, DROP COLUMN default_compression, DROP COLUMN default_legacy_maxfiles, DROP COLUMN default_keep_all, DROP COLUMN default_keep_last, DROP COLUMN default_keep_hourly, DROP COLUMN default_keep_daily, DROP COLUMN default_keep_weekly, DROP COLUMN default_keep_monthly, DROP COLUMN default_keep_yearly');
    }
}
