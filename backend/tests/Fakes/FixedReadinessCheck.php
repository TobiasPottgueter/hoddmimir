<?php

declare(strict_types=1);

namespace App\Tests\Fakes;

use App\Application\Readiness\ReadinessCheck;
use App\Application\Readiness\ReadinessCheckResult;

final readonly class FixedReadinessCheck implements ReadinessCheck
{
    public function __construct(private ReadinessCheckResult $result)
    {
    }

    public function name(): string
    {
        return $this->result->name;
    }

    public function check(): ReadinessCheckResult
    {
        return $this->result;
    }
}
