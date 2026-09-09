<?php

declare(strict_types=1);

namespace App\Application\Readiness;

interface ReadinessCheck
{
    public function name(): string;

    public function check(): ReadinessCheckResult;
}
