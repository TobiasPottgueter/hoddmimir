<?php

declare(strict_types=1);

namespace App\Tests\Integration\Database;

use App\Application\Collector\CollectorCycleToken;
use App\Application\Collector\CollectorLease;
use App\Application\Collector\CollectorWorkerId;
use App\Application\Backup\Queue\ClaimNextBackupCommand;
use App\Application\Backup\Queue\ExpectedBackupSize;
use App\Application\Backup\Queue\FinalizeClaimedBackupCommand;
use App\Application\Backup\Queue\QueueClaimTokenSource;
use App\Application\Backup\Queue\ShadowPromotion;
use App\Application\Backup\Execution\SubmitClaimedBackupCommand;
use App\Application\Backup\Execution\SubmitClaimedBackup;
use App\Application\Backup\Execution\BackupExecutionGate;
use App\Application\Backup\Execution\SubmissionPreparationStatus;
use App\Application\Backup\Execution\DefinitiveBackupFailureNotice;
use App\Infrastructure\Persistence\MariaDb\DbalBackupProblemRecorder;
use App\Infrastructure\Persistence\MariaDb\DbalBackupSubmissionStore;
use App\Infrastructure\Persistence\MariaDb\DbalAmbiguousSubmissionReconciliationStore;
use App\Infrastructure\Persistence\MariaDb\DbalBackupMonitoringStore;
use App\Infrastructure\Persistence\MariaDb\DbalBackupWorkerHeartbeatStore;
use App\Application\Backup\Monitoring\ReconcileAmbiguousSubmissionCommand;
use App\Application\Backup\Monitoring\ReconcileAmbiguousSubmission;
use App\Application\Backup\Monitoring\AmbiguousSubmissionEvidence;
use App\Application\Backup\Monitoring\AmbiguousSubmissionIdentity;
use App\Application\Backup\Monitoring\AmbiguousSubmissionTaskSource;
use App\Application\Backup\Monitoring\MonitorClaimedBackupCommand;
use App\Application\Backup\Monitoring\StopAttemptDisposition;
use App\Application\Proxmox\Pve\PveTaskLogEntry;
use App\Application\Proxmox\Pve\PveTaskLogPage;
use App\Application\Proxmox\Pve\PveTaskLogQuery;
use App\Application\Proxmox\Pve\PveTaskStopStatus;
use App\Application\Proxmox\Pve\PveUpid;
use App\Application\Proxmox\Pve\PveBackupApiFailureCode;
use App\Application\Proxmox\Pve\PveBackupClient;
use App\Application\Proxmox\Pve\PveBackupClientProvider;
use App\Application\Proxmox\Pve\PveBackupSubmission;
use App\Application\Proxmox\Pve\PveBackupSubmissionResult;
use App\Application\Proxmox\Pve\PveBackupTask;
use App\Application\Proxmox\Pve\PveTaskLifecycle;
use App\Application\Proxmox\Pve\PveTaskPage;
use App\Application\Proxmox\Pve\PveTaskQuery;
use App\Application\Proxmox\Pve\PveTaskSource;
use App\Application\Proxmox\Pve\PveTaskStatus;
use App\Application\Proxmox\Pve\PveTaskStopResult;
use App\Application\Proxmox\Pve\PveGuestType;
use App\Domain\Backup\ControlledRetryPolicy;
use App\Domain\Backup\MonitoringOutcome;
use App\Domain\Backup\RecoveryOutcome;
use App\Infrastructure\Persistence\MariaDb\DbalBackupQueueStore;
use App\Infrastructure\Persistence\MariaDb\DbalAutomaticShadowEvaluationSource;
use App\Domain\Scheduler\EvidenceFreshnessPolicy;
use Doctrine\DBAL\DriverManager;
use Throwable;
use DateTimeImmutable;
use DateTimeZone;
use App\Tests\Fakes\FrozenClock;

final class DbalBackupQueueStoreTest extends DatabaseTestCase
{
    private DateTimeImmutable $now;

    protected function setUp(): void
    {
        parent::setUp();
        $value = $this->connection()->fetchOne('SELECT UTC_TIMESTAMP(6)');
        self::assertIsString($value);
        $this->now = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s.u', $value, new DateTimeZone('UTC'))
            ?: throw new \RuntimeException('Could not read MariaDB UTC time.');
        $this->seedFixture();
    }

    public function testPromotionClaimEqualityFenceAndTerminalReleaseAreAtomic(): void
    {
        $this->setAllEvidenceAge(300);
        $store = $this->store();
        $promotion = new ShadowPromotion(
            self::id('request-a'),
            self::id('decision-a'),
            $this->now,
            '{"mode":"snapshot"}',
            hash('sha256', '{"mode":"snapshot"}', true),
        );
        self::assertSame(self::id('request-a'), $store->promote($promotion));
        self::assertSame(self::id('request-a'), $store->promote(new ShadowPromotion(
            self::id('ignored-id'),
            self::id('decision-a'),
            $this->now,
            '{"mode":"snapshot"}',
            hash('sha256', '{"mode":"snapshot"}', true),
        )));

        $claim = $store->claim(new ClaimNextBackupCommand(self::id('worker-a'), $this->now));
        self::assertNotNull($claim, 'Available bytes exactly equal minimum-free plus expected size.');
        self::assertSame(1, $claim->claimFence);
        self::assertSame('1000', $claim->expectedSizeBytes);
        self::assertSame('1', $this->numeric($this->connection()->fetchOne('SELECT slots_used FROM backup_node_slots')));
        self::assertSame('1', $this->numeric($this->connection()->fetchOne('SELECT slots_used FROM backup_target_slots')));
        self::assertSame('1000', $this->numeric($this->connection()->fetchOne('SELECT reserved_bytes FROM backup_capacity_reservations')));

        self::assertFalse($store->finalize(new FinalizeClaimedBackupCommand(
            $claim->id,
            str_repeat('x', 16),
            $claim->claimFence,
            'cancelled',
            $this->now,
        )));
        self::assertSame('1', $this->numeric($this->connection()->fetchOne('SELECT slots_used FROM backup_node_slots')));

        self::assertTrue($store->finalize(new FinalizeClaimedBackupCommand(
            $claim->id,
            $claim->claimToken,
            $claim->claimFence,
            'cancelled',
            $this->now,
        )));
        self::assertSame('0', $this->numeric($this->connection()->fetchOne('SELECT slots_used FROM backup_node_slots')));
        self::assertSame('0', $this->numeric($this->connection()->fetchOne('SELECT slots_used FROM backup_target_slots')));
        self::assertSame('cancelled', $this->connection()->fetchOne('SELECT state FROM backup_requests'));
        self::assertSame('3', $this->numeric($this->connection()->fetchOne('SELECT COUNT(*) FROM backup_request_events')));
        self::assertNotNull($this->connection()->fetchOne('SELECT released_at FROM backup_capacity_reservations'));
    }

    public function testAutomaticShadowSourceProjectsExecutorEvidenceFailClosed(): void
    {
        $source = new DbalAutomaticShadowEvaluationSource($this->connection());
        $candidate = $this->shadowCandidate($source);
        self::assertTrue($candidate->executorAuthorized);
        self::assertEquals($this->now, $candidate->executorObservedAt);

        $this->connection()->update('executor_permission_evidence', [
            'observed_at' => self::format($this->now->modify('-301 seconds')),
        ], ['guest_id' => self::id('guest')]);
        $stale = $this->shadowCandidate($source);
        self::assertTrue($stale->executorAuthorized);
        self::assertEquals($this->now->modify('-301 seconds'), $stale->executorObservedAt);

        $this->connection()->update('executor_permission_evidence', [
            'vm_backup_authorized' => 0,
            'authorized' => 0,
            'observed_at' => self::format($this->now),
        ], ['guest_id' => self::id('guest')]);
        $unauthorized = $this->shadowCandidate($source);
        self::assertFalse($unauthorized->executorAuthorized);
        self::assertEquals($this->now, $unauthorized->executorObservedAt);

        $this->connection()->delete('executor_permission_evidence', ['guest_id' => self::id('guest')]);
        $missing = $this->shadowCandidate($source);
        self::assertFalse($missing->executorAuthorized);
        self::assertNull($missing->executorObservedAt);
    }

    public function testAutomaticShadowSourceClosesDuplicatesForNonTerminalRequestsOnly(): void
    {
        $source = new DbalAutomaticShadowEvaluationSource($this->connection());
        self::assertTrue($this->shadowCandidate($source)->activeRequestAbsent);

        $requestId = $this->store()->promote(new ShadowPromotion(
            self::id('request-shadow-source'),
            self::id('decision-a'),
            $this->now,
            '{"mode":"snapshot"}',
            hash('sha256', '{"mode":"snapshot"}', true),
        ));
        self::assertFalse($this->shadowCandidate($source)->activeRequestAbsent);

        $this->connection()->update('backup_requests', [
            'state' => 'cancelled',
            'terminal_code' => 'cancelled_before_claim',
            'terminal_at' => self::format($this->now),
            'updated_at' => self::format($this->now),
        ], ['id' => $requestId]);
        self::assertTrue($this->shadowCandidate($source)->activeRequestAbsent);
    }

    public function testAutomaticShadowSourceUsesPhaseFiveNodeAndTargetSlotSemantics(): void
    {
        $source = new DbalAutomaticShadowEvaluationSource($this->connection());
        $initial = $this->shadowCandidate($source);
        self::assertTrue($initial->nodeConcurrencyAvailable);
        self::assertTrue($initial->targetConcurrencyAvailable);

        $now = self::format($this->now);
        $this->connection()->insert('backup_node_slots', [
            'node_id' => self::id('node'), 'connection_id' => self::id('connection'),
            'cluster_id' => self::id('cluster'), 'slot_limit' => 1, 'slots_used' => 1,
            'revision' => 1, 'updated_at' => $now,
        ]);
        $nodeBlocked = $this->shadowCandidate($source);
        self::assertFalse($nodeBlocked->nodeConcurrencyAvailable);
        self::assertTrue($nodeBlocked->targetConcurrencyAvailable);

        $this->connection()->delete('backup_node_slots', ['node_id' => self::id('node')]);
        $this->connection()->insert('backup_target_slots', [
            'target_id' => self::id('target'), 'connection_id' => self::id('connection'),
            'cluster_id' => self::id('cluster'), 'slot_limit' => 1, 'slots_used' => 1,
            'revision' => 1, 'updated_at' => $now,
        ]);
        $targetBlocked = $this->shadowCandidate($source);
        self::assertTrue($targetBlocked->nodeConcurrencyAvailable);
        self::assertFalse($targetBlocked->targetConcurrencyAvailable);
    }

