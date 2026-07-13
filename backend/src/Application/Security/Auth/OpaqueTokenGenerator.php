<?php

declare(strict_types=1);

namespace App\Application\Security\Auth;

interface OpaqueTokenGenerator
{
    public function generate(): OpaqueToken;
}
