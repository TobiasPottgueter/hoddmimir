<?php

declare(strict_types=1);

namespace App\Application\Monitoring;

use InvalidArgumentException;

final readonly class MonitoringApplyResult
{
    public function __construct(
        public MonitoringRunStatus $status,
        public int $created,
        public int $updated,
        public int $conflicts,
    ) {
        if (min($this->created, $this->updated, $this->conflicts) < 0) {
            throw new InvalidArgumentException('Monitoring apply counters must not be negative.');
        }
    }
}
