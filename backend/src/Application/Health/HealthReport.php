<?php

declare(strict_types=1);

namespace App\Application\Health;

use App\Application\Readiness\ReadinessReport;
use DateTimeImmutable;
use DateTimeInterface;

final readonly class HealthReport
{
    public function __construct(
        public DateTimeImmutable $checkedAt,
        public ReadinessReport $readiness,
    ) {
    }

    public function isReady(): bool
    {
        return $this->readiness->isReady();
    }

    /**
     * @return array{
     *     status: 'ok'|'unavailable',
     *     checkedAt: string,
     *     checks: array<string, array{status: 'ready'}|array{status: 'unavailable', reason: string}>
     * }
     */
    public function toArray(): array
    {
        return [
            'status' => $this->isReady() ? 'ok' : 'unavailable',
            'checkedAt' => $this->checkedAt->format(DateTimeInterface::RFC3339_EXTENDED),
            'checks' => $this->readiness->checks(),
        ];
    }
}
