<?php

declare(strict_types=1);

namespace App\Infrastructure\Proxmox\PveBackup;

use App\Application\Proxmox\Pve\BuildPveVzdumpPayload;
use App\Application\Proxmox\Pve\PveBackupApiFailure;
use App\Application\Proxmox\Pve\PveBackupApiFailureCode;
use App\Application\Proxmox\Pve\PveBackupClient;
use App\Application\Proxmox\Pve\PveBackupSubmission;
use App\Application\Proxmox\Pve\PveBackupSubmissionResult;
use App\Application\Proxmox\Pve\PveReadFailure;
use App\Application\Proxmox\Pve\PveTaskLogPage;
use App\Application\Proxmox\Pve\PveTaskLogQuery;
use App\Application\Proxmox\Pve\PveTaskPage;
use App\Application\Proxmox\Pve\PveTaskQuery;
use App\Application\Proxmox\Pve\PveTaskStatus;
use App\Application\Proxmox\Pve\PveTaskStopResult;
use App\Application\Proxmox\Pve\PveUpid;
use App\Application\Proxmox\Pve\PveVersion;
use App\Infrastructure\Proxmox\PveTaskStatusReader;
use App\Infrastructure\Proxmox\PveTaskPageReader;

final readonly class PveHttpBackupClient implements PveBackupClient
{
    public function __construct(
        private PveBackupApiTransport $transport,
        private PveVersion $version,
        private BuildPveVzdumpPayload $payloadBuilder,
        private PveBackupSubmissionReader $submissionReader,
        private PveTaskStatusReader $statusReader,
        private PveBackupTaskLogReader $logReader,
        private PveTaskPageReader $taskPageReader,
    ) {
    }

    public function submit(PveBackupSubmission $submission): PveBackupSubmissionResult
    {
        $result = $this->transport->post(
            ['nodes', $submission->node, 'vzdump'],
            $this->payloadBuilder->build($this->version, $submission),
        );
        if (PveBackupWriteTransportStatus::Ambiguous === $result->status) {
            return PveBackupSubmissionResult::ambiguous();
        }

        try {
            return PveBackupSubmissionResult::accepted(
                $this->submissionReader->read($submission, $result->decodedData()),
            );
        } catch (PveBackupApiFailure) {
            return PveBackupSubmissionResult::ambiguous();
        }
    }

    public function taskStatus(PveUpid $upid): PveTaskStatus
    {
        try {
            return $this->statusReader->read(
                $upid->node,
                $upid,
                $this->transport->get(['nodes', $upid->node, 'tasks', $upid->raw, 'status']),
            );
        } catch (PveReadFailure) {
            throw PveBackupApiFailure::for(PveBackupApiFailureCode::InvalidResponse);
        }
    }

    public function taskLog(PveUpid $upid, PveTaskLogQuery $query): PveTaskLogPage
    {
        return $this->logReader->read(
            $query,
            $this->transport->get(
                ['nodes', $upid->node, 'tasks', $upid->raw, 'log'],
                $query->parameters(),
            ),
        );
    }

    public function stopTask(PveUpid $upid): PveTaskStopResult
    {
        $result = $this->transport->delete(['nodes', $upid->node, 'tasks', $upid->raw]);
        if (PveBackupWriteTransportStatus::Ambiguous === $result->status) {
            return PveTaskStopResult::ambiguous();
        }
        if (null !== $result->decodedData()) {
            return PveTaskStopResult::ambiguous();
        }

        return PveTaskStopResult::requested();
    }

    public function taskPage(string $node, PveTaskQuery $query): PveTaskPage
    {
        try {
            return $this->taskPageReader->read(
                $node,
                $query,
                $this->transport->get(
                    ['nodes', $node, 'tasks'],
                    $query->parameters(),
                ),
            );
        } catch (PveReadFailure) {
            throw PveBackupApiFailure::for(PveBackupApiFailureCode::InvalidResponse);
        }
    }
}
