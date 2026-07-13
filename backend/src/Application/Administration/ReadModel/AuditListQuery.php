<?php

declare(strict_types=1);

namespace App\Application\Administration\ReadModel;

use App\Application\Inventory\ReadModel\PageCursor;
use App\Application\Inventory\ReadModel\PageRequest;
use App\Application\Inventory\ReadModel\ReadModelIdentifier;
use App\Application\Security\Audit\AuditEventType;
use App\Application\Security\Audit\AuditOutcome;

final readonly class AuditListQuery
{
    public function __construct(
        public PageRequest $page,
        public ?string $actorUserId = null,
        public ?AuditEventType $eventType = null,
        public ?AuditOutcome $outcome = null,
    ) {
        if (null !== $actorUserId) {
            new ReadModelIdentifier($actorUserId);
        }
    }

    public function context(): string
    {
        return PageCursor::context('admin-audit-v1', $this->actorUserId ?? '', null === $this->eventType ? '' : $this->eventType->value, null === $this->outcome ? '' : $this->outcome->value);
    }
}
