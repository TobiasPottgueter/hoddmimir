<?php

declare(strict_types=1);

namespace App\Infrastructure\Proxmox\Pbs;

use App\Application\Proxmox\Pbs\PbsReadFailure;
use App\Application\Proxmox\Pbs\PbsReadFailureCode;

final readonly class PbsPingReader
{
    public function assertPbs(PbsApiEnvelope $envelope): void
    {
        if (!$envelope->data instanceof \stdClass || true !== ($envelope->data->pong ?? null)) {
            throw PbsReadFailure::for(PbsReadFailureCode::InvalidResponse);
        }
    }
}
