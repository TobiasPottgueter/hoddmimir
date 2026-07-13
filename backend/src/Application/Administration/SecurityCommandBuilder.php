<?php

declare(strict_types=1);

namespace App\Application\Administration;

use App\Application\Security\Auth\PasswordHasher;
use App\Application\Security\Auth\SecurityIdentifierGenerator;
use App\Application\Security\PlaintextSecret;
use App\Domain\Security\NormalizedUsername;
use App\Domain\Security\PasswordPolicy;
use App\Domain\Security\Role;
use App\Domain\Security\UserDisplayName;
use InvalidArgumentException;
use SensitiveParameter;
use ValueError;

final readonly class SecurityCommandBuilder
{
    public function __construct(
        private PasswordHasher $passwords,
        private SecurityIdentifierGenerator $ids,
        private PasswordPolicy $passwordPolicy = new PasswordPolicy(),
    ) {
    }

    /** @param array<string, mixed> $payload */
    public function build(
        SecurityCommandType $type,
        ?string $subjectId,
        int $expectedRevision,
        array $payload,
        string $idempotencyKey,
        ?string $correlationId,
        #[SensitiveParameter] ?string $password,
    ): SecurityCommand {
        $subjectId ??= $this->ids->generate();
        $correlationId ??= $this->ids->generate();
        $this->validatePayload($type, $payload);
        if (SecurityCommandType::UserCreate === $type && null === $password) {
            throw new InvalidArgumentException('A password is required.');
        }
        $secret = null;
        $hash = null;
        if (null !== $password) {
            $this->passwordPolicy->assertValid($password);
            $secret = PlaintextSecret::fromString($password);
            $hash = $this->passwords->hash($secret);
        }
        return new SecurityCommand($type, $subjectId, $expectedRevision, $payload, $idempotencyKey, $correlationId, $secret, $hash);
    }

    /** @param array<string, mixed> $payload */
    private function validatePayload(SecurityCommandType $type, array $payload): void
    {
        if (SecurityCommandType::UserCreate === $type) {
            new NormalizedUsername($this->string($payload, 'username'));
            new UserDisplayName($this->string($payload, 'displayName'));
            $this->roles($payload);
        } elseif (SecurityCommandType::UserUpdate === $type) {
            new UserDisplayName($this->string($payload, 'displayName'));
        } elseif (SecurityCommandType::UserRolesReplace === $type) {
            $this->roles($payload);
        } elseif ([] !== $payload) {
            throw new InvalidArgumentException('The command payload must be empty.');
        }
    }

    /** @param array<string, mixed> $payload */
    private function roles(array $payload): void
    {
        $roles = $payload['roles'] ?? null;
        if (!is_array($roles) || [] === $roles || !array_is_list($roles) || count($roles) > count(Role::cases())) {
            throw new InvalidArgumentException('The role assignment is invalid.');
        }
        $seen = [];
        foreach ($roles as $role) {
            if (!is_string($role)) {
                throw new InvalidArgumentException('The role assignment is invalid.');
            }
            try {
                $typed = Role::from($role);
            } catch (ValueError) {
                throw new InvalidArgumentException('The role assignment is invalid.');
            }
            if (isset($seen[$typed->value])) {
                throw new InvalidArgumentException('The role assignment is invalid.');
            }
            $seen[$typed->value] = true;
        }
    }

    /** @param array<string, mixed> $payload */
    private function string(array $payload, string $key): string
    {
        $value = $payload[$key] ?? null;
        if (!is_string($value)) {
            throw new InvalidArgumentException('A security command text field is invalid.');
        }
        return $value;
    }
}
