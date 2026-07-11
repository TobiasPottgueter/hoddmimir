<?php

declare(strict_types=1);

namespace App\Infrastructure\Proxmox\Pbs;

use App\Application\Proxmox\Pbs\PbsDatastoreBackendType;
use App\Application\Proxmox\Pbs\PbsDatastoreCapacity;
use App\Application\Proxmox\Pbs\PbsDatastoreId;
use App\Application\Proxmox\Pbs\PbsReadFailure;
use App\Application\Proxmox\Pbs\PbsReadFailureCode;
use App\Application\Proxmox\Pbs\PbsVersion;
use ValueError;

final readonly class PbsDatastoreStatusReader
{
    public function read(
        PbsApiEnvelope $envelope,
        PbsDatastoreId $id,
        PbsDatastoreBackendType $expectedBackend,
        PbsVersion $version,
    ): PbsDatastoreCapacity {
        $data = $envelope->data;
        if (!$data instanceof \stdClass) {
            throw PbsReadFailure::for(PbsReadFailureCode::InvalidResponse);
        }
        $total = $data->total ?? null;
        $used = $data->used ?? null;
        $available = $data->avail ?? null;
        $values = [$total, $used, $available];
        foreach ($values as $value) {
            if (!is_int($value) || $value < 0) {
                throw PbsReadFailure::for(PbsReadFailureCode::InvalidResponse);
            }
        }
        if ($values[1] > $values[0] || $values[2] > $values[0]) {
            throw PbsReadFailure::for(PbsReadFailureCode::InvalidResponse);
        }
        $backend = PbsDatastoreBackendType::Filesystem;
        if (4 === $version->major) {
            try {
                $backend = PbsDatastoreBackendType::from(is_string($data->{'backend-type'} ?? null) ? $data->{'backend-type'} : '');
            } catch (ValueError) {
                throw PbsReadFailure::for(PbsReadFailureCode::InvalidResponse);
            }
        }
        if ($backend !== $expectedBackend) {
            throw PbsReadFailure::for(PbsReadFailureCode::InvalidResponse);
        }
        assert(is_int($total) && is_int($used) && is_int($available));
        return new PbsDatastoreCapacity($id, $backend, $total, $used, $available);
    }
}
