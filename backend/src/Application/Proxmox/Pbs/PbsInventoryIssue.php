<?php

declare(strict_types=1);

namespace App\Application\Proxmox\Pbs;

final readonly class PbsInventoryIssue
{
    public function __construct(
        public PbsInventoryIssueCode $code,
        public string $endpoint,
        public ?string $datastoreId = null,
    ) {
    }
}
