<?php

declare(strict_types=1);

namespace App\Infrastructure\Proxmox\Pbs;

final readonly class PbsApiEnvelope
{
    public function __construct(public mixed $data, public ?string $digest)
    {
    }
}
