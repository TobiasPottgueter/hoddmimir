<?php

declare(strict_types=1);

namespace App\Application\Proxmox\Pve;

final readonly class PveStorageResource
{
    public function __construct(
        public string $storage,
        public string $node,
        public ?string $status,
        public ?string $content,
        public ?int $totalBytes,
        public ?int $usedBytes,
        public ?int $availableBytes,
    ) {
    }

    public function identity(): string
    {
        return $this->node.':'.$this->storage;
    }
}
