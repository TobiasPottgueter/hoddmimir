<?php

declare(strict_types=1);

namespace App\Application\Backup\Execution;

final readonly class PveExecutorIdentityValidator
{
    public static function owner(string $identity): bool
    {
        return \strlen($identity) <= 64
            && 1 === \preg_match('/\A[A-Za-z0-9._+-]+@[A-Za-z][A-Za-z0-9._-]+\z/D', $identity);
    }

    public static function token(string $identity): bool
    {
        $separator = \strrpos($identity, '!');
        if (false === $separator) {
            return false;
        }
        $owner = \substr($identity, 0, $separator);
        $token = \substr($identity, $separator + 1);

        return self::owner($owner)
            && 1 === \preg_match('/\A[A-Za-z][A-Za-z0-9._-]{1,63}\z/D', $token);
    }

    public static function group(string $identity): bool
    {
        return 1 === \preg_match('/\A[A-Za-z][A-Za-z0-9._-]{0,254}\z/D', $identity);
    }
}
