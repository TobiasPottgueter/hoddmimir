<?php

declare(strict_types=1);

namespace App\Application\Proxmox\Pve;

final readonly class PveBackupInventoryIssue
{
    public function __construct(
        public PveBackupInventoryIssueCode $code,
        public string $endpoint,
        public string $field,
        public ?string $node = null,
        public ?string $identifier = null,
    ) {
    }
}
