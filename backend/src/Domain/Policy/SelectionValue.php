<?php

declare(strict_types=1);

namespace App\Domain\Policy;

enum SelectionValue: string
{
    case Inherit = 'inherit';
    case Include = 'include';
    case Exclude = 'exclude';

    public function explicit(): bool
    {
        return self::Inherit !== $this;
    }
}
