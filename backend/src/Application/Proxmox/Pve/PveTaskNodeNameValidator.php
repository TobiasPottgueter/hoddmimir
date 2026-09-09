<?php

declare(strict_types=1);

namespace App\Application\Proxmox\Pve;

final readonly class PveTaskNodeNameValidator
{
    private const ALPHANUMERIC = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789';
    private const NODE_CHARACTERS = self::ALPHANUMERIC.'-';

    public static function isValid(string $node): bool
    {
        $length = strlen($node);
        return $length >= 1
            && $length <= 63
            && 1 === strspn($node, self::ALPHANUMERIC, 0, 1)
            && 1 === strspn($node, self::ALPHANUMERIC, -1, 1)
            && $length === strspn($node, self::NODE_CHARACTERS);
    }
}
