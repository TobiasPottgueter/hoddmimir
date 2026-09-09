<?php

declare(strict_types=1);

namespace App\Domain\Scheduler;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;

final readonly class GateResult
{
    public ?DateTimeImmutable $observedAt;

    public function __construct(
        public GateCode $code,
        public bool $passed,
        public GateScope $scope,
        public GateSubjectId $subjectId,
        ?DateTimeImmutable $observedAt,
        public GateDetailCode $detailCode,
    ) {
        if ($passed !== (GateDetailCode::Passed === $detailCode)) {
            throw new InvalidArgumentException('A gate detail code must agree with its pass state.');
        }

        $this->observedAt = $observedAt?->setTimezone(new DateTimeZone('UTC'));
    }
}
