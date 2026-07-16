<?php

declare(strict_types=1);

namespace App\Application\Backup\Execution;

use InvalidArgumentException;

final readonly class ExecutorPermissionProjection
{
    public function __construct(
        public ExecutorEvidenceRefreshSubject $subject,
        public string $endpointId,
        public int $connectionRevision,
        public int $backupCredentialRevision,
        public int $scanCredentialRevision,
        public bool $vmBackupAuthorized,
        public bool $datastoreAllocateAuthorized,
    ) {
        if (16 !== \strlen($endpointId)
            || $connectionRevision < 1 || $backupCredentialRevision < 1 || $scanCredentialRevision < 1) {
            throw new InvalidArgumentException('Executor permission projection binding is invalid.');
        }
    }

    public function authorized(): bool
    {
        return $this->vmBackupAuthorized && $this->datastoreAllocateAuthorized;
    }
}
