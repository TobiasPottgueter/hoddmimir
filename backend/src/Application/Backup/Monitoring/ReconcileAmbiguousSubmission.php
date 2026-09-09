<?php

declare(strict_types=1);

namespace App\Application\Backup\Monitoring;

use App\Application\Proxmox\Pve\PveBackupTask;
use App\Domain\Backup\RecoveryOutcome;
use App\Application\Proxmox\Pve\PveBackupApiFailure;
use App\Domain\Shared\Clock;

final readonly class ReconcileAmbiguousSubmission
{
    public function __construct(
        private AmbiguousSubmissionReconciliationStore $store,
        private AmbiguousSubmissionTaskSource $source,
        private Clock $clock,
    ) {
    }

    public function execute(ReconcileAmbiguousSubmissionCommand $command): ReconciliationStatus
    {
        $identity = $this->store->prepare($command);
        if (null === $identity) {
            return ReconciliationStatus::NoWork;
        }
        try {
            $evidence = $this->source->read(
                $identity,
                fn (): bool => $this->store->renew($this->atNow($command)),
            );
        } catch (PveBackupApiFailure) {
            return ReconciliationStatus::TemporarilyUnavailable;
        }
        $matches = array_values(array_filter(
            $evidence->tasks,
            static fn (PveBackupTask $task): bool => self::matches($identity, $task),
        ));

        if ($evidence->complete && 1 === count($matches)) {
            $outcome = RecoveryOutcome::matched(new \App\Domain\Backup\TaskUpid($matches[0]->upid->raw));
            $status = ReconciliationStatus::Matched;
        } elseif ($evidence->complete && [] === $matches) {
            $outcome = RecoveryOutcome::provenNotStarted();
            $status = ReconciliationStatus::ProvenNotStarted;
        } elseif ($evidence->complete) {
            $outcome = RecoveryOutcome::multipleMatches();
            $status = ReconciliationStatus::Inconclusive;
        } else {
            $outcome = RecoveryOutcome::inconclusive();
            $status = ReconciliationStatus::Inconclusive;
        }
        $this->store->record($this->atNow($command), $outcome);

        return $status;
    }

    private function atNow(ReconcileAmbiguousSubmissionCommand $command): ReconcileAmbiguousSubmissionCommand
    {
        return new ReconcileAmbiguousSubmissionCommand(
            $command->requestId,
            $command->runId,
            $command->claimToken,
            $command->claimFence,
            $this->clock->now(),
        );
    }

    private static function matches(AmbiguousSubmissionIdentity $identity, PveBackupTask $task): bool
    {
        $upid = $task->upid;

        return hash_equals($identity->node, $upid->node)
            && (string) $identity->vmid === $upid->id
            && hash_equals($identity->user, $upid->user)
            && $upid->startTime >= $identity->windowStart->getTimestamp()
            && $upid->startTime <= $identity->windowEnd->getTimestamp();
    }
}
