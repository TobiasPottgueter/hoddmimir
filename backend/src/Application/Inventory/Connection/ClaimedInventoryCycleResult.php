<?php

declare(strict_types=1);

namespace App\Application\Inventory\Connection;

use App\Application\Collector\CollectorCycleStatus;
use App\Application\Collector\CollectorCycleResult;
use InvalidArgumentException;

final readonly class ClaimedInventoryCycleResult implements CollectorCycleResult
{
    public function __construct(
        public CollectorCycleStatus $status,
        public int $pveSucceeded,
        public int $pvePartial,
        public int $pveFailed,
        public int $pbsSucceeded,
        public int $pbsPartial,
        public int $pbsFailed,
    ) {
        if (min(
            $this->pveSucceeded,
            $this->pvePartial,
            $this->pveFailed,
            $this->pbsSucceeded,
            $this->pbsPartial,
            $this->pbsFailed,
        ) < 0) {
            throw new InvalidArgumentException('Collector cycle result counters must not be negative.');
        }
        $usable = $this->pveSucceeded + $this->pvePartial + $this->pbsSucceeded + $this->pbsPartial;
        $partial = $this->pvePartial + $this->pbsPartial;
        $failed = $this->pveFailed + $this->pbsFailed;
        $valid = match ($this->status) {
            CollectorCycleStatus::Succeeded => 0 === $partial && 0 === $failed,
            CollectorCycleStatus::Partial => $partial > 0 || ($usable > 0 && $failed > 0),
            CollectorCycleStatus::Failed => 0 === $usable && $failed > 0,
            default => false,
        };
        if (!$valid) {
            throw new InvalidArgumentException('The collector cycle result status is inconsistent with its counters.');
        }
    }

    public function status(): CollectorCycleStatus
    {
        return $this->status;
    }
}
