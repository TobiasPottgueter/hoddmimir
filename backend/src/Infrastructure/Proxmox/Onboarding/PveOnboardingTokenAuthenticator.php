<?php

declare(strict_types=1);

namespace App\Infrastructure\Proxmox\Onboarding;

use App\Application\Security\PlaintextSecret;
use App\Infrastructure\Proxmox\PveApiTokenIdentity;
use App\Infrastructure\Proxmox\PveRequestAuthenticator;

final readonly class PveOnboardingTokenAuthenticator implements PveRequestAuthenticator
{
    public function __construct(
        private PveApiTokenIdentity $identity,
        private PlaintextSecret $secret,
    ) {
    }

    public function authorize(callable $request): mixed
    {
        return $this->secret->consume(
            fn (string $secret): mixed => $request($this->identity->authorizationPrefix().$secret),
        );
    }
}
