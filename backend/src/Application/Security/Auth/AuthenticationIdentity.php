<?php

declare(strict_types=1);

namespace App\Application\Security\Auth;

use App\Domain\Security\NormalizedUsername;
use App\Domain\Security\Permission;
use App\Domain\Security\UserId;

final readonly class AuthenticationIdentity
{
    /** @param list<Permission> $permissions */
    public function __construct(
        public UserId $userId,
        public NormalizedUsername $username,
        public PasswordHash $passwordHash,
        public array $permissions,
    ) {
    }
}
