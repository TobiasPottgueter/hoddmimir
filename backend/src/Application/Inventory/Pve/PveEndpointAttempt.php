<?php

declare(strict_types=1);

namespace App\Application\Inventory\Pve;

use App\Application\Inventory\InventoryIdentifier;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;

final readonly class PveEndpointAttempt
{
    public DateTimeImmutable $startedAt;
    public DateTimeImmutable $finishedAt;

    public function __construct(
        public InventoryIdentifier $attemptId,
        public InventoryIdentifier $runId,
        public InventoryIdentifier $connectionId,
        public InventoryIdentifier $endpointId,
        public int $attemptNumber,
        public EndpointAttemptOutcome $outcome,
        public ?string $errorCode,
        DateTimeImmutable $startedAt,
        DateTimeImmutable $finishedAt,
    ) {
        if ($this->attemptNumber < 1) {
            throw new InvalidArgumentException('The endpoint attempt number must be positive.');
        }
        if ((EndpointAttemptOutcome::Selected === $this->outcome) !== (null === $this->errorCode)) {
            throw new InvalidArgumentException('The endpoint attempt outcome and error code are inconsistent.');
        }
        if (null !== $this->errorCode && !PveCoreTextValidator::isErrorCode($this->errorCode)) {
            throw new InvalidArgumentException('The endpoint attempt error code is invalid.');
        }
        $this->startedAt = $startedAt->setTimezone(new DateTimeZone('UTC'));
        $this->finishedAt = $finishedAt->setTimezone(new DateTimeZone('UTC'));
        if ($this->finishedAt < $this->startedAt) {
            throw new InvalidArgumentException('The endpoint attempt cannot finish before it starts.');
        }
    }
}
