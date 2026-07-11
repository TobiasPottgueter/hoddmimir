<?php

declare(strict_types=1);

namespace App\Application\Proxmox\Pve;

final readonly class PveBackupInventorySnapshot
{
    /** @var list<PveBackupTask> */
    public array $tasks;

    /** @var list<PveBackupInventoryIssue> */
    public array $issues;

    /**
     * @param list<PveBackupTask>             $tasks
     * @param list<PveBackupInventoryIssue> $issues
     */
    public function __construct(
        public PveBackupJobInventory $jobs,
        array $tasks,
        array $issues,
    ) {
        $this->tasks = $tasks;
        $this->issues = $issues;
    }

    public function isComplete(): bool
    {
        return $this->jobs->isComplete() && [] === $this->issues;
    }

    /** Incomplete snapshots are observations only and cannot authorize deletions. */
    public function permitsDeletionDecisions(): bool
    {
        return false;
    }
}
