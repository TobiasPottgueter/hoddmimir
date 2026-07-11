<?php

declare(strict_types=1);

namespace App\Infrastructure\Proxmox\Pbs;

use App\Application\Proxmox\Pbs\PbsInstanceIdentity;
use App\Application\Proxmox\Pbs\PbsReadFailure;
use App\Application\Proxmox\Pbs\PbsReadFailureCode;
use InvalidArgumentException;

final readonly class PbsInstanceIdentityReader
{
    public function read(PbsApiEnvelope $envelope): PbsInstanceIdentity
    {
        $value = $envelope->data instanceof \stdClass ? ($envelope->data->{'pbs-instance-id'} ?? null) : null;
        if (!is_string($value)) {
            throw PbsReadFailure::for(PbsReadFailureCode::InvalidResponse);
        }
        try {
            return new PbsInstanceIdentity($value);
        } catch (InvalidArgumentException) {
            throw PbsReadFailure::for(PbsReadFailureCode::InvalidResponse);
        }
    }
}
