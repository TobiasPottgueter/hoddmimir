<?php

declare(strict_types=1);

namespace App\Application\Target\ReadModel;

use App\Application\Inventory\ReadModel\ReadModelIdentifier;
use InvalidArgumentException;

final readonly class ConfiguredBackupTargetAllowedNode
{
    public function __construct(
        public string $id,
        public string $name,
    ) {
        new ReadModelIdentifier($id);
        if ('' === $name || \strlen($name) > 190 || \str_contains($name, "\0")) {
            throw new InvalidArgumentException('A configured backup-target node is invalid.');
        }
    }

    /** @return array{id: string, name: string} */
    public function toArray(): array
    {
        return ['id' => $this->id, 'name' => $this->name];
    }
}
