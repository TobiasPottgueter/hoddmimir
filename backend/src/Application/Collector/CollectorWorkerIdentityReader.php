<?php

declare(strict_types=1);

namespace App\Application\Collector;

interface CollectorWorkerIdentityReader
{
    public function existingWorkerId(): ?CollectorWorkerId;
}
