<?php

declare(strict_types=1);

namespace App\Application\Backup\Metrics;

use DateTimeImmutable;
use InvalidArgumentException;

final readonly class QueueMetricWindow
{
    public int $bucketSeconds;
    public DateTimeImmutable $since;

    public function __construct(public int $hours, public DateTimeImmutable $until)
    {
        $this->bucketSeconds = match ($hours) {
            24 => 120,
            168 => 900,
            720 => 3600,
            default => throw new InvalidArgumentException('Unsupported queue history window.'),
        };
        $this->since = $until->setTimestamp($until->getTimestamp() - $hours * 3600);
    }
}
