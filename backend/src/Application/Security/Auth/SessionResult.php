<?php

declare(strict_types=1);

namespace App\Application\Security\Auth;

use App\Domain\Security\SessionWindow;

final readonly class SessionResult
{
    public function __construct(
        public string $sessionId,
        public AuthenticatedPrincipal $principal,
        public OpaqueToken $csrfToken,
        public SessionWindow $window,
    ) {
    }
}
