<?php

declare(strict_types=1);

namespace App\Application\Target\ReadModel;

use App\Application\Inventory\ReadModel\PageCursor;
use App\Application\Inventory\ReadModel\PageCursorKind;
use App\Application\Inventory\ReadModel\PageRequest;
use App\Application\Inventory\ReadModel\ReadModelIdentifier;

final readonly class BackupTargetCandidateQuery
{
    public function __construct(
        public PageRequest $page,
        public ?ReadModelIdentifier $connectionId = null,
        public ?ReadModelIdentifier $clusterId = null,
    ) {
        $this->page->cursor?->assertContext(PageCursorKind::Resource, $this->cursorContext());
    }

    public function cursorContext(): string
    {
        return PageCursor::context(
            'backup-target-candidates-v1',
            null === $this->connectionId ? '' : $this->connectionId->value,
            null === $this->clusterId ? '' : $this->clusterId->value,
        );
    }
}
