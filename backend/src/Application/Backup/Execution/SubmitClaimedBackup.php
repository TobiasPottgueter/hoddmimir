<?php

declare(strict_types=1);

namespace App\Application\Backup\Execution;

use App\Application\Proxmox\Pve\PveBackupApiFailure;
use App\Application\Proxmox\Pve\PveBackupClientProvider;
use App\Application\Proxmox\Pve\PveBackupSubmissionStatus;
use App\Domain\Backup\ControlledRetryPolicy;
use App\Domain\Backup\SubmissionProvenance;

final readonly class SubmitClaimedBackup
{
    public function __construct(
        private BackupExecutionGate $executionGate,
        private BackupSubmissionTransaction $transaction,
        private PveBackupClientProvider $clients,
        private ControlledRetryPolicy $retryPolicy,
        private CheckBackupNodeTasks $nodeTasks,
        private \App\Domain\Shared\Clock $clock,
    ) {
    }

    public function execute(SubmitClaimedBackupCommand $command): SubmissionExecutionResult
    {
        if (ExistingSubmissionStatus::RecoveryRequired === $this->transaction->inspectExistingSubmission($command)) {
            return new SubmissionExecutionResult(SubmissionExecutionStatus::RecoveryRequired);
        }
        if (!$this->executionGate->enabled()) {
            return new SubmissionExecutionResult(SubmissionExecutionStatus::Disabled);
        }
        $nodes = $this->transaction->taskInspectionNodes($command);
        $observedAt = $this->clock->now();
        $blocker = $this->nodeTasks->blocker($command->requestId, $nodes);
        $evidence = [] === $nodes ? null : new BackupNodeTaskEvidence($nodes, $observedAt);
        $command = new SubmitClaimedBackupCommand(
            $command->requestId, $command->runId, $command->claimToken, $command->claimFence,
            $this->clock->now(), $evidence,
        );
        if (null === $blocker && (null === $evidence || !$evidence->covers($nodes, $command->now))) {
            $blocker = 'remote_tasks_stale';
        }
        if (null !== $blocker) {
            $this->transaction->deferRemoteTaskCheck($command, $blocker);
            return new SubmissionExecutionResult(SubmissionExecutionStatus::Blocked, blockerCode: $blocker);
        }
        $preparation = $this->transaction->prepareAfterFullRevalidation($command);
        if (SubmissionPreparationStatus::Blocked === $preparation->status) {
            return new SubmissionExecutionResult(SubmissionExecutionStatus::Blocked, blockerCode: $preparation->blockerCode);
        }
        if (SubmissionPreparationStatus::RecoveryRequired === $preparation->status) {
            return new SubmissionExecutionResult(SubmissionExecutionStatus::RecoveryRequired);
        }

        $prepared = $preparation->submission ?? throw new \LogicException('Prepared submission payload is missing.');
        try {
            $submission = $this->clients->forRequest($command->requestId)->submit($prepared->payload);
        } catch (PveBackupApiFailure $failure) {
            $nextRetryAt = $this->retryPolicy->nextAvailableAt(
                $command->now,
                $prepared->attempt,
                SubmissionProvenance::DefinitiveRejection,
            );
            $this->transaction->recordDefinitiveRejection(
                $command,
                $failure->failureCode,
                new DefinitiveBackupFailureNotice(
                    $prepared->attempt,
                    $prepared->guestId,
                    $prepared->guestName,
                    $prepared->payload->vmid,
                    $prepared->payload->guestType,
                    $prepared->payload->node,
                    $prepared->targetId,
                    $prepared->targetLabel,
                    $failure->failureCode,
                    $command->now,
                    $nextRetryAt,
                ),
            );

            return new SubmissionExecutionResult(SubmissionExecutionStatus::DefinitiveRejection);
        }

        if (PveBackupSubmissionStatus::Ambiguous === $submission->status) {
            $this->transaction->recordAmbiguous($command, null);

            return new SubmissionExecutionResult(SubmissionExecutionStatus::Ambiguous);
        }
        $upid = $submission->upid ?? throw new \LogicException('An accepted PVE submission lost its UPID.');
        $this->transaction->recordAccepted($command, $upid);

        return new SubmissionExecutionResult(SubmissionExecutionStatus::Accepted, $upid);
    }
}
