<?php

declare(strict_types=1);

namespace App\Application\Proxmox\Pbs;

use RuntimeException;

final class PbsReadFailure extends RuntimeException
{
    private function __construct(public readonly PbsReadFailureCode $failureCode)
    {
        parent::__construct('The PBS read operation failed.');
    }

    public static function for(PbsReadFailureCode $failureCode): self
    {
        return new self($failureCode);
    }
}
