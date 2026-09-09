<?php

declare(strict_types=1);

namespace App\Application\Proxmox\Pve;

use InvalidArgumentException;

final readonly class PveTaskPage
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
        public PveTaskQuery $query,
        public int $rawRowCount,
        array $tasks,
        array $issues,
    ) {
        if ($rawRowCount < 0 || $rawRowCount > $query->limit || count($tasks) > $rawRowCount) {
            throw new InvalidArgumentException('The PVE task page counters are invalid.');
        }
        $this->tasks = $tasks;
        $this->issues = $issues;
    }

    public function isShort(): bool
    {
        return $this->rawRowCount < $this->query->limit;
    }

    public function isComplete(): bool
    {
        return [] === $this->issues;
    }
}
