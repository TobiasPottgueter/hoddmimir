<?php

declare(strict_types=1);

namespace App\Application\Worker;

interface Sleeper
{
    public function sleep(int $seconds): void;
}
