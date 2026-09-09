<?php

declare(strict_types=1);

namespace App\Application\Monitoring;

use App\Application\Inventory\ReadModel\ReadModelIdentifier;

interface PbsTaskReadModel
{
    /** @return array{items: list<array<string, mixed>>, total: int} */
    public function tasks(?ReadModelIdentifier $connectionId, int $offset): array;
    /** @return array<string, mixed>|null */
    public function detail(ReadModelIdentifier $id): ?array;
}
