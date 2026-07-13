<?php

declare(strict_types=1);

namespace App\Application\Security\Auth;

use App\Domain\Security\SessionPolicy;
use App\Domain\Shared\Clock;

final readonly class AuthenticateSession
{
    public function __construct(
        private SecurityTransaction $transaction,
        private WebSessionStore $sessions,
        private OpaqueSecretHasher $digests,
        private CsrfTokenDeriver $csrfTokens,
        private Clock $clock,
        private SessionPolicy $policy = new SessionPolicy(),
    ) {
    }

    public function authenticate(OpaqueToken $sessionToken): SessionResult
    {
        return $this->transaction->run(function () use ($sessionToken): SessionResult {
            $digest = $this->digests->digestToken($sessionToken);
            $stored = $this->sessions->lockSession($digest);
            $now = $this->clock->now();
            if (null === $stored || null !== $stored->revokedAt || $stored->window->isExpiredAt($now)) {
                throw new AuthenticationFailed();
            }
            $csrfToken = $this->csrfTokens->derive($sessionToken);
            if (!$stored->csrfHash->equals($this->digests->digestToken($csrfToken))) {
                throw new AuthenticationFailed();
            }
            $window = $this->policy->touch($stored->window, $now);
            $this->sessions->touch($digest, $window);

            $principal = new AuthenticatedPrincipal(
                $stored->principal->userId,
                $stored->principal->username,
                $stored->principal->permissions,
                $stored->id,
            );

            return new SessionResult($stored->id, $principal, $csrfToken, $window);
        });
    }
}
