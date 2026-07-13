<?php

declare(strict_types=1);

namespace App\Infrastructure\Proxmox\Onboarding;

use App\Application\Configuration\Connection\Onboarding\OnboardingAsciiValidator;
use App\Application\Proxmox\Pbs\PbsReadFailure;
use App\Application\Proxmox\Pbs\PbsReadFailureCode;
use App\Application\Security\PlaintextSecret;
use App\Infrastructure\Proxmox\Pbs\PbsApiTokenIdentity;
use App\Infrastructure\Proxmox\Pbs\PbsRequestAuthenticator;

final readonly class PbsOnboardingTokenAuthenticator implements PbsRequestAuthenticator
{
    public function __construct(
        private PbsApiTokenIdentity $identity,
        private PlaintextSecret $secret,
    ) {
    }

    public function authorize(callable $request): mixed
    {
        return $this->secret->consume(function (string $secret) use ($request): mixed {
            if (!OnboardingAsciiValidator::isLowerUuid($secret)) {
                throw PbsReadFailure::for(PbsReadFailureCode::CredentialUnavailable);
            }

            return $request($this->identity->authorizationPrefix().$secret);
        });
    }
}
