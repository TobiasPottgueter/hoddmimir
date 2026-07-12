<?php

declare(strict_types=1);

namespace App\Infrastructure\Proxmox\Pbs;

use App\Application\Proxmox\Pbs\PbsAclEvidence;
use App\Application\Proxmox\Pbs\PbsJobKind;
use App\Application\Proxmox\Pbs\PbsJobListSnapshot;
use App\Application\Proxmox\Pbs\PbsTaskListQuery;
use App\Application\Proxmox\Pbs\PbsTaskPage;
use App\Application\Proxmox\Pbs\PbsTasksAndJobsLimits;

final readonly class PbsHttpTasksAndJobsClient implements PbsMonitoringClient
{
    public function __construct(
        private PbsApiTransport $transport,
        private PbsPermissionReader $permissionReader,
        private PbsJobListReader $jobReader,
        private PbsTaskPageReader $taskReader,
        private PbsTasksAndJobsLimits $limits = new PbsTasksAndJobsLimits(),
    ) {}

    public function aclEvidence(): PbsAclEvidence
    {
        return new PbsAclEvidence(
            $this->permission('/system/tasks'),
            $this->permission('/datastore'),
            $this->permission('/remote'),
        );
    }

    public function pruneJobs(): PbsJobListSnapshot
    {
        return $this->jobReader->read(
            $this->transport->get(PbsRequest::pruneJobs()),
            PbsJobKind::Prune,
            $this->limits->maximumJobsPerKind,
        );
    }

    public function syncJobs(): PbsJobListSnapshot
    {
        return $this->jobReader->read(
            $this->transport->get(PbsRequest::syncJobs()),
            PbsJobKind::Sync,
            $this->limits->maximumJobsPerKind,
        );
    }

    public function verifyJobs(): PbsJobListSnapshot
    {
        return $this->jobReader->read(
            $this->transport->get(PbsRequest::verifyJobs()),
            PbsJobKind::Verify,
            $this->limits->maximumJobsPerKind,
        );
    }

    public function page(string $node, PbsTaskListQuery $query): PbsTaskPage
    {
        return $this->taskReader->read($this->transport->get(PbsRequest::tasks($node, $query)), $query->pass);
    }

    private function permission(string $path): \App\Application\Proxmox\Pbs\PbsEffectivePermission
    {
        return $this->permissionReader->read($this->transport->get(PbsRequest::permission($path)), $path);
    }
}
