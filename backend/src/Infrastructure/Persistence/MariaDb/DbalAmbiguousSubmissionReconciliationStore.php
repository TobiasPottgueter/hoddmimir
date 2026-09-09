<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\MariaDb;

use App\Application\Backup\Monitoring\AmbiguousSubmissionIdentity;
use App\Application\Backup\Monitoring\AmbiguousSubmissionReconciliationStore;
use App\Application\Backup\Monitoring\ReconcileAmbiguousSubmissionCommand;
use App\Application\Proxmox\Pve\PveUpid;
use App\Domain\Backup\RecoveryOutcome;
use App\Domain\Backup\RecoveryOutcomeKind;
use App\Domain\Backup\BackupProblemCode;
use App\Application\Backup\Worker\BackupWorkerHeartbeatStatus;
use App\Application\Backup\Worker\BackupWorkerHeartbeatStore;
use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use RuntimeException;

final readonly class DbalAmbiguousSubmissionReconciliationStore implements AmbiguousSubmissionReconciliationStore
{
    public function __construct(
        private Connection $connection,
        private DbalBackupProblemRecorder $problems,
        private int $leaseSeconds = 120,
        private ?BackupWorkerHeartbeatStore $heartbeats = null,
        private \App\Application\Maintenance\MaintenanceAccess $maintenance = new \App\Application\Maintenance\UnrestrictedMaintenanceAccess(),
    )
    {
        if ($leaseSeconds < 1 || $leaseSeconds > 3600) throw new \InvalidArgumentException('Invalid reconciliation lease duration.');
    }

    public function renew(ReconcileAmbiguousSubmissionCommand $command): bool
    {
        return $this->connection->transactional(function (Connection $db) use ($command): bool {
            $row = $this->authority($db, $command);
            if (null === $row || !in_array($row['request_state'] ?? null, ['starting', 'reconcile_required'], true)) {
                return false;
            }
            $this->renewLease($db, $command);
            if (null !== $this->heartbeats) {
                $this->heartbeats->record(
                    $this->binary($row['lease_owner'] ?? null),
                    BackupWorkerHeartbeatStatus::Busy,
                    'reconciliation_page',
                    $command->now,
                    $command->now,
                );
            }

            return true;
        });
    }

    public function prepare(ReconcileAmbiguousSubmissionCommand $command): ?AmbiguousSubmissionIdentity
    {
        return $this->connection->transactional(function (Connection $db) use ($command): ?AmbiguousSubmissionIdentity {
            $row = $this->authority($db, $command);
            if (null === $row || !in_array($row['request_state'] ?? null, ['starting', 'reconcile_required'], true)
                || !in_array($row['run_state'] ?? null, ['awaiting_submission', 'reconcile_required'], true)) {
                return null;
            }
            $this->renewLease($db, $command);
            if ('starting' === $row['request_state']) {
                $this->requireAffected($db->executeStatement("UPDATE backup_runs SET state = 'reconcile_required', submission_provenance = 'ambiguous', revision = revision + 1 WHERE id = :run AND state='awaiting_submission' AND claim_token = :token AND claim_fence = :fence", ['run' => $command->runId, 'token' => $command->claimToken, 'fence' => $command->claimFence]), 'crash-recovery run');
                $this->requireAffected($db->executeStatement("UPDATE backup_requests SET state = 'reconcile_required', submission_provenance = 'ambiguous', retry_disposition = 'forbidden_ambiguous', revision = revision + 1, updated_at = :now WHERE id = :request AND state='starting' AND run_id=:run AND claim_token = :token AND claim_fence = :fence", ['now' => self::format($command->now), 'request' => $command->requestId, 'run' => $command->runId, 'token' => $command->claimToken, 'fence' => $command->claimFence]), 'crash-recovery request');
                $this->problems->attention(
                    $db,
                    $row,
                    $command->runId,
                    BackupProblemCode::ReconciliationRequired,
                    'submission_crash_boundary',
                    $command->now,
                );
                $this->event($db, $command, 'submission_crash_recovery', 'reconcile_required');
            }
            return new AmbiguousSubmissionIdentity(
                $command->requestId,
                $this->text($row['submission_node'] ?? null),
                $this->integer($row['submission_vmid'] ?? null),
                $this->text($row['submission_user'] ?? null),
                $this->date($row['submission_window_start'] ?? null),
                $this->date($row['submission_window_end'] ?? null),
            );
        });
    }

    public function record(ReconcileAmbiguousSubmissionCommand $command, RecoveryOutcome $outcome): void
    {
        $this->connection->transactional(function (Connection $db) use ($command, $outcome): void {
            $guest = $db->fetchOne('SELECT guest_id FROM backup_requests WHERE id=:id',
                ['id' => $command->requestId], ['id' => ParameterType::BINARY]);
            if (false === $guest) return;
            (new DbalBackupRequestGuestGuard())->lock($db, [$this->binary($guest)]);
            $row = $this->authority($db, $command);
            if (null === $row || 'reconcile_required' !== ($row['request_state'] ?? null)) return;
            if (RecoveryOutcomeKind::Matched === $outcome->kind) {
                $raw = $outcome->upid->value ?? throw new RuntimeException('Matched recovery lacks UPID.');
                $upid = PveUpid::parse($raw);
                if (!hash_equals($upid->node, $this->text($row['submission_node'] ?? null))
                    || (string) $this->integer($row['submission_vmid'] ?? null) !== $upid->id
                    || !hash_equals($upid->user, $this->text($row['submission_user'] ?? null))
                    || $upid->startTime < $this->date($row['submission_window_start'] ?? null)->getTimestamp()
                    || $upid->startTime > $this->date($row['submission_window_end'] ?? null)->getTimestamp()) throw new RuntimeException('A reconciled task changed submission identity.');
                // A complete remote search can return an older task inside the overlapping
                // submission window. Its existing local owner excludes it as this run's match.
                // The guest guard serializes this check with other assignments for this guest.
                if (false !== $db->fetchOne('SELECT id FROM backup_runs WHERE upid_hash=:hash AND id<>:run',
                    ['hash' => hash('sha256', $raw, true), 'run' => $command->runId],
                    ['hash' => ParameterType::BINARY, 'run' => ParameterType::BINARY])) {
                    $outcome = RecoveryOutcome::provenNotStarted();
                }
            }
            if (RecoveryOutcomeKind::Matched === $outcome->kind) {
                $raw = $outcome->upid->value ?? throw new RuntimeException('Matched recovery lacks UPID.');
                $this->requireAffected($db->executeStatement("UPDATE backup_runs SET state = 'running', submission_provenance = 'accepted', upid = :upid, upid_hash = :hash, recovery_outcome = 'matched', recovery_checked_at = :now, revision = revision + 1 WHERE id = :run AND state='reconcile_required' AND claim_token = :token AND claim_fence = :fence", ['upid' => $raw, 'hash' => hash('sha256', $raw, true), 'now' => self::format($command->now), 'run' => $command->runId, 'token' => $command->claimToken, 'fence' => $command->claimFence]), 'matched run');
                $this->requireAffected($db->executeStatement("UPDATE backup_requests SET state = 'running', submission_provenance = 'accepted', retry_disposition = 'controlled_allowed', revision = revision + 1, updated_at = :now WHERE id = :request AND state='reconcile_required' AND run_id=:run AND claim_token = :token AND claim_fence = :fence", ['now' => self::format($command->now), 'request' => $command->requestId, 'run' => $command->runId, 'token' => $command->claimToken, 'fence' => $command->claimFence]), 'matched request');
                $state = 'running'; $detail = null;
            } elseif ($outcome->permitsNewAttempt()
                && \App\Application\Maintenance\MaintenancePhase::Open === $this->maintenance->phase()) {
                $detail = RecoveryOutcomeKind::ProvenNotStarted === $outcome->kind
                    ? 'submission_not_found'
                    : 'multiple_submission_matches';
                $state = 'unknown';
                $this->requireAffected($db->executeStatement("UPDATE backup_runs SET state='unknown', recovery_outcome=:outcome, recovery_checked_at=:now, finished_at=:now, revision=revision+1 WHERE id=:run AND state='reconcile_required' AND claim_token=:token AND claim_fence=:fence", ['outcome' => $outcome->kind->value, 'now' => self::format($command->now), 'run' => $command->runId, 'token' => $command->claimToken, 'fence' => $command->claimFence]), 'terminal reconciliation run');
                $this->requireAffected($db->executeStatement("UPDATE backup_requests SET state='unknown', retry_disposition='forbidden_ambiguous', terminal_code=:code, terminal_at=:now, claim_token=NULL, lease_owner=NULL, lease_issued_at=NULL, lease_expires_at=NULL, revision=revision+1, updated_at=:now WHERE id=:request AND state='reconcile_required' AND run_id=:run AND claim_token=:token AND claim_fence=:fence", ['code' => $detail, 'now' => self::format($command->now), 'request' => $command->requestId, 'run' => $command->runId, 'token' => $command->claimToken, 'fence' => $command->claimFence]), 'terminal reconciliation request');
                $this->releaseResources($db, $row, $command->now);
                if (null === ($row['cancel_requested_at'] ?? null)) {
                    $this->createReconciledAttempt($db, $command);
                }
                $this->problems->attention($db, $row, $command->runId, BackupProblemCode::MonitoringUnknown, $detail, $command->now);
            } else {
                $detail = $outcome->kind->value; $state = 'reconcile_required';
                $this->requireAffected($db->executeStatement("UPDATE backup_runs SET recovery_outcome = :outcome, recovery_checked_at = :now, revision = revision + 1 WHERE id = :run AND state='reconcile_required' AND claim_token = :token AND claim_fence = :fence", ['outcome' => $detail, 'now' => self::format($command->now), 'run' => $command->runId, 'token' => $command->claimToken, 'fence' => $command->claimFence]), 'reconciliation observation');
            }
            $this->event($db, $command, 'reconciliation_'.$outcome->kind->value, $state, $detail);
        });
    }

    private function createReconciledAttempt(Connection $db, ReconcileAmbiguousSubmissionCommand $command): void
    {
        $retry = substr(hash('sha256', "reconciled-attempt\0".$command->requestId, true), 0, 16);
        $latest = $db->fetchOne(<<<'SQL'
SELECT MAX(previous.scheduled_at) FROM backup_requests request
JOIN backup_requests previous ON previous.policy_id=request.policy_id AND previous.guest_id=request.guest_id
WHERE request.id=:request
SQL, ['request' => $command->requestId], ['request' => ParameterType::BINARY]);
        // Preserve unique schedule identity, without delaying available_at or introducing a recovery timer.
        $latestAt = $this->date($latest);
        $scheduledAt = $latestAt >= $command->now ? $latestAt->modify('+1 microsecond') : $command->now;
        $this->requireAffected($db->executeStatement(<<<'SQL'
INSERT INTO backup_requests (
 id,root_request_id,attempt,origin,state,reason,priority,scheduled_at,available_at,
 connection_id,cluster_id,guest_id,node_id,placement_revision,placement_observed_at,
 policy_id,policy_revision,target_id,target_revision,shadow_decision_id,resolved_policy_json,
 resolved_policy_hash,expected_size_bytes,retry_disposition,submission_provenance,revision,
 claim_fence,created_at,updated_at
)
SELECT :retry,root_request_id,attempt+1,'retry','retry_wait',reason,priority,:scheduled,:scheduled,
 connection_id,cluster_id,guest_id,node_id,placement_revision,placement_observed_at,
 policy_id,policy_revision,target_id,target_revision,NULL,resolved_policy_json,resolved_policy_hash,
 expected_size_bytes,'not_applicable','not_submitted',1,0,:now,:now
FROM backup_requests WHERE id=:request AND state='unknown'
SQL, ['retry' => $retry, 'scheduled' => self::format($scheduledAt), 'now' => self::format($command->now), 'request' => $command->requestId],
            ['retry' => ParameterType::BINARY, 'request' => ParameterType::BINARY]), 'reconciled attempt');
        $db->insert('backup_request_events', [
            'id' => substr(hash('sha256', "reconciled-event\0".$retry, true), 0, 16),
            'request_id' => $retry, 'sequence_no' => 1,
            'event_type' => 'reconciliation_retry_scheduled', 'state' => 'retry_wait',
            'occurred_at' => self::format($command->now),
            'detail_code' => 'complete_task_search_without_unique_match',
        ]);
    }

    /** @param array<string,mixed> $row */
    private function releaseResources(Connection $db, array $row, DateTimeImmutable $at): void
    {
        $now = self::format($at);
        $node = $db->executeStatement('UPDATE backup_node_slots SET slots_used=slots_used-1,revision=revision+1,updated_at=:now WHERE node_id=:id AND slots_used>0', ['now' => $now, 'id' => $this->binary($row['node_id'] ?? null)]);
        $target = $db->executeStatement('UPDATE backup_target_slots SET slots_used=slots_used-1,revision=revision+1,updated_at=:now WHERE target_id=:id AND slots_used>0', ['now' => $now, 'id' => $this->binary($row['target_id'] ?? null)]);
        $reservation = $db->executeStatement('UPDATE backup_capacity_reservations SET released_at=:now WHERE request_id=:request AND released_at IS NULL', ['now' => $now, 'request' => $this->binary($row['id'] ?? null)]);
        if (1 !== (int) $node || 1 !== (int) $target || 1 !== (int) $reservation) throw new RuntimeException('Terminal reconciliation resource release is inconsistent.');
    }

    /** @return array<string, mixed>|null */
    private function authority(Connection $db, ReconcileAmbiguousSubmissionCommand $command): ?array
    {
        $row = $db->fetchAssociative(<<<'SQL'
SELECT request.*, request.id AS request_id, request.state AS request_state,
       request.claim_token, request.claim_fence, request.lease_expires_at,
       run.state AS run_state, run.submission_node,
       run.claim_token AS run_claim_token, run.claim_fence AS run_claim_fence,
       run.submission_vmid, run.submission_user, run.submission_window_start,
       run.submission_window_end, guest.name AS guest_name, guest.guest_type, guest.vmid,
       node.node_name, target.display_name AS target_label
FROM backup_requests request
JOIN backup_runs run ON run.id = request.run_id AND run.request_id=request.id
JOIN guests guest ON guest.id=request.guest_id
JOIN pve_nodes node ON node.id=request.node_id
JOIN backup_targets target ON target.id=request.target_id
WHERE request.id = :request AND run.id = :run FOR UPDATE
SQL, ['request' => $command->requestId, 'run' => $command->runId], ['request' => ParameterType::BINARY, 'run' => ParameterType::BINARY]);
        if (false === $row || !is_string($row['claim_token'] ?? null) || !hash_equals($command->claimToken, $row['claim_token'])
            || !is_string($row['run_claim_token'] ?? null) || !hash_equals($command->claimToken, $row['run_claim_token'])
            || $command->claimFence !== $this->integer($row['claim_fence'] ?? null)
            || $command->claimFence !== $this->integer($row['run_claim_fence'] ?? null)
            || $this->date($row['lease_expires_at'] ?? null) <= $command->now) return null;
        return $row;
    }

    private function renewLease(Connection $db, ReconcileAmbiguousSubmissionCommand $command): void
    {
        $expiry = $command->now->modify('+'.$this->leaseSeconds.' seconds');
        $this->requireAffected($db->executeStatement(<<<'SQL'
UPDATE backup_requests SET lease_issued_at=:now, lease_expires_at=:expiry,
 revision=revision+1, updated_at=:now
WHERE id=:request AND run_id=:run AND state IN ('starting','reconcile_required')
 AND claim_token=:token AND claim_fence=:fence AND lease_expires_at>:now
SQL, ['now' => self::format($command->now), 'expiry' => self::format($expiry), 'request' => $command->requestId, 'run' => $command->runId, 'token' => $command->claimToken, 'fence' => $command->claimFence], ['request' => ParameterType::BINARY, 'run' => ParameterType::BINARY, 'token' => ParameterType::BINARY]), 'reconciliation heartbeat');
    }

    private function requireAffected(int|string $affected, string $transition): void
    {
        if (1 !== (int) $affected) throw new RuntimeException('The '.$transition.' lost its fence.');
    }

    private function event(Connection $db, ReconcileAmbiguousSubmissionCommand $command, string $type, string $state, ?string $detail = null): void
    {
        $sequence = $this->integer($db->fetchOne('SELECT COALESCE(MAX(sequence_no),0)+1 FROM backup_run_events WHERE run_id=:run', ['run' => $command->runId]));
        $db->insert('backup_run_events', ['id' => substr(hash('sha256', "reconcile\0".$command->runId.pack('J', $sequence), true), 0, 16), 'run_id' => $command->runId, 'sequence_no' => $sequence, 'event_type' => $type, 'state' => $state, 'occurred_at' => self::format($command->now), 'detail_code' => $detail]);
    }

    private function integer(mixed $value): int { if (is_int($value) && $value >= 0) return $value; if (is_string($value) && ctype_digit($value) && strlen($value) < 19) return (int) $value; throw new RuntimeException('Invalid reconciliation integer.'); }
    private function binary(mixed $value): string { if (!is_string($value) || 16 !== strlen($value)) throw new RuntimeException('Invalid reconciliation identifier.'); return $value; }
    private function text(mixed $value): string { if (!is_string($value) || '' === $value) throw new RuntimeException('Invalid reconciliation text.'); return $value; }
    private function date(mixed $value): DateTimeImmutable { if (!is_string($value)) throw new RuntimeException('Invalid reconciliation timestamp.'); $date = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s.u', $value, new DateTimeZone('UTC')); if (false === $date) throw new RuntimeException('Invalid reconciliation timestamp.'); return $date; }
    private static function format(DateTimeImmutable $value): string { return $value->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.u'); }
}
