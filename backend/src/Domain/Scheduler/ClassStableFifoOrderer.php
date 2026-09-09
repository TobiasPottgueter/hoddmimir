<?php

declare(strict_types=1);

namespace App\Domain\Scheduler;

use InvalidArgumentException;

final readonly class ClassStableFifoOrderer
{
    /**
     * @param list<QueueOrderEntry> $entries
     *
     * @return list<QueueOrderEntry>
     */
    public function fifo(array $entries): array
    {
        $this->assertUniqueIds($entries);
        usort($entries, [self::class, 'compare']);

        return $entries;
    }

    /**
     * @param list<QueueOrderEntry> $entries
     *
     * @return list<QueueOrderEntry>
     */
    public function withFairness(array $entries, WithinPriorityFairness $fairness): array
    {
        $fifo = $this->fifo($entries);
        $groups = [];
        foreach ($fifo as $entry) {
            $groups[$entry->priority->value][] = $entry;
        }

        $result = [];
        foreach (Priority::cases() as $priority) {
            $group = $groups[$priority->value] ?? null;
            if (null === $group) {
                continue;
            }
            $reordered = $fairness->reorder($priority, $group);
            $this->assertFairnessResult($priority, $group, $reordered);
            array_push($result, ...$reordered);
        }

        return $result;
    }

    private static function compare(QueueOrderEntry $left, QueueOrderEntry $right): int
    {
        $priority = $right->priority->value <=> $left->priority->value;
        if (0 !== $priority) {
            return $priority;
        }

        $scheduled = $left->scheduledAt <=> $right->scheduledAt;

        return 0 !== $scheduled ? $scheduled : strcmp($left->stableId, $right->stableId);
    }

    /** @param list<QueueOrderEntry> $entries */
    private function assertUniqueIds(array $entries): void
    {
        $seen = [];
        foreach ($entries as $entry) {
            $key = bin2hex($entry->stableId);
            if (isset($seen[$key])) {
                throw new InvalidArgumentException('Queue ordering IDs must be unique.');
            }
            $seen[$key] = true;
        }
    }

    /**
     * @param non-empty-list<QueueOrderEntry> $expected
     * @param list<QueueOrderEntry>           $actual
     */
    private function assertFairnessResult(Priority $priority, array $expected, array $actual): void
    {
        if (count($expected) !== count($actual)) {
            throw new InvalidArgumentException('Fairness must retain every candidate in its priority class.');
        }

        $expectedById = [];
        foreach ($expected as $entry) {
            $expectedById[bin2hex($entry->stableId)] = $entry;
        }
        $seen = [];
        foreach ($actual as $entry) {
            $key = bin2hex($entry->stableId);
            if ($entry->priority !== $priority) {
                throw new InvalidArgumentException('Fairness may only reorder existing candidates inside one priority class.');
            }
            if (!isset($expectedById[$key])) {
                throw new InvalidArgumentException('Fairness may only reorder existing candidates inside one priority class.');
            }
            if ($expectedById[$key] !== $entry) {
                throw new InvalidArgumentException('Fairness may only reorder existing candidates inside one priority class.');
            }
            if (isset($seen[$key])) {
                throw new InvalidArgumentException('Fairness may only reorder existing candidates inside one priority class.');
            }
            $seen[$key] = true;
        }
    }
}
