<?php

declare(strict_types=1);

namespace App\Application\Proxmox\Pve;

final readonly class PveStorageIssue
{
    public function __construct(
        public PveStorageIssueCode $code,
        public string $endpoint,
        public string $field,
        public ?string $storageId = null,
        public ?string $node = null,
    ) {
    }
}
