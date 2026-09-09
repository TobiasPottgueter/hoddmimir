<?php

declare(strict_types=1);

namespace App\Infrastructure\Security;

interface NonceSource
{
    public function nextNonce(): string;
}
