<?php

declare(strict_types=1);

namespace App\Application\Inventory\Connection;

use RuntimeException;

final class EndpointReadFailure extends RuntimeException
{
    private function __construct(public readonly EndpointReadFailureCode $failureCode)
    {
        parent::__construct('The Proxmox endpoint read failed.');
    }

    public static function for(EndpointReadFailureCode $failureCode): self
    {
        return new self($failureCode);
    }
}
