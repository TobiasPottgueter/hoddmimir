<?php

declare(strict_types=1);

namespace App\Application\Security\Auth;

interface SecurityTransaction
{
    /**
     * @template T
     *
     * @param callable(): T $operation
     *
     * @return T
     */
    public function run(callable $operation): mixed;
}
