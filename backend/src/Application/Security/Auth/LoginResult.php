<?php

declare(strict_types=1);

namespace App\Application\Security\Auth;

use App\Domain\Security\SessionWindow;

final readonly class LoginResult
{
    public function __construct(
        public OpaqueToken $sessionToken,
        public OpaqueToken $csrfToken,
        public AuthenticatedPrincipal $principal,
        public SessionWindow $window,
    ) {
    }
}
