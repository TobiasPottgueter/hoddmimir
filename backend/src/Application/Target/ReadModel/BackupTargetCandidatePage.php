<?php

declare(strict_types=1);

namespace App\Application\Target\ReadModel;

use App\Application\Inventory\ReadModel\PageCursor;
use App\Application\Inventory\ReadModel\PageRequest;
use InvalidArgumentException;

final readonly class BackupTargetCandidatePage
{
    /** @param list<BackupTargetCandidate> $items */
    public function __construct(
        public PageRequest $page,
        public array $items,
        public ?PageCursor $nextCursor,
    ) {
        if (count($items) > $page->limit || (null !== $nextCursor && [] === $items)) {
            throw new InvalidArgumentException('The backup-target candidate page is invalid.');
        }
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'items' => array_map(static fn (BackupTargetCandidate $item): array => $item->toArray(), $this->items),
            'page' => [
                'limit' => $this->page->limit,
                'count' => count($this->items),
                'hasMore' => null !== $this->nextCursor,
                'nextCursor' => $this->nextCursor?->opaque(),
            ],
        ];
    }
}
