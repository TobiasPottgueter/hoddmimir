<?php

declare(strict_types=1);

namespace App\Application\Inventory\Connection;

use InvalidArgumentException;

final readonly class EndpointScanReference
{
    public function __construct(
        public EndpointId $endpointId,
        public int $priority,
    ) {
        if ($priority < 0 || $priority > 65_535) {
            throw new InvalidArgumentException('An endpoint priority must be an unsigned 16-bit integer.');
        }
    }
}
