<?php

declare(strict_types=1);

namespace App\Infrastructure\Proxmox\Pbs;

use App\Application\Proxmox\Pbs\PbsReadFailure;
use App\Application\Proxmox\Pbs\PbsReadFailureCode;
use App\Infrastructure\Validation\AsciiPatternValidator;

final readonly class PbsNodesReader
{
    /** @return list<string> */
    public function read(PbsApiEnvelope $envelope): array
    {
        $data = $envelope->data;
        if (!is_array($data) || 1 !== count($data) || !$data[0] instanceof \stdClass) {
            throw PbsReadFailure::for(PbsReadFailureCode::InvalidResponse);
        }
        $node = $data[0]->node ?? null;
        if (!is_string($node) || !AsciiPatternValidator::matches('/\A[A-Za-z0-9](?:[A-Za-z0-9-]{0,61}[A-Za-z0-9])?\z/D', $node)) {
            throw PbsReadFailure::for(PbsReadFailureCode::InvalidResponse);
        }
        return [$node];
    }
}
