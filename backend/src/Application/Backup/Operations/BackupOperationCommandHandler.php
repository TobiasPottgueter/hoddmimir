<?php

declare(strict_types=1);

namespace App\Application\Backup\Operations;

use App\Application\Security\Audit\AuditEventType;
use App\Application\Security\Audit\AuditOutcome;
use App\Application\Security\Audit\SecurityAuditRecorder;
use App\Application\Security\Auth\AuthenticatedPrincipal;
use App\Application\Security\Auth\AuthorizationDenied;
use App\Application\Security\Auth\PermissionAuthorizer;
use App\Domain\Security\Permission;

final readonly class BackupOperationCommandHandler
{
    public function __construct(
        private PermissionAuthorizer $authorizer,
        private BackupOperationCommandRepository $repository,
        private SecurityAuditRecorder $audit,
    ) {}

    public function handle(BackupOperationCommand $command, AuthenticatedPrincipal $principal): BackupOperationCommandResult
    {
        try {
            $this->authorizer->require($principal, Permission::BackupOperationsManage);
        } catch (AuthorizationDenied $denied) {
            $this->audit->record(
                BackupOperationCommandType::ManualRequest === $command->type
                    ? AuditEventType::ManualBackupRequested
                    : AuditEventType::BackupCancelRequested,
                AuditOutcome::Denied,
                $command->correlationId,
                $principal->userId,
                $principal->sessionId,
                'backup_request',
                $command->subjectId,
                'permission_denied',
            );
            throw $denied;
        }

        return $this->repository->execute($command, $principal);
    }
}
