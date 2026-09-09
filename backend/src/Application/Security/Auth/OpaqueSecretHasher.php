<?php

declare(strict_types=1);

namespace App\Application\Security\Auth;

use App\Application\Security\PlaintextSecret;

interface OpaqueSecretHasher
{
    public function digest(PlaintextSecret $secret): SecurityDigest;
    public function digestToken(OpaqueToken $token): SecurityDigest;
}
