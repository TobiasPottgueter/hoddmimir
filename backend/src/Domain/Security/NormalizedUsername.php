<?php

declare(strict_types=1);

namespace App\Domain\Security;

use InvalidArgumentException;

final readonly class NormalizedUsername
{
    public string $value;
    public function __construct(string $username)
    {
        $value = \strtolower(\trim($username));
        if (\strlen($value) < 3) {
            throw new InvalidArgumentException('The normalized username is invalid.');
        }
        if (\strlen($value) > 64) {
            throw new InvalidArgumentException('The normalized username is invalid.');
        }
        if (1 !== \preg_match('/\A[a-z0-9][a-z0-9._-]*\z/D', $value)) {
            throw new InvalidArgumentException('The normalized username is invalid.');
        }
        $this->value = $value;
    }
}
