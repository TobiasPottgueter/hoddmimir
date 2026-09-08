<?php

declare(strict_types=1);

namespace App\Infrastructure\Proxmox\Pbs;

use App\Application\Proxmox\Pbs\PbsAclEvidence;
use App\Application\Proxmox\Pbs\PbsJobListSnapshot;
use App\Application\Proxmox\Pbs\PbsTaskPageSource;

interface PbsMonitoringClient extends PbsTaskPageSource, \App\Application\Proxmox\Pbs\PbsTaskInspectionSource
{
    public function aclEvidence(): PbsAclEvidence;

    public function pruneJobs(): PbsJobListSnapshot;

    public function syncJobs(): PbsJobListSnapshot;

    public function verifyJobs(): PbsJobListSnapshot;
}
