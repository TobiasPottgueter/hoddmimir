<?php

declare(strict_types=1);

namespace App\Application\Security\Auth;

interface CsrfTokenDeriver
{
    public function derive(OpaqueToken $sessionToken): OpaqueToken;
}
