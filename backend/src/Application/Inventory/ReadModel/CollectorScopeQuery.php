<?php

declare(strict_types=1);

namespace App\Application\Inventory\ReadModel;

final readonly class CollectorScopeQuery
{
    public function __construct(
        public ReadModelIdentifier $runId,
        public PageRequest $page,
    ) {
        $this->page->cursor?->assertContext(PageCursorKind::CollectorScope, $this->cursorContext());
    }

    public function cursorContext(): string
    {
        return PageCursor::context('collector-scopes-v1', $this->runId->value);
    }
}
