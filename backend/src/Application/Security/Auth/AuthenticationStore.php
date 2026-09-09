<?php

declare(strict_types=1);

namespace App\Application\Security\Auth;

use App\Domain\Security\NormalizedUsername;

interface AuthenticationStore
{
    public function findEnabled(NormalizedUsername $username): ?AuthenticationIdentity;
}
