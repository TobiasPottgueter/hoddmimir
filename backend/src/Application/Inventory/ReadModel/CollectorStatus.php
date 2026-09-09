<?php

declare(strict_types=1);

namespace App\Application\Inventory\ReadModel;

final readonly class CollectorStatus
{
    /**
     * @param array<string, bool|int|string|null> $schedule
     * @param array<string, bool|string|null>|null $heartbeat
     */
    public function __construct(
        public string $generatedAt,
        public array $schedule,
        public ?array $heartbeat,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'generatedAt' => $this->generatedAt,
            'schedule' => $this->schedule,
            'heartbeat' => $this->heartbeat,
        ];
    }
}