    public function testAutomaticShadowSourceRequiresEnabledPbsConnectionAndExactEnabledEndpoint(): void
    {
        $this->seedPbsShadowEvidence();
        $source = new DbalAutomaticShadowEvaluationSource($this->connection());
        self::assertTrue($this->shadowCandidate($source)->pbsMappingValid);

        $this->connection()->update('proxmox_connections', ['enabled' => 0], ['id' => self::id('pbs-connection')]);
        self::assertFalse($this->shadowCandidate($source)->pbsMappingValid);

        $this->connection()->update('proxmox_connections', ['enabled' => 1], ['id' => self::id('pbs-connection')]);
        $this->connection()->update('proxmox_connection_endpoints', ['enabled' => 0], ['id' => self::id('pbs-endpoint')]);
        self::assertFalse($this->shadowCandidate($source)->pbsMappingValid);

        $this->connection()->update('proxmox_connection_endpoints', [
            'enabled' => 1, 'host' => 'other-pbs.example.test', 'port' => 8008,
        ], ['id' => self::id('pbs-endpoint')]);
        self::assertFalse($this->shadowCandidate($source)->pbsMappingValid);

        $this->connection()->update('proxmox_connection_endpoints', [
            'host' => 'pbs.example.test', 'port' => 8007,
        ], ['id' => self::id('pbs-endpoint')]);
        self::assertTrue($this->shadowCandidate($source)->pbsMappingValid);
        self::assertCount(2, $source->candidates($this->shadowLease()), 'EXISTS must not duplicate candidates.');
    }

    public function testStaleRevalidationDefersWithoutLeakingResources(): void
    {
        $store = $this->store();
        $store->promote(new ShadowPromotion(
            self::id('request-a'),
            self::id('decision-a'),
            $this->now,
            '{"mode":"snapshot"}',
            hash('sha256', '{"mode":"snapshot"}', true),
        ));
        $this->connection()->update('executor_permission_evidence', [
            'observed_at' => self::format($this->now->modify('-301 seconds')),
        ], ['connection_id' => self::id('connection')]);

        self::assertNull($store->claim(new ClaimNextBackupCommand(self::id('worker-a'), $this->now)));
        self::assertSame('retry_wait', $this->connection()->fetchOne('SELECT state FROM backup_requests'));
        self::assertSame('0', $this->numeric($this->connection()->fetchOne('SELECT COUNT(*) FROM backup_capacity_reservations')));
        self::assertSame('0', $this->numeric($this->connection()->fetchOne('SELECT COUNT(*) FROM backup_node_slots')));
        self::assertSame('0', $this->numeric($this->connection()->fetchOne('SELECT COUNT(*) FROM backup_target_slots')));
    }

    public function testActiveTakeoverPreservesTheExistingRunIdentityForEveryRecoveryState(): void
    {
        $store = $this->store();
        $store->promote(new ShadowPromotion(
            self::id('request-a'), self::id('decision-a'), $this->now,
            '{"mode":"snapshot"}', hash('sha256', '{"mode":"snapshot"}', true),
        ));
        $initial = $store->claim(new ClaimNextBackupCommand(self::id('worker-a'), $this->now));
        self::assertNotNull($initial);
        self::assertNull($initial->runId);

        $runId = self::id('backup-run');
        $this->connection()->insert('backup_runs', [
            'id' => $runId,
            'request_id' => $initial->id,
            'root_request_id' => $initial->id,
            'attempt' => 1,
            'state' => 'awaiting_submission',
            'claim_token' => $initial->claimToken,
            'claim_fence' => $initial->claimFence,
            'submission_provenance' => 'not_submitted',
            'started_at' => self::format($this->now),
        ]);
        $this->connection()->createSavepoint('active_takeover_case');

        foreach ([
            'starting' => ['awaiting_submission', 'not_submitted', null],
            'running' => ['running', 'accepted', 'UPID:node-a:0000002A:000F4240:67000000:vzdump:100:backup@pve:'],
            'reconcile_required' => ['reconcile_required', 'ambiguous', null],
        ] as $requestState => [$runState, $provenance, $upid]) {
            $this->connection()->update('backup_runs', [
                'state' => $runState,
                'submission_provenance' => $provenance,
                'upid' => $upid,
                'upid_hash' => null === $upid ? null : hash('sha256', $upid, true),
            ], ['id' => $runId]);
            $this->connection()->update('backup_requests', [
                'state' => $requestState,
                'run_id' => $runId,
                'lease_issued_at' => self::format($this->now->modify('-2 seconds')),
                'lease_expires_at' => self::format($this->now->modify('-1 second')),
            ], ['id' => $initial->id]);

            $takenOver = $store->claim(new ClaimNextBackupCommand(
                self::id('takeover-'.$requestState),
                $this->now,
            ));
            self::assertNotNull($takenOver);
            self::assertSame($requestState, $takenOver->state);
            self::assertSame($runId, $takenOver->runId);
            self::assertSame(2, $takenOver->claimFence);

            $this->connection()->rollbackSavepoint('active_takeover_case');
        }
    }

    public function testOwnedActiveWorkIsReturnedBeforePendingWithoutChangingItsFence(): void
    {
        $store = $this->store();
        $store->promote(new ShadowPromotion(
            self::id('request-a'), self::id('decision-a'), $this->now,
            '{"mode":"snapshot"}', hash('sha256', '{"mode":"snapshot"}', true),
        ));
        $initial = $store->claim(new ClaimNextBackupCommand(self::id('worker-a'), $this->now));
        self::assertNotNull($initial);
        $runId = self::id('owned-run');
        $this->connection()->insert('backup_runs', [
            'id' => $runId, 'request_id' => $initial->id, 'root_request_id' => $initial->id,
            'attempt' => 1, 'state' => 'awaiting_submission', 'claim_token' => $initial->claimToken,
            'claim_fence' => $initial->claimFence, 'submission_provenance' => 'not_submitted',
            'started_at' => self::format($this->now),
        ]);
        $this->connection()->update('backup_requests', ['state' => 'starting', 'run_id' => $runId], ['id' => $initial->id]);
        $store->promote(new ShadowPromotion(
            self::id('request-b'), self::id('decision-b'), $this->now,
            '{"mode":"snapshot"}', hash('sha256', '{"mode":"snapshot"}', true),
        ));

        $owned = $store->claim(new ClaimNextBackupCommand(self::id('worker-a'), $this->now->modify('+1 second')));
        self::assertNotNull($owned);
        self::assertSame($initial->id, $owned->id);
        self::assertSame('starting', $owned->state);
        self::assertSame($runId, $owned->runId);
        self::assertSame($initial->claimToken, $owned->claimToken);
        self::assertSame($initial->claimFence, $owned->claimFence);

        $foreign = $store->claim(new ClaimNextBackupCommand(self::id('worker-b'), $this->now->modify('+1 second')));
        self::assertNull($foreign, 'A foreign worker must not receive another worker\'s valid lease.');
    }

    public function testDisabledExecutionAndCancelledPendingRequestsCannotCreateNewClaims(): void
    {
        $store = $this->store();
        $store->promote(new ShadowPromotion(
            self::id('request-a'), self::id('decision-a'), $this->now,
            '{"mode":"snapshot"}', hash('sha256', '{"mode":"snapshot"}', true),
        ));
        self::assertNull($store->claim(new ClaimNextBackupCommand(
            self::id('worker-a'), $this->now, allowNewClaims: false,
        )));
        $this->connection()->update('backup_requests', ['cancel_requested_at' => self::format($this->now)], ['id' => self::id('request-a')]);
        self::assertNull($store->claim(new ClaimNextBackupCommand(self::id('worker-a'), $this->now)));
        self::assertSame('pending', $this->connection()->fetchOne('SELECT state FROM backup_requests WHERE id=:id', ['id' => self::id('request-a')]));
        self::assertSame('0', $this->numeric($this->connection()->fetchOne('SELECT COUNT(*) FROM backup_capacity_reservations')));
    }

    public function testPreSubmitRevalidationDefersAndReleasesWhenSelectionChanges(): void
    {
        $store = $this->store();
        $policy = '{"mode":"snapshot","compression":"zstd","desiredRetention":{"maxfiles":1}}';
        $store->promote(new ShadowPromotion(
            self::id('request-a'), self::id('decision-a'), $this->now,
            '{"mode":"snapshot"}', hash('sha256', '{"mode":"snapshot"}', true),
        ));
        $claim = $store->claim(new ClaimNextBackupCommand(self::id('worker-a'), $this->now));
        self::assertNotNull($claim);
        $this->seedBackupCredential();
        $this->connection()->update('backup_requests', [
            'resolved_policy_json' => $policy,
            'resolved_policy_hash' => hash('sha256', $policy, true),
        ], ['id' => $claim->id]);
        $this->connection()->update('backup_policy_assignments', [
            'selection_value' => 'exclude',
        ], ['id' => self::id('global-include')]);
        $submission = new DbalBackupSubmissionStore(
            $this->connection(), new DbalBackupProblemRecorder(),
        );

        $result = $submission->prepareAfterFullRevalidation(new SubmitClaimedBackupCommand(
            $claim->id, self::id('blocked-run'), $claim->claimToken, $claim->claimFence, $this->now,
        ));

        self::assertSame(SubmissionPreparationStatus::Blocked, $result->status);
        self::assertSame('eligibility_changed', $result->blockerCode);
        self::assertSame('retry_wait', $this->connection()->fetchOne('SELECT state FROM backup_requests WHERE id=:id', ['id' => $claim->id]));
        self::assertNull($this->connection()->fetchOne('SELECT claim_token FROM backup_requests WHERE id=:id', ['id' => $claim->id]));
        self::assertSame('0', $this->numeric($this->connection()->fetchOne('SELECT slots_used FROM backup_node_slots')));
        self::assertSame('0', $this->numeric($this->connection()->fetchOne('SELECT slots_used FROM backup_target_slots')));
        self::assertNotNull($this->connection()->fetchOne('SELECT released_at FROM backup_capacity_reservations WHERE request_id=:id', ['id' => $claim->id]));
        self::assertSame('0', $this->numeric($this->connection()->fetchOne('SELECT COUNT(*) FROM backup_runs')));
    }

