<?php

declare(strict_types=1);

namespace App\Application\Backup\Execution\ReadModel;

use App\Application\Inventory\ReadModel\PageCursor;
use App\Application\Inventory\ReadModel\PageCursorKind;
use App\Application\Inventory\ReadModel\PageRequest;
use App\Application\Inventory\ReadModel\ReadModelIdentifier;
use InvalidArgumentException;

final readonly class ExecutorPermissionEvidenceQuery
{
    public function __construct(
        public PageRequest $page,
        public ?ReadModelIdentifier $connectionId = null,
        public ?ReadModelIdentifier $clusterId = null,
        public ?ReadModelIdentifier $targetId = null,
        public ?ReadModelIdentifier $nodeId = null,
        public ?ReadModelIdentifier $guestId = null,
    ) {
        $this->page->cursor?->assertContext(PageCursorKind::Resource, $this->cursorContext());
        if (null !== $this->page->cursor && $this->page->cursor->first !== $this->page->cursor->second) {
            throw new InvalidArgumentException('The executor permission evidence cursor is invalid.');
        }
    }

    public function cursorContext(): string
    {
        return PageCursor::context(
            'executor-permission-evidence-v1',
            $this->identifierValue($this->connectionId),
            $this->identifierValue($this->clusterId),
            $this->identifierValue($this->targetId),
            $this->identifierValue($this->nodeId),
            $this->identifierValue($this->guestId),
        );
    }

    private function identifierValue(?ReadModelIdentifier $identifier): string
    {
        return null === $identifier ? '' : $identifier->value;
    }
}
