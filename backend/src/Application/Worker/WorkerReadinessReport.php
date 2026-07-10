<?php

declare(strict_types=1);

namespace App\Application\Worker;

use App\Domain\Worker\WorkerKind;
use DateTimeImmutable;
use DateTimeInterface;

final readonly class WorkerReadinessReport
{
    public function __construct(
        public WorkerKind $worker,
        public DateTimeImmutable $checkedAt,
    ) {
    }

    /**
     * @return array{component: string, status: 'ready', checkedAt: string}
     */
    public function toArray(): array
    {
        return [
            'component' => $this->worker->value,
            'status' => 'ready',
            'checkedAt' => $this->checkedAt->format(DateTimeInterface::RFC3339_EXTENDED),
        ];
    }
}
