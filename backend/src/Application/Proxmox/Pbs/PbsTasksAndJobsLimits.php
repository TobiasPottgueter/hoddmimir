<?php

declare(strict_types=1);

namespace App\Application\Proxmox\Pbs;

use InvalidArgumentException;

final readonly class PbsTasksAndJobsLimits
{
    public function __construct(
        public int $pageSize = 256,
        public int $maximumPagesPerStream = 16,
        public int $maximumRowsPerStream = 4096,
        public int $maximumJobsPerKind = 4096,
        public int $maximumHistoryWindowSeconds = 86_400,
    ) {
        if ($pageSize < 1 || $pageSize > 1000
            || $maximumPagesPerStream < 1 || $maximumPagesPerStream > 64
            || $maximumRowsPerStream < $pageSize || $maximumRowsPerStream > 65_536
            || $maximumJobsPerKind < 1 || $maximumJobsPerKind > 65_536
            || $maximumHistoryWindowSeconds < 1 || $maximumHistoryWindowSeconds > 86_400) {
            throw new InvalidArgumentException('The PBS task and job limits are invalid.');
        }
    }
}
