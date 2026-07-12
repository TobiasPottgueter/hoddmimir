<?php

declare(strict_types=1);

namespace App\Application\Inventory\ReadModel;

final readonly class InventoryResource
{
    /** @param array<string, bool|int|string|null|list<string>> $attributes */
    public function __construct(
        public string $id,
        public InventoryResourceKind $kind,
        public string $connectionId,
        public string $connectionName,
        public ?string $parentId,
        public string $displayName,
        public InventoryState $inventoryState,
        public string $firstSeenAt,
        public string $lastSeenAt,
        public ?string $archivedAt,
        public ?string $stateObservedAt,
        public array $attributes,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'kind' => $this->kind->value,
            'connectionId' => $this->connectionId,
            'connectionName' => $this->connectionName,
            'parentId' => $this->parentId,
            'displayName' => $this->displayName,
            'inventoryState' => $this->inventoryState->value,
            'firstSeenAt' => $this->firstSeenAt,
            'lastSeenAt' => $this->lastSeenAt,
            'archivedAt' => $this->archivedAt,
            'stateObservedAt' => $this->stateObservedAt,
            'attributes' => $this->attributes,
        ];
    }
}
