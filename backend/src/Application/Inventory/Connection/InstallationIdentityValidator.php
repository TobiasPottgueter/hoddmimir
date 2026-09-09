<?php

declare(strict_types=1);

namespace App\Application\Inventory\Connection;

final readonly class InstallationIdentityValidator
{
    public static function isValid(string $identity): bool
    {
        $length = strlen($identity);
        if (0 === $length) {
            return false;
        }
        if ($length > 255) {
            return false;
        }
        if (!ctype_alnum($identity[0])) {
            return false;
        }
        if (!ctype_alnum($identity[$length - 1])) {
            return false;
        }

        for ($index = 1; $index < $length - 1; ++$index) {
            $character = $identity[$index];
            if (ctype_alnum($character)) {
                continue;
            }
            if ('.' !== $character && '_' !== $character && '-' !== $character) {
                return false;
            }
        }

        return true;
    }
}
