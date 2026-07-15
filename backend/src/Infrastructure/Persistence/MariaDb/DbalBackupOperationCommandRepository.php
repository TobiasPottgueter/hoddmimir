<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\MariaDb;

use App\Application\Backup\Operations\BackupOperationCommand;
use App\Application\Backup\Operations\BackupOperationCommandRepository;
use App\Application\Backup\Operations\BackupOperationCommandResult;
use App\Application\Backup\Operations\BackupOperationCommandStatus;
use App\Application\Backup\Operations\BackupOperationCommandType;
use App\Application\Security\Auth\AuthenticatedPrincipal;
use App\Application\Security\Auth\SecurityIdentifierGenerator;
use App\Domain\Shared\Clock;
use App\Domain\Policy\BackupMode;
use App\Domain\Policy\BackupPolicy;
use App\Domain\Policy\Compression;
use App\Domain\Policy\FailureNotificationRecipients;
use App\Domain\Policy\PolicyId;
use App\Domain\Policy\PolicyPriority;
use App\Domain\Policy\PolicyResolver;
use App\Domain\Policy\PolicyRevision;
use App\Domain\Policy\PolicyStatus;
use App\Domain\Policy\PolicyThresholds;
use App\Domain\Policy\RetentionPolicy;
use App\Domain\Policy\Schedule;
use App\Domain\Target\BackupTargetId;
use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\DBAL\ParameterType;
use RuntimeException;

