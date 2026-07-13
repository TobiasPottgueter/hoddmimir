<?php

declare(strict_types=1);

namespace App\Application\Scheduler\Shadow\ReadModel;

final readonly class ShadowDecisionDetail
{
    /** @param list<ShadowGateDetail> $gates */
    public function __construct(public ShadowDecisionSummary $decision, public array $gates) {}
    /** @return array<string, mixed> */
    public function toArray(): array { return [...$this->decision->toArray(), 'gates' => array_map(static fn (ShadowGateDetail $gate): array => $gate->toArray(), $this->gates)]; }
}
