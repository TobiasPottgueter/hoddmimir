<?php

declare(strict_types=1);

namespace App\Application\Scheduler\Shadow;

use App\Domain\Scheduler\GateResult;
use InvalidArgumentException;

final readonly class OrderedShadowGate
{
    public function __construct(
        public int $position,
        public GateResult $result,
    ) {
        if ($this->position < 1) {
            throw new InvalidArgumentException('A shadow gate position must be positive.');
        }
    }
}
