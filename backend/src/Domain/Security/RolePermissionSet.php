<?php

declare(strict_types=1);

namespace App\Domain\Security;

final readonly class RolePermissionSet
{
    /** @return non-empty-list<Permission> */
    public static function for(Role $role): array
    {
        return Role::Admin === $role ? Permission::cases() : [Permission::InventoryRead];
    }
}
