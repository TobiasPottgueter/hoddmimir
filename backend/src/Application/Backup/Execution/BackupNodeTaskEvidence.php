<?php

declare(strict_types=1);

namespace App\Application\Backup\Execution;

use App\Application\Proxmox\Pve\PveTaskNodeNameValidator;
use DateTimeImmutable;
use InvalidArgumentException;

/** Fresh, complete direct reads proving that the inspected nodes have no backup tasks. */
final readonly class BackupNodeTaskEvidence
{
    public const MAXIMUM_AGE_SECONDS = 30;

    /** @param list<string> $nodes */
    public function __construct(public array $nodes, public DateTimeImmutable $observedAt)
    {
        if ([] === $nodes || 0 !== $observedAt->getOffset()) {
            throw new InvalidArgumentException('Invalid backup task evidence.');
        }
        foreach ($nodes as $node) {
            if (!PveTaskNodeNameValidator::isValid($node)) {
                throw new InvalidArgumentException('Invalid backup task evidence node.');
            }
        }
    }

    /** @param list<string> $requiredNodes */
    public function covers(array $requiredNodes, DateTimeImmutable $now): bool
    {
        return [] !== $requiredNodes
            && [] === array_diff($requiredNodes, $this->nodes)
            && $this->observedAt <= $now
            && $this->observedAt->modify('+'.self::MAXIMUM_AGE_SECONDS.' seconds') >= $now;
    }
}
