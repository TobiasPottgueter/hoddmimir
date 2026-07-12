<?php

declare(strict_types=1);

namespace App\Application\Monitoring;

use App\Application\Inventory\Connection\ConnectionId;
use DateTimeImmutable;
use InvalidArgumentException;

final readonly class MonitoringWindowPlanner
{
    public function __construct(
        private MonitoringCursorCatalog $cursors,
        private int $overlapSeconds = 300,
    ) {
        if ($this->overlapSeconds < 0 || $this->overlapSeconds > 3600) {
            throw new InvalidArgumentException('The monitoring window overlap is invalid.');
        }
    }

    /** @param non-empty-list<string> $scopeKeys */
    public function plan(
        ConnectionId $connectionId,
        MonitoringCursorKind $kind,
        array $scopeKeys,
        DateTimeImmutable $cutoff,
        int $maximumWindowSeconds,
    ): MonitoringWindowPlan {
        if ($maximumWindowSeconds < 1 || $maximumWindowSeconds > 86_400) {
            throw new InvalidArgumentException('The monitoring maximum window is invalid.');
        }
        $until = $cutoff->getTimestamp();
        if ($until < 0) {
            throw new InvalidArgumentException('The monitoring cutoff cannot precede the UNIX epoch.');
        }
        $completed = $this->cursors->oldestCompletedUntil($connectionId, $kind, $scopeKeys);
        $historyGap = null !== $completed
            && $until - $completed->getTimestamp() > $maximumWindowSeconds;
        if (null === $completed) {
            $candidate = $until - $maximumWindowSeconds;
        } else {
            $candidate = $completed->getTimestamp() - $this->overlapSeconds;
        }
        $since = $candidate < 0 ? 0 : $candidate;
        if ($until - $since > $maximumWindowSeconds) {
            $since = $until - $maximumWindowSeconds;
        }

        return new MonitoringWindowPlan($since, $until, $historyGap);
    }
}
