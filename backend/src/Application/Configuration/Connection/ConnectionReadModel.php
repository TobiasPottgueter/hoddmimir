<?php

declare(strict_types=1);

namespace App\Application\Configuration\Connection;

use App\Application\Inventory\ReadModel\PageRequest;

interface ConnectionReadModel
{
    /** @return array{items: list<array<string, mixed>>, page: array{limit: int, count: int, hasMore: bool, nextCursor: ?string}} */
    public function connections(PageRequest $page): array;

    /** @return array<string, mixed>|null */
    public function connection(string $id): ?array;
}
