<?php

declare(strict_types=1);

namespace App\Application\Monitoring;

use App\Application\Inventory\InventoryIdentifier;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;

final readonly class MonitoringRunFailure
{
    public DateTimeImmutable $finishedAt;

    public function __construct(
        public InventoryIdentifier $runId,
        public InventoryIdentifier $connectionId,
        public string $errorCode,
        DateTimeImmutable $finishedAt,
    ) {
        if ('' === $this->errorCode || strlen($this->errorCode) > 64
            || strlen($this->errorCode) !== strspn($this->errorCode, 'abcdefghijklmnopqrstuvwxyz0123456789_')) {
            throw new InvalidArgumentException('The monitoring error code is invalid.');
        }
        $this->finishedAt = $finishedAt->setTimezone(new DateTimeZone('UTC'));
    }
}
