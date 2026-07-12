<?php

declare(strict_types=1);

namespace App\Application\Inventory\ReadModel;

use InvalidArgumentException;

final readonly class InventoryResourceQuery
{
    public function __construct(
        public InventoryResourceKind $kind,
        public PageRequest $page,
        public ?ReadModelIdentifier $connectionId = null,
        public ?ReadModelIdentifier $parentId = null,
        public ?InventoryState $inventoryState = null,
        public ?string $guestType = null,
    ) {
        if (null !== $this->parentId && !$this->kind->permitsParentFilter()) {
            throw new InvalidArgumentException('The selected resource kind does not support a parent filter.');
        }
        if (null === $this->guestType) {
            $this->assertCursor();
            return;
        }
        if (InventoryResourceKind::PveGuest !== $this->kind
            || ('qemu' !== $this->guestType && 'lxc' !== $this->guestType)) {
            throw new InvalidArgumentException('The guest type filter is invalid for the selected resource kind.');
        }
        $this->assertCursor();
    }

    public function cursorContext(): string
    {
        return PageCursor::context(
            'inventory-resources-v1',
            $this->kind->value,
            null === $this->connectionId ? '' : $this->connectionId->value,
            null === $this->parentId ? '' : $this->parentId->value,
            null === $this->inventoryState ? '' : $this->inventoryState->value,
            $this->guestType ?? '',
        );
    }

    private function assertCursor(): void
    {
        $this->page->cursor?->assertContext(PageCursorKind::Resource, $this->cursorContext());
    }
}
