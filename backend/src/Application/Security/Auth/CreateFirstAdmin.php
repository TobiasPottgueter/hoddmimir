<?php

declare(strict_types=1);

namespace App\Application\Security\Auth;

use App\Application\Security\Audit\AuditEventType;
use App\Application\Security\Audit\AuditOutcome;
use App\Application\Security\Audit\SecurityAuditRecorder;
use App\Application\Security\PlaintextSecret;
use App\Domain\Security\NormalizedUsername;
use App\Domain\Security\PasswordPolicy;
use App\Domain\Security\UserDisplayName;
use App\Domain\Security\UserId;
use App\Domain\Shared\Clock;
use SensitiveParameter;

final readonly class CreateFirstAdmin implements FirstAdminCreator
{
    public function __construct(
        private SecurityTransaction $transaction,
        private FirstAdminStore $admins,
        private PasswordHasher $passwords,
        private SecurityIdentifierGenerator $ids,
        private SecurityAuditRecorder $audit,
        private Clock $clock,
        private PasswordPolicy $passwordPolicy = new PasswordPolicy(),
    ) {
    }

    /** @return bool true when created, false for an exact idempotent match */
    public function create(string $username, string $displayName, #[SensitiveParameter] string $password, bool $idempotent): bool
    {
        $this->passwordPolicy->assertValid($password);
        $normalized = new NormalizedUsername($username);
        $display = new UserDisplayName($displayName);
        $secret = PlaintextSecret::fromString($password);

        return $this->transaction->run(function () use ($normalized, $display, $secret, $idempotent): bool {
            $existing = $this->admins->findAdmin($normalized);
            if (null !== $existing) {
                if ($idempotent && $existing->displayName->value === $display->value
                    && $this->passwords->verify($secret, $existing->passwordHash)) {
                    return false;
                }
                throw new AdminBootstrapConflict('The requested first administrator conflicts with persisted state.');
            }
            if (0 !== $this->admins->countUsers()) {
                throw new AdminBootstrapConflict('The first administrator can only be created in an empty user store.');
            }
            $id = new UserId($this->ids->generate());
            $this->admins->createAdmin($id, $normalized, $display, $this->passwords->hash($secret), $this->clock->now());
            $this->audit->record(AuditEventType::FirstAdminCreated, AuditOutcome::Succeeded, $this->ids->generate(), $id, null, 'user', $id->binary());

            return true;
        });
    }
}
