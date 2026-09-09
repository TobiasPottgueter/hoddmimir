<?php

declare(strict_types=1);

namespace App\Application\Inventory\PbsContent;

use App\Application\Inventory\InventoryIdentifier;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;

final readonly class PbsContentRunFailure
{
    public DateTimeImmutable $finishedAt;

    public function __construct(
        public InventoryIdentifier $runId,
        public InventoryIdentifier $connectionId,
        public string $errorCode,
        DateTimeImmutable $finishedAt,
    ) {
        $length = strlen($errorCode);
        if ($length < 1 || $length > 64
            || $length !== strspn($errorCode, 'abcdefghijklmnopqrstuvwxyz0123456789_')) {
            throw new InvalidArgumentException('The PBS content failure code is invalid.');
        }
        $this->finishedAt = $finishedAt->setTimezone(new DateTimeZone('UTC'));
    }
}
