<?php

declare(strict_types=1);

namespace App\Application\Proxmox\Pve;

final readonly class PveInventoryIssue
{
    public function __construct(
        public PveInventoryIssueCode $code,
        public string $resourceType,
        public int $sourceIndex,
        public string $field,
    ) {
    }
}
