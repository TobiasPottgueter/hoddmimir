<?php

declare(strict_types=1);

namespace App\Domain\Policy;

use DomainException;

final class PolicyActivationFailed extends DomainException
{
    /** @param non-empty-list<PolicyActivationBlocker> $blockers */
    public function __construct(public readonly array $blockers)
    {
        parent::__construct('The backup policy cannot be activated.');
    }
}
