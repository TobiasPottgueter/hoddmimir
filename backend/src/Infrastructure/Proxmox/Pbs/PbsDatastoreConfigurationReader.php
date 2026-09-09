<?php

declare(strict_types=1);

namespace App\Infrastructure\Proxmox\Pbs;

use App\Application\Proxmox\Pbs\PbsDatastoreConfigurationSnapshot;
use App\Application\Proxmox\Pbs\PbsDatastoreId;
use App\Application\Proxmox\Pbs\PbsReadFailure;
use App\Application\Proxmox\Pbs\PbsReadFailureCode;
use App\Infrastructure\Validation\AsciiPatternValidator;
use InvalidArgumentException;

final readonly class PbsDatastoreConfigurationReader
{
    public function read(PbsApiEnvelope $envelope): PbsDatastoreConfigurationSnapshot
    {
        if (!is_array($envelope->data) || !is_string($envelope->digest)
            || !AsciiPatternValidator::matches('/\A[0-9a-f]{64}\z/D', $envelope->digest)) {
            throw PbsReadFailure::for(PbsReadFailureCode::InvalidResponse);
        }
        $ids = [];
        foreach ($envelope->data as $row) {
            $name = $row instanceof \stdClass ? ($row->name ?? null) : null;
            if (!is_string($name)) {
                throw PbsReadFailure::for(PbsReadFailureCode::InvalidResponse);
            }
            try {
                $id = new PbsDatastoreId($name);
            } catch (InvalidArgumentException) {
                throw PbsReadFailure::for(PbsReadFailureCode::InvalidResponse);
            }
            if (isset($ids[$id->value])) {
                throw PbsReadFailure::for(PbsReadFailureCode::InvalidResponse);
            }
            $ids[$id->value] = $id;
        }
        ksort($ids, SORT_STRING);
        return new PbsDatastoreConfigurationSnapshot($envelope->digest, array_values($ids));
    }
}
