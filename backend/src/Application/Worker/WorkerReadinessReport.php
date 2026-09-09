<?php

declare(strict_types=1);

namespace App\Application\Worker;

use App\Application\Readiness\ReadinessReport;
use App\Domain\Worker\WorkerKind;
use DateTimeImmutable;
use DateTimeInterface;

final readonly class WorkerReadinessReport
{
    public function __construct(
        public WorkerKind $worker,
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
     *     component: string,
     *     status: 'ready'|'unavailable',
     *     checkedAt: string,
     *     checks: array<string, array{status: 'ready'}|array{status: 'unavailable', reason: string}>
     * }
     */
    public function toArray(): array
    {
        return [
            'component' => $this->worker->value,
            'status' => $this->isReady() ? 'ready' : 'unavailable',
            'checkedAt' => $this->checkedAt->format(DateTimeInterface::RFC3339_EXTENDED),
            'checks' => $this->readiness->checks(),
        ];
    }
}
