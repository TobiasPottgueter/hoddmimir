<?php

declare(strict_types=1);

namespace App\Application\Backup\Execution;

use App\Application\Proxmox\Pve\PveStorageIdValidator;
use InvalidArgumentException;

final readonly class ExecutorEvidenceRefreshSubject
{
    public function __construct(
        public string $connectionId,
        public string $clusterId,
        public string $targetId,
        public string $nodeId,
        public string $storageId,
        public string $storageName,
        public ?string $guestId,
        public ?int $vmid,
    ) {
        foreach ([$connectionId, $clusterId, $targetId, $nodeId, $storageId] as $id) {
            if (16 !== \strlen($id)) {
                throw new InvalidArgumentException('Executor evidence subject identifiers must contain 16 bytes.');
            }
        }
        if ((null === $guestId) !== (null === $vmid)
            || (null !== $guestId && 16 !== \strlen($guestId))
            || (null !== $vmid && ($vmid < 1 || $vmid > 999_999_999))
            || !PveStorageIdValidator::isValid($storageName)) {
            throw new InvalidArgumentException('Executor evidence subject is inconsistent.');
        }
    }

    public function guestPath(): string
    {
        return null === $this->vmid ? '/vms' : '/vms/'.$this->vmid;
    }

    public function storagePath(): string
    {
        return '/storage/'.$this->storageName;
    }

    public function cursor(): string
    {
        return $this->targetId.$this->nodeId.($this->guestId ?? \str_repeat("\0", 16));
    }
}
