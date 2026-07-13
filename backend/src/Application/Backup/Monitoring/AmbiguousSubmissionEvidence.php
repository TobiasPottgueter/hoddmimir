<?php

declare(strict_types=1);

namespace App\Application\Backup\Monitoring;

use App\Application\Proxmox\Pve\PveBackupTask;
use InvalidArgumentException;

final readonly class AmbiguousSubmissionEvidence
{
    /** @var list<PveBackupTask> */
    public array $tasks;

    /** @param list<PveBackupTask> $tasks */
    public function __construct(public bool $complete, array $tasks)
    {
        $seen = [];
        foreach ($tasks as $task) {
            // @phpstan-ignore instanceof.alwaysTrue (runtime validation at the port boundary)
            if (!$task instanceof PveBackupTask || isset($seen[$task->upid->raw])) {
                throw new InvalidArgumentException('Ambiguous submission evidence contains invalid or duplicate tasks.');
            }
            $seen[$task->upid->raw] = true;
        }
        $this->tasks = $tasks;
    }
}
