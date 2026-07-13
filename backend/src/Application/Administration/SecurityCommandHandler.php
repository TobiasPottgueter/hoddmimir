<?php

declare(strict_types=1);

namespace App\Application\Administration;

use App\Application\Security\Auth\AuthenticatedPrincipal;
use App\Application\Security\Auth\AuthorizationDenied;
use App\Application\Security\Auth\PermissionAuthorizer;
use App\Domain\Security\Permission;

final readonly class SecurityCommandHandler
{
    public function __construct(private PermissionAuthorizer $authorizer, private SecurityCommandRepository $repository)
    {
    }

    public function handle(SecurityCommand $command, AuthenticatedPrincipal $principal): SecurityCommandResult
    {
        try {
            $this->authorizer->require($principal, Permission::SecurityManage);
        } catch (AuthorizationDenied) {
            return $this->repository->record($command, $principal, new SecurityCommandResult(SecurityCommandStatus::Denied, null, 'permission_denied'));
        }
        return $this->repository->execute($command, $principal);
    }
}
