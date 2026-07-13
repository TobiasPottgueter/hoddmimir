<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\MariaDb;

use App\Domain\Backup\BackupProblemCode;
use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use JsonException;
use RuntimeException;

final readonly class DbalBackupProblemRecorder
{
    /** @param array<string, mixed> $context
     *  @throws JsonException
     */
    public function failure(
        Connection $db,
        array $context,
        string $runId,
        BackupProblemCode $code,
        ?string $detailCode,
        DateTimeImmutable $occurredAt,
        DateTimeImmutable $nextRetryAt,
    ): void {
        $eventKey = BackupProblemCode::SubmissionRejected === $code ? 'submission_rejected' : 'task_failed';
        if (null !== $this->existingOutboxFailures($db, $context, $runId, $eventKey, 'failure', $code, $detailCode, $occurredAt, $nextRetryAt)) {
            return;
        }
        $root = $this->binary($context['root_request_id'] ?? null);
        $current = $db->fetchAssociative('SELECT * FROM backup_problem_states WHERE root_request_id=:root FOR UPDATE', ['root' => $root]);
        $failures = false === $current ? 1 : $this->integer($current['consecutive_failures'] ?? null) + 1;
        $openedAt = false === $current ? $occurredAt : $this->date($current['opened_at'] ?? null);
        $db->executeStatement(<<<'SQL'
INSERT INTO backup_problem_states (root_request_id, problem_code, consecutive_failures, opened_at, last_occurred_at, last_notified_at, revision)
VALUES (:root,:code,:failures,:opened,:occurred,:occurred,1)
ON DUPLICATE KEY UPDATE problem_code=VALUES(problem_code), consecutive_failures=VALUES(consecutive_failures),
 last_occurred_at=VALUES(last_occurred_at), last_notified_at=VALUES(last_notified_at), revision=revision+1
SQL, ['root' => $root, 'code' => $code->value, 'failures' => $failures, 'opened' => self::format($openedAt), 'occurred' => self::format($occurredAt)]);
        $this->outbox($db, $context, $runId, $eventKey, 'failure', $code, $detailCode, $openedAt, $occurredAt, $nextRetryAt, $failures);
    }

    /** @param array<string, mixed> $context
     *  @throws JsonException
     */
    public function recovery(Connection $db, array $context, string $runId, DateTimeImmutable $occurredAt): void
    {
        if (null !== $this->existingOutboxFailures($db, $context, $runId, 'recovery', 'recovery', null, null, $occurredAt, null)) {
            return;
        }
        $root = $this->binary($context['root_request_id'] ?? null);
        $current = $db->fetchAssociative('SELECT * FROM backup_problem_states WHERE root_request_id=:root FOR UPDATE', ['root' => $root]);
        if (false === $current) return;
        $code = BackupProblemCode::from($this->text($current['problem_code'] ?? null));
        $failures = $this->integer($current['consecutive_failures'] ?? null);
        $this->outbox($db, $context, $runId, 'recovery', 'recovery', $code, null, $this->date($current['opened_at'] ?? null), $occurredAt, null, $failures);
        $db->delete('backup_problem_states', ['root_request_id' => $root], ['root_request_id' => ParameterType::BINARY]);
    }

    /** @param array<string, mixed> $context
     *  @throws JsonException
     */
    public function attention(
        Connection $db,
        array $context,
        string $runId,
        BackupProblemCode $code,
        string $detailCode,
        DateTimeImmutable $occurredAt,
    ): void {
        $eventKey = match ($code) {
            BackupProblemCode::MonitoringUnknown => 'monitoring_unknown',
            BackupProblemCode::CancelDispatchUnknown => 'cancel_dispatch_unknown',
            default => 'submission_ambiguous',
        };
        if (null !== $this->existingOutboxFailures($db, $context, $runId, $eventKey, 'attention_required', $code, $detailCode, $occurredAt, null)) {
            return;
        }
        $root = $this->binary($context['root_request_id'] ?? null);
        $current = $db->fetchAssociative('SELECT * FROM backup_problem_states WHERE root_request_id=:root FOR UPDATE', ['root' => $root]);
        $failures = false === $current ? 1 : $this->integer($current['consecutive_failures'] ?? null) + 1;
        $openedAt = false === $current ? $occurredAt : $this->date($current['opened_at'] ?? null);
        $db->executeStatement(<<<'SQL'
INSERT INTO backup_problem_states (root_request_id, problem_code, consecutive_failures, opened_at, last_occurred_at, last_notified_at, revision)
VALUES (:root,:code,:failures,:opened,:occurred,:occurred,1)
ON DUPLICATE KEY UPDATE problem_code=VALUES(problem_code), consecutive_failures=VALUES(consecutive_failures),
 last_occurred_at=VALUES(last_occurred_at), last_notified_at=VALUES(last_notified_at), revision=revision+1
SQL, ['root' => $root, 'code' => $code->value, 'failures' => $failures, 'opened' => self::format($openedAt), 'occurred' => self::format($occurredAt)]);
        $this->outbox($db, $context, $runId, $eventKey, 'attention_required', $code, $detailCode, $openedAt, $occurredAt, null, $failures);
    }

    /** @param array<string,mixed> $context
     *  @throws JsonException
     */
    private function outbox(Connection $db, array $context, string $runId, string $eventKey, string $kind, BackupProblemCode $code, ?string $detail, DateTimeImmutable $openedAt, DateTimeImmutable $at, ?DateTimeImmutable $next, int $failures): void
    {
        $id = substr(hash('sha256', "backup-notification\0".$runId."\0".$eventKey, true), 0, 16);
        $payload = $this->payload($context, $code, $detail, $openedAt, $at, $next, $failures);
        $db->executeStatement(<<<'SQL'
INSERT INTO backup_notification_outbox (
 id, root_request_id, request_id, run_id, event_key, notification_kind, attempt, payload_json,
 state, delivery_attempts, available_at, created_at, revision
) VALUES (:id,:root,:request,:run,:event_key,:kind,:attempt,:payload,'pending',0,:at,:at,1)
SQL, [
            'id' => $id, 'root' => $this->binary($context['root_request_id'] ?? null),
            'request' => $this->binary($context['id'] ?? $context['request_id'] ?? null),
            'run' => $runId, 'event_key' => $eventKey, 'kind' => $kind, 'attempt' => $this->integer($context['attempt'] ?? null),
            'payload' => $payload, 'at' => self::format($at),
        ], ['id' => ParameterType::BINARY, 'root' => ParameterType::BINARY, 'request' => ParameterType::BINARY, 'run' => ParameterType::BINARY]);
    }

    /** @param array<string,mixed> $context
     *  @throws JsonException
     */
    private function payload(array $context, BackupProblemCode $code, ?string $detail, DateTimeImmutable $openedAt, DateTimeImmutable $at, ?DateTimeImmutable $next, int $failures): string
    {
        return json_encode([
            'guestName' => $this->text($context['guest_name'] ?? null),
            'vmid' => $this->integer($context['vmid'] ?? null),
            'guestType' => $this->text($context['guest_type'] ?? null),
            'node' => $this->text($context['node_name'] ?? $context['submission_node'] ?? null),
            'targetLabel' => $this->text($context['target_label'] ?? null),
            'problemCode' => $code->value,
            'detailCode' => $detail,
            'openedAt' => self::payloadTime($openedAt),
            'occurredAt' => self::payloadTime($at),
            'nextRetryAt' => null === $next ? null : self::payloadTime($next),
            'consecutiveFailures' => $failures,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    }

    /** @param array<string,mixed> $context
     *  @throws JsonException
     */
    private function existingOutboxFailures(Connection $db, array $context, string $runId, string $eventKey, string $kind, ?BackupProblemCode $code, ?string $detail, DateTimeImmutable $at, ?DateTimeImmutable $next): ?int
    {
        $row = $db->fetchAssociative(
            'SELECT id, root_request_id, request_id, notification_kind, attempt, payload_json FROM backup_notification_outbox WHERE run_id=:run AND event_key=:event FOR UPDATE',
            ['run' => $runId, 'event' => $eventKey],
            ['run' => ParameterType::BINARY],
        );
        if (false === $row) {
            return null;
        }
        $payload = json_decode($this->text($row['payload_json'] ?? null), true, 32, JSON_THROW_ON_ERROR);
        if (!is_array($payload)) {
            throw new RuntimeException('An existing notification payload is invalid.');
        }
        $storedCode = $code ?? BackupProblemCode::from($this->text($payload['problemCode'] ?? null));
        $failures = $this->integer($payload['consecutiveFailures'] ?? null);
        $expectedId = substr(hash('sha256', "backup-notification\0".$runId."\0".$eventKey, true), 0, 16);
        $root = $this->binary($context['root_request_id'] ?? null);
        $request = $this->binary($context['id'] ?? $context['request_id'] ?? null);
        $openedAt = $this->datePayload($payload['openedAt'] ?? null);
        $expectedPayload = $this->payload($context, $storedCode, $detail, $openedAt, $at, $next, $failures);
        if (!is_string($row['id'] ?? null) || !hash_equals($expectedId, $row['id'])
            || !is_string($row['root_request_id'] ?? null) || !hash_equals($root, $row['root_request_id'])
            || !is_string($row['request_id'] ?? null) || !hash_equals($request, $row['request_id'])
            || $kind !== ($row['notification_kind'] ?? null)
            || $this->integer($context['attempt'] ?? null) !== $this->integer($row['attempt'] ?? null)
            || !hash_equals($expectedPayload, $this->text($row['payload_json'] ?? null))) {
            throw new RuntimeException('An existing notification does not match the deterministic replay.');
        }
        return $failures;
    }

    private function binary(mixed $v): string { if (!is_string($v)||16!==strlen($v)) throw new RuntimeException('Invalid notification context identifier.'); return $v; }
    private function text(mixed $v): string { if (!is_string($v)||''===$v) throw new RuntimeException('Invalid notification context text.'); return $v; }
    private function integer(mixed $v): int { if(is_int($v)&&$v>=0)return $v; if(is_string($v)&&ctype_digit($v)&&strlen($v)<19)return(int)$v; throw new RuntimeException('Invalid notification context integer.'); }
    private function date(mixed $v): DateTimeImmutable { if(!is_string($v))throw new RuntimeException('Invalid problem timestamp.'); $d=DateTimeImmutable::createFromFormat('!Y-m-d H:i:s.u',$v,new \DateTimeZone('UTC')); if(false===$d)throw new RuntimeException('Invalid problem timestamp.'); return $d; }
    private function datePayload(mixed $v): DateTimeImmutable { if(!is_string($v))throw new RuntimeException('Invalid problem payload timestamp.'); $d=DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:s.u\Z',$v,new \DateTimeZone('UTC')); if(false===$d)throw new RuntimeException('Invalid problem payload timestamp.'); return $d; }
    private static function format(DateTimeImmutable $v): string { return $v->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s.u'); }
    private static function payloadTime(DateTimeImmutable $v): string { return $v->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.u\Z'); }
}
