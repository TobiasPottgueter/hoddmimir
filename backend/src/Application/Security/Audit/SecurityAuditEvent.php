<?php

declare(strict_types=1);

namespace App\Application\Security\Audit;

use App\Domain\Security\UserId;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;

final readonly class SecurityAuditEvent
{
    public DateTimeImmutable $occurredAt;

    public function __construct(
        public string $id,
        DateTimeImmutable $occurredAt,
        public ?UserId $actorUserId,
        public ?string $actorSessionId,
        public AuditEventType $type,
        public AuditOutcome $outcome,
        public ?string $subjectType,
        public ?string $subjectId,
        public ?string $reasonCode,
        public string $correlationId,
    ) {
        if (16 !== \strlen($id) || 16 !== \strlen($correlationId)) {
            throw new InvalidArgumentException('The structured security audit event is invalid.');
        }
        if (null !== $actorSessionId && 16 !== \strlen($actorSessionId)) {
            throw new InvalidArgumentException('The structured security audit event is invalid.');
        }
        if ((null === $subjectType) !== (null === $subjectId)) {
            throw new InvalidArgumentException('The structured security audit event is invalid.');
        }
        if (null !== $subjectId && 16 !== \strlen($subjectId)) {
            throw new InvalidArgumentException('The structured security audit event is invalid.');
        }
        if (null !== $subjectType && !\in_array($subjectType, [
            'user', 'session', 'role', 'target', 'policy', 'selection', 'guest_override', 'backup_request',
        ], true)) {
            throw new InvalidArgumentException('The structured security audit event is invalid.');
        }
        if (null !== $reasonCode && 1 !== \preg_match('/\A[a-z0-9][a-z0-9._-]{0,63}\z/D', $reasonCode)) {
            throw new InvalidArgumentException('The structured security audit event is invalid.');
        }
        $this->occurredAt = $occurredAt->setTimezone(new DateTimeZone('UTC'));
    }
}
