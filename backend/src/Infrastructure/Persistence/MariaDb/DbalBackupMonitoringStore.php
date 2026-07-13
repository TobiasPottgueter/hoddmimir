<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\MariaDb;

use App\Application\Backup\Monitoring\BackupMonitoringTransaction;
use App\Application\Backup\Monitoring\MonitorClaimedBackupCommand;
use App\Application\Backup\Monitoring\PreparedBackupMonitoring;
use App\Application\Backup\Monitoring\StopAttemptDisposition;
use App\Application\Backup\Worker\BackupWorkerHeartbeatStatus;
use App\Application\Backup\Worker\BackupWorkerHeartbeatStore;
use App\Application\Proxmox\Pve\PveBackupApiFailureCode;
use App\Application\Proxmox\Pve\PveTaskLogPage;
use App\Application\Proxmox\Pve\PveTaskStopStatus;
use App\Application\Proxmox\Pve\PveUpid;
use App\Domain\Backup\ControlledRetryPolicy;
use App\Domain\Backup\BackupProblemCode;
use App\Domain\Backup\MonitoringOutcome;
use App\Domain\Backup\SubmissionProvenance;
use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use RuntimeException;

final readonly class DbalBackupMonitoringStore implements BackupMonitoringTransaction
{
    public function __construct(
        private Connection $connection,
        private ControlledRetryPolicy $retryPolicy,
        private DbalBackupProblemRecorder $problems,
        private int $leaseSeconds = 120,
        private ?BackupWorkerHeartbeatStore $heartbeats = null,
    ) {
        if ($leaseSeconds < 1 || $leaseSeconds > 3600) {
            throw new \InvalidArgumentException('The monitoring lease duration is invalid.');
        }
    }

    public function renew(MonitorClaimedBackupCommand $command): bool
    {
        return $this->connection->transactional(function (Connection $db) use ($command): bool {
            $row = $this->authority($db, $command->requestId, $command->runId, $command->claimToken, $command->claimFence, $command->now);
            if (null === $row || !in_array($row['run_state'] ?? null, ['running', 'cancel_requested'], true)) {
                return false;
            }
            $this->renewLease($db, $command);
            $this->pulse($row, $command->now, 'monitoring_io');

            return true;
        });
    }

    public function prepare(MonitorClaimedBackupCommand $command): ?PreparedBackupMonitoring
    {
        return $this->connection->transactional(function (Connection $db) use ($command): ?PreparedBackupMonitoring {
            $row = $this->authority($db, $command->requestId, $command->runId, $command->claimToken, $command->claimFence, $command->now);
            if (null === $row || !in_array($row['run_state'] ?? null, ['running', 'cancel_requested'], true)) {
                return null;
            }
            $this->renewLease($db, $command);
            $upid = $this->upid($row);
            $cancelRequested = null !== ($row['request_cancel_requested_at'] ?? null)
                || null !== ($row['run_cancel_requested_at'] ?? null);
            if (!$cancelRequested) {
                $stop = StopAttemptDisposition::NotRequested;
            } elseif (null === ($row['stop_attempt_claimed_at'] ?? null)) {
                $stop = StopAttemptDisposition::ReadyToClaim;
            } elseif ('dispatching' === ($row['stop_attempt_status'] ?? null)) {
                $affected = $db->executeStatement(<<<'SQL'
UPDATE backup_runs
SET stop_attempt_status = 'dispatch_unknown', stop_failure_code = 'worker_lost_after_stop_dispatch',
    stop_attempt_resolved_at = :now, revision = revision + 1
WHERE id = :run AND request_id = :request AND claim_token = :token AND claim_fence = :fence
  AND stop_attempt_status = 'dispatching'
SQL, [
                    'now' => self::format($command->now), 'run' => $command->runId,
                    'request' => $command->requestId, 'token' => $command->claimToken,
                    'fence' => $command->claimFence,
                ], ['run' => ParameterType::BINARY, 'request' => ParameterType::BINARY, 'token' => ParameterType::BINARY]);
                if (1 !== $affected) {
                    throw new RuntimeException('The orphaned stop dispatch lost its fence.');
                }
                $this->appendRunEvent($db, $command->runId, 'stop_dispatch_unknown', 'cancel_requested', $command->now, 'worker_lost_after_stop_dispatch');
                $this->problems->attention(
                    $db,
                    $row,
                    $command->runId,
                    BackupProblemCode::CancelDispatchUnknown,
                    'worker_lost_after_stop_dispatch',
                    $command->now,
                );
                $stop = StopAttemptDisposition::DispatchUnknown;
            } else {
                $stop = StopAttemptDisposition::AlreadyAttempted;
            }

            return new PreparedBackupMonitoring($upid, $this->integer($row['next_log_offset'] ?? null), $stop);
        });
    }

    public function claimStopAttempt(MonitorClaimedBackupCommand $command, PveUpid $upid): bool
    {
        return $this->connection->transactional(function (Connection $db) use ($command, $upid): bool {
            $row = $this->authority($db, $command->requestId, $command->runId, $command->claimToken, $command->claimFence, $command->now);
            if (null === $row || !$this->sameUpid($row, $upid)
                || (null === ($row['request_cancel_requested_at'] ?? null) && null === ($row['run_cancel_requested_at'] ?? null))
                || null !== ($row['stop_attempt_claimed_at'] ?? null)
                || null !== ($row['stop_attempt_status'] ?? null)) {
                return false;
            }
            $affected = $db->executeStatement(<<<'SQL'
UPDATE backup_runs
SET state = 'cancel_requested', cancel_requested_at = COALESCE(cancel_requested_at, :now),
    stop_attempt_claimed_at = :now, stop_attempt_status = 'dispatching', revision = revision + 1
WHERE id = :run AND request_id = :request AND claim_token = :token AND claim_fence = :fence
  AND stop_attempt_claimed_at IS NULL AND stop_attempt_status IS NULL
SQL, [
                'now' => self::format($command->now), 'run' => $command->runId,
                'request' => $command->requestId, 'token' => $command->claimToken,
                'fence' => $command->claimFence,
            ], ['run' => ParameterType::BINARY, 'request' => ParameterType::BINARY, 'token' => ParameterType::BINARY]);
            if (1 !== $affected) {
                return false;
            }
            $this->appendRunEvent($db, $command->runId, 'stop_attempt_dispatching', 'cancel_requested', $command->now);

            return true;
        });
    }

    public function appendLogPage(MonitorClaimedBackupCommand $command, PveUpid $upid, PveTaskLogPage $page): void
    {
        $this->connection->transactional(function (Connection $db) use ($command, $upid, $page): void {
            $row = $this->authority($db, $command->requestId, $command->runId, $command->claimToken, $command->claimFence, $command->now);
            if (null === $row || !$this->sameUpid($row, $upid)) {
                return;
            }
            $next = $this->integer($row['next_log_offset'] ?? null);
            foreach ($page->entries as $entry) {
                $existing = $db->fetchOne(
                    'SELECT content FROM backup_run_log_entries WHERE run_id = :run AND line_no = :line',
                    ['run' => $command->runId, 'line' => $entry->number],
                    ['run' => ParameterType::BINARY],
                );
                if (false === $existing) {
                    $db->insert('backup_run_log_entries', [
                        'run_id' => $command->runId,
                        'line_no' => $entry->number,
                        'observed_at' => self::format($command->now),
                        'content' => $entry->text,
                    ], ['run_id' => ParameterType::BINARY]);
                } elseif (!is_string($existing) || !hash_equals($existing, $entry->text)) {
                    throw new RuntimeException('A PVE task log line changed after it was persisted.');
                }
                $next = max($next, $entry->number + 1);
            }
            $affected = $db->executeStatement(
                'UPDATE backup_runs SET next_log_offset = :next, revision = revision + 1 WHERE id = :run AND claim_token = :token AND claim_fence = :fence',
                ['next' => $next, 'run' => $command->runId, 'token' => $command->claimToken, 'fence' => $command->claimFence],
                ['run' => ParameterType::BINARY, 'token' => ParameterType::BINARY],
            );
            if (1 !== $affected) {
                throw new RuntimeException('The monitoring log cursor lost its fence.');
            }
        });
    }

    public function recordStopAttempt(
        MonitorClaimedBackupCommand $command,
        PveUpid $upid,
        ?PveTaskStopStatus $status,
        ?PveBackupApiFailureCode $failure,
    ): void {
        if ((null === $status) === (null === $failure)) {
            throw new \InvalidArgumentException('A stop attempt needs exactly one outcome.');
        }
        $this->connection->transactional(function (Connection $db) use ($command, $upid, $status, $failure): void {
            $row = $this->authority($db, $command->requestId, $command->runId, $command->claimToken, $command->claimFence, $command->now);
            if (null === $row || !$this->sameUpid($row, $upid)
                || null === ($row['stop_attempt_claimed_at'] ?? null)
                || 'dispatching' !== ($row['stop_attempt_status'] ?? null)) {
                return;
            }
            $stopStatus = null === $status ? 'definitive_rejection' : $status->value;
            $affected = $db->executeStatement(<<<'SQL'
UPDATE backup_runs
SET stop_attempt_status = :status, stop_failure_code = :failure,
    stop_attempt_resolved_at = :now, revision = revision + 1
WHERE id = :run AND claim_token = :token AND claim_fence = :fence AND stop_attempt_status = 'dispatching'
SQL, [
                'status' => $stopStatus, 'failure' => $failure?->value,
                'now' => self::format($command->now), 'run' => $command->runId,
                'token' => $command->claimToken, 'fence' => $command->claimFence,
            ], ['run' => ParameterType::BINARY, 'token' => ParameterType::BINARY]);
            if (1 !== $affected) {
                throw new RuntimeException('The stop attempt lost its fence.');
            }
            $this->appendRunEvent($db, $command->runId, 'stop_attempt_recorded', 'cancel_requested', $command->now, $failure->value ?? $stopStatus);
        });
    }

    public function recordObservation(
        MonitorClaimedBackupCommand $command,
        PveUpid $upid,
        MonitoringOutcome $outcome,
        ?string $exitStatus,
        ?PveBackupApiFailureCode $failure,
    ): void {
        if (null !== $exitStatus && (strlen($exitStatus) > 255 || str_contains($exitStatus, "\0"))) {
            throw new \InvalidArgumentException('The task exit status is invalid.');
        }
        $this->connection->transactional(function (Connection $db) use ($command, $upid, $outcome, $exitStatus, $failure): void {
            $row = $this->authority($db, $command->requestId, $command->runId, $command->claimToken, $command->claimFence, $command->now);
            if (null === $row || !$this->sameUpid($row, $upid)) {
                return;
            }
            if (MonitoringOutcome::Running === $outcome || MonitoringOutcome::TemporarilyUnavailable === $outcome) {
                $affected = $db->executeStatement(<<<'SQL'
UPDATE backup_runs
SET status_observed_at = :now, status_failure_code = :failure,
    exit_status = :exit_status, revision = revision + 1
WHERE id = :run AND claim_token = :token AND claim_fence = :fence
SQL, [
                    'now' => self::format($command->now), 'failure' => $failure?->value,
                    'exit_status' => $exitStatus, 'run' => $command->runId,
                    'token' => $command->claimToken, 'fence' => $command->claimFence,
                ], ['run' => ParameterType::BINARY, 'token' => ParameterType::BINARY]);
                if (1 !== $affected) {
                    throw new RuntimeException('The monitoring observation lost its fence.');
                }
                $this->renewLease($db, $command);
                return;
            }

            $state = match ($outcome) {
                MonitoringOutcome::Succeeded => 'succeeded',
                MonitoringOutcome::Failed => 'failed',
                MonitoringOutcome::Cancelled => 'cancelled',
            };
            $terminalCode = 'failed' === $state ? 'task_failed' : null;
            $runAffected = $db->executeStatement(<<<'SQL'
UPDATE backup_runs
SET state = :state, status_observed_at = :now, status_failure_code = :failure,
    exit_status = :exit_status, finished_at = :now, revision = revision + 1
WHERE id = :run AND claim_token = :token AND claim_fence = :fence
  AND state IN ('running', 'cancel_requested')
SQL, [
                'state' => $state, 'now' => self::format($command->now), 'failure' => $failure?->value,
                'exit_status' => $exitStatus, 'run' => $command->runId,
                'token' => $command->claimToken, 'fence' => $command->claimFence,
            ], ['run' => ParameterType::BINARY, 'token' => ParameterType::BINARY]);
            if (1 !== $runAffected) {
                throw new RuntimeException('The terminal run transition lost its fence.');
            }
            $requestAffected = $db->executeStatement(<<<'SQL'
UPDATE backup_requests
SET state = :state, retry_disposition = :retry, terminal_code = :code, terminal_at = :now,
    claim_token = NULL, lease_owner = NULL, lease_issued_at = NULL, lease_expires_at = NULL,
    revision = revision + 1, updated_at = :now
WHERE id = :request AND run_id = :run AND claim_token = :token AND claim_fence = :fence
  AND state IN ('running', 'reconcile_required')
SQL, [
                'state' => $state,
                'retry' => 'failed' === $state ? 'controlled_allowed' : 'not_applicable',
                'code' => $terminalCode, 'now' => self::format($command->now),
                'request' => $command->requestId, 'run' => $command->runId,
                'token' => $command->claimToken, 'fence' => $command->claimFence,
            ], ['request' => ParameterType::BINARY, 'run' => ParameterType::BINARY, 'token' => ParameterType::BINARY]);
            if (1 !== $requestAffected) {
                throw new RuntimeException('The terminal request transition lost its fence.');
            }
            $this->releaseResources($db, $row, $command->now);
            $this->appendRunEvent($db, $command->runId, 'task_'.$state, $state, $command->now, $terminalCode);
            $this->appendRequestEvent($db, $command->requestId, 'task_'.$state, $state, $command->now, $command->claimFence, $terminalCode);
            if ('failed' === $state) {
                $nextRetryAt = $this->createRetry($db, $row, $command->now);
                $this->problems->failure($db, $row, $command->runId, BackupProblemCode::TaskFailed, 'task_failed', $command->now, $nextRetryAt);
            } elseif ('succeeded' === $state) {
                $this->recordSuccess($db, $row, $command->now);
                $this->problems->recovery($db, $row, $command->runId, $command->now);
            }
        });
    }

    /** @return array<string, mixed>|null */
    private function authority(Connection $db, string $requestId, string $runId, string $token, int $fence, DateTimeImmutable $now): ?array
    {
        $row = $db->fetchAssociative(<<<'SQL'
SELECT request.*, request.state AS request_state, request.cancel_requested_at AS request_cancel_requested_at,
       run.state AS run_state, run.upid, run.upid_hash, run.next_log_offset,
       run.cancel_requested_at AS run_cancel_requested_at, run.stop_attempt_claimed_at,
       run.stop_attempt_status, run.stop_attempt_resolved_at,
       run.submission_node, run.submission_vmid, run.submission_user,
       run.submission_window_start, run.submission_window_end,
       guest.name AS guest_name, guest.guest_type, guest.vmid,
       node.node_name, target.display_name AS target_label
FROM backup_requests request
JOIN backup_runs run ON run.id = request.run_id AND run.request_id = request.id
JOIN guests guest ON guest.id = request.guest_id
JOIN pve_nodes node ON node.id = request.node_id
JOIN backup_targets target ON target.id = request.target_id
WHERE request.id = :request AND run.id = :run
FOR UPDATE
SQL, ['request' => $requestId, 'run' => $runId], ['request' => ParameterType::BINARY, 'run' => ParameterType::BINARY]);
        if (false === $row
            || !is_string($row['claim_token'] ?? null) || !hash_equals($token, $row['claim_token'])
            || $fence !== $this->integer($row['claim_fence'] ?? null)
            || !is_string($row['lease_expires_at'] ?? null) || $this->date($row['lease_expires_at']) <= $now
            || !is_string($row['run_id'] ?? null) || !hash_equals($runId, $row['run_id'])) {
            return null;
        }
        return $row;
    }

    /** @param array<string, mixed> $row */
    private function releaseResources(Connection $db, array $row, DateTimeImmutable $at): void
    {
        $now = self::format($at);
        $nodeAffected = $db->executeStatement('UPDATE backup_node_slots SET slots_used = slots_used - 1, revision = revision + 1, updated_at = :now WHERE node_id = :id AND slots_used > 0', ['now' => $now, 'id' => $this->binary($row['node_id'] ?? null)]);
        $targetAffected = $db->executeStatement('UPDATE backup_target_slots SET slots_used = slots_used - 1, revision = revision + 1, updated_at = :now WHERE target_id = :id AND slots_used > 0', ['now' => $now, 'id' => $this->binary($row['target_id'] ?? null)]);
        $reservationAffected = $db->executeStatement('UPDATE backup_capacity_reservations SET released_at = :now WHERE request_id = :request AND released_at IS NULL', ['now' => $now, 'request' => $this->binary($row['id'] ?? null)]);
        if (1 !== $nodeAffected || 1 !== $targetAffected || 1 !== $reservationAffected) {
            throw new RuntimeException('Terminal backup resource release is inconsistent.');
        }
    }

    /** @param array<string, mixed> $row */
    private function createRetry(Connection $db, array $row, DateTimeImmutable $failedAt): DateTimeImmutable
    {
        $attempt = $this->integer($row['attempt'] ?? null);
        if ($attempt >= 4_294_967_295) {
            throw new RuntimeException('The backup retry attempt counter is exhausted.');
        }
        $next = $this->retryPolicy->nextAvailableAt($failedAt, $attempt, SubmissionProvenance::Accepted);
        $requestId = $this->binary($row['id'] ?? null);
        $retryId = substr(hash('sha256', "retry\0".$requestId, true), 0, 16);
        $affected = $db->executeStatement(<<<'SQL'
INSERT IGNORE INTO backup_requests (
    id, root_request_id, attempt, origin, state, reason, priority, scheduled_at, available_at,
    connection_id, cluster_id, guest_id, node_id, placement_revision, placement_observed_at,
    policy_id, policy_revision, target_id, target_revision, shadow_decision_id,
    resolved_policy_json, resolved_policy_hash, expected_size_bytes, retry_disposition,
    submission_provenance, revision, claim_fence, created_at, updated_at
)
SELECT :retry, root_request_id, attempt + 1, 'retry', 'retry_wait', reason, priority, :next, :next,
       connection_id, cluster_id, guest_id, node_id, placement_revision, placement_observed_at,
       policy_id, policy_revision, target_id, target_revision, NULL,
       resolved_policy_json, resolved_policy_hash, expected_size_bytes, 'not_applicable',
       'not_submitted', 1, 0, :failed, :failed
FROM backup_requests WHERE id = :request AND state = 'failed'
SQL, ['retry' => $retryId, 'next' => self::format($next), 'failed' => self::format($failedAt), 'request' => $requestId], ['retry' => ParameterType::BINARY, 'request' => ParameterType::BINARY]);
        if (1 === $affected) {
            $this->appendRequestEvent($db, $retryId, 'retry_scheduled', 'retry_wait', $failedAt, null, 'task_failed');
        } elseif (0 === $affected) {
            $existing = $db->fetchAssociative(
                'SELECT root_request_id, attempt, origin, state, available_at, submission_provenance FROM backup_requests WHERE id = :id',
                ['id' => $retryId],
                ['id' => ParameterType::BINARY],
            );
            if (false === $existing
                || !is_string($existing['root_request_id'] ?? null)
                || !hash_equals($this->binary($row['root_request_id'] ?? null), $existing['root_request_id'])
                || $attempt + 1 !== $this->integer($existing['attempt'] ?? null)
                || 'retry' !== ($existing['origin'] ?? null)
                || 'retry_wait' !== ($existing['state'] ?? null)
                || 'not_submitted' !== ($existing['submission_provenance'] ?? null)
                || self::format($next) !== ($existing['available_at'] ?? null)) {
                throw new RuntimeException('An existing backup retry does not match the deterministic replay.');
            }
        } else {
            throw new RuntimeException('Unexpected retry insertion result.');
        }

        return $next;
    }

    private function renewLease(Connection $db, MonitorClaimedBackupCommand $command): void
    {
        $expiry = $command->now->modify('+'.$this->leaseSeconds.' seconds');
        $affected = $db->executeStatement(<<<'SQL'
UPDATE backup_requests
SET lease_issued_at = :now, lease_expires_at = :expiry, revision = revision + 1, updated_at = :now
WHERE id = :request AND run_id = :run AND claim_token = :token AND claim_fence = :fence
  AND state IN ('running', 'reconcile_required') AND lease_expires_at > :now
SQL, [
            'now' => self::format($command->now), 'expiry' => self::format($expiry),
            'request' => $command->requestId, 'run' => $command->runId,
            'token' => $command->claimToken, 'fence' => $command->claimFence,
        ], [
            'request' => ParameterType::BINARY, 'run' => ParameterType::BINARY,
            'token' => ParameterType::BINARY,
        ]);
        if (1 !== $affected) {
            throw new RuntimeException('The monitoring lease heartbeat lost its fence.');
        }
    }

    /** @param array<string, mixed> $row */
    private function pulse(array $row, DateTimeImmutable $now, string $activity): void
    {
        if (null === $this->heartbeats) {
            return;
        }
        $this->heartbeats->record(
            $this->binary($row['lease_owner'] ?? null),
            BackupWorkerHeartbeatStatus::Busy,
            $activity,
            $now,
            $now,
        );
    }

    /** @param array<string, mixed> $row */
    private function recordSuccess(Connection $db, array $row, DateTimeImmutable $at): void
    {
        $db->executeStatement(<<<'SQL'
INSERT INTO guest_backup_state (
    guest_id, policy_id, target_id, connection_id, cluster_id, last_success_at,
    last_success_size_bytes, baseline_bytes, baseline_observed_at, updated_at
)
SELECT request.guest_id, request.policy_id, request.target_id, request.connection_id, request.cluster_id,
       :at, request.expected_size_bytes, COALESCE(write_state.diskwrite_bytes, 0),
       COALESCE(write_state.observed_at, :at), :at
FROM backup_requests request
LEFT JOIN guest_write_states write_state ON write_state.guest_id = request.guest_id
WHERE request.id = :request
ON DUPLICATE KEY UPDATE last_success_at = VALUES(last_success_at),
    last_success_size_bytes = VALUES(last_success_size_bytes), baseline_bytes = VALUES(baseline_bytes),
    baseline_observed_at = VALUES(baseline_observed_at), updated_at = VALUES(updated_at)
SQL, ['at' => self::format($at), 'request' => $this->binary($row['id'] ?? null)]);
    }

    /** @param array<string, mixed> $row */
    private function upid(array $row): PveUpid
    {
        $raw = $this->text($row['upid'] ?? null);
        $upid = PveUpid::parse($raw);
        if (!$this->sameUpid($row, $upid)) {
            throw new RuntimeException('The persisted backup UPID hash is invalid.');
        }
        return $upid;
    }

    /** @param array<string, mixed> $row */
    private function sameUpid(array $row, PveUpid $upid): bool
    {
        return is_string($row['upid'] ?? null) && hash_equals($row['upid'], $upid->raw)
            && is_string($row['upid_hash'] ?? null) && hash_equals($row['upid_hash'], hash('sha256', $upid->raw, true));
    }

    private function appendRunEvent(Connection $db, string $runId, string $type, string $state, DateTimeImmutable $at, ?string $detail = null): void
    {
        $sequence = $this->integer($db->fetchOne('SELECT COALESCE(MAX(sequence_no), 0) + 1 FROM backup_run_events WHERE run_id = :run', ['run' => $runId]));
        $db->insert('backup_run_events', ['id' => substr(hash('sha256', "run-event\0".$runId.pack('J', $sequence), true), 0, 16), 'run_id' => $runId, 'sequence_no' => $sequence, 'event_type' => $type, 'state' => $state, 'occurred_at' => self::format($at), 'detail_code' => $detail]);
    }

    private function appendRequestEvent(Connection $db, string $requestId, string $type, string $state, DateTimeImmutable $at, ?int $fence, ?string $detail = null): void
    {
        $sequence = $this->integer($db->fetchOne('SELECT COALESCE(MAX(sequence_no), 0) + 1 FROM backup_request_events WHERE request_id = :request', ['request' => $requestId]));
        $db->insert('backup_request_events', ['id' => substr(hash('sha256', "request-event\0".$requestId.pack('J', $sequence), true), 0, 16), 'request_id' => $requestId, 'sequence_no' => $sequence, 'event_type' => $type, 'state' => $state, 'claim_fence' => $fence, 'occurred_at' => self::format($at), 'detail_code' => $detail]);
    }

    private function integer(mixed $value): int
    {
        if (is_int($value) && $value >= 0) return $value;
        if (is_string($value) && ctype_digit($value) && strlen($value) < 19) return (int) $value;
        throw new RuntimeException('Invalid backup monitoring integer.');
    }

    private function binary(mixed $value): string
    {
        if (!is_string($value) || 16 !== strlen($value)) throw new RuntimeException('Invalid backup monitoring identifier.');
        return $value;
    }

    private function text(mixed $value): string
    {
        if (!is_string($value) || '' === $value) throw new RuntimeException('Invalid backup monitoring text.');
        return $value;
    }

    private function date(mixed $value): DateTimeImmutable
    {
        if (!is_string($value)) throw new RuntimeException('Invalid backup monitoring timestamp.');
        $date = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s.u', $value, new DateTimeZone('UTC'));
        if (false === $date) throw new RuntimeException('Invalid backup monitoring timestamp.');
        return $date;
    }

    private static function format(DateTimeImmutable $value): string
    {
        return $value->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.u');
    }
}
