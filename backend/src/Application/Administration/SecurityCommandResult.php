<?php

declare(strict_types=1);

namespace App\Application\Administration;

use InvalidArgumentException;

final readonly class SecurityCommandResult
{
    public function __construct(
        public SecurityCommandStatus $status,
        public ?int $revision = null,
        public ?string $blocker = null,
    ) {
        if (\in_array($status, [SecurityCommandStatus::Applied, SecurityCommandStatus::Replayed, SecurityCommandStatus::Conflict], true)
            && (null === $revision || $revision < 0 || null !== $blocker)) {
            throw new InvalidArgumentException('The security command result is invalid.');
        }
        if (\in_array($status, [SecurityCommandStatus::Blocked, SecurityCommandStatus::Denied], true)
            && (null !== $revision || !\is_string($blocker) || 1 !== \preg_match('/\A[a-z0-9][a-z0-9._-]{0,63}\z/D', $blocker))) {
            throw new InvalidArgumentException('The security command result is invalid.');
        }
    }

    public static function blocked(string $blocker): self
    {
        return new self(SecurityCommandStatus::Blocked, null, $blocker);
    }
}
