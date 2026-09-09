<?php

declare(strict_types=1);

namespace App\Application\Configuration\Connection\Onboarding;

use InvalidArgumentException;

final readonly class OnboardingRemoteEvidence
{
    /** @var list<OnboardingIdentityEvidence> */ public array $identities;
    /** @var list<OnboardingRoleDefinition> */ public array $roles;

    /**
     * @param list<OnboardingIdentityEvidence> $identities
     * @param list<OnboardingRoleDefinition>    $roles
     */
    public function __construct(public bool $tlsVerified, array $identities, array $roles = [])
    {
        $byKind = [];
        foreach ($identities as $identity) {
            if (isset($byKind[$identity->kind->value])) {
                throw new InvalidArgumentException('The onboarding remote evidence is invalid.');
            }
            $byKind[$identity->kind->value] = $identity;
        }
        $this->identities = array_values($byKind);
        $this->roles = $roles;
    }

    public function identity(OnboardingCredentialKind $kind): ?OnboardingIdentityEvidence
    {
        foreach ($this->identities as $identity) {
            if ($identity->kind === $kind) {
                return $identity;
            }
        }
        return null;
    }
}
