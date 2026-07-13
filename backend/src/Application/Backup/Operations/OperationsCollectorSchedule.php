<?php

declare(strict_types=1);

namespace App\Application\Backup\Operations;

final readonly class OperationsCollectorSchedule
{
    public function __construct(
        public string $nextScanAt,
        public ?string $lastAttemptStartedAt,
        public ?string $lastAttemptFinishedAt,
        public ?string $lastSuccessfulAppliedAt,
    ) {
    }

    /** @return array{nextScanAt:string,lastAttemptStartedAt:?string,lastAttemptFinishedAt:?string,lastSuccessfulAppliedAt:?string} */
    public function toArray(): array
    {
        return [
            'nextScanAt'=>$this->nextScanAt, 'lastAttemptStartedAt'=>$this->lastAttemptStartedAt,
            'lastAttemptFinishedAt'=>$this->lastAttemptFinishedAt,
            'lastSuccessfulAppliedAt'=>$this->lastSuccessfulAppliedAt,
        ];
    }
}
