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
        $this->recordFailure($db, $context, $runId, $runId, $eventKey, $code, $detailCode, $occurredAt, $nextRetryAt);
    }

    /** @param array<string, mixed> $context
     *  @throws JsonException
     */
    public function prePost(
        Connection $db,
        array $context,
        string $occurrenceId,
        BackupProblemCode $code,
        string $detailCode,
        DateTimeImmutable $occurredAt,
        DateTimeImmutable $nextRetryAt,
    ): void {
        $this->recordFailure(
            $db,
            $context,
            $occurrenceId,
            null,
            'pre_post_'.$code->value,
            $code,
            $detailCode,
            $occurredAt,
            $nextRetryAt,
        );
    }

    /** @param array<string, mixed> $context
     *  @throws JsonException
     */
    public function recovery(Connection $db, array $context, string $runId, DateTimeImmutable $occurredAt): void
    {
        if (null !== $this->existingOutbox($db, $context, $runId, $runId, 'recovery', 'recovery', null, null, $occurredAt, null)) return;
        $obligation = $this->obligation($context);
        $current = $db->fetchAssociative('SELECT * FROM backup_problem_states WHERE obligation_id=:obligation FOR UPDATE', ['obligation' => $obligation], ['obligation' => ParameterType::BINARY]);
        if (false === $current) return;
        $code = BackupProblemCode::from($this->text($current['problem_code'] ?? null));
        $failures = $this->integer($current['consecutive_failures'] ?? null);
        $this->outbox($db, $context, $runId, $runId, 'recovery', 'recovery', $code, null, $this->date($current['opened_at'] ?? null), $occurredAt, null, $failures);
        $db->delete('backup_problem_states', ['obligation_id' => $obligation], ['obligation_id' => ParameterType::BINARY]);
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
        if (null !== $this->existingOutbox($db, $context, $runId, $runId, $eventKey, 'attention_required', $code, $detailCode, $occurredAt, null)) {
            return;
        }
        $obligation = $this->obligation($context);
        $root = $this->nullableBinary($context['root_request_id'] ?? null);
        $current = $db->fetchAssociative('SELECT * FROM backup_problem_states WHERE obligation_id=:obligation FOR UPDATE', ['obligation' => $obligation], ['obligation' => ParameterType::BINARY]);
        $failures = false === $current ? 1 : $this->integer($current['consecutive_failures'] ?? null) + 1;
        $openedAt = false === $current ? $occurredAt : $this->date($current['opened_at'] ?? null);
        $db->executeStatement(<<<'SQL'
INSERT INTO backup_problem_states (obligation_id, root_request_id, problem_code, consecutive_failures, opened_at, last_occurred_at, last_notified_at, revision)
VALUES (:obligation,:root,:code,:failures,:opened,:occurred,:occurred,1)
ON DUPLICATE KEY UPDATE problem_code=VALUES(problem_code), consecutive_failures=VALUES(consecutive_failures),
 root_request_id=COALESCE(root_request_id,VALUES(root_request_id)), last_occurred_at=VALUES(last_occurred_at),
 last_notified_at=VALUES(last_notified_at), revision=revision+1
SQL, ['obligation' => $obligation, 'root' => $root, 'code' => $code->value, 'failures' => $failures, 'opened' => self::format($openedAt), 'occurred' => self::format($occurredAt)], ['obligation' => ParameterType::BINARY, 'root' => ParameterType::BINARY]);
        $this->outbox($db, $context, $runId, $runId, $eventKey, 'attention_required', $code, $detailCode, $openedAt, $occurredAt, null, $failures);
    }

    /** @param array<string,mixed> $context */
    private function recordFailure(Connection $db, array $context, string $occurrenceId, ?string $runId, string $eventKey, BackupProblemCode $code, ?string $detailCode, DateTimeImmutable $occurredAt, DateTimeImmutable $nextRetryAt): void
    {
        if (null !== $this->existingOutbox($db, $context, $occurrenceId, $runId, $eventKey, 'failure', $code, $detailCode, $occurredAt, $nextRetryAt)) return;
        $obligation = $this->obligation($context);
        $root = $this->nullableBinary($context['root_request_id'] ?? null);
        $current = $db->fetchAssociative('SELECT * FROM backup_problem_states WHERE obligation_id=:obligation FOR UPDATE', ['obligation' => $obligation], ['obligation' => ParameterType::BINARY]);
        $failures = false === $current ? 1 : $this->integer($current['consecutive_failures'] ?? null) + 1;
        $openedAt = false === $current ? $occurredAt : $this->date($current['opened_at'] ?? null);
        $db->executeStatement(<<<'SQL'
INSERT INTO backup_problem_states (obligation_id, root_request_id, problem_code, consecutive_failures, opened_at, last_occurred_at, last_notified_at, revision)
VALUES (:obligation,:root,:code,:failures,:opened,:occurred,:occurred,1)
ON DUPLICATE KEY UPDATE problem_code=VALUES(problem_code), consecutive_failures=VALUES(consecutive_failures),
 root_request_id=COALESCE(root_request_id,VALUES(root_request_id)), last_occurred_at=VALUES(last_occurred_at),
 last_notified_at=VALUES(last_notified_at), revision=revision+1
SQL, ['obligation' => $obligation, 'root' => $root, 'code' => $code->value, 'failures' => $failures, 'opened' => self::format($openedAt), 'occurred' => self::format($occurredAt)], ['obligation' => ParameterType::BINARY, 'root' => ParameterType::BINARY]);
        $this->outbox($db, $context, $occurrenceId, $runId, $eventKey, 'failure', $code, $detailCode, $openedAt, $occurredAt, $nextRetryAt, $failures);
    }

    /** @param array<string,mixed> $context
     *  @throws JsonException
     */
    private function outbox(Connection $db, array $context, string $occurrenceId, ?string $runId, string $eventKey, string $kind, BackupProblemCode $code, ?string $detail, DateTimeImmutable $openedAt, DateTimeImmutable $at, ?DateTimeImmutable $next, int $failures): void
    {
        $occurrenceId = $this->binary($occurrenceId);
        $id = substr(hash('sha256', "backup-notification\0".$occurrenceId."\0".$eventKey, true), 0, 16);
        $payload = $this->payload($context, $code, $detail, $openedAt, $at, $next, $failures);
        $db->executeStatement(<<<'SQL'
INSERT INTO backup_notification_outbox (
 id, obligation_id, occurrence_id, root_request_id, request_id, run_id, event_key, notification_kind, attempt, check_number, payload_json,
 state, delivery_attempts, available_at, created_at, revision
) VALUES (:id,:obligation,:occurrence,:root,:request,:run,:event_key,:kind,:attempt,:check_number,:payload,'pending',0,:at,:at,1)
SQL, [
            'id' => $id, 'obligation' => $this->obligation($context), 'occurrence' => $occurrenceId,
            'root' => $this->nullableBinary($context['root_request_id'] ?? null),
            'request' => $this->nullableBinary($context['id'] ?? $context['request_id'] ?? null),
            'run' => $runId, 'event_key' => $eventKey, 'kind' => $kind,
            'attempt' => null === $runId ? null : $this->integer($context['attempt'] ?? null),
            'check_number' => $failures,
            'payload' => $payload, 'at' => self::format($at),
        ], ['id' => ParameterType::BINARY, 'obligation' => ParameterType::BINARY, 'occurrence' => ParameterType::BINARY, 'root' => ParameterType::BINARY, 'request' => ParameterType::BINARY, 'run' => ParameterType::BINARY]);
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
    private function existingOutbox(Connection $db, array $context, string $occurrenceId, ?string $runId, string $eventKey, string $kind, ?BackupProblemCode $code, ?string $detail, DateTimeImmutable $at, ?DateTimeImmutable $next): ?int
    {
        $occurrenceId = $this->binary($occurrenceId);
        $row = $db->fetchAssociative(
            'SELECT id, obligation_id, occurrence_id, root_request_id, request_id, run_id, notification_kind, attempt, check_number, payload_json FROM backup_notification_outbox WHERE occurrence_id=:occurrence AND event_key=:event FOR UPDATE',
            ['occurrence' => $occurrenceId, 'event' => $eventKey],
            ['occurrence' => ParameterType::BINARY],
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
        $expectedId = substr(hash('sha256', "backup-notification\0".$occurrenceId."\0".$eventKey, true), 0, 16);
        $obligation = $this->obligation($context);
        $root = $this->nullableBinary($context['root_request_id'] ?? null);
        $request = $this->nullableBinary($context['id'] ?? $context['request_id'] ?? null);
        $openedAt = $this->datePayload($payload['openedAt'] ?? null);
        $expectedPayload = $this->payload($context, $storedCode, $detail, $openedAt, $at, $next, $failures);
        if (!is_string($row['id'] ?? null) || !hash_equals($expectedId, $row['id'])
            || !is_string($row['obligation_id'] ?? null) || !hash_equals($obligation, $row['obligation_id'])
            || !is_string($row['occurrence_id'] ?? null) || !hash_equals($occurrenceId, $row['occurrence_id'])
            || !$this->sameNullableBinary($root, $row['root_request_id'] ?? null)
            || !$this->sameNullableBinary($request, $row['request_id'] ?? null)
            || !$this->sameNullableBinary($runId, $row['run_id'] ?? null)
            || $kind !== ($row['notification_kind'] ?? null)
            || (null === $runId
                ? null !== ($row['attempt'] ?? null)
                : $this->integer($context['attempt'] ?? null) !== $this->integer($row['attempt'] ?? null))
            || $failures !== $this->integer($row['check_number'] ?? null)
            || !hash_equals($expectedPayload, $this->text($row['payload_json'] ?? null))) {
            throw new RuntimeException('An existing notification does not match the deterministic replay.');
        }
        return $failures;
    }

    private function binary(mixed $v): string { if (!is_string($v)||16!==strlen($v)) throw new RuntimeException('Invalid notification context identifier.'); return $v; }
    private function nullableBinary(mixed $v): ?string { return null === $v ? null : $this->binary($v); }
    private function sameNullableBinary(?string $expected, mixed $actual): bool { return null === $expected ? null === $actual : is_string($actual) && hash_equals($expected, $actual); }
    /** @param array<string,mixed> $context */
    private function obligation(array $context): string
    {
        $identity = '';
        foreach (['connection_id', 'cluster_id', 'guest_id', 'policy_id', 'target_id'] as $field) {
            $identity .= $this->binary($context[$field] ?? null);
        }

        return substr(hash('sha256', "backup-obligation\0".$identity, true), 0, 16);
    }
    private function text(mixed $v): string { if (!is_string($v)||''===$v) throw new RuntimeException('Invalid notification context text.'); return $v; }
    private function integer(mixed $v): int { if(is_int($v)&&$v>=0)return $v; if(is_string($v)&&ctype_digit($v)&&strlen($v)<19)return(int)$v; throw new RuntimeException('Invalid notification context integer.'); }
    private function date(mixed $v): DateTimeImmutable { if(!is_string($v))throw new RuntimeException('Invalid problem timestamp.'); $d=DateTimeImmutable::createFromFormat('!Y-m-d H:i:s.u',$v,new \DateTimeZone('UTC')); if(false===$d)throw new RuntimeException('Invalid problem timestamp.'); return $d; }
    private function datePayload(mixed $v): DateTimeImmutable { if(!is_string($v))throw new RuntimeException('Invalid problem payload timestamp.'); $d=DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:s.u\Z',$v,new \DateTimeZone('UTC')); if(false===$d)throw new RuntimeException('Invalid problem payload timestamp.'); return $d; }
    private static function format(DateTimeImmutable $v): string { return $v->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s.u'); }
    private static function payloadTime(DateTimeImmutable $v): string { return $v->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.u\Z'); }
}
