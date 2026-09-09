<?php

declare(strict_types=1);

namespace App\Infrastructure\Validation;

final readonly class AsciiPatternValidator
{
    public static function matches(string $pattern, string $value): bool
    {
        return 1 === preg_match($pattern, $value);
    }
}
