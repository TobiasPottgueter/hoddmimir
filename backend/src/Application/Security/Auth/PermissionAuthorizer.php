<?php

declare(strict_types=1);

namespace App\Application\Security\Auth;

use App\Domain\Security\Permission;

final readonly class PermissionAuthorizer
{
    public function require(AuthenticatedPrincipal $principal, Permission $permission): void
    {
        if (!$principal->has($permission)) {
            throw new AuthorizationDenied('The authenticated principal lacks the required permission.');
        }
    }
}
