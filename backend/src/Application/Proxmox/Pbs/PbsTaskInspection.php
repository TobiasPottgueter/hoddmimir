<?php

declare(strict_types=1);

namespace App\Application\Proxmox\Pbs;

/** Bounded, redacted diagnostic evidence; never used as scheduler occupancy evidence. */
final readonly class PbsTaskInspection
{
    /** @param list<array{number: int, text: string}> $lines */
    public function __construct(
        public PbsUpid $upid,
        public ?string $status,
        public ?string $exitStatus,
        public ?int $endTime,
        public array $lines,
        public bool $truncated,
        public ?PbsReadFailureCode $statusFailure,
        public ?PbsReadFailureCode $logFailure,
    ) {}
}