    public function testPreSubmitCancellationReleasesClaimWithoutCreatingAPveRun(): void
    {
        $store = $this->store();
        $store->promote(new ShadowPromotion(
            self::id('request-a'), self::id('decision-a'), $this->now,
            '{"mode":"snapshot"}', hash('sha256', '{"mode":"snapshot"}', true),
        ));
        $claim = $store->claim(new ClaimNextBackupCommand(self::id('worker-a'), $this->now));
        self::assertNotNull($claim);
        $this->seedBackupCredential();
        $this->connection()->update('backup_requests', [
            'cancel_requested_at' => self::format($this->now),
        ], ['id' => $claim->id]);
        $submission = new DbalBackupSubmissionStore(
            $this->connection(), new DbalBackupProblemRecorder(),
        );

        $result = $submission->prepareAfterFullRevalidation(new SubmitClaimedBackupCommand(
            $claim->id, self::id('cancelled-run'), $claim->claimToken, $claim->claimFence, $this->now,
        ));

        self::assertSame(SubmissionPreparationStatus::Blocked, $result->status);
        self::assertSame('cancel_requested', $result->blockerCode);
        self::assertSame('cancelled', $this->connection()->fetchOne('SELECT state FROM backup_requests WHERE id=:id', ['id' => $claim->id]));
        self::assertNotNull($this->connection()->fetchOne('SELECT terminal_at FROM backup_requests WHERE id=:id', ['id' => $claim->id]));
        self::assertSame('0', $this->numeric($this->connection()->fetchOne('SELECT COUNT(*) FROM backup_runs')));
    }

    public function testPreparedSubmissionPersistsAcceptedAmbiguousAndDefinitiveOutcomesExactlyOnce(): void
    {
        $store = $this->store();
        $store->promote(new ShadowPromotion(
            self::id('request-a'), self::id('decision-a'), $this->now,
            '{"mode":"snapshot"}', hash('sha256', '{"mode":"snapshot"}', true),
        ));
        $claim = $store->claim(new ClaimNextBackupCommand(self::id('worker-a'), $this->now));
        self::assertNotNull($claim);
        $this->seedBackupCredential();
        $policy = '{"mode":"snapshot","compression":"zstd","desiredRetention":{"maxfiles":1}}';
        $this->connection()->update('backup_requests', [
            'resolved_policy_json' => $policy,
            'resolved_policy_hash' => hash('sha256', $policy, true),
        ], ['id' => $claim->id]);
        $submission = new DbalBackupSubmissionStore($this->connection(), new DbalBackupProblemRecorder());
        $run = self::id('submission-run');
        $command = new SubmitClaimedBackupCommand($claim->id, $run, $claim->claimToken, $claim->claimFence, $this->now);
        self::assertSame(\App\Application\Backup\Execution\ExistingSubmissionStatus::FreshClaim, $submission->inspectExistingSubmission($command));
        self::assertSame(SubmissionPreparationStatus::PreparedNow, $submission->prepareAfterFullRevalidation($command)->status);
        self::assertSame(\App\Application\Backup\Execution\ExistingSubmissionStatus::RecoveryRequired, $submission->inspectExistingSubmission($command));
        $this->connection()->createSavepoint('submission_outcome');

        $upid = PveUpid::parse('UPID:node-a:0000002A:000F4240:67000000:vzdump:100:backup@pve:');
        $submission->recordAccepted($command, $upid);
        self::assertSame('running', $this->connection()->fetchOne('SELECT state FROM backup_requests WHERE id=:id', ['id' => $claim->id]));
        $this->connection()->rollbackSavepoint('submission_outcome');

        $submission->recordAmbiguous($command, PveBackupApiFailureCode::Transport);
        self::assertSame('reconcile_required', $this->connection()->fetchOne('SELECT state FROM backup_requests WHERE id=:id', ['id' => $claim->id]));
        self::assertSame('1', $this->numeric($this->connection()->fetchOne("SELECT COUNT(*) FROM backup_notification_outbox WHERE notification_kind='attention_required'")));
        $this->connection()->rollbackSavepoint('submission_outcome');

        $submission->recordDefinitiveRejection(
            $command,
            PveBackupApiFailureCode::PermissionDenied,
            new DefinitiveBackupFailureNotice(
                1, self::id('guest'), 'guest-a', 100, PveGuestType::Qemu, 'node-a',
                self::id('target'), 'Target', PveBackupApiFailureCode::PermissionDenied,
                $this->now, $this->now->modify('+60 seconds'),
            ),
        );
        self::assertSame('failed', $this->connection()->fetchOne('SELECT state FROM backup_requests WHERE id=:id', ['id' => $claim->id]));
        self::assertSame('1', $this->numeric($this->connection()->fetchOne("SELECT COUNT(*) FROM backup_requests WHERE origin='retry'")));
        self::assertSame('1', $this->numeric($this->connection()->fetchOne("SELECT COUNT(*) FROM backup_notification_outbox WHERE notification_kind='failure'")));
    }

    public function testPreparedSubmissionOmitsDesiredRetentionWithoutDeletionApproval(): void
    {
        $prepared = $this->prepareSubmissionForPolicy(
            '{"version":2,"mode":"snapshot","compression":"zstd","desiredRetention":{"maxfiles":7},"approvedDeletionRetention":null,"failureNotificationRecipients":[]}',
        );

        self::assertNull($prepared->payload->legacyMaxFiles);
        self::assertNull($prepared->payload->pruneBackups);
    }

    public function testPreparedSubmissionIncludesOnlyApprovedDeletionRetention(): void
    {
        $prepared = $this->prepareSubmissionForPolicy(
            '{"version":2,"mode":"snapshot","compression":"zstd","desiredRetention":{"prune-backups":{"keep-last":30}},"approvedDeletionRetention":{"prune-backups":{"keep-last":3,"keep-daily":7}},"failureNotificationRecipients":[]}',
        );

        self::assertNull($prepared->payload->legacyMaxFiles);
        self::assertNotNull($prepared->payload->pruneBackups);
        self::assertSame(3, $prepared->payload->pruneBackups->keepLast);
        self::assertSame(7, $prepared->payload->pruneBackups->keepDaily);
    }

    public function testPreSubmitFreshnessAcceptsTheExactMicrosecondBoundary(): void
    {
        $this->setAllEvidenceAge(300);

        $prepared = $this->prepareSubmissionForPolicy(
            '{"version":2,"mode":"snapshot","compression":"zstd","desiredRetention":{"maxfiles":7},"approvedDeletionRetention":null,"failureNotificationRecipients":[]}',
        );

        self::assertSame(100, $prepared->payload->vmid);
    }

    public function testPreSubmitFreshnessRejectsTheFirstMicrosecondAfterTheBoundary(): void
    {
        $policy = '{"version":2,"mode":"snapshot","compression":"zstd","desiredRetention":{"maxfiles":7},"approvedDeletionRetention":null,"failureNotificationRecipients":[]}';
        $store = $this->store();
        $store->promote(new ShadowPromotion(
            self::id('request-stale'), self::id('decision-a'), $this->now,
            '{"mode":"snapshot"}', hash('sha256', '{"mode":"snapshot"}', true),
        ));
        $claim = $store->claim(new ClaimNextBackupCommand(self::id('worker-a'), $this->now));
        self::assertNotNull($claim);
        $this->seedBackupCredential();
        $this->connection()->update('backup_requests', [
            'resolved_policy_json' => $policy,
            'resolved_policy_hash' => hash('sha256', $policy, true),
        ], ['id' => $claim->id]);
        $staleAt = self::format($this->now->modify('-300 seconds')->modify('-1 microsecond'));
        $this->connection()->update(
            'executor_permission_evidence',
            ['observed_at' => $staleAt],
            ['connection_id' => self::id('connection')],
        );
        $submission = new DbalBackupSubmissionStore($this->connection(), new DbalBackupProblemRecorder());

        $prepared = $submission->prepareAfterFullRevalidation(new SubmitClaimedBackupCommand(
            $claim->id,
            self::id('stale-run'),
            $claim->claimToken,
            $claim->claimFence,
            $this->now,
        ));

        self::assertSame(SubmissionPreparationStatus::Blocked, $prepared->status);
        self::assertSame('executor_seen_stale', $prepared->blockerCode);
        self::assertSame('retry_wait', $this->connection()->fetchOne(
            'SELECT state FROM backup_requests WHERE id=:id',
            ['id' => $claim->id],
        ));
    }

    public function testProvenAbsentAmbiguousSubmissionBecomesUnknownExactlyOnce(): void
    {
        $store = $this->store();
        $store->promote(new ShadowPromotion(
            self::id('request-a'), self::id('decision-a'), $this->now,
            '{"mode":"snapshot"}', hash('sha256', '{"mode":"snapshot"}', true),
        ));
        $claim = $store->claim(new ClaimNextBackupCommand(self::id('worker-a'), $this->now));
        self::assertNotNull($claim);
        $run = self::id('ambiguous-run');
        $this->connection()->insert('backup_runs', [
            'id' => $run, 'request_id' => $claim->id, 'root_request_id' => $claim->id,
            'attempt' => 1, 'state' => 'reconcile_required', 'claim_token' => $claim->claimToken,
            'claim_fence' => $claim->claimFence, 'submission_provenance' => 'ambiguous',
            'started_at' => self::format($this->now), 'submission_node' => 'node-a',
            'submission_vmid' => 100, 'submission_user' => 'backup@pve',
            'submission_window_start' => self::format($this->now->modify('-30 seconds')),
            'submission_window_end' => self::format($this->now->modify('+30 seconds')),
        ]);
        $this->connection()->update('backup_requests', [
            'state' => 'reconcile_required', 'run_id' => $run,
            'submission_provenance' => 'ambiguous', 'retry_disposition' => 'forbidden_ambiguous',
        ], ['id' => $claim->id]);
        $reconciliation = new DbalAmbiguousSubmissionReconciliationStore(
            $this->connection(), new DbalBackupProblemRecorder(),
        );
        $command = new ReconcileAmbiguousSubmissionCommand(
            $claim->id, $run, $claim->claimToken, $claim->claimFence, $this->now,
        );

        $reconciliation->record($command, RecoveryOutcome::provenNotStarted());
        $reconciliation->record($command, RecoveryOutcome::provenNotStarted());

        self::assertSame('unknown', $this->connection()->fetchOne('SELECT state FROM backup_requests WHERE id=:id', ['id' => $claim->id]));
        self::assertSame('submission_not_found', $this->connection()->fetchOne('SELECT terminal_code FROM backup_requests WHERE id=:id', ['id' => $claim->id]));
        self::assertSame('0', $this->numeric($this->connection()->fetchOne("SELECT COUNT(*) FROM backup_requests WHERE origin='retry'")));
        self::assertSame('1', $this->numeric($this->connection()->fetchOne("SELECT COUNT(*) FROM backup_notification_outbox WHERE event_key='monitoring_unknown'")));
        self::assertSame('0', $this->numeric($this->connection()->fetchOne('SELECT slots_used FROM backup_node_slots')));
        self::assertSame('0', $this->numeric($this->connection()->fetchOne('SELECT slots_used FROM backup_target_slots')));
        self::assertNull($store->claim(new ClaimNextBackupCommand(self::id('worker-a'), $this->now->modify('+5 seconds'))));
    }

