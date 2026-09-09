<?php

declare(strict_types=1);

namespace App\Domain\Target;

enum TargetStatus: string
{
    case Disabled = 'disabled';
    case Enabled = 'enabled';

    public function executable(): bool
    {
        return self::Enabled === $this;
    }
}
