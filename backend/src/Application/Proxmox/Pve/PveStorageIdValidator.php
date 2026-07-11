<?php

declare(strict_types=1);

namespace App\Application\Proxmox\Pve;

final readonly class PveStorageIdValidator
{
    public static function isValid(string $value): bool
    {
        if (!isset($value[0]) || !isset($value[1])) {
            return false;
        }

        $lastIndex = 0;
        for ($index = 0; isset($value[$index]); ++$index) {
            $character = $value[$index];
            if (0 === $index) {
                if (!self::isAsciiLetter($character)) {
                    return false;
                }
            } elseif (!self::isAsciiLetter($character)
                && !self::isDigit($character)
                && '.' !== $character
                && '_' !== $character
                && '-' !== $character) {
                return false;
            }

            $lastIndex = $index;
        }

        $lastCharacter = $value[$lastIndex];
        return self::isAsciiLetter($lastCharacter) || self::isDigit($lastCharacter);
    }

    private static function isAsciiLetter(string $character): bool
    {
        return ($character >= 'a' && $character <= 'z')
            || ($character >= 'A' && $character <= 'Z');
    }

    private static function isDigit(string $character): bool
    {
        return $character >= '0' && $character <= '9';
    }
}
