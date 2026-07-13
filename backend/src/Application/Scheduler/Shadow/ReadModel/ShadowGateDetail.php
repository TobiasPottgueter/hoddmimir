<?php

declare(strict_types=1);

namespace App\Application\Scheduler\Shadow\ReadModel;

use App\Domain\Scheduler\GateCode;
use App\Domain\Scheduler\GateDetailCode;
use App\Domain\Scheduler\GateScope;
use InvalidArgumentException;

final readonly class ShadowGateDetail
{
    public function __construct(
        public int $position,
        public GateCode $code,
        public bool $passed,
        public GateScope $scope,
        public string $subjectId,
        public ?string $observedAt,
        public GateDetailCode $detailCode,
    ) {
        if ($position < 1 || $passed !== (GateDetailCode::Passed === $detailCode)) {
            throw new InvalidArgumentException('The shadow gate detail is inconsistent.');
        }
    }

    /** @return array{position: int, code: string, passed: bool, scope: string, subjectId: string, observedAt: ?string, detailCode: string} */
    public function toArray(): array
    {
        return [
            'position' => $this->position,
            'code' => $this->code->value,
            'passed' => $this->passed,
            'scope' => $this->scope->value,
            'subjectId' => $this->subjectId,
            'observedAt' => $this->observedAt,
            'detailCode' => $this->detailCode->value,
        ];
    }
}
