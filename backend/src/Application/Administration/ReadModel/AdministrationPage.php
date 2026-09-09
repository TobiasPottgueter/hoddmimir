<?php

declare(strict_types=1);

namespace App\Application\Administration\ReadModel;

use App\Application\Inventory\ReadModel\PageCursor;
use App\Application\Inventory\ReadModel\PageRequest;
use InvalidArgumentException;

final readonly class AdministrationPage
{
    /** @param list<AdministrationReadItem> $items */
    public function __construct(public PageRequest $page, public array $items, public ?PageCursor $nextCursor)
    {
        if (count($items) > $page->limit || (null !== $nextCursor && [] === $items)) {
            throw new InvalidArgumentException('The administration page is invalid.');
        }
    }

    /** @return array{items: list<array<string, mixed>>, page: array{limit: int, count: int, hasMore: bool, nextCursor: ?string}} */
    public function toArray(): array
    {
        return [
            'items' => array_map(static fn (AdministrationReadItem $item): array => $item->toArray(), $this->items),
            'page' => ['limit' => $this->page->limit, 'count' => count($this->items), 'hasMore' => null !== $this->nextCursor, 'nextCursor' => $this->nextCursor?->opaque()],
        ];
    }
}
