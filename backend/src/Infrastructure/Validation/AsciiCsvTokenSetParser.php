<?php

declare(strict_types=1);

namespace App\Infrastructure\Validation;

final readonly class AsciiCsvTokenSetParser
{
    /** @return null|list<string> */
    public static function parse(string $value): ?array
    {
        if ('' === $value) {
            return null;
        }

        $normalized = [];
        foreach (explode(',', $value) as $token) {
            $token = trim($token);
            if (!AsciiPatternValidator::matches('/\A[A-Za-z0-9][A-Za-z0-9._-]*\z/D', $token)) {
                return null;
            }

            $normalized[$token] = true;
        }

        $tokens = array_keys($normalized);
        sort($tokens, SORT_STRING);
        return $tokens;
    }
}
