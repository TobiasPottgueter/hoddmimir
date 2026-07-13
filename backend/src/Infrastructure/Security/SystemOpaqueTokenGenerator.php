<?php

declare(strict_types=1);

namespace App\Infrastructure\Security;

use App\Application\Security\Auth\OpaqueToken;
use App\Application\Security\Auth\OpaqueTokenGenerator;

final readonly class SystemOpaqueTokenGenerator implements OpaqueTokenGenerator
{
    public function generate(): OpaqueToken
    {
        return new OpaqueToken(random_bytes(32));
    }
}
