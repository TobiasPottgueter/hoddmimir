<?php

declare(strict_types=1);

namespace App\Application\Proxmox\Pbs;

use InvalidArgumentException;

final readonly class PbsNamespace
{
    public function __construct(public string $value)
    {
        $segments = explode('/', $value);
        if ('' === $value || strlen($value) > 256 || count($segments) > 8) {
            throw new InvalidArgumentException('The PBS namespace is invalid.');
        }
        foreach ($segments as $segment) {
            $length = strlen($segment);
            if (0 === $length
                || 1 !== strspn($segment[0], 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789_')
                || $length !== strspn(
                    $segment,
                    'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789_.-',
                )) {
                throw new InvalidArgumentException('The PBS namespace is invalid.');
            }
        }
    }
}