    public function testReconciliationRenewsHeartbeatPersistsInconclusiveAndAdoptsOneMatchedTask(): void
    {
        $store = $this->store();
        $store->promote(new ShadowPromotion(
            self::id('request-a'), self::id('decision-a'), $this->now,
            '{"mode":"snapshot"}', hash('sha256', '{"mode":"snapshot"}', true),
        ));
        $claim = $store->claim(new ClaimNextBackupCommand(self::id('worker-a'), $this->now));
        self::assertNotNull($claim);
        $run = self::id('reconcile-run');
        $this->connection()->insert('backup_runs', [
            'id' => $run, 'request_id' => $claim->id, 'root_request_id' => $claim->id,
            'attempt' => 1, 'state' => 'reconcile_required', 'claim_token' => $claim->claimToken,
            'claim_fence' => $claim->claimFence, 'submission_provenance' => 'ambiguous',
            'started_at' => self::format($this->now), 'submission_node' => 'node-a',
            'submission_vmid' => 100, 'submission_user' => 'backup@pve',
            'submission_window_start' => self::format($this->now->modify('-30 seconds')),
            'submission_window_end' => self::format($this->now->modify('+30 seconds')),
        ]);
        $this->connection()->update('backup_requests', [
            'state' => 'reconcile_required', 'run_id' => $run,
            'submission_provenance' => 'ambiguous', 'retry_disposition' => 'forbidden_ambiguous',
        ], ['id' => $claim->id]);
        $reconciliation = new DbalAmbiguousSubmissionReconciliationStore(
            $this->connection(), new DbalBackupProblemRecorder(),
            heartbeats: new DbalBackupWorkerHeartbeatStore($this->connection(), 150, 'test'),
        );
        $command = new ReconcileAmbiguousSubmissionCommand($claim->id, $run, $claim->claimToken, $claim->claimFence, $this->now);

        self::assertTrue($reconciliation->renew($command));
        self::assertSame('reconciliation_page', $this->connection()->fetchOne("SELECT current_activity FROM worker_heartbeats WHERE worker_kind='backup'"));
        self::assertNotNull($reconciliation->prepare($command));
        $reconciliation->record($command, RecoveryOutcome::inconclusive());
        self::assertSame('inconclusive', $this->connection()->fetchOne('SELECT recovery_outcome FROM backup_runs WHERE id=:id', ['id' => $run]));

        $raw = sprintf('UPID:node-a:0000002A:000F4240:%08X:vzdump:100:backup@pve:', $this->now->getTimestamp());
        $reconciliation->record($command, RecoveryOutcome::matched(new \App\Domain\Backup\TaskUpid($raw)));
        self::assertSame('running', $this->connection()->fetchOne('SELECT state FROM backup_requests WHERE id=:id', ['id' => $claim->id]));
        self::assertSame($raw, $this->connection()->fetchOne('SELECT upid FROM backup_runs WHERE id=:id', ['id' => $run]));
        self::assertSame('0', $this->numeric($this->connection()->fetchOne("SELECT COUNT(*) FROM backup_requests WHERE origin='retry'")));

        self::assertFalse($reconciliation->renew(new ReconcileAmbiguousSubmissionCommand(
            $claim->id, $run, str_repeat('x', 16), $claim->claimFence, $this->now,
        )));
    }

    public function testMonitoringPersistsStopOnceLogsFailureRetryRecoveryAndTerminalRelease(): void
    {
        $store = $this->store();
        $store->promote(new ShadowPromotion(
            self::id('request-a'), self::id('decision-a'), $this->now,
            '{"mode":"snapshot"}', hash('sha256', '{"mode":"snapshot"}', true),
        ));
        $claim = $store->claim(new ClaimNextBackupCommand(self::id('worker-a'), $this->now));
        self::assertNotNull($claim);
        $run = self::id('monitor-failed-run');
        $upid = $this->seedAcceptedRun($claim->id, $run, $claim->claimToken, $claim->claimFence, $this->now);
        $this->connection()->update('backup_requests', ['cancel_requested_at' => self::format($this->now)], ['id' => $claim->id]);
        $monitor = new DbalBackupMonitoringStore(
            $this->connection(), new ControlledRetryPolicy(), new DbalBackupProblemRecorder(),
            heartbeats: new DbalBackupWorkerHeartbeatStore($this->connection(), 150, 'test'),
        );
        $command = new MonitorClaimedBackupCommand($claim->id, $run, $claim->claimToken, $claim->claimFence, $this->now);

        self::assertTrue($monitor->renew($command));
        self::assertSame('monitoring_io', $this->connection()->fetchOne(
            "SELECT current_activity FROM worker_heartbeats WHERE worker_kind='backup'",
        ));
        self::assertSame(StopAttemptDisposition::ReadyToClaim, $monitor->prepare($command)?->stopAttempt);
        self::assertTrue($monitor->claimStopAttempt($command, $upid));
        self::assertSame('dispatching', $this->connection()->fetchOne('SELECT stop_attempt_status FROM backup_runs WHERE id=:id', ['id' => $run]));
        $monitor->recordStopAttempt($command, $upid, PveTaskStopStatus::Requested, null);
        self::assertSame('requested', $this->connection()->fetchOne('SELECT stop_attempt_status FROM backup_runs WHERE id=:id', ['id' => $run]));
        self::assertNotFalse($this->connection()->fetchOne('SELECT stop_attempt_resolved_at FROM backup_runs WHERE id=:id', ['id' => $run]));
        self::assertSame(StopAttemptDisposition::AlreadyAttempted, $monitor->prepare($command)?->stopAttempt);
        $page = new PveTaskLogPage(new PveTaskLogQuery(0, 10), [
            new PveTaskLogEntry(0, 'starting backup'),
            new PveTaskLogEntry(1, 'synthetic failure'),
        ]);
        $monitor->appendLogPage($command, $upid, $page);
        $monitor->appendLogPage($command, $upid, $page);
        self::assertSame('2', $this->numeric($this->connection()->fetchOne('SELECT COUNT(*) FROM backup_run_log_entries WHERE run_id=:run', ['run' => $run])));

        $monitor->recordObservation($command, $upid, MonitoringOutcome::Failed, 'ERROR', null);
        self::assertSame('failed', $this->connection()->fetchOne('SELECT state FROM backup_requests WHERE id=:id', ['id' => $claim->id]));
        self::assertSame('1', $this->numeric($this->connection()->fetchOne("SELECT COUNT(*) FROM backup_requests WHERE origin='retry'")));
        self::assertSame('1', $this->numeric($this->connection()->fetchOne("SELECT COUNT(*) FROM backup_notification_outbox WHERE notification_kind='failure'")));

        $retryId = $this->connection()->fetchOne("SELECT id FROM backup_requests WHERE origin='retry'");
        self::assertIsString($retryId);
        $retryAt = $this->now->modify('+60 seconds');
        $retryClaim = $store->claim(new ClaimNextBackupCommand(self::id('worker-b'), $retryAt));
        self::assertNotNull($retryClaim);
        self::assertSame($retryId, $retryClaim->id);
        $successRun = self::id('monitor-success-run');
        $successUpid = $this->seedAcceptedRun($retryClaim->id, $successRun, $retryClaim->claimToken, $retryClaim->claimFence, $retryAt);
        $successCommand = new MonitorClaimedBackupCommand($retryClaim->id, $successRun, $retryClaim->claimToken, $retryClaim->claimFence, $retryAt);
        $monitor->recordObservation($successCommand, $successUpid, MonitoringOutcome::Succeeded, 'OK', null);

        self::assertSame('succeeded', $this->connection()->fetchOne('SELECT state FROM backup_requests WHERE id=:id', ['id' => $retryClaim->id]));
        self::assertSame('1', $this->numeric($this->connection()->fetchOne("SELECT COUNT(*) FROM backup_notification_outbox WHERE notification_kind='recovery'")));
        self::assertSame('0', $this->numeric($this->connection()->fetchOne('SELECT COUNT(*) FROM backup_problem_states')));
        $recovery = $this->connection()->fetchOne("SELECT payload_json FROM backup_notification_outbox WHERE notification_kind='recovery'");
        self::assertIsString($recovery);
        self::assertStringContainsString('"openedAt"', $recovery);
        self::assertSame('0', $this->numeric($this->connection()->fetchOne('SELECT slots_used FROM backup_node_slots')));
        self::assertSame('0', $this->numeric($this->connection()->fetchOne('SELECT slots_used FROM backup_target_slots')));
    }

