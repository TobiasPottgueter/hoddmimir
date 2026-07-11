<?php

declare(strict_types=1);

namespace App\Application\Inventory\Pve;

use App\Application\Collector\CollectorCycleStatus;
use App\Application\Collector\CollectorCycleResult;
use InvalidArgumentException;

final readonly class ClaimedPveCoreInventoryCycleResult implements CollectorCycleResult
{
    public function __construct(
        public CollectorCycleStatus $status,
        public int $pveSucceeded,
        public int $pvePartial,
        public int $pveFailed,
        public int $pbsDeferred,
    ) {
        if (min($this->pveSucceeded, $this->pvePartial, $this->pveFailed, $this->pbsDeferred) < 0) {
            throw new InvalidArgumentException('Collector cycle result counters must not be negative.');
        }
        $usablePve = $this->pveSucceeded + $this->pvePartial;
        $valid = match ($this->status) {
            CollectorCycleStatus::Succeeded => 0 === $this->pvePartial
                && 0 === $this->pveFailed
                && 0 === $this->pbsDeferred,
            CollectorCycleStatus::Partial => $this->pvePartial > 0
                || $this->pbsDeferred > 0
                || ($usablePve > 0 && $this->pveFailed > 0),
            CollectorCycleStatus::Failed => 0 === $usablePve && $this->pveFailed > 0,
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
