<?php

declare(strict_types=1);

namespace App\Application\Backup\Execution;

use InvalidArgumentException;

final readonly class PveExecutorPermissionSnapshot
{
    public const int MAXIMUM_MATRIX_PATHS = 4_096;
    public const int MAXIMUM_ACL_ENTRIES = 4_096;

    /**
     * @param list<PveExecutorPermissionMatrixPath> $backupPermissionMatrix
     * @param list<PveExecutorAclEntry>              $scanAcl
     */
    public function __construct(
        public string $connectionId,
        public string $endpointId,
        public int $connectionRevision,
        public int $backupCredentialRevision,
        public int $scanCredentialRevision,
        public int $majorVersion,
        public string $backupTokenIdentity,
        public string $backupOwnerIdentity,
        public array $backupPermissionMatrix,
        public array $scanAcl,
    ) {
        if (16 !== \strlen($connectionId) || 16 !== \strlen($endpointId)
            || $connectionRevision < 1 || $backupCredentialRevision < 1 || $scanCredentialRevision < 1
            || !\in_array($majorVersion, [7, 8, 9], true)
            || !PveExecutorIdentityValidator::token($backupTokenIdentity)
            || !PveExecutorIdentityValidator::owner($backupOwnerIdentity)
            || !\str_starts_with($backupTokenIdentity, $backupOwnerIdentity.'!')) {
            throw new InvalidArgumentException('PVE executor permission snapshot identity is invalid.');
        }

        if (\count($backupPermissionMatrix) > self::MAXIMUM_MATRIX_PATHS
            || \count($scanAcl) > self::MAXIMUM_ACL_ENTRIES) {
            throw new InvalidArgumentException('PVE executor permission snapshot exceeds its bounds.');
        }
        $paths = [];
        foreach ($backupPermissionMatrix as $entry) {
            /** @phpstan-ignore instanceof.alwaysTrue (enforce the external runtime boundary) */
            if (!$entry instanceof PveExecutorPermissionMatrixPath || isset($paths[$entry->path])) {
                throw new InvalidArgumentException('PVE executor permission snapshot contains a duplicate matrix path.');
            }
            $paths[$entry->path] = true;
        }
        $aclKeys = [];
        foreach ($scanAcl as $entry) {
            /** @phpstan-ignore instanceof.alwaysTrue (enforce the external runtime boundary) */
            if (!$entry instanceof PveExecutorAclEntry) {
                throw new InvalidArgumentException('PVE executor permission snapshot contains an invalid ACL entry.');
            }
            $key = $entry->path."\0".$entry->type."\0".$entry->identity."\0".$entry->roleId."\0".(int) $entry->propagate;
            if (isset($aclKeys[$key])) {
                throw new InvalidArgumentException('PVE executor permission snapshot contains a duplicate ACL entry.');
            }
            $aclKeys[$key] = true;
        }
    }
}
