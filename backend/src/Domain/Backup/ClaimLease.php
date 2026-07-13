<?php

declare(strict_types=1);

namespace App\Domain\Backup;

use DateTimeImmutable;
use InvalidArgumentException;

final readonly class ClaimLease
{
    public function __construct(
        public ClaimAuthority $authority,
        public DateTimeImmutable $issuedAt,
        public DateTimeImmutable $expiresAt,
    ) {
        self::assertUtc($issuedAt);
        self::assertUtc($expiresAt);
        if ($expiresAt <= $issuedAt) {
            throw new InvalidArgumentException('A claim lease must expire after it is issued.');
        }
    }

    public function isActiveAt(DateTimeImmutable $now): bool
    {
        self::assertUtc($now);

        return $now >= $this->issuedAt && $now < $this->expiresAt;
    }

    public function assertCurrent(ClaimAuthority $authority, DateTimeImmutable $now): void
    {
        if (!$this->authority->equals($authority) || !$this->isActiveAt($now)) {
            throw new InvalidArgumentException('The claim authority is stale.');
        }
    }

    public function renew(
        ClaimAuthority $authority,
        DateTimeImmutable $now,
        DateTimeImmutable $newExpiry,
    ): self {
        $this->assertCurrent($authority, $now);
        self::assertUtc($newExpiry);
        if ($newExpiry <= $this->expiresAt) {
            throw new InvalidArgumentException('A renewed claim lease must extend its expiry monotonically.');
        }

        return new self($this->authority, $this->issuedAt, $newExpiry);
    }

    private static function assertUtc(DateTimeImmutable $value): void
    {
        if (0 !== $value->getOffset()) {
            throw new InvalidArgumentException('Claim timestamps must use UTC.');
        }
    }
}