final readonly class DbalBackupOperationCommandRepository implements BackupOperationCommandRepository
{
    private const string DATE_FORMAT = 'Y-m-d H:i:s.u';
    private DbalBackupRequestGuestGuard $guestGuard;

    public function __construct(
        private Connection $connection,
        private SecurityIdentifierGenerator $ids,
        private Clock $clock,
        private PolicyResolver $policyResolver,
    ) {
        $this->guestGuard = new DbalBackupRequestGuestGuard();
    }

    public function execute(BackupOperationCommand $command, AuthenticatedPrincipal $principal): BackupOperationCommandResult
    {
        try {
            return $this->connection->transactional(function () use ($command, $principal): BackupOperationCommandResult {
                $existing = $this->idempotency($command, $principal, true);
                if (null !== $existing) return $existing;

                $result = BackupOperationCommandType::ManualRequest === $command->type
                    ? $this->manualRequest($command)
                    : $this->cancelRequest($command);
                $this->persistIdempotency($command, $principal, $result);
                if (BackupOperationCommandStatus::Applied === $result->status) {
                    $this->audit($command, $principal);
                }

                return $result;
            });
        } catch (UniqueConstraintViolationException) {
            return $this->idempotency($command, $principal, false)
                ?? throw new RuntimeException('The operation idempotency race could not be resolved.');
        }
    }

    private function manualRequest(BackupOperationCommand $command): BackupOperationCommandResult
    {
        try {
            $this->guestGuard->lock($this->connection, [$command->guestId]);
        } catch (RuntimeException $error) {
            if ('The guest request guard no longer exists.' !== $error->getMessage()) {
                throw $error;
            }

            return $this->blocked('policy_or_guest_missing');
        }
        if (null !== $this->guestGuard->activeRequestId($this->connection, $command->guestId)) {
            return $this->blocked('active_request_exists');
        }

        $lockedPolicyRevision = $this->connection->fetchOne(
            'SELECT revision FROM backup_policies WHERE id = :policy_id FOR UPDATE',
            ['policy_id' => $command->policyId],
            ['policy_id' => ParameterType::BINARY],
        );
        if (false === $lockedPolicyRevision) return $this->blocked('policy_or_guest_missing');
        $lockedPolicyRevision = $this->integer($lockedPolicyRevision);
        if ($lockedPolicyRevision !== $command->expectedRevision) {
            return new BackupOperationCommandResult(BackupOperationCommandStatus::Conflict, $lockedPolicyRevision);
        }

        $row = $this->connection->fetchAssociative(<<<'SQL'
SELECT policy.*, target.revision AS target_revision, target.status AS target_status,
       storage.storage_type AS target_storage_type,
       guest.connection_id AS guest_connection_id, guest.cluster_id AS guest_cluster_id,
       guest.inventory_state AS guest_state, guest.is_template, guest.provisioned_size_bytes,
       placement.node_id, placement.placement_revision, placement.observed_at AS placement_observed_at,
       guest_override.backup_mode AS guest_backup_mode, guest_override.compression AS guest_compression,
       guest_override.legacy_maxfiles AS guest_legacy_maxfiles, guest_override.keep_all AS guest_keep_all,
       guest_override.keep_last AS guest_keep_last, guest_override.keep_hourly AS guest_keep_hourly,
       guest_override.keep_daily AS guest_keep_daily, guest_override.keep_weekly AS guest_keep_weekly,
       guest_override.keep_monthly AS guest_keep_monthly, guest_override.keep_yearly AS guest_keep_yearly,
       (SELECT capability.version_major FROM proxmox_capability_snapshots capability
        WHERE capability.connection_id = policy.connection_id
        ORDER BY capability.last_observed_at DESC, capability.id DESC LIMIT 1) AS pve_major
FROM backup_policies policy
JOIN backup_targets target ON target.connection_id = policy.connection_id AND target.cluster_id = policy.cluster_id AND target.id = policy.target_id
JOIN pve_storages storage ON storage.connection_id = target.connection_id AND storage.cluster_id = target.cluster_id AND storage.id = target.storage_id
JOIN guests guest ON guest.connection_id = policy.connection_id AND guest.cluster_id = policy.cluster_id AND guest.id = :guest_id
LEFT JOIN guest_placements placement ON placement.guest_id = guest.id
LEFT JOIN backup_policy_guest_overrides guest_override ON guest_override.connection_id = guest.connection_id
 AND guest_override.cluster_id = guest.cluster_id AND guest_override.policy_id = policy.id
 AND guest_override.guest_id = guest.id AND guest_override.status = 'active'
WHERE policy.id = :policy_id
SQL, ['guest_id' => $command->guestId, 'policy_id' => $command->policyId],
            ['guest_id' => ParameterType::BINARY, 'policy_id' => ParameterType::BINARY]);
        if (false === $row) return $this->blocked('policy_or_guest_missing');
        $revision = $this->integer($row['revision'] ?? null);
        if ($revision !== $lockedPolicyRevision) throw new RuntimeException('The locked policy revision changed within the manual request transaction.');
        if ('enabled' !== $this->text($row['status'] ?? null)) return $this->blocked('policy_not_enabled');
        if ('enabled' !== $this->text($row['target_status'] ?? null)) return $this->blocked('target_not_enabled');
        if ('active' !== $this->text($row['guest_state'] ?? null) || 1 === $this->nullableInteger($row['is_template'] ?? null)) return $this->blocked('guest_not_eligible');
        if (!is_string($row['node_id'] ?? null) || !is_string($row['placement_observed_at'] ?? null)) return $this->blocked('placement_missing');
        $size = $this->nullableInteger($row['provisioned_size_bytes'] ?? null);
        if (null === $size || $size < 1) return $this->blocked('expected_size_missing');

        $now = $this->databaseNow();
        $pveMajor = $this->nullableInteger($row['pve_major'] ?? null);
        if (null === $pveMajor) return $this->blocked('pve_evidence_missing');
        $policy = BackupPolicy::rehydrate(
            new PolicyId($command->policyId), new PolicyRevision($revision), PolicyStatus::Enabled,
            new BackupTargetId($this->binary($row['target_id'] ?? null)),
            BackupMode::from($this->text($row['backup_mode'] ?? null)), Compression::from($this->text($row['compression'] ?? null)),
            $this->retention($row, ''), new PolicyPriority($this->integer($row['policy_priority'] ?? null)),
            new PolicyThresholds($this->nullableInteger($row['maximum_age_seconds'] ?? null), $this->nullableDecimal($row['bytes_written_threshold'] ?? null), $this->nullableInteger($row['cooldown_seconds'] ?? null)),
            Schedule::from($this->text($row['schedule'] ?? null)),
            $this->failureRecipients($row['failure_notification_recipients_json'] ?? null),
        );
        $resolvedPolicy = $this->policyResolver->resolve(
            $policy, $pveMajor,
            null === ($row['guest_backup_mode'] ?? null) ? null : BackupMode::from($this->text($row['guest_backup_mode'])),
            null === ($row['guest_compression'] ?? null) ? null : Compression::from($this->text($row['guest_compression'])),
            $this->retention($row, 'guest_'),
            1 === $this->integer($row['retention_execution_enabled'] ?? null),
            'pbs' !== ($row['target_storage_type'] ?? null),
        );
        $resolved = $resolvedPolicy->canonicalJson();
        $id = $command->subjectId;
        $this->connection->insert('backup_requests', [
            'id' => $id, 'root_request_id' => $id, 'attempt' => 1, 'origin' => 'manual', 'state' => 'pending',
            'reason' => 'manual', 'priority' => 400, 'scheduled_at' => $now, 'available_at' => $now,
            'connection_id' => $this->binary($row['guest_connection_id'] ?? null),
            'cluster_id' => $this->binary($row['guest_cluster_id'] ?? null), 'guest_id' => $command->guestId,
            'node_id' => $this->binary($row['node_id']), 'placement_revision' => $this->integer($row['placement_revision'] ?? null),
            'placement_observed_at' => $this->text($row['placement_observed_at']), 'policy_id' => $command->policyId,
            'policy_revision' => $revision, 'target_id' => $this->binary($row['target_id'] ?? null),
            'target_revision' => $this->integer($row['target_revision'] ?? null),
            'resolved_policy_json' => $resolved, 'resolved_policy_hash' => hash('sha256', $resolved, true),
            'expected_size_bytes' => $size, 'retry_disposition' => 'not_applicable',
            'created_at' => $now, 'updated_at' => $now,
        ], [
            'id'=>ParameterType::BINARY,'root_request_id'=>ParameterType::BINARY,'connection_id'=>ParameterType::BINARY,
            'cluster_id'=>ParameterType::BINARY,'guest_id'=>ParameterType::BINARY,'node_id'=>ParameterType::BINARY,
            'policy_id'=>ParameterType::BINARY,'target_id'=>ParameterType::BINARY,
            'resolved_policy_hash'=>ParameterType::BINARY,
        ]);
        $this->requestEvent($id, 1, 'manual_requested', 'pending', $now, null);

        return new BackupOperationCommandResult(BackupOperationCommandStatus::Applied, 1);
    }

    private function cancelRequest(BackupOperationCommand $command): BackupOperationCommandResult
    {
        $row = $this->connection->fetchAssociative('SELECT state, revision, cancel_requested_at FROM backup_requests WHERE id = :id FOR UPDATE',
            ['id' => $command->subjectId], ['id' => ParameterType::BINARY]);
        if (false === $row) return $this->blocked('request_missing');
        $revision = $this->integer($row['revision'] ?? null);
        if ($revision !== $command->expectedRevision) return new BackupOperationCommandResult(BackupOperationCommandStatus::Conflict, $revision);
        $state = \App\Application\Backup\Operations\BackupRequestState::from($this->text($row['state'] ?? null));
        if ($state->terminal()) return $this->blocked('request_terminal');
        if (null !== ($row['cancel_requested_at'] ?? null)) return $this->blocked('cancel_already_requested');

        $next = $revision + 1; $now = $this->databaseNow();
        $immediate = in_array($state, [\App\Application\Backup\Operations\BackupRequestState::Pending, \App\Application\Backup\Operations\BackupRequestState::RetryWait], true);
        $affected = $this->connection->executeStatement($immediate
            ? "UPDATE backup_requests SET state='cancelled', cancel_requested_at=:now, terminal_code='manual_cancel', terminal_at=:now, revision=:revision, updated_at=:now WHERE id=:id AND revision=:expected AND state IN ('pending','retry_wait')"
            : "UPDATE backup_requests SET cancel_requested_at=:now, revision=:revision, updated_at=:now WHERE id=:id AND revision=:expected AND state IN ('leased','starting','running','reconcile_required') AND cancel_requested_at IS NULL",
            ['now' => $now, 'revision' => $next, 'expected' => $revision, 'id' => $command->subjectId],
            ['revision' => ParameterType::INTEGER, 'expected' => ParameterType::INTEGER, 'id' => ParameterType::BINARY]);
        if (1 !== $affected) throw new RuntimeException('The cancel transition lost its revision authority.');
        $sequence = $this->connection->fetchOne('SELECT COALESCE(MAX(sequence_no), 0) + 1 FROM backup_request_events WHERE request_id = :id',
            ['id' => $command->subjectId], ['id' => ParameterType::BINARY]);
        $this->requestEvent($command->subjectId, $this->integer($sequence), $immediate ? 'cancelled' : 'cancel_requested', $immediate ? 'cancelled' : $state->value, $now, null);

        return new BackupOperationCommandResult(BackupOperationCommandStatus::Applied, $next);
    }

    private function idempotency(BackupOperationCommand $command, AuthenticatedPrincipal $principal, bool $lock): ?BackupOperationCommandResult
    {
        $row = $this->connection->fetchAssociative('SELECT payload_hash, result_status, result_revision, blocker_code FROM backup_operation_commands WHERE actor_user_id = :actor AND idempotency_key = :key'.($lock ? ' FOR UPDATE' : ''),
            ['actor' => $principal->userId->binary(), 'key' => $command->idempotencyKey], ['actor' => ParameterType::BINARY]);
        if (false === $row) return null;
        $revision = $this->nullableInteger($row['result_revision'] ?? null);
        if (!hash_equals($this->bytes($row['payload_hash'] ?? null, 32), $command->payloadHash)) {
            return new BackupOperationCommandResult(BackupOperationCommandStatus::Conflict, $revision ?? 1);
        }
        return match ($this->text($row['result_status'] ?? null)) {
            'applied' => new BackupOperationCommandResult(BackupOperationCommandStatus::Replayed, $revision),
            'conflict' => new BackupOperationCommandResult(BackupOperationCommandStatus::Conflict, $revision),
            'blocked' => new BackupOperationCommandResult(BackupOperationCommandStatus::Blocked, null, $this->text($row['blocker_code'] ?? null)),
            default => throw new RuntimeException('Invalid persisted operation result.'),
        };
    }

    private function persistIdempotency(BackupOperationCommand $command, AuthenticatedPrincipal $principal, BackupOperationCommandResult $result): void
    {
        $this->connection->insert('backup_operation_commands', [
            'actor_user_id' => $principal->userId->binary(), 'idempotency_key' => $command->idempotencyKey,
            'command_type' => $command->type->value, 'subject_id' => $command->subjectId,
            'payload_hash' => $command->payloadHash, 'result_status' => $result->status->value,
            'result_revision' => $result->revision, 'blocker_code' => $result->blocker, 'created_at' => $this->databaseNow(),
        ], ['actor_user_id'=>ParameterType::BINARY,'subject_id'=>ParameterType::BINARY,'payload_hash'=>ParameterType::BINARY]);
    }

    private function audit(BackupOperationCommand $command, AuthenticatedPrincipal $principal): void
    {
        $this->connection->insert('audit_events', [
            'id' => $this->ids->generate(), 'occurred_at' => $this->databaseNow(),
            'actor_user_id' => $principal->userId->binary(), 'actor_session_id' => $principal->sessionId,
            'event_type' => BackupOperationCommandType::ManualRequest === $command->type ? 'manual_backup_requested' : 'backup_cancel_requested',
            'outcome' => 'succeeded', 'subject_type' => 'backup_request', 'subject_id' => $command->subjectId,
            'reason_code' => null, 'correlation_id' => $command->correlationId,
        ], ['id'=>ParameterType::BINARY,'actor_user_id'=>ParameterType::BINARY,'actor_session_id'=>ParameterType::BINARY,'subject_id'=>ParameterType::BINARY,'correlation_id'=>ParameterType::BINARY]);
    }

    private function requestEvent(string $requestId, int $sequence, string $type, string $state, string $at, ?string $detail): void
    {
        $this->connection->insert('backup_request_events', [
            'id'=>$this->ids->generate(),'request_id'=>$requestId,'sequence_no'=>$sequence,'event_type'=>$type,
            'state'=>$state,'claim_fence'=>null,'occurred_at'=>$at,'detail_code'=>$detail,
        ], ['id'=>ParameterType::BINARY,'request_id'=>ParameterType::BINARY]);
    }

    private function blocked(string $code): BackupOperationCommandResult { return new BackupOperationCommandResult(BackupOperationCommandStatus::Blocked, null, $code); }
    private function databaseNow(): string { return $this->clock->now()->setTimezone(new DateTimeZone('UTC'))->format(self::DATE_FORMAT); }
    private function binary(mixed $value): string { if (!is_string($value) || 16 !== strlen($value)) throw new RuntimeException('Invalid operation binary value.'); return $value; }
    private function bytes(mixed $value, int $length): string { if (!is_string($value) || $length !== strlen($value)) throw new RuntimeException('Invalid operation byte value.'); return $value; }
    private function text(mixed $value): string { if (!is_string($value) || '' === $value) throw new RuntimeException('Invalid operation text value.'); return $value; }
    private function integer(mixed $value): int { if (is_int($value) && $value >= 0) return $value; if (!is_string($value) || !ctype_digit($value)) throw new RuntimeException('Invalid operation integer value.'); return (int) $value; }
    private function nullableInteger(mixed $value): ?int { return null === $value ? null : $this->integer($value); }
    private function nullableDecimal(mixed $value): ?string { if (null === $value) return null; if (!is_int($value) && !is_string($value)) throw new RuntimeException('Invalid operation decimal.'); return (string) $value; }
    private function failureRecipients(mixed $value): FailureNotificationRecipients
    {
        if (!is_string($value)) throw new RuntimeException('Missing failure notification recipients.');
        $decoded = json_decode($value, true, 8, JSON_THROW_ON_ERROR);
        if (!is_array($decoded) || !array_is_list($decoded) || [] !== array_filter($decoded, static fn (mixed $address): bool => !is_string($address))) throw new RuntimeException('Invalid failure notification recipients.');
        /** @var list<string> $decoded */
        return new FailureNotificationRecipients($decoded);
    }
    /** @param array<string, mixed> $row */
    private function retention(array $row, string $prefix): ?RetentionPolicy
    {
        $legacy = $this->nullableInteger($row[$prefix.'legacy_maxfiles'] ?? null);
        if (null !== $legacy) return RetentionPolicy::legacyMaxFiles($legacy);
        $keys = ['keep_all','keep_last','keep_hourly','keep_daily','keep_weekly','keep_monthly','keep_yearly'];
        if ([] === array_filter($keys, fn (string $key): bool => null !== ($row[$prefix.$key] ?? null))) return null;
        return RetentionPolicy::prune(
            null === ($row[$prefix.'keep_all'] ?? null) ? null : 1 === $this->integer($row[$prefix.'keep_all']),
            ...array_map(fn (string $key): ?int => $this->nullableInteger($row[$prefix.$key] ?? null), array_slice($keys, 1)),
        );
    }
}
