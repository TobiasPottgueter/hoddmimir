<?php

declare(strict_types=1);

namespace App\Application\Security\Auth;

use App\Domain\Security\NormalizedUsername;
use App\Domain\Security\Permission;
use App\Domain\Security\UserId;
use InvalidArgumentException;

final readonly class AuthenticatedPrincipal
{
    /** @var list<Permission> */ public array $permissions;
    /** @param list<Permission> $permissions */
    public function __construct(
        public UserId $userId,
        public NormalizedUsername $username,
        array $permissions,
        public ?string $sessionId = null,
    )
    {
        if (null !== $sessionId && 16 !== \strlen($sessionId)) {
            throw new InvalidArgumentException('Principal session identifier is invalid.');
        }
        $by = [];
        foreach ($permissions as $permission) {
            // @phpstan-ignore instanceof.alwaysTrue (enforce the PHPDoc boundary at runtime)
            if (!$permission instanceof Permission) {
                throw new InvalidArgumentException('Principal permissions are invalid.');
            } $by[$permission->value] = $permission;
        } \ksort($by);
        $this->permissions = \array_values($by);
    }
    public function has(Permission $permission): bool
    {
        return \in_array($permission, $this->permissions, true);
    }
}
