<?php

declare(strict_types=1);

namespace App\Application\Proxmox\Pve;

final readonly class PveBackupInventorySnapshot
{
    /** @var list<PveBackupTask> */
    public array $tasks;

    /** @var list<PveBackupInventoryIssue> */
    public array $issues;

    /** @var list<PveTaskStreamScanResult> */
    public array $taskStreams;

    /**
     * @param list<PveBackupTask>             $tasks
     * @param list<PveBackupInventoryIssue> $issues
     * @param list<PveTaskStreamScanResult>  $taskStreams
     */
    public function __construct(
        public PveBackupJobInventory $jobs,
        array $tasks,
        array $issues,
        public PveTaskArchiveWindow $archiveWindow,
        array $taskStreams,
        public int $taskRequests,
        public int $rawTaskRows,
    ) {
        if ($taskRequests < 0 || $rawTaskRows < 0) {
            throw new \InvalidArgumentException('The PVE backup inventory scan counters are invalid.');
        }
        $this->tasks = $tasks;
        $this->issues = $issues;
        $this->taskStreams = $taskStreams;
    }

    public function isComplete(): bool
    {
        if (!$this->jobs->isComplete() || [] !== $this->issues) {
            return false;
        }
        foreach ($this->taskStreams as $stream) {
            if (!$stream->isComplete()) {
                return false;
            }
        }

        return true;
    }

    /** Incomplete snapshots are observations only and cannot authorize deletions. */
    public function permitsDeletionDecisions(): bool
    {
        return false;
    }
}
