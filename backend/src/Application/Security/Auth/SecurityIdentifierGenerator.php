<?php

declare(strict_types=1);

namespace App\Application\Security\Auth;

interface SecurityIdentifierGenerator
{
    /** @return string exactly 16 binary bytes */
    public function generate(): string;
}