    public function testOrphanedStopDispatchBecomesVisibleUnknownAndIsNeverClaimedAgain(): void
    {
        $store = $this->store();
        $store->promote(new ShadowPromotion(
            self::id('request-a'), self::id('decision-a'), $this->now,
            '{"mode":"snapshot"}', hash('sha256', '{"mode":"snapshot"}', true),
        ));
        $claim = $store->claim(new ClaimNextBackupCommand(self::id('worker-a'), $this->now));
        self::assertNotNull($claim);
        $run = self::id('orphan-stop-run');
        $upid = $this->seedAcceptedRun($claim->id, $run, $claim->claimToken, $claim->claimFence, $this->now);
        $this->connection()->update('backup_requests', ['cancel_requested_at' => self::format($this->now)], ['id' => $claim->id]);
        $monitor = new DbalBackupMonitoringStore(
            $this->connection(), new ControlledRetryPolicy(), new DbalBackupProblemRecorder(),
        );
        $command = new MonitorClaimedBackupCommand($claim->id, $run, $claim->claimToken, $claim->claimFence, $this->now);

        self::assertSame(StopAttemptDisposition::ReadyToClaim, $monitor->prepare($command)?->stopAttempt);
        self::assertTrue($monitor->claimStopAttempt($command, $upid));
        self::assertSame('dispatching', $this->connection()->fetchOne('SELECT stop_attempt_status FROM backup_runs WHERE id=:id', ['id' => $run]));

        $next = new MonitorClaimedBackupCommand(
            $claim->id, $run, $claim->claimToken, $claim->claimFence, $this->now->modify('+1 second'),
        );
        self::assertSame(StopAttemptDisposition::DispatchUnknown, $monitor->prepare($next)?->stopAttempt);
        self::assertSame('dispatch_unknown', $this->connection()->fetchOne('SELECT stop_attempt_status FROM backup_runs WHERE id=:id', ['id' => $run]));
        self::assertSame('worker_lost_after_stop_dispatch', $this->connection()->fetchOne('SELECT stop_failure_code FROM backup_runs WHERE id=:id', ['id' => $run]));
        self::assertNotFalse($this->connection()->fetchOne('SELECT stop_attempt_resolved_at FROM backup_runs WHERE id=:id', ['id' => $run]));
        self::assertSame('1', $this->numeric($this->connection()->fetchOne("SELECT COUNT(*) FROM backup_run_events WHERE run_id=:run AND event_type='stop_dispatch_unknown'", ['run' => $run])));
        self::assertSame('1', $this->numeric($this->connection()->fetchOne("SELECT COUNT(*) FROM backup_notification_outbox WHERE run_id=:run AND event_key='cancel_dispatch_unknown'", ['run' => $run])));

        $monitor->recordStopAttempt($next, $upid, PveTaskStopStatus::Requested, null);
        self::assertSame('dispatch_unknown', $this->connection()->fetchOne('SELECT stop_attempt_status FROM backup_runs WHERE id=:id', ['id' => $run]));
        self::assertSame(StopAttemptDisposition::AlreadyAttempted, $monitor->prepare($next)?->stopAttempt);
        self::assertFalse($monitor->claimStopAttempt($next, $upid));
    }

    public function testEveryStopDispatchOutcomeIsPersistedOnceWithResolvedTime(): void
    {
        $store = $this->store();
        $store->promote(new ShadowPromotion(
            self::id('request-a'), self::id('decision-a'), $this->now,
            '{"mode":"snapshot"}', hash('sha256', '{"mode":"snapshot"}', true),
        ));
        $claim = $store->claim(new ClaimNextBackupCommand(self::id('worker-a'), $this->now));
        self::assertNotNull($claim);
        $run = self::id('stop-outcome-run');
        $upid = $this->seedAcceptedRun($claim->id, $run, $claim->claimToken, $claim->claimFence, $this->now);
        $this->connection()->update('backup_requests', ['cancel_requested_at' => self::format($this->now)], ['id' => $claim->id]);
        $monitor = new DbalBackupMonitoringStore(
            $this->connection(), new ControlledRetryPolicy(), new DbalBackupProblemRecorder(),
        );
        $command = new MonitorClaimedBackupCommand($claim->id, $run, $claim->claimToken, $claim->claimFence, $this->now);
        $this->connection()->createSavepoint('stop_dispatch_outcome');

        foreach ([
            [PveTaskStopStatus::Requested, null, 'requested', null],
            [PveTaskStopStatus::Ambiguous, null, 'ambiguous', null],
            [null, PveBackupApiFailureCode::PermissionDenied, 'definitive_rejection', 'permission_denied'],
        ] as [$status, $failure, $expectedStatus, $expectedFailure]) {
            self::assertSame(StopAttemptDisposition::ReadyToClaim, $monitor->prepare($command)?->stopAttempt);
            self::assertTrue($monitor->claimStopAttempt($command, $upid));
            $monitor->recordStopAttempt($command, $upid, $status, $failure);
            $row = $this->connection()->fetchAssociative(
                'SELECT stop_attempt_status, stop_failure_code, stop_attempt_resolved_at FROM backup_runs WHERE id=:id',
                ['id' => $run],
            );
            self::assertIsArray($row);
            self::assertSame($expectedStatus, $row['stop_attempt_status'] ?? null);
            self::assertSame($expectedFailure, $row['stop_failure_code'] ?? null);
            self::assertIsString($row['stop_attempt_resolved_at'] ?? null);
            self::assertFalse($monitor->claimStopAttempt($command, $upid));
            $this->connection()->rollbackSavepoint('stop_dispatch_outcome');
        }
    }

    public function testExecutorEvidenceCannotHideWhichAclPartFailed(): void
    {
        try {
            $this->connection()->executeStatement(<<<'SQL'
UPDATE executor_permission_evidence
SET vm_backup_authorized = 0, datastore_allocate_authorized = 1, authorized = 1
WHERE guest_id = :guest
SQL, ['guest' => self::id('guest')]);
            self::fail('MariaDB accepted contradictory executor ACL evidence.');
        } catch (\Doctrine\DBAL\Exception) {
            self::addToAssertionCount(1);
        }
    }

    public function testFutureMissingAndSelectionChangesFailClosedWithoutReservations(): void
    {
        $store = $this->store();
        $store->promote(new ShadowPromotion(
            self::id('request-a'), self::id('decision-a'), $this->now, '{"mode":"snapshot"}', hash('sha256', '{"mode":"snapshot"}', true),
        ));
        $connection = $this->connection();
        $connection->createSavepoint('queue_case');

        $mutations = [
            fn () => $connection->update('executor_permission_evidence', [
                'observed_at' => self::format($this->now->modify('+1 second')),
            ], ['connection_id' => self::id('connection')]),
            fn () => $connection->delete('executor_permission_evidence', [
                'connection_id' => self::id('connection'),
            ]),
            fn () => $connection->update('backup_policy_assignments', [
                'selection_value' => 'exclude',
            ], ['id' => self::id('global-include')]),
        ];

        foreach ($mutations as $mutate) {
            $mutate();
            self::assertNull($store->claim(new ClaimNextBackupCommand(self::id('worker-a'), $this->now)));
            self::assertSame('0', $this->numeric($connection->fetchOne('SELECT COUNT(*) FROM backup_capacity_reservations')));
            self::assertSame('0', $this->numeric($connection->fetchOne('SELECT COUNT(*) FROM backup_node_slots')));
            $connection->rollbackSavepoint('queue_case');
        }
    }

    public function testAmbiguousApplicationSubmissionIsReconciledAfterTakeoverWithoutSecondPost(): void
    {
        $store = $this->store();
        $store->promote(new ShadowPromotion(
            self::id('request-a'), self::id('decision-a'), $this->now,
            '{"mode":"snapshot"}', hash('sha256', '{"mode":"snapshot"}', true),
        ));
        $claim = $store->claim(new ClaimNextBackupCommand(self::id('worker-a'), $this->now));
        self::assertNotNull($claim);
        $this->seedBackupCredential();
        $policy = '{"mode":"snapshot","compression":"zstd","desiredRetention":{"maxfiles":1}}';
        $this->connection()->update('backup_requests', [
            'resolved_policy_json' => $policy,
            'resolved_policy_hash' => hash('sha256', $policy, true),
        ], ['id' => $claim->id]);
        $journal = tempnam(sys_get_temp_dir(), 'hoddmimir-ambiguous-post-');
        self::assertIsString($journal);
        file_put_contents($journal, '');
        try {
            $run = self::id('integrated-ambiguous-run');
            $submit = new SubmitClaimedBackup(
                new IntegratedExecutionGate(),
                new DbalBackupSubmissionStore($this->connection(), new DbalBackupProblemRecorder()),
                new IntegratedJournalBackupClient($journal, true),
                new ControlledRetryPolicy(),
            );
            $result = $submit->execute(new SubmitClaimedBackupCommand(
                $claim->id, $run, $claim->claimToken, $claim->claimFence, $this->now,
            ));
            self::assertSame(\App\Application\Backup\Execution\SubmissionExecutionStatus::Ambiguous, $result->status);
            self::assertSame('P', file_get_contents($journal));

            $takeoverAt = $this->now->modify('+121 seconds');
            $takeover = $store->claim(new ClaimNextBackupCommand(self::id('worker-b'), $takeoverAt));
            self::assertNotNull($takeover);
            self::assertSame('reconcile_required', $takeover->state);
            self::assertSame($run, $takeover->runId);
            self::assertGreaterThan($claim->claimFence, $takeover->claimFence);
            $reconcile = new ReconcileAmbiguousSubmission(
                new DbalAmbiguousSubmissionReconciliationStore($this->connection(), new DbalBackupProblemRecorder()),
                new IntegratedReconciliationSource(true),
                new FrozenClock($takeoverAt),
            );
            $status = $reconcile->execute(new ReconcileAmbiguousSubmissionCommand(
                $takeover->id, $run, $takeover->claimToken, $takeover->claimFence, $takeoverAt,
            ));
            self::assertSame(\App\Application\Backup\Monitoring\ReconciliationStatus::Matched, $status);
            self::assertSame('P', file_get_contents($journal));
            self::assertSame('running', $this->connection()->fetchOne('SELECT state FROM backup_requests WHERE id=:id', ['id' => $takeover->id]));
            self::assertSame('1', $this->numeric($this->connection()->fetchOne("SELECT COUNT(*) FROM backup_run_events WHERE run_id=:run AND event_type='reconciliation_matched'", ['run' => $run])));
        } finally {
            @unlink($journal);
        }
    }

