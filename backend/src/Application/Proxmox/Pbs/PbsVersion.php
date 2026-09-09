<?php

declare(strict_types=1);

namespace App\Application\Proxmox\Pbs;

final readonly class PbsVersion
{
    public function __construct(
        public int $major,
        public int $minor,
        public ?int $patch,
        public string $version,
        public string $release,
        public string $repoId,
    ) {
    }

    public function supportsInstanceIdentity(): bool
    {
        return $this->major > 4 || (4 === $this->major && $this->minor >= 2);
    }
}
