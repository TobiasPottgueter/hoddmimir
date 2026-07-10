<?php

declare(strict_types=1);

namespace App\Application\Health;

use DateTimeImmutable;
use DateTimeInterface;

final readonly class HealthReport
{
    public function __construct(public DateTimeImmutable $checkedAt)
    {
    }

    /**
     * @return array{status: 'ok', checkedAt: string}
     */
    public function toArray(): array
    {
        return [
            'status' => 'ok',
            'checkedAt' => $this->checkedAt->format(DateTimeInterface::RFC3339_EXTENDED),
        ];
    }
}
