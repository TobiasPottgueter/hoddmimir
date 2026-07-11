<?php

declare(strict_types=1);

namespace App\Application\Inventory\Pve;

final readonly class PveCoreTextValidator
{
    private const string ASCII_ALPHANUMERIC = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789';
    private const string PRINTABLE_ASCII = self::ASCII_ALPHANUMERIC.'!"#$%&\'()*+,-./:;<=>?@[\\]^_`{|}~';
    private const string NODE_CHARACTERS = self::ASCII_ALPHANUMERIC.'._-';
    private const string LOWERCASE = 'abcdefghijklmnopqrstuvwxyz';
    private const string ERROR_CODE_CHARACTERS = self::LOWERCASE.'0123456789_';

    public static function isPrintableBinding(string $value): bool
    {
        $length = strlen($value);

        return $length >= 1
            && $length <= 190
            && $length === strspn($value, self::PRINTABLE_ASCII);
    }

    public static function isNodeName(string $value): bool
    {
        $length = strlen($value);

        return $length >= 1
            && $length <= 190
            && 1 === strspn($value, self::ASCII_ALPHANUMERIC, 0, 1)
            && $length === strspn($value, self::NODE_CHARACTERS);
    }

    public static function isErrorCode(string $value): bool
    {
        $length = strlen($value);

        return $length >= 1
            && $length <= 64
            && 1 === strspn($value, self::LOWERCASE, 0, 1)
            && $length === strspn($value, self::ERROR_CODE_CHARACTERS);
    }
}
