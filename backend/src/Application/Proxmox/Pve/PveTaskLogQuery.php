<?php

declare(strict_types=1);

namespace App\Application\Proxmox\Pve;

use InvalidArgumentException;

final readonly class PveTaskLogQuery
{
    public const int MAXIMUM_PAGE_SIZE = 500;

    public function __construct(
        public int $start = 0,
        public int $limit = 100,
    ) {
        if ($start < 0 || $limit < 1 || $limit > self::MAXIMUM_PAGE_SIZE || $start > PHP_INT_MAX - $limit) {
            throw new InvalidArgumentException('The PVE task log page bounds are invalid.');
        }
    }

    /** @return array{start: int, limit: int} */
    public function parameters(): array
    {
        return ['start' => $this->start, 'limit' => $this->limit];
    }

    public function nextPage(): self
    {
        return new self($this->start + $this->limit, $this->limit);
    }
}