    public function testCrashBeforeApplicationDispatchReconcilesToUnknownWithoutAnyPost(): void
    {
        $store = $this->store();
        $store->promote(new ShadowPromotion(
            self::id('request-a'), self::id('decision-a'), $this->now,
            '{"mode":"snapshot"}', hash('sha256', '{"mode":"snapshot"}', true),
        ));
        $claim = $store->claim(new ClaimNextBackupCommand(self::id('worker-a'), $this->now));
        self::assertNotNull($claim);
        $this->seedBackupCredential();
        $policy = '{"mode":"snapshot","compression":"zstd","desiredRetention":{"maxfiles":1}}';
        $this->connection()->update('backup_requests', [
            'resolved_policy_json' => $policy,
            'resolved_policy_hash' => hash('sha256', $policy, true),
        ], ['id' => $claim->id]);
        $journal = tempnam(sys_get_temp_dir(), 'hoddmimir-pre-dispatch-crash-');
        self::assertIsString($journal);
        file_put_contents($journal, '');
        try {
            $run = self::id('integrated-pre-dispatch-run');
            $command = new SubmitClaimedBackupCommand(
                $claim->id, $run, $claim->claimToken, $claim->claimFence, $this->now,
            );
            $transaction = new DbalBackupSubmissionStore($this->connection(), new DbalBackupProblemRecorder());
            self::assertSame(SubmissionPreparationStatus::PreparedNow, $transaction->prepareAfterFullRevalidation($command)->status);
            self::assertSame('', file_get_contents($journal));

            $restartAt = $this->now->modify('+1 second');
            $restart = $store->claim(new ClaimNextBackupCommand(self::id('worker-a'), $restartAt));
            self::assertNotNull($restart);
            self::assertSame('starting', $restart->state);
            self::assertSame($run, $restart->runId);
            $reconcile = new ReconcileAmbiguousSubmission(
                new DbalAmbiguousSubmissionReconciliationStore($this->connection(), new DbalBackupProblemRecorder()),
                new IntegratedReconciliationSource(false),
                new FrozenClock($restartAt),
            );
            $status = $reconcile->execute(new ReconcileAmbiguousSubmissionCommand(
                $restart->id, $run, $restart->claimToken, $restart->claimFence, $restartAt,
            ));
            self::assertSame(\App\Application\Backup\Monitoring\ReconciliationStatus::ProvenNotStarted, $status);
            self::assertSame('', file_get_contents($journal));
            self::assertSame('unknown', $this->connection()->fetchOne('SELECT state FROM backup_requests WHERE id=:id', ['id' => $restart->id]));
            self::assertSame('0', $this->numeric($this->connection()->fetchOne("SELECT COUNT(*) FROM backup_requests WHERE origin='retry'")));
        } finally {
            @unlink($journal);
        }
    }

    public function testTwoWorkersCannotDoubleClaimOneNodeOrCapacityBudget(): void
    {
        if (!function_exists('pcntl_fork')) {
            self::markTestSkipped('pcntl is required for the queue race proof.');
        }
        $store = $this->store();
        $store->promote(new ShadowPromotion(
            self::id('request-a'), self::id('decision-a'), $this->now, '{"mode":"snapshot"}', hash('sha256', '{"mode":"snapshot"}', true),
        ));
        $store->promote(new ShadowPromotion(
            self::id('request-b'), self::id('decision-b'), $this->now, '{"mode":"snapshot"}', hash('sha256', '{"mode":"snapshot"}', true),
        ));
        $this->seedBackupCredential();
        $policy = '{"mode":"snapshot","compression":"zstd","desiredRetention":{"maxfiles":1}}';
        $this->connection()->executeStatement(
            'UPDATE backup_requests SET resolved_policy_json=:policy, resolved_policy_hash=:hash',
            ['policy' => $policy, 'hash' => hash('sha256', $policy, true)],
        );
        $journal = tempnam(sys_get_temp_dir(), 'hoddmimir-post-race-');
        self::assertIsString($journal);
        file_put_contents($journal, '');
        $parameters = $this->connection()->getParams();
        $this->connection()->commit();
        $children = [
            $this->startSubmissionChild($parameters, 'worker-race-a', $journal),
            $this->startSubmissionChild($parameters, 'worker-race-b', $journal),
        ];

        try {
            foreach ($children as [, $socket]) {
                self::assertSame(1, fwrite($socket, '1'));
            }
            $results = [];
            foreach ($children as [$pid, $socket]) {
                $results[] = $this->finishClaimChild($pid, $socket);
            }
            sort($results, SORT_STRING);
            self::assertSame(['none', 'submitted'], $results);
            self::assertSame('P', file_get_contents($journal));
            self::assertSame('1', $this->numeric($this->connection()->fetchOne('SELECT COUNT(*) FROM backup_runs')));
            self::assertSame('1', $this->numeric($this->connection()->fetchOne(
                "SELECT COUNT(*) FROM backup_requests WHERE state = 'running'",
            )));
            self::assertSame('1', $this->numeric($this->connection()->fetchOne(
                'SELECT COUNT(*) FROM backup_capacity_reservations WHERE released_at IS NULL',
            )));
            self::assertSame('1', $this->numeric($this->connection()->fetchOne('SELECT slots_used FROM backup_node_slots')));
        } finally {
            @unlink($journal);
            $this->cleanupCommittedFixture();
        }
    }

    public function testDuplicatePendingRequestsForOneGuestChooseOneStableWinner(): void
    {
        $store = $this->store();
        $store->promote(new ShadowPromotion(
            self::id('request-a'), self::id('decision-a'), $this->now,
            '{"mode":"snapshot"}', hash('sha256', '{"mode":"snapshot"}', true),
        ));
        $store->promote(new ShadowPromotion(
            self::id('request-c'), self::id('decision-c'), $this->now->modify('+1 second'),
            '{"mode":"snapshot"}', hash('sha256', '{"mode":"snapshot"}', true),
        ));

        $claim = $store->claim(new ClaimNextBackupCommand(self::id('worker-a'), $this->now->modify('+1 second')));
        self::assertNotNull($claim);
        self::assertSame(self::id('request-a'), $claim->id);
        self::assertSame('pending', $this->connection()->fetchOne(
            'SELECT state FROM backup_requests WHERE id = :id', ['id' => self::id('request-c')],
        ));
    }

    private function store(): DbalBackupQueueStore
    {
        return new DbalBackupQueueStore(
            $this->connection(),
            new FixedQueueTokens(),
            new ExpectedBackupSize(),
            new EvidenceFreshnessPolicy(),
        );
    }

    private function seedAcceptedRun(string $request, string $run, string $token, int $fence, DateTimeImmutable $at): PveUpid
    {
        $raw = sprintf('UPID:node-a:0000002A:000F4240:%08X:vzdump:100:backup@pve:', $at->getTimestamp());
        $upid = PveUpid::parse($raw);
        $this->connection()->insert('backup_runs', [
            'id' => $run, 'request_id' => $request,
            'root_request_id' => $this->connection()->fetchOne('SELECT root_request_id FROM backup_requests WHERE id=:id', ['id' => $request]),
            'attempt' => $this->connection()->fetchOne('SELECT attempt FROM backup_requests WHERE id=:id', ['id' => $request]),
            'state' => 'running', 'claim_token' => $token, 'claim_fence' => $fence,
            'submission_provenance' => 'accepted', 'upid' => $raw,
            'upid_hash' => hash('sha256', $raw, true), 'started_at' => self::format($at),
            'submission_node' => 'node-a', 'submission_vmid' => 100, 'submission_user' => 'backup@pve',
            'submission_window_start' => self::format($at->modify('-30 seconds')),
            'submission_window_end' => self::format($at->modify('+30 seconds')),
        ]);
        $this->connection()->update('backup_requests', [
            'state' => 'running', 'run_id' => $run, 'submission_provenance' => 'accepted',
            'retry_disposition' => 'not_applicable',
        ], ['id' => $request]);

        return $upid;
    }

    private function shadowCandidate(DbalAutomaticShadowEvaluationSource $source): \App\Application\Scheduler\Shadow\AutomaticShadowCandidate
    {
        $candidates = array_values(array_filter(
            $source->candidates($this->shadowLease()),
            static fn (\App\Application\Scheduler\Shadow\AutomaticShadowCandidate $candidate): bool => self::id('guest') === $candidate->guestId,
        ));
        self::assertCount(1, $candidates);

        return $candidates[0];
    }

    private function shadowLease(): CollectorLease
    {
        return new CollectorLease(
            new CollectorWorkerId(self::id('collector')),
            new CollectorCycleToken(self::id('cycle')),
            1,
            $this->now->modify('+1 hour'),
        );
    }

    private function seedPbsShadowEvidence(): void
    {
        $now = self::format($this->now);
        $connection = self::id('pbs-connection');
        $server = self::id('pbs-server');
        $datastore = self::id('pbs-datastore');
        $run = self::id('pbs-inventory-run');

        $this->connection()->insert('proxmox_connections', [
            'id' => $connection, 'display_name' => 'PBS shadow', 'product' => 'pbs', 'enabled' => 1,
            'revision' => 1, 'created_at' => $now, 'updated_at' => $now,
        ]);
        $this->connection()->insert('proxmox_connection_endpoints', [
            'id' => self::id('pbs-endpoint'), 'connection_id' => $connection,
            'host' => 'pbs.example.test', 'port' => 8007, 'priority' => 1,
            'enabled' => 1, 'tls_mode' => 'system_ca', 'created_at' => $now, 'updated_at' => $now,
        ]);
        $this->connection()->insert('inventory_sync_runs', [
            'id' => $run, 'cycle_token' => self::id('cycle'), 'collector_fencing_token' => 1,
            'connection_id' => $connection, 'expected_connection_revision' => 1,
            'status' => 'succeeded', 'authoritative' => 1, 'started_at' => $now,
            'heartbeat_at' => $now, 'finished_at' => $now, 'applied_at' => $now,
        ]);
        $this->connection()->insert('pbs_servers', [
            'id' => $server, 'connection_id' => $connection, 'node_name' => 'pbs-a',
            'version_major' => 4, 'version_minor' => 0, 'version_patch' => 0,
            'version_text' => '4.0', 'release_text' => '1', 'repo_id' => 'repo',
            'first_seen_run_id' => $run, 'last_seen_run_id' => $run,
            'first_seen_at' => $now, 'last_seen_at' => $now,
        ]);
        $this->connection()->insert('pbs_datastores', [
            'id' => $datastore, 'connection_id' => $connection, 'server_id' => $server,
            'datastore_name' => 'primary', 'backend_type' => 'filesystem', 'mount_status' => 'mounted',
            'allows_backup_writes' => 1, 'inventory_state' => 'active',
            'first_seen_run_id' => $run, 'last_seen_run_id' => $run,
            'first_seen_at' => $now, 'last_seen_at' => $now,
        ]);
        $this->connection()->insert('pbs_datastore_capacity_state', [
            'datastore_id' => $datastore, 'connection_id' => $connection, 'server_id' => $server,
            'backend_type' => 'filesystem', 'semantics' => 'datastore_filesystem',
            'total_bytes' => '1100', 'used_bytes' => '0', 'available_bytes' => '1100',
            'observed_at' => $now, 'sync_run_id' => $run,
        ]);
        $this->connection()->insert('pve_storage_pbs_mappings', [
            'storage_id' => self::id('storage'), 'connection_id' => self::id('connection'),
            'cluster_id' => self::id('cluster'), 'server' => 'pbs.example.test', 'port' => 8007,
            'datastore' => 'primary', 'namespace' => null, 'observed_at' => $now,
            'sync_run_id' => self::id('inventory-run'),
        ]);
        $this->connection()->update('backup_targets', [
            'pbs_connection_id' => $connection, 'pbs_datastore_id' => $datastore,
        ], ['id' => self::id('target')]);
    }

