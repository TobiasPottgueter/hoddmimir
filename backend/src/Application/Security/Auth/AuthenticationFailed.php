<?php

declare(strict_types=1);

namespace App\Application\Security\Auth;

use RuntimeException;

final class AuthenticationFailed extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('Authentication failed.');
    }
}
