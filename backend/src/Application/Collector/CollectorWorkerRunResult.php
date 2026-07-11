<?php

declare(strict_types=1);

namespace App\Application\Collector;

use App\Application\Worker\WorkerReadinessReport;

final readonly class CollectorWorkerRunResult
{
    public function __construct(
        public CollectorWorkerRunCode $code,
        public ?WorkerReadinessReport $readiness = null,
    ) {
    }

    public function exitCode(): int
    {
        return match ($this->code) {
            CollectorWorkerRunCode::CycleSucceeded,
            CollectorWorkerRunCode::NoCycleDue,
            CollectorWorkerRunCode::CollectorStopped => 0,
            default => 1,
        };
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        $payload = [
            'component' => 'collector',
            'status' => 0 === $this->exitCode() ? 'ok' : 'failed',
            'code' => $this->code->value,
        ];

        if (null !== $this->readiness) {
            $payload['readiness'] = $this->readiness->toArray();
        }

        return $payload;
    }
}
