<?php

declare(strict_types=1);

namespace App\Infrastructure\Readiness;

use App\Application\Readiness\ReadinessCheck;
use App\Application\Readiness\ReadinessCheckResult;
use Doctrine\DBAL\Connection;
use Throwable;

final readonly class DatabaseSchemaReadinessCheck implements ReadinessCheck
{
    public const array EXPECTED_MIGRATIONS = [
        'DoctrineMigrations\\Version20260710000100',
    ];

    public function __construct(private Connection $connection)
    {
    }

    public function name(): string
    {
        return 'database_schema';
    }

    public function check(): ReadinessCheckResult
    {
        try {
            $metadataTableExists = $this->connection->fetchOne(
                <<<'SQL'
                    SELECT COUNT(*)
                    FROM information_schema.TABLES
                    WHERE TABLE_SCHEMA = DATABASE()
                      AND TABLE_NAME = 'doctrine_migration_versions'
                    SQL,
            );

            if (1 !== $metadataTableExists && '1' !== $metadataTableExists) {
                return ReadinessCheckResult::unavailable($this->name(), 'migration_metadata_missing');
            }

            $rawExecutedVersions = $this->connection->fetchFirstColumn(
                'SELECT version FROM doctrine_migration_versions ORDER BY version',
            );
            $executedVersions = [];
            foreach ($rawExecutedVersions as $version) {
                if (!is_string($version)) {
                    return ReadinessCheckResult::unavailable($this->name(), 'database_unavailable');
                }
                $executedVersions[] = $version;
            }
            if ([] !== array_diff(self::EXPECTED_MIGRATIONS, $executedVersions)) {
                return ReadinessCheckResult::unavailable($this->name(), 'migration_version_mismatch');
            }

            return ReadinessCheckResult::ready($this->name());
        } catch (Throwable) {
            return ReadinessCheckResult::unavailable($this->name(), 'database_unavailable');
        }
    }
}
