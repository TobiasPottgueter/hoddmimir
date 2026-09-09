<?php

declare(strict_types=1);

namespace App\Domain\Policy;

final readonly class BackupDefaults
{
    public function __construct(
        public ?BackupMode $mode = null,
        public ?Compression $compression = null,
        public ?RetentionPolicy $retention = null,
    ) {
    }
}
