<?php

declare(strict_types=1);

namespace App\Application\Security\Auth;

use App\Application\Security\Audit\AuditEventType;
use App\Application\Security\Audit\AuditOutcome;
use App\Application\Security\Audit\SecurityAuditRecorder;
use App\Domain\Shared\Clock;

final readonly class LogoutSession
{
    public function __construct(
        private SecurityTransaction $transaction,
        private WebSessionStore $sessions,
        private OpaqueSecretHasher $digests,
        private SecurityAuditRecorder $audit,
        private Clock $clock,
    ) {
    }

    public function logout(OpaqueToken $sessionToken, OpaqueToken $csrfToken, string $correlationId): void
    {
        $success = $this->transaction->run(function () use ($sessionToken, $csrfToken, $correlationId): bool {
            $sessionDigest = $this->digests->digestToken($sessionToken);
            $stored = $this->sessions->lockSession($sessionDigest);
            if (null === $stored || null !== $stored->revokedAt || !$stored->csrfHash->equals($this->digests->digestToken($csrfToken))) {
                $this->audit->record(AuditEventType::SessionRevoked, AuditOutcome::Denied, $correlationId, reasonCode: 'authentication_failed');

                return false;
            }
            $now = $this->clock->now();
            if ($stored->window->isExpiredAt($now)) {
                $this->audit->record(AuditEventType::SessionRevoked, AuditOutcome::Denied, $correlationId, $stored->principal->userId, $stored->id, 'session', $stored->id, 'session_expired');

                return false;
            }
            $this->sessions->revoke($sessionDigest, $now);
            $this->audit->record(AuditEventType::SessionRevoked, AuditOutcome::Succeeded, $correlationId, $stored->principal->userId, $stored->id, 'session', $stored->id);

            return true;
        });
        if (!$success) {
            throw new AuthenticationFailed();
        }
    }
}
