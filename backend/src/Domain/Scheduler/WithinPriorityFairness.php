<?php

declare(strict_types=1);

namespace App\Domain\Scheduler;

interface WithinPriorityFairness
{
    /**
     * @param non-empty-list<QueueOrderEntry> $fifoEntries
     *
     * @return list<QueueOrderEntry>
     */
    public function reorder(Priority $priority, array $fifoEntries): array;
}
