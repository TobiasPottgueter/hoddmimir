<?php

declare(strict_types=1);

namespace App\Application\Configuration\Connection\Onboarding;

use InvalidArgumentException;

final readonly class OnboardingPermission
{
    public function __construct(
        public string $path,
        public string $privilege,
        public bool $granted,
        public bool $propagated,
    ) {
        if (!OnboardingAsciiValidator::isPermissionPath($path)) {
            throw new InvalidArgumentException('The normalized onboarding permission is invalid.');
        }
        if (!OnboardingAsciiValidator::isPrivilege($privilege)) {
            throw new InvalidArgumentException('The normalized onboarding permission is invalid.');
        }
    }
}
