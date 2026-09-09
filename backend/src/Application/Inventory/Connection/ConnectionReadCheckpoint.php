<?php

declare(strict_types=1);

namespace App\Application\Inventory\Connection;

interface ConnectionReadCheckpoint
{
    public function checkpoint(): void;
}