    private function seedBackupCredential(): void
    {
        $now = self::format($this->now);
        $this->connection()->insert('proxmox_credentials', [
            'id' => self::id('backup-credential'), 'connection_id' => self::id('connection'),
            'purpose' => 'backup', 'auth_scheme' => 'api_token', 'principal' => 'backup@pve',
            'token_name' => 'hoddmimir', 'secret_envelope' => 'test-envelope',
            'envelope_version' => 1, 'key_id' => 'test-key', 'revision' => 1,
            'created_at' => $now, 'updated_at' => $now,
        ]);
    }

    private function seedFixture(): void
    {
        $now = self::format($this->now);
        $cycle = self::id('cycle');
        $worker = self::id('collector');
        $run = self::id('inventory-run');
        $connection = self::id('connection');
        $cluster = self::id('cluster');
        $node = self::id('node');
        $storage = self::id('storage');
        $target = self::id('target');
        $policy = self::id('policy');
        $guest = self::id('guest');
        $guestB = self::id('guest-b');
        $evaluation = self::id('evaluation');

        $this->connection()->insert('worker_heartbeats', [
            'worker_instance_id' => $worker, 'worker_kind' => 'collector', 'status' => 'ready',
            'started_at' => $now, 'heartbeat_at' => $now,
            'expires_at' => self::format($this->now->modify('+1 hour')), 'build_version' => 'test',
        ]);
        $this->connection()->insert('collector_schedule', [
            'schedule_name' => 'inventory', 'grid_started_at' => $now, 'interval_seconds' => 120,
            'next_scan_at' => $now, 'lease_owner' => $worker, 'lease_token' => $cycle,
            'lease_fencing_token' => 1, 'lease_acquired_at' => $now,
            'lease_expires_at' => self::format($this->now->modify('+1 hour')),
            'last_cycle_started_at' => $now, 'updated_at' => $now,
        ]);
        $this->connection()->insert('collector_cycles', [
            'cycle_token' => $cycle, 'schedule_name' => 'inventory', 'worker_instance_id' => $worker,
            'worker_kind' => 'collector', 'fencing_token' => 1, 'scheduled_for' => $now,
            'started_at' => $now, 'heartbeat_at' => $now, 'status' => 'running',
        ]);
        $this->connection()->insert('proxmox_connections', [
            'id' => $connection, 'display_name' => 'Queue test', 'product' => 'pve', 'enabled' => 1,
            'revision' => 1, 'created_at' => $now, 'updated_at' => $now,
        ]);
        $this->connection()->insert('inventory_sync_runs', [
            'id' => $run, 'cycle_token' => $cycle, 'collector_fencing_token' => 1,
            'connection_id' => $connection, 'expected_connection_revision' => 1,
            'status' => 'succeeded', 'authoritative' => 1, 'started_at' => $now,
            'heartbeat_at' => $now, 'finished_at' => $now, 'applied_at' => $now,
        ]);
        $this->connection()->insert('pve_clusters', [
            'id' => $cluster, 'connection_id' => $connection, 'external_name' => 'cluster',
            'topology' => 'clustered', 'inventory_state' => 'active', 'first_seen_run_id' => $run,
            'last_seen_run_id' => $run, 'first_seen_at' => $now, 'last_seen_at' => $now,
        ]);
        $this->connection()->insert('pve_nodes', [
            'id' => $node, 'connection_id' => $connection, 'cluster_id' => $cluster,
            'node_name' => 'node-a', 'api_status' => 'online', 'inventory_state' => 'active',
            'first_seen_run_id' => $run, 'last_seen_run_id' => $run,
            'first_seen_at' => $now, 'last_seen_at' => $now,
        ]);
        $this->connection()->insert('pve_storages', [
            'id' => $storage, 'connection_id' => $connection, 'cluster_id' => $cluster,
            'storage_name' => 'backup-a', 'storage_type' => 'dir', 'supports_backup' => 1,
            'disabled' => 0, 'content_json' => '["backup"]', 'shared' => 1,
            'inventory_state' => 'active', 'first_seen_run_id' => $run, 'last_seen_run_id' => $run,
            'first_seen_at' => $now, 'last_seen_at' => $now,
        ]);
        $this->connection()->insert('guests', [
            'id' => $guest, 'connection_id' => $connection, 'cluster_id' => $cluster,
            'guest_type' => 'qemu', 'vmid' => 100, 'name' => 'guest-a', 'is_template' => 0,
            'provisioned_size_bytes' => '1000', 'inventory_state' => 'active',
            'first_seen_run_id' => $run, 'last_seen_run_id' => $run,
            'first_seen_at' => $now, 'last_seen_at' => $now,
        ]);
        $this->connection()->insert('guest_placements', [
            'guest_id' => $guest, 'connection_id' => $connection, 'cluster_id' => $cluster,
            'node_id' => $node, 'placement_revision' => 1, 'observed_at' => $now, 'sync_run_id' => $run,
        ]);
        $this->connection()->insert('guests', [
            'id' => $guestB, 'connection_id' => $connection, 'cluster_id' => $cluster,
            'guest_type' => 'lxc', 'vmid' => 101, 'name' => 'guest-b', 'is_template' => 0,
            'provisioned_size_bytes' => '1000', 'inventory_state' => 'active',
            'first_seen_run_id' => $run, 'last_seen_run_id' => $run,
            'first_seen_at' => $now, 'last_seen_at' => $now,
        ]);
        $this->connection()->insert('guest_placements', [
            'guest_id' => $guestB, 'connection_id' => $connection, 'cluster_id' => $cluster,
            'node_id' => $node, 'placement_revision' => 1, 'observed_at' => $now, 'sync_run_id' => $run,
        ]);
        $this->connection()->insert('pve_node_storage_state', [
            'connection_id' => $connection, 'cluster_id' => $cluster, 'node_id' => $node,
            'storage_id' => $storage, 'enabled' => 1, 'active' => 1, 'shared' => 1,
            'capacity_status' => 'measured', 'total_bytes' => '1100', 'used_bytes' => '0',
            'available_bytes' => '1100', 'observed_at' => $now, 'sync_run_id' => $run,
        ]);
        $this->connection()->insert('backup_targets', [
            'id' => $target, 'connection_id' => $connection, 'cluster_id' => $cluster,
            'storage_id' => $storage, 'display_name' => 'Target', 'status' => 'enabled',
            'revision' => 1, 'minimum_free_bytes' => '100', 'fixed_parallel_limit' => 1,
            'created_at' => $now, 'updated_at' => $now,
        ]);
        $this->connection()->insert('backup_target_allowed_nodes', [
            'target_id' => $target, 'connection_id' => $connection, 'cluster_id' => $cluster,
            'node_id' => $node, 'created_at' => $now,
        ]);
        $this->connection()->insert('backup_policies', [
            'id' => $policy, 'connection_id' => $connection, 'cluster_id' => $cluster,
            'target_id' => $target, 'display_name' => 'Policy', 'status' => 'enabled',
            'revision' => 1, 'policy_priority' => 100, 'backup_mode' => 'snapshot',
            'compression' => 'zstd', 'maximum_age_seconds' => 3600,
            'schedule' => 'collector_cycle', 'keep_last' => 1,
            'retention_execution_enabled' => 0, 'created_at' => $now, 'updated_at' => $now,
        ]);
        $this->connection()->insert('backup_policy_assignments', [
            'id' => self::id('global-include'), 'policy_id' => $policy,
            'connection_id' => $connection, 'cluster_id' => $cluster, 'scope' => 'global',
            'selection_value' => 'include', 'status' => 'active', 'revision' => 1,
            'created_at' => $now, 'updated_at' => $now,
        ]);
        $this->connection()->insert('executor_permission_evidence', [
            'id' => self::id('executor-guest-a'), 'connection_id' => $connection,
            'cluster_id' => $cluster, 'target_id' => $target,
            'node_id' => $node, 'storage_id' => $storage, 'guest_id' => $guest,
            'vm_backup_authorized' => 1, 'datastore_allocate_authorized' => 1,
            'authorized' => 1, 'observed_at' => $now, 'revision' => 1,
        ]);
        $this->connection()->insert('executor_permission_evidence', [
            'id' => self::id('executor-guest-b'), 'connection_id' => $connection,
            'cluster_id' => $cluster, 'target_id' => $target,
            'node_id' => $node, 'storage_id' => $storage, 'guest_id' => $guestB,
            'vm_backup_authorized' => 1, 'datastore_allocate_authorized' => 1,
            'authorized' => 1, 'observed_at' => $now, 'revision' => 1,
        ]);
        $this->connection()->insert('scheduler_evaluation_runs', [
            'id' => $evaluation, 'cycle_token' => $cycle, 'collector_fencing_token' => 1,
            'evaluator_version' => 1, 'payload_hash' => hash('sha256', 'evaluation', true),
            'decision_count' => 3, 'gate_count' => 0,
            'started_at' => $now, 'completed_at' => $now, 'persisted_at' => $now,
        ]);
        $this->connection()->insert('scheduler_decisions', [
            'id' => self::id('decision-a'), 'evaluation_run_id' => $evaluation,
            'decision_ordinal' => 1, 'connection_id' => $connection, 'cluster_id' => $cluster,
            'guest_id' => $guest, 'node_id' => $node, 'placement_revision' => 1,
            'placement_observed_at' => $now, 'outcome' => 'eligible', 'reason' => 'never_backed_up',
            'priority' => 300, 'policy_id' => $policy, 'policy_revision' => 1,
            'policy_snapshot_hash' => hash('sha256', '{"mode":"snapshot"}', true),
            'target_id' => $target, 'target_revision' => 1,
            'inventory_observed_at' => $now, 'capacity_observed_at' => $now,
        ]);
        $this->connection()->insert('scheduler_decisions', [
            'id' => self::id('decision-c'), 'evaluation_run_id' => $evaluation,
            'decision_ordinal' => 3, 'connection_id' => $connection, 'cluster_id' => $cluster,
            'guest_id' => $guest, 'node_id' => $node, 'placement_revision' => 1,
            'placement_observed_at' => $now, 'outcome' => 'eligible', 'reason' => 'never_backed_up',
            'priority' => 300, 'policy_id' => $policy, 'policy_revision' => 1,
            'policy_snapshot_hash' => hash('sha256', '{"mode":"snapshot"}', true),
            'target_id' => $target, 'target_revision' => 1,
            'inventory_observed_at' => $now, 'capacity_observed_at' => $now,
        ]);
        $this->connection()->insert('scheduler_decisions', [
            'id' => self::id('decision-b'), 'evaluation_run_id' => $evaluation,
            'decision_ordinal' => 2, 'connection_id' => $connection, 'cluster_id' => $cluster,
            'guest_id' => $guestB, 'node_id' => $node, 'placement_revision' => 1,
            'placement_observed_at' => $now, 'outcome' => 'eligible', 'reason' => 'never_backed_up',
            'priority' => 300, 'policy_id' => $policy, 'policy_revision' => 1,
            'policy_snapshot_hash' => hash('sha256', '{"mode":"snapshot"}', true),
            'target_id' => $target, 'target_revision' => 1,
            'inventory_observed_at' => $now, 'capacity_observed_at' => $now,
        ]);
    }

