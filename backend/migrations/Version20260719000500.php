<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
use RuntimeException;

final class Version20260719000500 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Allow runless pre-POST Matrix problems with stable obligation and occurrence identity.';
    }

    public function isTransactional(): bool
    {
        return false;
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE backup_problem_states DROP FOREIGN KEY fk_backup_problem_state_root');
        $this->addSql('ALTER TABLE backup_problem_states DROP PRIMARY KEY, ADD obligation_id BINARY(16) NULL FIRST');
        $this->addSql(<<<'SQL'
UPDATE backup_problem_states problem
JOIN backup_requests request ON request.id = problem.root_request_id
SET problem.obligation_id = UNHEX(LEFT(SHA2(CONCAT(
    CAST('backup-obligation' AS BINARY), 0x00,
    request.connection_id, request.cluster_id, request.guest_id, request.policy_id, request.target_id
), 256), 32))
SQL);
        $this->addSql(<<<'SQL'
ALTER TABLE backup_problem_states
    MODIFY obligation_id BINARY(16) NOT NULL,
    MODIFY root_request_id BINARY(16) NULL,
    ADD PRIMARY KEY (obligation_id),
    ADD CONSTRAINT uq_backup_problem_state_root UNIQUE (root_request_id),
    ADD CONSTRAINT fk_backup_problem_state_root FOREIGN KEY (root_request_id)
        REFERENCES backup_requests (id) ON DELETE SET NULL
SQL);

        $this->addSql('ALTER TABLE backup_notification_outbox DROP FOREIGN KEY fk_backup_notification_root');
        $this->addSql('ALTER TABLE backup_notification_outbox DROP FOREIGN KEY fk_backup_notification_request');
        $this->addSql('ALTER TABLE backup_notification_outbox DROP FOREIGN KEY fk_backup_notification_run');
        $this->addSql('ALTER TABLE backup_notification_outbox DROP INDEX uq_backup_notification_run_event');
        $this->addSql('ALTER TABLE backup_notification_outbox ADD obligation_id BINARY(16) NULL AFTER id, ADD occurrence_id BINARY(16) NULL AFTER obligation_id, ADD check_number INT UNSIGNED NULL AFTER attempt');
        $this->addSql(<<<'SQL'
UPDATE backup_notification_outbox notification
JOIN backup_requests request ON request.id = notification.root_request_id
SET notification.obligation_id = UNHEX(LEFT(SHA2(CONCAT(
        CAST('backup-obligation' AS BINARY), 0x00,
        request.connection_id, request.cluster_id, request.guest_id, request.policy_id, request.target_id
    ), 256), 32)),
    notification.occurrence_id = notification.run_id,
    notification.check_number = notification.attempt
SQL);
        $this->addSql('ALTER TABLE backup_notification_outbox DROP CONSTRAINT chk_backup_notification_attempt');
        $this->addSql(<<<'SQL'
ALTER TABLE backup_notification_outbox
    MODIFY obligation_id BINARY(16) NOT NULL,
    MODIFY occurrence_id BINARY(16) NOT NULL,
    MODIFY root_request_id BINARY(16) NULL,
    MODIFY request_id BINARY(16) NULL,
    MODIFY run_id BINARY(16) NULL,
    MODIFY attempt INT UNSIGNED NULL,
    MODIFY check_number INT UNSIGNED NOT NULL,
    ADD CONSTRAINT uq_backup_notification_occurrence UNIQUE (occurrence_id, event_key),
    ADD INDEX idx_backup_notification_obligation (obligation_id, created_at),
    ADD CONSTRAINT fk_backup_notification_root FOREIGN KEY (root_request_id)
        REFERENCES backup_requests (id) ON DELETE CASCADE,
    ADD CONSTRAINT fk_backup_notification_request FOREIGN KEY (request_id)
        REFERENCES backup_requests (id) ON DELETE CASCADE,
    ADD CONSTRAINT fk_backup_notification_run FOREIGN KEY (run_id)
        REFERENCES backup_runs (id) ON DELETE CASCADE,
    ADD CONSTRAINT chk_backup_notification_attempt CHECK (
        (attempt IS NULL OR attempt > 0) AND check_number > 0 AND revision > 0
    )
SQL);
        $this->grantCollector('GRANT');
    }

    public function down(Schema $schema): void
    {
        $this->grantCollector('REVOKE');
        $this->addSql('DELETE FROM backup_notification_outbox WHERE root_request_id IS NULL OR request_id IS NULL OR run_id IS NULL');
        $this->addSql('DELETE FROM backup_problem_states WHERE root_request_id IS NULL');
        $this->addSql('ALTER TABLE backup_notification_outbox DROP FOREIGN KEY fk_backup_notification_root');
        $this->addSql('ALTER TABLE backup_notification_outbox DROP FOREIGN KEY fk_backup_notification_request');
        $this->addSql('ALTER TABLE backup_notification_outbox DROP FOREIGN KEY fk_backup_notification_run');
        $this->addSql('ALTER TABLE backup_notification_outbox DROP CONSTRAINT chk_backup_notification_attempt');
        $this->addSql('ALTER TABLE backup_notification_outbox DROP INDEX uq_backup_notification_occurrence, DROP INDEX idx_backup_notification_obligation');
        $this->addSql(<<<'SQL'
ALTER TABLE backup_notification_outbox
    MODIFY root_request_id BINARY(16) NOT NULL,
    MODIFY request_id BINARY(16) NOT NULL,
    MODIFY run_id BINARY(16) NOT NULL,
    MODIFY attempt INT UNSIGNED NOT NULL,
    DROP COLUMN check_number,
    DROP COLUMN occurrence_id,
    DROP COLUMN obligation_id,
    ADD CONSTRAINT uq_backup_notification_run_event UNIQUE (run_id, event_key),
    ADD CONSTRAINT fk_backup_notification_root FOREIGN KEY (root_request_id)
        REFERENCES backup_requests (id) ON DELETE CASCADE,
    ADD CONSTRAINT fk_backup_notification_request FOREIGN KEY (request_id)
        REFERENCES backup_requests (id) ON DELETE CASCADE,
    ADD CONSTRAINT fk_backup_notification_run FOREIGN KEY (run_id)
        REFERENCES backup_runs (id) ON DELETE CASCADE,
    ADD CONSTRAINT chk_backup_notification_attempt CHECK (attempt > 0 AND revision > 0)
SQL);
        $this->addSql('ALTER TABLE backup_problem_states DROP FOREIGN KEY fk_backup_problem_state_root');
        $this->addSql('ALTER TABLE backup_problem_states DROP INDEX uq_backup_problem_state_root, DROP PRIMARY KEY');
        $this->addSql(<<<'SQL'
ALTER TABLE backup_problem_states
    MODIFY root_request_id BINARY(16) NOT NULL,
    DROP COLUMN obligation_id,
    ADD PRIMARY KEY (root_request_id),
    ADD CONSTRAINT fk_backup_problem_state_root FOREIGN KEY (root_request_id)
        REFERENCES backup_requests (id) ON DELETE CASCADE
SQL);
    }

    private function grantCollector(string $operation): void
    {
        $database = $this->connection->getDatabase();
        if (!is_string($database) || 1 !== preg_match('/^[A-Za-z0-9_]+$/D', $database)
            || !in_array($operation, ['GRANT', 'REVOKE'], true)) {
            throw new RuntimeException('Invalid pre-POST notification grant database.');
        }
        $direction = 'GRANT' === $operation ? 'TO' : 'FROM';
        $this->addSql(sprintf(
            "%s SELECT, INSERT, UPDATE, DELETE ON `%s`.`backup_problem_states` %s 'hoddmimir_collector'@'%%'",
            $operation,
            $database,
            $direction,
        ));
        $this->addSql(sprintf(
            "%s SELECT, INSERT ON `%s`.`backup_notification_outbox` %s 'hoddmimir_collector'@'%%'",
            $operation,
            $database,
            $direction,
        ));
    }
}
