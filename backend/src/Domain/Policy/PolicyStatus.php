<?php

declare(strict_types=1);

namespace App\Domain\Policy;

enum PolicyStatus: string
{
    case Draft = 'draft';
    case Enabled = 'enabled';
    case Disabled = 'disabled';

    public function executable(): bool
    {
        return self::Enabled === $this;
    }
}
