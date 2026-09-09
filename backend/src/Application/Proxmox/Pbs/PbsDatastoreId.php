<?php

declare(strict_types=1);

namespace App\Application\Proxmox\Pbs;

use InvalidArgumentException;

final readonly class PbsDatastoreId
{
    public function __construct(public string $value)
    {
        $length = strlen($value);
        $validLength = $length >= 3 && $length <= 32;
        $validFirstCharacter = $length > 0 && (ctype_alnum($value[0]) || '_' === $value[0]);
        $validCharacters = $length === strspn($value, 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789._-');
        if (!$validLength || !$validFirstCharacter || !$validCharacters) {
            throw new InvalidArgumentException('The PBS datastore identifier is invalid.');
        }
    }
}
