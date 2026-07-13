<?php

declare(strict_types=1);

namespace App\Application\Configuration\Connection\Onboarding;

use App\Application\Security\PlaintextSecret;
use InvalidArgumentException;
use SensitiveParameter;

final readonly class OnboardingCredential
{
    public PlaintextSecret $secret;

    public function __construct(
        public OnboardingCredentialKind $kind,
        public string $tokenId,
        #[SensitiveParameter] string $tokenSecret,
    ) {
        if (strlen($tokenId) < 5) {
            throw new InvalidArgumentException('The onboarding token identifier is invalid.');
        }
        if (strlen($tokenId) > 190) {
            throw new InvalidArgumentException('The onboarding token identifier is invalid.');
        }
        if (!OnboardingAsciiValidator::isTokenId($tokenId)) {
            throw new InvalidArgumentException('The onboarding token identifier is invalid.');
        }
        $this->secret = PlaintextSecret::fromString($tokenSecret);
    }
}
