<?php

declare(strict_types=1);

namespace App\Infrastructure\Proxmox\ExecutorEvidence;

use App\Application\Backup\Execution\ExecutorEvidenceRefreshFailure;
use App\Application\Backup\Execution\ExecutorEvidenceRefreshFailureCode;
use App\Application\Backup\Execution\PveExecutorAclEntry;
use App\Application\Backup\Execution\PveExecutorPermissionMatrixPath;
use App\Application\Backup\Execution\PveExecutorPermissionSnapshot;
use InvalidArgumentException;

final readonly class PveExecutorPermissionSnapshotParser
{
    public function parse(
        PveExecutorEvidenceEndpointConfiguration $configuration,
        mixed $matrixData,
        mixed $aclData,
    ): PveExecutorPermissionSnapshot {
        try {
            return new PveExecutorPermissionSnapshot(
                $configuration->connectionId,
                $configuration->endpointId,
                $configuration->connectionRevision,
                $configuration->backupCredentialRevision,
                $configuration->scanCredentialRevision,
                $configuration->majorVersion,
                $configuration->backupTokenIdentity,
                $configuration->backupOwnerIdentity,
                $this->matrix($matrixData),
                $this->acl($aclData),
            );
        } catch (InvalidArgumentException) {
            throw ExecutorEvidenceRefreshFailure::for(ExecutorEvidenceRefreshFailureCode::InvalidResponse);
        }
    }

    /** @return list<PveExecutorPermissionMatrixPath> */
    private function matrix(mixed $data): array
    {
        $object = $data instanceof \stdClass;
        if ($object) {
            $data = \get_object_vars($data);
        }
        if (!\is_array($data) || (!$object && \array_is_list($data))
            || \count($data) > PveExecutorPermissionSnapshot::MAXIMUM_MATRIX_PATHS) {
            throw new InvalidArgumentException('Invalid PVE executor permission matrix.');
        }
        $result = [];
        foreach ($data as $path => $privileges) {
            $privilegeObject = $privileges instanceof \stdClass;
            if ($privilegeObject) {
                $privileges = \get_object_vars($privileges);
            }
            if (!\is_string($path) || !\is_array($privileges)
                || (!$privilegeObject && \array_is_list($privileges))) {
                throw new InvalidArgumentException('Invalid PVE executor permission matrix.');
            }
            $result[] = new PveExecutorPermissionMatrixPath($path, $this->privileges($privileges));
        }
        return $result;
    }

    /**
     * @param array<mixed, mixed> $privileges
     * @return array<string, 0|1>
     */
    private function privileges(array $privileges): array
    {
        $result = [];
        foreach ($privileges as $privilege => $propagate) {
            if (!\is_string($privilege) || !\in_array($propagate, [0, 1], true)) {
                throw new InvalidArgumentException('Invalid PVE executor permission matrix.');
            }
            $result[$privilege] = $propagate;
        }

        return $result;
    }

    /** @return list<PveExecutorAclEntry> */
    private function acl(mixed $data): array
    {
        if (!\is_array($data) || !\array_is_list($data)
            || \count($data) > PveExecutorPermissionSnapshot::MAXIMUM_ACL_ENTRIES) {
            throw new InvalidArgumentException('Invalid PVE executor ACL.');
        }
        $result = [];
        foreach ($data as $row) {
            if ($row instanceof \stdClass) {
                $row = \get_object_vars($row);
            }
            if (!\is_array($row)
                || !\is_string($row['path'] ?? null)
                || !\is_string($row['type'] ?? null)
                || !\is_string($row['ugid'] ?? null)
                || !\is_string($row['roleid'] ?? null)
                || !\in_array($row['propagate'] ?? null, [0, 1], true)) {
                throw new InvalidArgumentException('Invalid PVE executor ACL.');
            }
            $result[] = new PveExecutorAclEntry(
                $row['path'], $row['type'], $row['ugid'], $row['roleid'], 1 === $row['propagate'],
            );
        }
        return $result;
    }
}
