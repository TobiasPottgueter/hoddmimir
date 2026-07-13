<?php

declare(strict_types=1);

namespace App\Application\Backup\Monitoring;

use App\Application\Proxmox\Pve\PveBackupApiFailure;
use App\Application\Proxmox\Pve\PveBackupApiFailureCode;
use App\Application\Proxmox\Pve\PveBackupClientProvider;
use App\Application\Proxmox\Pve\PveTaskLogPage;
use App\Application\Proxmox\Pve\PveTaskLogQuery;
use App\Application\Proxmox\Pve\PveTaskStopStatus;
use App\Domain\Backup\MonitoringOutcome;
use App\Domain\Shared\Clock;

final readonly class MonitorClaimedBackup
{
    public function __construct(
        private BackupMonitoringTransaction $transaction,
        private PveBackupClientProvider $clients,
        private PveTaskStatusClassifier $classifier,
        private Clock $clock,
        private int $logPageSize = 200,
    ) {
        if ($logPageSize < 1 || $logPageSize > PveTaskLogQuery::MAXIMUM_PAGE_SIZE) {
            throw new \InvalidArgumentException('The backup monitoring log page size is invalid.');
        }
    }

    public function execute(MonitorClaimedBackupCommand $command): MonitoringTickResult
    {
        $prepared = $this->transaction->prepare($command);
        if (null === $prepared) {
            return new MonitoringTickResult(MonitoringTickStatus::NoWork, 0);
        }

        try {
            $client = $this->clients->forRequest($command->requestId);
        } catch (PveBackupApiFailure $failure) {
            $this->transaction->recordObservation(
                $this->atNow($command),
                $prepared->upid,
                MonitoringOutcome::TemporarilyUnavailable,
                null,
                $failure->failureCode,
            );
            return new MonitoringTickResult(
                MonitoringTickStatus::TemporarilyUnavailable,
                0,
                statusFailure: $failure->failureCode,
            );
        }
        $command = $this->atNow($command);
        if (!$this->transaction->renew($command)) {
            return new MonitoringTickResult(MonitoringTickStatus::NoWork, 0);
        }
        [$stopStatus, $stopFailure] = $this->stopIfRequested($command, $prepared, $client);

        $command = $this->atNow($command);
        if (!$this->transaction->renew($command)) {
            return new MonitoringTickResult(MonitoringTickStatus::NoWork, 0);
        }
        [$page, $logFailure] = $this->readLog($prepared, $client);
        if (null !== $page) {
            $this->transaction->appendLogPage($this->atNow($command), $prepared->upid, $page);
        }

        $command = $this->atNow($command);
        if (!$this->transaction->renew($command)) {
            return new MonitoringTickResult(MonitoringTickStatus::NoWork, 0);
        }
        try {
            $taskStatus = $client->taskStatus($prepared->upid);
            $outcome = $this->classifier->classify($taskStatus, $prepared->cancelWasRequested());
            $exitStatus = $taskStatus->exitStatus;
            $statusFailure = null;
        } catch (PveBackupApiFailure $failure) {
            $outcome = MonitoringOutcome::TemporarilyUnavailable;
            $exitStatus = null;
            $statusFailure = $failure->failureCode;
        }
        $this->transaction->recordObservation($this->atNow($command), $prepared->upid, $outcome, $exitStatus, $statusFailure);

        return new MonitoringTickResult(
            self::tickStatus($outcome),
            null === $page ? 0 : \count($page->entries),
            $logFailure,
            $statusFailure,
            $stopStatus,
            $stopFailure,
        );
    }

    private function atNow(MonitorClaimedBackupCommand $command): MonitorClaimedBackupCommand
    {
        return new MonitorClaimedBackupCommand(
            $command->requestId,
            $command->runId,
            $command->claimToken,
            $command->claimFence,
            $this->clock->now(),
        );
    }

    /** @return array{?PveTaskStopStatus, ?PveBackupApiFailureCode} */
    private function stopIfRequested(
        MonitorClaimedBackupCommand $command,
        PreparedBackupMonitoring $prepared,
        \App\Application\Proxmox\Pve\PveBackupClient $client,
    ): array {
        if (StopAttemptDisposition::ReadyToClaim !== $prepared->stopAttempt) {
            return [null, null];
        }
        if (!$this->transaction->claimStopAttempt($command, $prepared->upid)) {
            return [null, null];
        }
        try {
            $status = $client->stopTask($prepared->upid)->status;
            $this->transaction->recordStopAttempt($command, $prepared->upid, $status, null);

            return [$status, null];
        } catch (PveBackupApiFailure $failure) {
            // The dispatching intent was persisted before I/O. A thrown, definitive
            // client failure is recorded and never retried.
            $this->transaction->recordStopAttempt($command, $prepared->upid, null, $failure->failureCode);

            return [null, $failure->failureCode];
        }
    }

    /** @return array{?PveTaskLogPage, ?PveBackupApiFailureCode} */
    private function readLog(
        PreparedBackupMonitoring $prepared,
        \App\Application\Proxmox\Pve\PveBackupClient $client,
    ): array
    {
        try {
            return [
                $client->taskLog(
                    $prepared->upid,
                    new PveTaskLogQuery($prepared->nextLogOffset, $this->logPageSize),
                ),
                null,
            ];
        } catch (PveBackupApiFailure $failure) {
            return [null, $failure->failureCode];
        }
    }

    private static function tickStatus(MonitoringOutcome $outcome): MonitoringTickStatus
    {
        return MonitoringTickStatus::from($outcome->value);
    }
}
