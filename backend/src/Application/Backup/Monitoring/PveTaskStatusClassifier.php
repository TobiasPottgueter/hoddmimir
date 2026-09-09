<?php

declare(strict_types=1);

namespace App\Application\Backup\Monitoring;

use App\Application\Proxmox\Pve\PveTaskLifecycle;
use App\Application\Proxmox\Pve\PveTaskStatus;
use App\Domain\Backup\MonitoringOutcome;

final readonly class PveTaskStatusClassifier
{
    public function classify(PveTaskStatus $status, bool $cancelWasRequested): MonitoringOutcome
    {
        if (!$status->isComplete() || null === $status->lifecycle) {
            return MonitoringOutcome::TemporarilyUnavailable;
        }
        if (PveTaskLifecycle::Running === $status->lifecycle) {
            return MonitoringOutcome::Running;
        }
        if ('OK' === $status->exitStatus) {
            return MonitoringOutcome::Succeeded;
        }

        return $cancelWasRequested ? MonitoringOutcome::Cancelled : MonitoringOutcome::Failed;
    }
}
