<?php

declare(strict_types=1);

namespace App\Domain\Policy;

final readonly class EffectiveSelection
{
    public function __construct(
        public bool $included,
        public ?SelectionScope $decidedBy,
        public SelectionValue $value,
    ) {
    }
}
