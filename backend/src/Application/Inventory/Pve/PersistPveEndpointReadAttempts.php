<?php

declare(strict_types=1);

namespace App\Application\Inventory\Pve;

use App\Application\Inventory\Connection\ClaimedCycleCheckpoint;
use App\Application\Inventory\Connection\EndpointId;
use App\Application\Inventory\Connection\EndpointReadAttemptOutcome;
use App\Application\Inventory\Connection\EndpointReadAttemptSink;
use App\Application\Inventory\Connection\EndpointReadAttemptStarted;
use App\Application\Inventory\Connection\EndpointReadFailureCode;
use App\Application\Inventory\InventoryIdentifier;
use App\Application\Inventory\InventoryIdentifierGenerator;
use App\Domain\Shared\Clock;

final readonly class PersistPveEndpointReadAttempts implements EndpointReadAttemptSink
{
    public function __construct(
        private ClaimedCycleCheckpoint $checkpoint,
        private PveCoreInventoryStore $store,
        private InventoryIdentifierGenerator $identifierGenerator,
        private Clock $clock,
        private InventoryIdentifier $runId,
        private InventoryIdentifier $connectionId,
    ) {
    }

    public function start(EndpointId $endpointId, int $attemptNumber): EndpointReadAttemptStarted
    {
        return new EndpointReadAttemptStarted($endpointId, $attemptNumber, $this->clock->now());
    }

    public function finish(
        EndpointReadAttemptStarted $attempt,
        EndpointReadAttemptOutcome $outcome,
        ?EndpointReadFailureCode $failureCode,
    ): void {
        $this->store->recordEndpointAttempt($this->checkpoint->lease(), new PveEndpointAttempt(
            $this->identifierGenerator->generate(),
            $this->runId,
            $this->connectionId,
            new InventoryIdentifier($attempt->endpointId->bytes),
            $attempt->attemptNumber,
            EndpointAttemptOutcome::from($outcome->value),
            $failureCode?->value,
            $attempt->startedAt,
            $this->clock->now(),
        ));
    }
}
