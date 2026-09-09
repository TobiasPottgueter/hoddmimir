<?php

declare(strict_types=1);

namespace App\Application\Backup\Execution;

use DateTimeImmutable;
use InvalidArgumentException;

final readonly class ExecutorPermissionEvidence
{
    /** @param list<string> $missingPermissions */
    public function __construct(
        public string $connectionId,
        public string $clusterId,
        public string $guestId,
        public string $targetId,
        public string $nodeId,
        public string $storageId,
        public bool $vmBackupAuthorized,
        public bool $datastoreAllocateAuthorized,
        public bool $authorized,
        public DateTimeImmutable $observedAt,
        public string $guestPath,
        public string $storagePath,
        public array $missingPermissions,
    ) {
        foreach ([$connectionId, $clusterId, $guestId, $targetId, $nodeId, $storageId] as $id) {
            if (16 !== \strlen($id)) {
                throw new InvalidArgumentException('Executor evidence identifiers must contain 16 bytes.');
            }
        }
        if (0 !== $observedAt->getOffset()) {
            throw new InvalidArgumentException('Executor permission evidence is inconsistent.');
        }
        if (1 !== \preg_match('#^/vms/[1-9][0-9]*$#D', $guestPath)) {
            throw new InvalidArgumentException('Executor permission evidence is inconsistent.');
        }
        if (1 !== \preg_match('#^/storage/[A-Za-z0-9][A-Za-z0-9._-]*$#D', $storagePath)) {
            throw new InvalidArgumentException('Executor permission evidence is inconsistent.');
        }
        if ($authorized !== ($vmBackupAuthorized && $datastoreAllocateAuthorized)) {
            throw new InvalidArgumentException('Executor permission evidence is inconsistent.');
        }
        if ($vmBackupAuthorized !== !\in_array('VM.Backup', $missingPermissions, true)) {
            throw new InvalidArgumentException('Executor permission evidence is inconsistent.');
        }
        if ($datastoreAllocateAuthorized !== !\in_array('Datastore.AllocateSpace', $missingPermissions, true)) {
            throw new InvalidArgumentException('Executor permission evidence is inconsistent.');
        }
        foreach ($missingPermissions as $permission) {
            if (!\in_array($permission, ['VM.Backup', 'Datastore.AllocateSpace'], true)) {
                throw new InvalidArgumentException('Executor permission evidence contains an unknown permission.');
            }
        }
    }
}
