<?php

declare(strict_types=1);

namespace App\Application\Policy\ReadModel;

use App\Application\Inventory\ReadModel\PageCursor;
use App\Application\Inventory\ReadModel\PageCursorKind;
use App\Application\Inventory\ReadModel\PageRequest;
use App\Application\Inventory\ReadModel\ReadModelIdentifier;

final readonly class PolicySelectionQuery
{
    public function __construct(public string $policyId, public PageRequest $page)
    {
        new ReadModelIdentifier($policyId);
        $page->cursor?->assertContext(PageCursorKind::Resource, $this->cursorContext());
    }

    public function cursorContext(): string
    {
        return PageCursor::context('policy-selection-v1', $this->policyId);
    }
}
