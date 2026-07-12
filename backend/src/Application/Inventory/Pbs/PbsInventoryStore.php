<?php

declare(strict_types=1);

namespace App\Application\Inventory\Pbs;

use App\Application\Collector\CollectorLease;
use App\Application\Inventory\Pve\PveEndpointAttempt;
use App\Application\Inventory\Pve\PveSyncRunFailure;
use App\Application\Inventory\Pve\PveSyncRunStart;

interface PbsInventoryStore
{
    public function beginRun(CollectorLease $lease, PveSyncRunStart $start): void;
    public function recordEndpointAttempt(CollectorLease $lease, PveEndpointAttempt $attempt): void;
    public function finishWithoutSnapshot(CollectorLease $lease, PveSyncRunFailure $failure): void;
    public function apply(CollectorLease $lease, PbsInventoryCommit $inventory): PbsInventoryApplyResult;
}
