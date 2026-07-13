<?php

declare(strict_types=1);

namespace App\Application\Security\Auth;

use SensitiveParameter;

interface FirstAdminCreator
{
    public function create(string $username, string $displayName, #[SensitiveParameter] string $password, bool $idempotent): bool;
}
