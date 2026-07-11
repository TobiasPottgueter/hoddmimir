<?php

declare(strict_types=1);

namespace App\Infrastructure\Proxmox\Pbs;

interface PbsApiTransport
{
    public function get(PbsRequest $request): PbsApiEnvelope;
}
