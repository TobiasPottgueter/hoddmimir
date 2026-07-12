<?php

declare(strict_types=1);

namespace App\Infrastructure\Proxmox\Pbs;

use App\Application\Proxmox\Pbs\PbsNamespace;
use App\Application\Proxmox\Pbs\PbsReadFailure;
use App\Application\Proxmox\Pbs\PbsReadFailureCode;

final readonly class PbsNamespaceListReader
{
    /** @return list<PbsNamespace> */
    public function read(PbsApiEnvelope $envelope): array
    {
        if (!is_array($envelope->data)) {
            throw PbsReadFailure::for(PbsReadFailureCode::InvalidResponse);
        }
        if (!array_is_list($envelope->data)) {
            throw PbsReadFailure::for(PbsReadFailureCode::InvalidResponse);
        }
        $rows = [];
        try {
            foreach ($envelope->data as $row) {
                if (!$row instanceof \stdClass) {
                    throw PbsReadFailure::for(PbsReadFailureCode::InvalidResponse);
                }
                $value = $row->ns ?? null;
                if (!is_string($value)) {
                    throw PbsReadFailure::for(PbsReadFailureCode::InvalidResponse);
                }
                $namespace = new PbsNamespace($value);
                if (isset($rows[$namespace->value])) {
                    throw PbsReadFailure::for(PbsReadFailureCode::InvalidResponse);
                }
                $rows[$namespace->value] = $namespace;
            }
        } catch (\InvalidArgumentException) {
            throw PbsReadFailure::for(PbsReadFailureCode::InvalidResponse);
        }
        ksort($rows, SORT_STRING);
        return array_values($rows);
    }
}
