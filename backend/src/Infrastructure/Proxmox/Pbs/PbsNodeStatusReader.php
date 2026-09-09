<?php

declare(strict_types=1);

namespace App\Infrastructure\Proxmox\Pbs;

use App\Application\Proxmox\Pbs\PbsNodeStatus;
use App\Application\Proxmox\Pbs\PbsReadFailure;
use App\Application\Proxmox\Pbs\PbsReadFailureCode;

final readonly class PbsNodeStatusReader
{
    public function read(PbsApiEnvelope $envelope, string $node): PbsNodeStatus
    {
        $data = $envelope->data;
        $memory = $data instanceof \stdClass ? ($data->memory ?? null) : null;
        $root = $data instanceof \stdClass ? ($data->root ?? null) : null;
        if (!$data instanceof \stdClass || !$memory instanceof \stdClass || !$root instanceof \stdClass) {
            throw PbsReadFailure::for(PbsReadFailureCode::InvalidResponse);
        }
        $uptime = $data->uptime ?? null;
        $memoryTotal = $memory->total ?? null;
        $memoryUsed = $memory->used ?? null;
        $rootTotal = $root->total ?? null;
        $rootUsed = $root->used ?? null;
        $rootAvailable = $root->avail ?? null;
        $values = [$uptime, $memoryTotal, $memoryUsed, $rootTotal, $rootUsed, $rootAvailable];
        foreach ($values as $value) {
            if (!is_int($value) || $value < 0) {
                throw PbsReadFailure::for(PbsReadFailureCode::InvalidResponse);
            }
        }
        if ($values[2] > $values[1] || $values[4] > $values[3] || $values[5] > $values[3]) {
            throw PbsReadFailure::for(PbsReadFailureCode::InvalidResponse);
        }
        assert(is_int($uptime) && is_int($memoryTotal) && is_int($memoryUsed));
        assert(is_int($rootTotal) && is_int($rootUsed) && is_int($rootAvailable));
        return new PbsNodeStatus($node, $uptime, $memoryTotal, $memoryUsed, $rootTotal, $rootUsed, $rootAvailable);
    }
}
