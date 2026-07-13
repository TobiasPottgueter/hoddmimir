<?php

declare(strict_types=1);

namespace App\Infrastructure\Security;

use App\Application\Security\Auth\SecurityIdentifierGenerator;

final readonly class SystemSecurityIdentifierGenerator implements SecurityIdentifierGenerator
{
    public function generate(): string
    {
        return random_bytes(16);
    }
}
