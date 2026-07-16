<?php

declare(strict_types=1);

namespace App\Application\Backup\Worker;

use App\Application\Backup\Execution\SubmissionExecutionStatus;
use App\Application\Backup\Execution\BackupExecutionGate;
use App\Application\Backup\Execution\ExecutorEvidenceLeaseOwnershipLost;
use App\Application\Backup\Execution\ExecutorEvidenceRefresh;
use App\Application\Backup\Execution\ExecutorEvidenceRefreshFailure;
use App\Application\Backup\Execution\SubmitClaimedBackup;
use App\Application\Backup\Execution\SubmitClaimedBackupCommand;
use App\Application\Backup\Monitoring\MonitorClaimedBackup;
use App\Application\Backup\Monitoring\MonitorClaimedBackupCommand;
use App\Application\Backup\Monitoring\ReconcileAmbiguousSubmission;
use App\Application\Backup\Monitoring\ReconcileAmbiguousSubmissionCommand;
use App\Application\Backup\Queue\BackupQueueStore;
use App\Application\Backup\Queue\ClaimNextBackupCommand;
use App\Domain\Shared\Clock;
use InvalidArgumentException;
use RuntimeException;

final readonly class BackupWorkerRunner implements BackupWorkerRuntime
{
    public function __construct(
        private ExecutorEvidenceRefresh $executorEvidence,
        private BackupQueueStore $queue,
        private BackupExecutionGate $executionGate,
        private SubmitClaimedBackup $submit,
        private MonitorClaimedBackup $monitor,
        private ReconcileAmbiguousSubmission $reconcile,
        private BackupRunIdentifierSource $runIds,
        private BackupNotificationDeliveryHook $notifications,
        private Clock $clock,
    ) {
    }

    public function runOnce(string $workerId): BackupWorkerTickStatus
    {
        if (16 !== \strlen($workerId)) throw new InvalidArgumentException('The backup worker identifier must contain 16 bytes.');
        try {
            $this->executorEvidence->refreshDue($workerId);
        } catch (ExecutorEvidenceRefreshFailure|ExecutorEvidenceLeaseOwnershipLost) {
            // Expected refresh failures are already represented by fail-closed evidence state.
            // They must not interrupt monitoring, reconciliation, or notification delivery.
        }
        $now = $this->clock->now();
        $claim = $this->queue->claim(new ClaimNextBackupCommand(
            $workerId,
            $now,
            allowNewClaims: $this->executionGate->enabled(),
        ));
        if (null === $claim) {
            $this->notifications->deliverOne($now);
            return BackupWorkerTickStatus::NoWork;
        }
        if ('leased' === $claim->state) {
            $runId = $this->runIds->next();
            if (16 !== \strlen($runId)) throw new RuntimeException('The backup run identifier source returned an invalid value.');
            $result = $this->submit->execute(new SubmitClaimedBackupCommand(
                $claim->id, $runId, $claim->claimToken, $claim->claimFence, $now,
            ));
            $status = \in_array($result->status, [SubmissionExecutionStatus::Accepted, SubmissionExecutionStatus::Ambiguous, SubmissionExecutionStatus::DefinitiveRejection], true)
                ? BackupWorkerTickStatus::Submitted
                : BackupWorkerTickStatus::SubmissionDeferred;
        } else {
            $runId = $claim->runId ?? throw new RuntimeException('An active backup claim lost its run identifier.');
            if ('running' === $claim->state) {
                $this->monitor->execute(new MonitorClaimedBackupCommand(
                    $claim->id, $runId, $claim->claimToken, $claim->claimFence, $now,
                ));
                $status = BackupWorkerTickStatus::Monitored;
            } else {
                $this->reconcile->execute(new ReconcileAmbiguousSubmissionCommand(
                    $claim->id, $runId, $claim->claimToken, $claim->claimFence, $now,
                ));
                $status = BackupWorkerTickStatus::Reconciled;
            }
        }
        $this->notifications->deliverOne($now);
        return $status;
    }
}
