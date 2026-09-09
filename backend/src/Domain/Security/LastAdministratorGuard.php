<?php

declare(strict_types=1);

namespace App\Domain\Security;

use InvalidArgumentException;

final readonly class LastAdministratorGuard
{
    public function assertNotSelfLockout(bool $targetsActor, bool $removesAdministrativeAccess): void
    {
        if ($targetsActor && $removesAdministrativeAccess) {
            throw new LastAdministratorViolation('An administrator cannot remove their own administrative access.');
        }
    }

    public function assertCanRemove(bool $targetIsEnabledAdministrator, int $enabledAdministratorCount): void
    {
        if ($enabledAdministratorCount < 0) {
            throw new InvalidArgumentException('The enabled administrator count cannot be negative.');
        }
        if ($targetIsEnabledAdministrator && $enabledAdministratorCount <= 1) {
            throw new LastAdministratorViolation('The last enabled administrator cannot be removed or disabled.');
        }
    }
}
