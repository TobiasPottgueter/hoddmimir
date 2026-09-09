<?php

declare(strict_types=1);

namespace App\Application\Proxmox\Pbs;

use InvalidArgumentException;

final readonly class PbsJobId
{
    public function __construct(public string $value)
    {
        $length = strlen($value);
        if ($length < 3 || $length > 32
            || 1 !== strspn($value[0], 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789_')
            || $length !== strspn(
                $value,
                'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789_.-',
            )) {
            throw new InvalidArgumentException('The PBS job identifier is invalid.');
        }
    }
}
