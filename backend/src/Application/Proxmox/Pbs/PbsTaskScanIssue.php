<?php

declare(strict_types=1);

namespace App\Application\Proxmox\Pbs;

final readonly class PbsTaskScanIssue
{
    public function __construct(
        public PbsTaskFilterFamily $family,
        public PbsTaskPass $pass,
        public PbsTaskScanIssueCode $code,
    ) {}
}
