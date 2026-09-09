<?php

declare(strict_types=1);

namespace App\Infrastructure\Security;

use App\Application\Security\Auth\PasswordHash;
use App\Application\Security\Auth\PasswordHasher;
use App\Application\Security\PlaintextSecret;

final readonly class NativeArgon2idPasswordHasher implements PasswordHasher
{
    public function hash(PlaintextSecret $password): PasswordHash
    {
        return $password->consume(static fn (string $value): PasswordHash => new PasswordHash(password_hash($value, PASSWORD_ARGON2ID)));
    }
    public function verify(PlaintextSecret $password, PasswordHash $hash): bool
    {
        return $password->consume(static fn (string $value): bool => password_verify($value, $hash->encoded()));
    }
    public function needsRehash(PasswordHash $hash): bool
    {
        return password_needs_rehash($hash->encoded(), PASSWORD_ARGON2ID);
    }
}
