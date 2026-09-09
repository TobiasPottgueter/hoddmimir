<?php

declare(strict_types=1);

namespace App\Application\Proxmox\Pve;

use InvalidArgumentException;

final readonly class PveTaskLogPage
{
    /** @var list<PveTaskLogEntry> */
    public array $entries;

    /** @param list<PveTaskLogEntry> $entries */
    public function __construct(
        public PveTaskLogQuery $query,
        array $entries,
    ) {
        if (count($entries) > $query->limit) {
            throw new InvalidArgumentException('The PVE task log page exceeds its requested limit.');
        }
        $previous = null;
        foreach ($entries as $entry) {
            // @phpstan-ignore instanceof.alwaysTrue (enforce the runtime boundary promised by the PHPDoc)
            if (!$entry instanceof PveTaskLogEntry || (null !== $previous && $entry->number <= $previous)) {
                throw new InvalidArgumentException('The PVE task log page is invalid.');
            }
            $previous = $entry->number;
        }
        $this->entries = $entries;
    }

    public function isShort(): bool
    {
        return count($this->entries) < $this->query->limit;
    }
}
