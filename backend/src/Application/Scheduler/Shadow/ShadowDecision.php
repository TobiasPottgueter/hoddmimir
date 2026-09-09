<?php

declare(strict_types=1);

namespace App\Application\Scheduler\Shadow;

use App\Application\Inventory\InventoryIdentifier;
use App\Domain\Scheduler\DecisionOutcome;
use App\Domain\Scheduler\ReasonPriority;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;

final readonly class ShadowDecision
{
    /** @var list<OrderedShadowGate> */
    public array $gates;
    public DateTimeImmutable $inventoryObservedAt;
    public ?DateTimeImmutable $capacityObservedAt;
    public ?DateTimeImmutable $writeStateObservedAt;

    /** @param list<OrderedShadowGate> $gates */
    public function __construct(
        public ShadowDecisionId $id,
        public InventoryIdentifier $connectionId,
        public InventoryIdentifier $clusterId,
        public InventoryIdentifier $guestId,
        public ?ShadowPlacementEvidence $placement,
        public DecisionOutcome $outcome,
        public ?ReasonPriority $reasonPriority,
        public ?ShadowPolicyEvidence $policy,
        public ?ShadowTargetEvidence $target,
        DateTimeImmutable $inventoryObservedAt,
        ?DateTimeImmutable $capacityObservedAt,
        ?DateTimeImmutable $writeStateObservedAt,
        array $gates,
    ) {
        if ([] === $gates) {
            throw new InvalidArgumentException('A shadow decision must persist every checked gate.');
        }

        $hasFailedGate = false;
        foreach ($gates as $offset => $gate) {
            // @phpstan-ignore instanceof.alwaysTrue (enforce the declared runtime boundary)
            if (!$gate instanceof OrderedShadowGate || $gate->position !== $offset + 1) {
                throw new InvalidArgumentException('Shadow decision gates must be contiguous and ordered from one.');
            }
            $hasFailedGate = $hasFailedGate || !$gate->result->passed;
        }
        $this->assertOutcome($hasFailedGate);

        $utc = new DateTimeZone('UTC');
        $this->inventoryObservedAt = $inventoryObservedAt->setTimezone($utc);
        $this->capacityObservedAt = $capacityObservedAt?->setTimezone($utc);
        $this->writeStateObservedAt = $writeStateObservedAt?->setTimezone($utc);
        $this->gates = $gates;
    }

    private function assertOutcome(bool $hasFailedGate): void
    {
        if (DecisionOutcome::Eligible === $this->outcome) {
            $this->assertEligible($hasFailedGate);
            return;
        }
        if (DecisionOutcome::NotDue === $this->outcome) {
            $this->assertNotDue($hasFailedGate);
            return;
        }
        $this->assertFailedGate($hasFailedGate);
    }

    private function assertEligible(bool $hasFailedGate): void
    {
        if ($hasFailedGate) {
            throw new InvalidArgumentException('An eligible shadow decision requires passed gates.');
        }
        if (null === $this->reasonPriority) {
            throw new InvalidArgumentException('An eligible shadow decision requires a backup reason.');
        }
        if (null === $this->placement) {
            throw new InvalidArgumentException('An eligible shadow decision requires placement evidence.');
        }
        if (null === $this->policy) {
            throw new InvalidArgumentException('An eligible shadow decision requires policy evidence.');
        }
        if (null === $this->target) {
            throw new InvalidArgumentException('An eligible shadow decision requires target evidence.');
        }
    }

    private function assertNotDue(bool $hasFailedGate): void
    {
        if ($hasFailedGate) {
            throw new InvalidArgumentException('A not-due shadow decision requires passed gates.');
        }
        if (null !== $this->reasonPriority) {
            throw new InvalidArgumentException('A not-due shadow decision cannot carry a backup reason.');
        }
        if (null === $this->placement) {
            throw new InvalidArgumentException('A not-due shadow decision requires placement evidence.');
        }
        if (null === $this->policy) {
            throw new InvalidArgumentException('A not-due shadow decision requires policy evidence.');
        }
        if (null === $this->target) {
            throw new InvalidArgumentException('A not-due shadow decision requires target evidence.');
        }
    }

    private function assertFailedGate(bool $hasFailedGate): void
    {
        if (!$hasFailedGate) {
            throw new InvalidArgumentException('A blocked or deduplicated shadow decision requires a failed gate.');
        }
    }
}
