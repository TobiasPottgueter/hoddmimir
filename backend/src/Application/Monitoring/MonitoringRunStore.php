<?php

declare(strict_types=1);

namespace App\Application\Monitoring;

use App\Application\Collector\CollectorLease;

interface MonitoringRunStore
{
    public function begin(CollectorLease $lease, MonitoringRunStart $start): void;

    public function fail(CollectorLease $lease, MonitoringRunFailure $failure): void;

    public function apply(CollectorLease $lease, MonitoringCommit $commit): MonitoringApplyResult;

    public function finishFailedCommit(
        CollectorLease $lease,
        MonitoringCommit $commit,
        string $errorCode,
    ): void;
}
