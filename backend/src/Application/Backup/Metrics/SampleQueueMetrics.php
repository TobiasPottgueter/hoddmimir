<?php

declare(strict_types=1);

namespace App\Application\Backup\Metrics;

use App\Domain\Shared\Clock;
use DateTimeImmutable;

final readonly class SampleQueueMetrics
{
    public function __construct(private QueueMetricStore $store, private Clock $clock) {}

    public function execute(): void
    {
        $now = $this->clock->now();
        $slot = new DateTimeImmutable('@'.(\intdiv($now->getTimestamp(), 120) * 120));
        $this->store->sample($slot, $slot->modify('-30 days'));
    }
}
