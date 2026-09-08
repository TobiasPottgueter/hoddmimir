<?php

declare(strict_types=1);

namespace App\Application\Proxmox\Pbs;

final readonly class CollectPbsTaskInspections
{
    /**
     * Limit remote work per inventory cycle. Running tasks come first, then newest tasks.
     * @param list<PbsTaskObservation> $tasks
     * @return list<PbsTaskInspection>
     */
    public function collect(PbsTaskInspectionSource $source, array $tasks): array
    {
        usort($tasks, static fn (PbsTaskObservation $a, PbsTaskObservation $b): int =>
            ($b->isRunning() <=> $a->isRunning())
            ?: ($b->upid->startTime <=> $a->upid->startTime)
            ?: strcmp($a->upid->value, $b->upid->value));
        $inspections = [];
        foreach (array_slice($tasks, 0, 8) as $task) {
            $inspections[] = $source->inspect($task->upid);
        }
        return $inspections;
    }
}
