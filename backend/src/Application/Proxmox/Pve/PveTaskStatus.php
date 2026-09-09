<?php

declare(strict_types=1);

namespace App\Application\Proxmox\Pve;

final readonly class PveTaskStatus
{
    /** @var list<PveBackupInventoryIssue> */
    public array $issues;

    /** @param list<PveBackupInventoryIssue> $issues */
    public function __construct(
        public PveUpid $upid,
        public ?PveTaskLifecycle $lifecycle,
        public ?string $exitStatus,
        public ?int $reportedProcessStart,
        array $issues,
    ) {
        $this->issues = $issues;
    }

    public function isComplete(): bool
    {
        return [] === $this->issues;
    }

    public function isSuccessful(): bool
    {
        return $this->isComplete()
            && PveTaskLifecycle::Stopped === $this->lifecycle
            && 'OK' === $this->exitStatus;
    }
}
