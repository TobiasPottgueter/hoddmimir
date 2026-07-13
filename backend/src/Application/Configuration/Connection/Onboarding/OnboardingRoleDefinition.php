<?php

declare(strict_types=1);

namespace App\Application\Configuration\Connection\Onboarding;

use InvalidArgumentException;

final readonly class OnboardingRoleDefinition
{
    /** @var list<string> */ public array $privileges;

    /** @param list<string> $privileges */
    public function __construct(public string $name, array $privileges)
    {
        if ('' === $name) {
            throw new InvalidArgumentException('The onboarding role definition is invalid.');
        }
        if (strlen($name) > 64) {
            throw new InvalidArgumentException('The onboarding role definition is invalid.');
        }
        $unique = [];
        foreach ($privileges as $privilege) {
            if (!OnboardingAsciiValidator::isPrivilege($privilege)) {
                throw new InvalidArgumentException('The onboarding role definition is invalid.');
            }
            $unique[$privilege] = true;
        }
        $values = array_keys($unique);
        sort($values);
        $this->privileges = $values;
    }
}
