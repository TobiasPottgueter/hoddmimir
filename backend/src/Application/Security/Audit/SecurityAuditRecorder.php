<?php

declare(strict_types=1);

namespace App\Application\Security\Audit;

use App\Application\Security\Auth\SecurityIdentifierGenerator;
use App\Domain\Security\UserId;
use App\Domain\Shared\Clock;

final readonly class SecurityAuditRecorder
{
    public function __construct(
        private AuditEventStore $store,
        private SecurityIdentifierGenerator $ids,
        private Clock $clock,
    ) {
    }

    public function record(
        AuditEventType $type,
        AuditOutcome $outcome,
        string $correlationId,
        ?UserId $actorUserId = null,
        ?string $actorSessionId = null,
        ?string $subjectType = null,
        ?string $subjectId = null,
        ?string $reasonCode = null,
    ): void {
        $this->store->append(new SecurityAuditEvent(
            $this->ids->generate(),
            $this->clock->now(),
            $actorUserId,
            $actorSessionId,
            $type,
            $outcome,
            $subjectType,
            $subjectId,
            $reasonCode,
            $correlationId,
        ));
    }
}
