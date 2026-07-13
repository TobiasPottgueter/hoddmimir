<?php

declare(strict_types=1);

namespace App\Application\Scheduler\Shadow\ReadModel;

use App\Application\Inventory\ReadModel\PageCursor;
use App\Application\Inventory\ReadModel\PageCursorKind;
use App\Application\Inventory\ReadModel\PageRequest;
use App\Application\Inventory\ReadModel\ReadModelIdentifier;
use App\Domain\Scheduler\BackupReason;
use App\Domain\Scheduler\DecisionOutcome;

final readonly class ShadowPageQuery
{
    public function __construct(
        public PageRequest $page,
        public string $context,
        public ?DecisionOutcome $outcome = null,
        public ?BackupReason $reason = null,
        public ?string $policyId = null,
        public ?string $targetId = null,
        public ?string $guestId = null,
    ) {
        foreach ([$this->policyId, $this->targetId, $this->guestId] as $identifier) {
            if (null !== $identifier) {
                new ReadModelIdentifier($identifier);
            }
        }
        $page->cursor?->assertContext(PageCursorKind::Resource, $this->cursorContext());
    }

    public function cursorContext(): string
    {
        return PageCursor::context(
            $this->context,
            null === $this->outcome ? '' : $this->outcome->value,
            null === $this->reason ? '' : $this->reason->value,
            $this->policyId ?? '',
            $this->targetId ?? '',
            $this->guestId ?? '',
        );
    }
}
