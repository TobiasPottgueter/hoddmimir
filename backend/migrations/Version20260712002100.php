<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
use RuntimeException;

final class Version20260712002100 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Bound public login work and storage with IP/global throttles and retained failure audit data.';
    }

    public function isTransactional(): bool
    {
        return false;
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
CREATE TABLE login_ip_attempts (
    ip_address VARBINARY(16) NOT NULL,
    window_started_at DATETIME(6) NOT NULL,
    failure_count TINYINT UNSIGNED NOT NULL,
    last_failed_at DATETIME(6) NOT NULL,
    locked_until DATETIME(6) NULL,
    updated_at DATETIME(6) NOT NULL,
    PRIMARY KEY (ip_address),
    INDEX idx_login_ip_attempts_cleanup (updated_at),
    CONSTRAINT chk_login_ip_attempts_ip CHECK (OCTET_LENGTH(ip_address) IN (4, 16)),
    CONSTRAINT chk_login_ip_attempts_count CHECK (failure_count BETWEEN 1 AND 5),
    CONSTRAINT chk_login_ip_attempts_window CHECK (
        window_started_at <= last_failed_at
        AND last_failed_at < window_started_at + INTERVAL 15 MINUTE
        AND updated_at >= last_failed_at
    ),
    CONSTRAINT chk_login_ip_attempts_lock CHECK (
        (failure_count < 5 AND locked_until IS NULL)
        OR (failure_count = 5 AND locked_until = last_failed_at + INTERVAL 15 MINUTE)
    )
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);
        $this->addSql(<<<'SQL'
CREATE TABLE login_global_throttle (
    singleton_id TINYINT UNSIGNED NOT NULL,
    window_started_at DATETIME(6) NOT NULL,
    failure_count SMALLINT UNSIGNED NOT NULL,
    last_failed_at DATETIME(6) NOT NULL,
    locked_until DATETIME(6) NULL,
    updated_at DATETIME(6) NOT NULL,
    PRIMARY KEY (singleton_id),
    CONSTRAINT chk_login_global_singleton CHECK (singleton_id = 1),
    CONSTRAINT chk_login_global_count CHECK (failure_count BETWEEN 0 AND 1000),
    CONSTRAINT chk_login_global_window CHECK (
        window_started_at <= last_failed_at
        AND last_failed_at < window_started_at + INTERVAL 15 MINUTE
        AND updated_at >= last_failed_at
    ),
    CONSTRAINT chk_login_global_lock CHECK (
        (failure_count < 1000 AND locked_until IS NULL)
        OR (failure_count = 1000 AND locked_until = last_failed_at + INTERVAL 15 MINUTE)
    )
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);
        $this->addSql("INSERT INTO login_global_throttle (singleton_id, window_started_at, failure_count, last_failed_at, locked_until, updated_at) VALUES (1, UTC_TIMESTAMP(6), 0, UTC_TIMESTAMP(6), NULL, UTC_TIMESTAMP(6))");
        $this->addSql(<<<'PROCEDURE_SQL'
CREATE PROCEDURE prune_login_security_state(IN attempts_before DATETIME(6), IN audit_before DATETIME(6))
SQL SECURITY DEFINER
MODIFIES SQL DATA
BEGIN
    DELETE FROM login_attempts WHERE updated_at < attempts_before LIMIT 1000;
    DELETE FROM login_ip_attempts WHERE updated_at < attempts_before LIMIT 1000;
    DELETE FROM audit_events
     WHERE actor_user_id IS NULL AND event_type = 'login_failed' AND occurred_at < audit_before
     LIMIT 1000;
END
PROCEDURE_SQL);
        $this->privileges('GRANT');
    }

    public function down(Schema $schema): void
    {
        $this->privileges('REVOKE');
        $this->addSql('DROP PROCEDURE prune_login_security_state');
        $this->addSql('DROP TABLE login_global_throttle');
        $this->addSql('DROP TABLE login_ip_attempts');
    }

    private function privileges(string $operation): void
    {
        $database = $this->connection->getDatabase();
        if (!is_string($database) || 1 !== preg_match('/^[A-Za-z0-9_]+$/D', $database)
            || !in_array($operation, ['GRANT', 'REVOKE'], true)) {
            throw new RuntimeException('Invalid login throttle grant identifier.');
        }
        $to = 'GRANT' === $operation ? 'TO' : 'FROM';
        $this->addSql(sprintf("%s SELECT, INSERT, UPDATE, DELETE ON `%s`.`login_ip_attempts` %s 'hoddmimir_web'@'%%'", $operation, $database, $to));
        $this->addSql(sprintf("%s SELECT, UPDATE ON `%s`.`login_global_throttle` %s 'hoddmimir_web'@'%%'", $operation, $database, $to));
        $this->addSql(sprintf("%s EXECUTE ON PROCEDURE `%s`.`prune_login_security_state` %s 'hoddmimir_web'@'%%'", $operation, $database, $to));
    }
}
