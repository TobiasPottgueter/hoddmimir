<?php

declare(strict_types=1);

namespace App\Application\Inventory\Pve;

use App\Application\Collector\CollectorLease;

interface PveCoreInventoryStore
{
    public function beginRun(CollectorLease $lease, PveSyncRunStart $start): void;

    public function recordEndpointAttempt(CollectorLease $lease, PveEndpointAttempt $attempt): void;

    public function finishWithoutSnapshot(CollectorLease $lease, PveSyncRunFailure $failure): void;

    public function apply(CollectorLease $lease, PveCoreInventoryCommit $commit): PveCoreApplyResult;
}
