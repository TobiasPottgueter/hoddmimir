<?php

declare(strict_types=1);

namespace App\Application\Proxmox\Pve;

final readonly class PveVersion
{
    public function __construct(
        public int $major,
        public int $minor,
        public ?int $patch,
        public string $release,
        public string $version,
        public string $repoId,
    ) {
    }
}
