<?php

declare(strict_types=1);

namespace App\Application\Inventory\Connection;

interface EndpointReadAttemptSink
{
    public function start(EndpointId $endpointId, int $attemptNumber): EndpointReadAttemptStarted;

    public function finish(
        EndpointReadAttemptStarted $attempt,
        EndpointReadAttemptOutcome $outcome,
        ?EndpointReadFailureCode $failureCode,
    ): void;
}
