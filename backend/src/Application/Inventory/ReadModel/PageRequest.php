<?php

declare(strict_types=1);

namespace App\Application\Inventory\ReadModel;

use InvalidArgumentException;

final readonly class PageRequest
{
    public function __construct(
        public int $limit = 50,
        public ?PageCursor $cursor = null,
    ) {
        if ($this->limit < 1 || $this->limit > 100) {
            throw new InvalidArgumentException('The inventory pagination is outside the supported bounds.');
        }
    }
}
