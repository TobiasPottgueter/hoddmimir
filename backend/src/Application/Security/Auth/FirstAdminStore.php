<?php

declare(strict_types=1);

namespace App\Application\Security\Auth;

use App\Domain\Security\NormalizedUsername;
use App\Domain\Security\UserDisplayName;
use App\Domain\Security\UserId;
use DateTimeImmutable;

interface FirstAdminStore
{
    public function countUsers(): int;

    public function findAdmin(NormalizedUsername $username): ?ExistingAdmin;

    public function createAdmin(UserId $id, NormalizedUsername $username, UserDisplayName $displayName, PasswordHash $passwordHash, DateTimeImmutable $at): void;
}
