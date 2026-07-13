<?php

declare(strict_types=1);

namespace App\Domain\Backup;

final readonly class ClaimAuthority
{
    public function __construct(
        public ClaimToken $token,
        public ClaimFence $fence,
    ) {
    }

    public function equals(self $other): bool
    {
        return $this->fence->value === $other->fence->value && $this->token->equals($other->token);
    }
}
