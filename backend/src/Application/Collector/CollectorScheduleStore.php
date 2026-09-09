<?php

declare(strict_types=1);

namespace App\Application\Collector;

use DateTimeImmutable;

interface CollectorScheduleStore
{
    public function bootstrap(int $gridWidthSeconds): CollectorScheduleSnapshot;

    public function claimDue(
        CollectorWorkerId $workerId,
        CollectorCycleToken $cycleToken,
        int $leaseTtlSeconds,
    ): CollectorClaimDecision;

    public function renew(CollectorLease $lease, int $leaseTtlSeconds): CollectorLease;

    public function finalize(
        CollectorLease $lease,
        CollectorCycleStatus $status,
        int $durationMilliseconds,
    ): DateTimeImmutable;

}
