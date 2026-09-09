<?php

declare(strict_types=1);

namespace App\Application\Backup\Operations;

use App\Application\Inventory\ReadModel\PageCursor;
use App\Application\Inventory\ReadModel\PageRequest;

final readonly class OperationsPage
{
    /** @param list<array<string, mixed>> $items */
    public function __construct(public PageRequest $page, public array $items, public ?PageCursor $nextCursor) {}

    /** @return array{items: list<array<string, mixed>>, page: array{limit: int, count: int, hasMore: bool, nextCursor: ?string}} */
    public function toArray(): array
    {
        return ['items' => $this->items, 'page' => [
            'limit' => $this->page->limit, 'count' => count($this->items),
            'hasMore' => null !== $this->nextCursor, 'nextCursor' => $this->nextCursor?->opaque(),
        ]];
    }
}
