<?php

declare(strict_types=1);

namespace App\Application\Inventory\Pve;

use App\Application\Inventory\InventoryIdentifier;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;

final readonly class PveCoreInventoryCommit
{
    /** @var list<PveNodeObservation> */
    public array $nodes;

    /** @var list<PveGuestObservation> */
    public array $guests;

    public DateTimeImmutable $observedAt;

    /**
     * @param list<PveNodeObservation>  $nodes
     * @param list<PveGuestObservation> $guests
     */
    public function __construct(
        public InventoryIdentifier $runId,
        public InventoryIdentifier $connectionId,
        public InventoryIdentifier $endpointId,
        public int $expectedConnectionRevision,
        public PveCoreInstallationBinding $binding,
        public PveCoreScopeResult $topologyScope,
        public PveCoreScopeResult $guestScope,
        array $nodes,
        array $guests,
        DateTimeImmutable $observedAt,
    ) {
        if ($this->expectedConnectionRevision < 1) {
            throw new InvalidArgumentException('The expected connection revision must be positive.');
        }
        if (PveCoreScope::Topology !== $this->topologyScope->scope || PveCoreScope::Guests !== $this->guestScope->scope) {
            throw new InvalidArgumentException('The PVE core inventory scopes are invalid.');
        }

        $nodesByName = [];
        foreach ($nodes as $node) {
            // @phpstan-ignore instanceof.alwaysTrue (enforce the runtime boundary promised by the PHPDoc)
            if (!$node instanceof PveNodeObservation || isset($nodesByName[$node->name])) {
                throw new InvalidArgumentException('The PVE core node observations are invalid.');
            }
            $nodesByName[$node->name] = $node;
        }
        if ($this->topologyScope->isComplete() && [] === $nodesByName) {
            throw new InvalidArgumentException('A complete PVE topology requires at least one node.');
        }
        if (PveCoreBindingKind::Standalone === $this->binding->kind
            && [] !== $nodesByName
            && (1 !== count($nodesByName) || !isset($nodesByName[$this->binding->value]))) {
            throw new InvalidArgumentException('A standalone PVE binding must identify its only node.');
        }

        $guestsByKey = [];
        foreach ($guests as $guest) {
            // @phpstan-ignore instanceof.alwaysTrue (enforce the runtime boundary promised by the PHPDoc)
            if (!$guest instanceof PveGuestObservation
                || isset($guestsByKey[$guest->key()])
                || !isset($nodesByName[$guest->node])) {
                throw new InvalidArgumentException('The PVE core guest observations are invalid.');
            }
            $guestsByKey[$guest->key()] = $guest;
        }
        ksort($nodesByName, SORT_STRING);
        ksort($guestsByKey, SORT_STRING);
        $this->nodes = array_values($nodesByName);
        $this->guests = array_values($guestsByKey);
        $this->observedAt = $observedAt->setTimezone(new DateTimeZone('UTC'));
    }

    public function isFullyAuthoritative(): bool
    {
        return $this->topologyScope->isComplete() && $this->guestScope->isComplete();
    }

    /** @return 'succeeded'|'partial'|'failed' */
    public function overallStatus(): string
    {
        if ($this->isFullyAuthoritative()) {
            return 'succeeded';
        }

        if (InventoryScopeStatus::Failed === $this->topologyScope->status
            && InventoryScopeStatus::Failed === $this->guestScope->status
            && [] === $this->nodes && [] === $this->guests) {
            return 'failed';
        }

        return 'partial';
    }
}
