<?php

declare(strict_types=1);

namespace App\Application\Inventory\ReadModel;

final readonly class InventoryOverview
{
    /** @param array<string, int> $counts */
    public function __construct(
        public string $generatedAt,
        public ?string $latestInventoryAt,
        public array $counts,
    ) {}

    /** @return array{generatedAt: string, latestInventoryAt: ?string, counts: array<string, int>} */
    public function toArray(): array
    {
        return [
            'generatedAt' => $this->generatedAt,
            'latestInventoryAt' => $this->latestInventoryAt,
            'counts' => $this->counts,
        ];
    }
}
