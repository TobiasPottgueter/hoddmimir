<?php

declare(strict_types=1);

namespace App\Application\Proxmox\Pbs;

interface PbsTaskInspectionSource
{
    public function inspect(PbsUpid $upid): PbsTaskInspection;
}
