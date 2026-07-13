<?php

declare(strict_types=1);

namespace App\Application\Security\Auth;

use App\Domain\Security\SessionWindow;
use App\Domain\Security\UserId;

interface WebSessionStore
{
    public function create(string $sessionId, UserId $userId, SecurityDigest $tokenHash, SecurityDigest $csrfHash, SessionWindow $window): void;
    public function lockSession(SecurityDigest $tokenHash): ?StoredWebSession;
    public function touch(SecurityDigest $tokenHash, SessionWindow $window): void;
    public function revoke(SecurityDigest $tokenHash, \DateTimeImmutable $at): void;
}
