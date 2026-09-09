<?php

declare(strict_types=1);

namespace App\Application\Security\Auth;

use App\Domain\Security\SessionWindow;
use DateTimeImmutable;
use InvalidArgumentException;

final readonly class StoredWebSession
{
    public function __construct(
        public string $id,
        public AuthenticatedPrincipal $principal,
        public SecurityDigest $csrfHash,
        public SessionWindow $window,
        public ?DateTimeImmutable $revokedAt,
    ) {
        if (16 !== strlen($id)) {
            throw new InvalidArgumentException('A session ID must contain exactly 16 bytes.');
        }
    }
}
