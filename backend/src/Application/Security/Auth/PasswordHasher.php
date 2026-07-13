<?php

declare(strict_types=1);

namespace App\Application\Security\Auth;

use App\Application\Security\PlaintextSecret;

interface PasswordHasher
{
    public function hash(PlaintextSecret $password): PasswordHash;
    public function verify(PlaintextSecret $password, PasswordHash $hash): bool;
    public function needsRehash(PasswordHash $hash): bool;
}
