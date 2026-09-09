<?php

declare(strict_types=1);

namespace App\Application\Proxmox\Pve;

final readonly class PveNodeStorageStatusSet
{
    /** @var list<PveNodeStorageStatus> */
    public array $statuses;

    /** @var list<PveStorageIssue> */
    public array $issues;

    /**
     * @param list<PveNodeStorageStatus> $statuses
     * @param list<PveStorageIssue>      $issues
     */
    public function __construct(
        public string $node,
        array $statuses,
        array $issues,
    ) {
        usort(
            $statuses,
            static fn (PveNodeStorageStatus $left, PveNodeStorageStatus $right): int => $left->storageId <=> $right->storageId,
        );
        $this->statuses = $statuses;
        $this->issues = $issues;
    }
}
