<?php

declare(strict_types=1);

namespace App\Application\Proxmox\Pve;

final readonly class PveBackupJobInventory
{
    /** @var list<PveBackupJob> */
    public array $jobs;

    /** @var list<PveBackupInventoryIssue> */
    public array $issues;

    /**
     * @param list<PveBackupJob>             $jobs
     * @param list<PveBackupInventoryIssue> $issues
     */
    public function __construct(
        public PveBackupJobCapabilities $capabilities,
        array $jobs,
        array $issues,
    ) {
        $this->jobs = $jobs;
        $this->issues = $issues;
    }

    public function isComplete(): bool
    {
        return [] === $this->issues;
    }
}
