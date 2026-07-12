<?php

declare(strict_types=1);

namespace App\Application\Inventory\PbsContent;

final readonly class PbsContentApplyResult
{
    public function __construct(
        public PbsContentRunStatus $status,
        public int $created,
        public int $updated,
        public int $archived,
    ) {}
}
