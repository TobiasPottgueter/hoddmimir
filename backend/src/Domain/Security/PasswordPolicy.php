<?php

declare(strict_types=1);

namespace App\Domain\Security;

use InvalidArgumentException;
use SensitiveParameter;

final readonly class PasswordPolicy
{
    public const int MINIMUM_BYTES = 12;
    public const int MAXIMUM_BYTES = 128;

    public function assertValid(#[SensitiveParameter] string $password): void
    {
        $length = strlen($password);
        if ($length < self::MINIMUM_BYTES || $length > self::MAXIMUM_BYTES) {
            throw new InvalidArgumentException('The administrator password length is invalid.');
        }
    }
}
