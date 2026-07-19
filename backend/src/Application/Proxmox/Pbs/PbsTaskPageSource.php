<?php

declare(strict_types=1);

namespace App\Application\Proxmox\Pbs;

interface PbsTaskPageSource
{
    public function page(PbsTaskListQuery $query): PbsTaskPage;
}
