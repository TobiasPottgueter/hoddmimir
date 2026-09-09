<?php

declare(strict_types=1);

namespace App\Application\Proxmox\Pbs;

use InvalidArgumentException;

final readonly class PbsInstanceIdentity
{
    public function __construct(public string $value)
    {
        if (32 !== strlen($value) || 32 !== strspn($value, '0123456789abcdef')) {
            throw new InvalidArgumentException('The PBS instance identity is invalid.');
        }
    }
}
