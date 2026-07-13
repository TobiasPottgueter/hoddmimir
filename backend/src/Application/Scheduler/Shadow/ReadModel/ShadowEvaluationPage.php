<?php

declare(strict_types=1);

namespace App\Application\Scheduler\Shadow\ReadModel;

use App\Application\Inventory\ReadModel\PageCursor;
use App\Application\Inventory\ReadModel\PageRequest;

final readonly class ShadowEvaluationPage
{
    /** @param list<ShadowEvaluationSummary> $items */
    public function __construct(public PageRequest $page, public array $items, public ?PageCursor $nextCursor) {}
    /** @return array<string, mixed> */
    public function toArray(): array { return ['items' => array_map(static fn ($item) => $item->toArray(), $this->items), 'page' => ['limit' => $this->page->limit, 'count' => count($this->items), 'hasMore' => null !== $this->nextCursor, 'nextCursor' => $this->nextCursor?->opaque()]]; }
}
