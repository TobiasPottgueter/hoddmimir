<?php

declare(strict_types=1);

namespace App\Application\Configuration\Connection\Onboarding;

use InvalidArgumentException;

final readonly class OnboardingIdentityEvidence
{
    /** @var list<OnboardingPermission> */ public array $permissions;

    /** @param list<OnboardingPermission> $permissions */
    public function __construct(
        public OnboardingCredentialKind $kind,
        public OnboardingProduct $detectedProduct,
        public int $major,
        public int $minor,
        public string $rawVersion,
        array $permissions,
    ) {
        if ($major < 1 || $minor < 0 || '' === $rawVersion || strlen($rawVersion) > 255) {
            throw new InvalidArgumentException('The onboarding identity evidence is invalid.');
        }
        $this->permissions = $permissions;
    }
}
