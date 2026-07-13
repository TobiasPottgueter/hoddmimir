<?php

declare(strict_types=1);

namespace App\Domain\Security;

use InvalidArgumentException;

final readonly class UserDisplayName
{
    public string $value;
    public function __construct(string $name)
    {
        $value = \trim($name);
        if ('' === $value) {
            throw new InvalidArgumentException('The user display name is invalid.');
        }
        if (\strlen($value) > 190) {
            throw new InvalidArgumentException('The user display name is invalid.');
        }
        $this->value = $value;
    }
}
