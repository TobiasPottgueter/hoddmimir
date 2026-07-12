<?php

declare(strict_types=1);

namespace App\Application\Inventory\ReadModel;

use InvalidArgumentException;

final readonly class ReadPage
{
    /** @param list<InventoryResource|CollectorRun|CollectorScope> $items */
    public function __construct(
        public PageRequest $page,
        public array $items,
        public ?PageCursor $nextCursor,
    ) {
        if (count($this->items) > $this->page->limit) {
            throw new InvalidArgumentException('A read-model page exceeds its requested limit.');
        }
        if (null !== $this->nextCursor && [] === $this->items) {
            throw new InvalidArgumentException('An empty read-model page cannot have a continuation cursor.');
        }
    }

    /** @return array{items: list<array<string, mixed>>, page: array{limit: int, count: int, hasMore: bool, nextCursor: ?string}} */
    public function toArray(): array
    {
        return [
            'items' => array_map(
                static fn (InventoryResource|CollectorRun|CollectorScope $item): array => $item->toArray(),
                $this->items,
            ),
            'page' => [
                'limit' => $this->page->limit,
                'count' => count($this->items),
                'hasMore' => null !== $this->nextCursor,
                'nextCursor' => $this->nextCursor?->opaque(),
            ],
        ];
    }
}
