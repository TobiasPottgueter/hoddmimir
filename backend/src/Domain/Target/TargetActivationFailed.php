<?php

declare(strict_types=1);

namespace App\Domain\Target;

use DomainException;

final class TargetActivationFailed extends DomainException
{
    /** @param non-empty-list<TargetActivationBlocker> $blockers */
    public function __construct(public readonly array $blockers)
    {
        parent::__construct('The backup target cannot be enabled.');
    }
}
