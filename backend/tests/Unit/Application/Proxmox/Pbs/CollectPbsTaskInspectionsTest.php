<?php

declare(strict_types=1);

namespace App\Tests\Unit\Application\Proxmox\Pbs;

use App\Application\Proxmox\Pbs\CollectPbsTaskInspections;
use App\Application\Proxmox\Pbs\PbsTaskInspection;
use App\Application\Proxmox\Pbs\PbsTaskInspectionSource;
use App\Application\Proxmox\Pbs\PbsTaskObservation;
use App\Application\Proxmox\Pbs\PbsTaskOutcome;
use App\Application\Proxmox\Pbs\PbsUpid;
use PHPUnit\Framework\TestCase;

final class CollectPbsTaskInspectionsTest extends TestCase
{
    public function testEmptyBoundAndDeterministicPriority(): void
    {
        $source = new class implements PbsTaskInspectionSource {
            public function inspect(PbsUpid $upid): PbsTaskInspection
            { return new PbsTaskInspection($upid, null, null, null, [], false, null, null); }
        };
        $collector = new CollectPbsTaskInspections();
        self::assertSame([], $collector->collect($source, []));
        $tasks = [];
        foreach (range(1, 12) as $index) {
            $upid = new PbsUpid(sprintf('UPID:pbs:00000001:00000002:%08X:%08X:verify:store:root@pam:', $index, 1 === $index ? 1 : 100));
            $tasks[] = new PbsTaskObservation($upid, 'localhost', 1 === $index, true, 1 === $index ? null : PbsTaskOutcome::Ok, null);
        }
        $result = $collector->collect($source, array_reverse($tasks));
        self::assertCount(8, $result);
        self::assertSame(array_map(static fn (PbsTaskObservation $task): string => $task->upid->value, array_slice($tasks, 0, 8)),
            array_map(static fn (PbsTaskInspection $inspection): string => $inspection->upid->value, $result));
        $newest = new PbsTaskObservation(new PbsUpid('UPID:pbs:00000001:00000002:000000FF:65000000:verify:store:root@pam:'), 'localhost', false, true, PbsTaskOutcome::Ok, null);
        self::assertSame($newest->upid, $collector->collect($source, [$tasks[1], $newest])[0]->upid);
    }
}
