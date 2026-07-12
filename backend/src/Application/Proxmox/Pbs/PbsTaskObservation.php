<?php

declare(strict_types=1);

namespace App\Application\Proxmox\Pbs;

use InvalidArgumentException;

final readonly class PbsTaskObservation
{
    public function __construct(
        public PbsUpid $upid,
        public ?string $reportedNode,
        public bool $seenRunning,
        public bool $seenHistory,
        public ?PbsTaskOutcome $outcome,
        public ?int $endTime,
    ) {
        if (!$seenRunning && !$seenHistory
            || !PbsTaskFilterFamily::allowsAny($upid->workerType)
            || null === $outcome && null !== $endTime
            || null !== $endTime && $endTime < $upid->startTime
            || null !== $reportedNode && !$this->validReportedNode($reportedNode)) {
            throw new InvalidArgumentException('The PBS task lifecycle evidence is inconsistent.');
        }
    }

    public function isRunning(): bool
    {
        return null === $this->outcome;
    }

    public function merge(self $other): self
    {
        if ($this->upid->value !== $other->upid->value) {
            throw new InvalidArgumentException('Different PBS tasks cannot be merged.');
        }
        $reportedNode = $this->preferredReportedNode($other);
        if (!$this->isRunning() && !$other->isRunning()) {
            if ($this->outcome !== $other->outcome
                || null !== $this->endTime && null !== $other->endTime
                    && $this->endTime !== $other->endTime) {
                throw new InvalidArgumentException('Conflicting terminal PBS tasks cannot be merged.');
            }
            $outcome = $this->outcome;
            $endTime = $this->endTime ?? $other->endTime;
        } else {
            if ($this->isRunning()) {
                $lifecycle = $other;
            } else {
                $lifecycle = $this;
            }
            $outcome = $lifecycle->outcome;
            $endTime = $lifecycle->endTime;
        }
        return new self(
            $this->upid,
            $reportedNode,
            $this->seenRunning || $other->seenRunning,
            $this->seenHistory || $other->seenHistory,
            $outcome,
            $endTime,
        );
    }

    private function preferredReportedNode(self $other): ?string
    {
        $nodes = array_values(array_unique(array_filter(
            [$this->reportedNode, $other->reportedNode],
            static fn (?string $node): bool => null !== $node,
        )));
        sort($nodes, SORT_STRING);
        foreach ($nodes as $node) {
            if ('localhost' !== $node) {
                return $node;
            }
        }
        return $nodes[0] ?? null;
    }

    private function validReportedNode(string $node): bool
    {
        $length = strlen($node);
        return 'localhost' === $node
            || $length >= 1 && $length <= 63
                && $length === strspn($node, 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789-')
                && '-' !== $node[0] && '-' !== $node[$length - 1];
    }
}
