<?php

declare(strict_types=1);

namespace App\Application\Administration\ReadModel;

use App\Application\Security\Audit\AuditEventType;
use App\Application\Security\Audit\AuditOutcome;

final readonly class AdministrationAuditEvent implements AdministrationReadItem
{
    public function __construct(
        public string $id,
        public string $occurredAt,
        public ?string $actorUserId,
        public ?string $actorSessionId,
        public AuditEventType $eventType,
        public AuditOutcome $outcome,
        public ?string $subjectType,
        public ?string $subjectId,
        public ?string $reasonCode,
        public string $correlationId,
    ) {
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'id' => $this->id, 'occurredAt' => $this->occurredAt,
            'actorUserId' => $this->actorUserId, 'actorSessionId' => $this->actorSessionId,
            'eventType' => $this->eventType->value, 'outcome' => $this->outcome->value,
            'subjectType' => $this->subjectType, 'subjectId' => $this->subjectId,
            'reasonCode' => $this->reasonCode, 'correlationId' => $this->correlationId,
        ];
    }
}
