<?php

declare(strict_types=1);

namespace App\Infrastructure\Security;

use App\Application\Security\Auth\OpaqueSecretHasher;
use App\Application\Security\Auth\OpaqueToken;
use App\Application\Security\Auth\SecurityDigest;
use App\Application\Security\PlaintextSecret;

final readonly class Sha256OpaqueSecretHasher implements OpaqueSecretHasher
{
    public function digest(PlaintextSecret $secret): SecurityDigest
    {
        return $secret->consume(static fn (string $value): SecurityDigest => new SecurityDigest(hash('sha256', $value, true)));
    }

    public function digestToken(OpaqueToken $token): SecurityDigest
    {
        return new SecurityDigest(hash('sha256', $token->bytes(), true));
    }
}
