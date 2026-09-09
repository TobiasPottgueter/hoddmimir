<?php

declare(strict_types=1);

namespace App\Application\Security\Auth;

use App\Domain\Security\NormalizedUsername;
use App\Domain\Security\UserDisplayName;
use App\Domain\Security\UserId;

final readonly class ExistingAdmin
{
    public function __construct(
        public UserId $id,
        public NormalizedUsername $username,
        public UserDisplayName $displayName,
        public PasswordHash $passwordHash,
    ) {
    }
}