    /** @param array<string, mixed> $parameters
     *  @return array{int, resource}
     */
    private function startSubmissionChild(array $parameters, string $worker, string $journal): array
    {
        $sockets = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        if (false === $sockets) {
            throw new \RuntimeException('Could not create queue race sockets.');
        }
        [$parent, $child] = $sockets;
        $pid = pcntl_fork();
        if (-1 === $pid) {
            throw new \RuntimeException('Could not fork queue claimant.');
        }
        if (0 === $pid) {
            fclose($parent);
            if ('1' !== fread($child, 1)) {
                exit(2);
            }
            // @phpstan-ignore argument.type
            $connection = DriverManager::getConnection($parameters);
            try {
                $store = new DbalBackupQueueStore(
                    $connection, new FixedQueueTokens(), new ExpectedBackupSize(), new EvidenceFreshnessPolicy(),
                );
                $claim = $store->claim(new ClaimNextBackupCommand(self::id($worker), $this->now));
                if (null === $claim) {
                    fwrite($child, 'none');
                } else {
                    $submission = new SubmitClaimedBackup(
                        new IntegratedExecutionGate(),
                        new DbalBackupSubmissionStore($connection, new DbalBackupProblemRecorder()),
                        new IntegratedJournalBackupClient($journal, false),
                        new ControlledRetryPolicy(),
                    );
                    $submission->execute(new SubmitClaimedBackupCommand(
                        $claim->id, self::id('run-'.$worker), $claim->claimToken, $claim->claimFence, $this->now,
                    ));
                    fwrite($child, 'submitted');
                }
            } catch (Throwable $exception) {
                fwrite($child, $exception::class.':'.$exception->getMessage());
            } finally {
                $connection->close();
                fclose($child);
            }
            pcntl_exec('/bin/true');
            posix_kill(posix_getpid(), SIGKILL);
            exit(3);
        }
        fclose($child);
        stream_set_timeout($parent, 15);

        return [$pid, $parent];
    }

    /** @param resource $socket */
    private function finishClaimChild(int $pid, $socket): string
    {
        $result = stream_get_contents($socket);
        fclose($socket);
        $status = null;
        pcntl_waitpid($pid, $status);
        self::assertIsInt($status);
        self::assertTrue(pcntl_wifexited($status));
        self::assertSame(0, pcntl_wexitstatus($status));
        self::assertIsString($result);

        return $result;
    }

    private function cleanupCommittedFixture(): void
    {
        $this->connection()->executeStatement('SET FOREIGN_KEY_CHECKS = 0');
        try {
            foreach ([
                'backup_capacity_reservations', 'backup_request_events', 'backup_requests',
                'backup_target_slots', 'backup_node_slots', 'executor_permission_evidence',
                'scheduler_decision_gates', 'scheduler_decisions', 'scheduler_evaluation_runs',
                'backup_policy_guest_overrides', 'backup_policy_assignments', 'backup_policies',
                'backup_target_allowed_nodes', 'backup_targets', 'guest_placements', 'guests',
                'pve_node_storage_state', 'pve_storages', 'pve_nodes', 'pve_clusters',
                'inventory_sync_runs', 'proxmox_connections', 'collector_cycles',
                'collector_schedule', 'worker_heartbeats',
            ] as $table) {
                $this->connection()->executeStatement('DELETE FROM '.$table);
            }
        } finally {
            $this->connection()->executeStatement('SET FOREIGN_KEY_CHECKS = 1');
        }
    }

    private function numeric(mixed $value): string
    {
        if (is_int($value) || (is_string($value) && ctype_digit($value))) {
            return (string) $value;
        }
        self::fail('MariaDB returned a non-numeric value.');
    }

    private function setAllEvidenceAge(int $seconds): void
    {
        $at = self::format($this->now->modify('-'.$seconds.' seconds'));
        foreach (['pve_clusters', 'pve_nodes', 'guests', 'pve_storages'] as $table) {
            $this->connection()->executeStatement(
                sprintf('UPDATE %s SET first_seen_at = :at, last_seen_at = :at', $table),
                ['at' => $at],
            );
        }
        $this->connection()->update('guest_placements', ['observed_at' => $at], ['guest_id' => self::id('guest')]);
        $this->connection()->update('pve_node_storage_state', ['observed_at' => $at], ['node_id' => self::id('node')]);
        $this->connection()->update('executor_permission_evidence', ['observed_at' => $at], ['connection_id' => self::id('connection')]);
    }

    private static function id(string $label): string
    {
        return substr(hash('sha256', $label, true), 0, 16);
    }

    private function prepareSubmissionForPolicy(string $policy): \App\Application\Backup\Execution\PreparedBackupSubmission
    {
        $store = $this->store();
        $store->promote(new ShadowPromotion(
            self::id('request-retention'), self::id('decision-a'), $this->now,
            '{"mode":"snapshot"}', hash('sha256', '{"mode":"snapshot"}', true),
        ));
        $claim = $store->claim(new ClaimNextBackupCommand(self::id('worker-a'), $this->now));
        self::assertNotNull($claim);
        $this->seedBackupCredential();
        $this->connection()->update('backup_requests', [
            'resolved_policy_json' => $policy,
            'resolved_policy_hash' => hash('sha256', $policy, true),
        ], ['id' => $claim->id]);
        $submission = new DbalBackupSubmissionStore($this->connection(), new DbalBackupProblemRecorder());
        $prepared = $submission->prepareAfterFullRevalidation(new SubmitClaimedBackupCommand(
            $claim->id,
            self::id('retention-run'),
            $claim->claimToken,
            $claim->claimFence,
            $this->now,
        ));

        self::assertSame(SubmissionPreparationStatus::PreparedNow, $prepared->status);
        self::assertNotNull($prepared->submission);

        return $prepared->submission;
    }

    private static function format(DateTimeImmutable $value): string
    {
        return $value->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.u');
    }
}

final class FixedQueueTokens implements QueueClaimTokenSource
{
    private int $sequence = 0;

    public function next(): string
    {
        return substr(hash('sha256', 'queue-token-'.++$this->sequence, true), 0, 16);
    }
}

final readonly class IntegratedExecutionGate implements BackupExecutionGate
{
    public function enabled(): bool
    {
        return true;
    }
}

final class IntegratedJournalBackupClient implements PveBackupClient, PveBackupClientProvider
{
    public function __construct(private readonly string $journal, private readonly bool $ambiguous)
    {
    }

    public function forRequest(string $requestId): PveBackupClient
    {
        return $this;
    }

    public function submit(PveBackupSubmission $submission): PveBackupSubmissionResult
    {
        if (false === file_put_contents($this->journal, 'P', FILE_APPEND | LOCK_EX)) {
            throw new \RuntimeException('Could not append the integrated POST journal.');
        }
        if ($this->ambiguous) {
            return PveBackupSubmissionResult::ambiguous();
        }
        $upid = PveUpid::parse(sprintf(
            'UPID:%s:0000002A:000F4240:67000000:vzdump:%d:backup@pve:',
            $submission->node,
            $submission->vmid,
        ));

        return PveBackupSubmissionResult::accepted($upid);
    }

    public function taskStatus(PveUpid $upid): PveTaskStatus
    {
        return new PveTaskStatus($upid, PveTaskLifecycle::Running, null, null, []);
    }

    public function taskLog(PveUpid $upid, PveTaskLogQuery $query): PveTaskLogPage
    {
        return new PveTaskLogPage($query, []);
    }

    public function stopTask(PveUpid $upid): PveTaskStopResult
    {
        return PveTaskStopResult::requested();
    }

    public function taskPage(string $node, PveTaskQuery $query): PveTaskPage
    {
        throw new \LogicException('The integrated reconciliation source supplies bounded evidence directly.');
    }
}

final readonly class IntegratedReconciliationSource implements AmbiguousSubmissionTaskSource
{
    public function __construct(private bool $matched)
    {
    }

    public function read(AmbiguousSubmissionIdentity $identity, callable $beforePage): AmbiguousSubmissionEvidence
    {
        if (!$beforePage()) {
            return new AmbiguousSubmissionEvidence(false, []);
        }
        if (!$this->matched) {
            return new AmbiguousSubmissionEvidence(true, []);
        }
        $start = $identity->windowStart->getTimestamp();
        $upid = PveUpid::parse(sprintf(
            'UPID:%s:0000002A:000F4240:%08X:vzdump:%d:%s:',
            $identity->node,
            $start,
            $identity->vmid,
            $identity->user,
        ));

        return new AmbiguousSubmissionEvidence(true, [
            new PveBackupTask($upid, PveTaskSource::Archive, $start + 1, 'OK'),
        ]);
    }
}
