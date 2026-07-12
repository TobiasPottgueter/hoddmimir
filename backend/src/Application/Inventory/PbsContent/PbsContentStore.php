<?php

declare(strict_types=1);

namespace App\Application\Inventory\PbsContent;

use App\Application\Collector\CollectorLease;

interface PbsContentStore
{
    public function begin(CollectorLease $lease, PbsContentRunStart $start): void;
    public function fail(CollectorLease $lease, PbsContentRunFailure $failure): void;
    public function apply(CollectorLease $lease, PbsContentCommit $commit): PbsContentApplyResult;
}
