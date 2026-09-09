<?php

declare(strict_types=1);

namespace App\Application\Administration\ReadModel;

interface AdministrationReadItem
{
    /** @return array<string, mixed> */
    public function toArray(): array;
}
