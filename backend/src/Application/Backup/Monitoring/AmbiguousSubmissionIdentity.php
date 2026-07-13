<?php

declare(strict_types=1);

namespace App\Application\Backup\Monitoring;

use App\Application\Proxmox\Pve\PveTaskNodeNameValidator;
use DateTimeImmutable;
use InvalidArgumentException;

final readonly class AmbiguousSubmissionIdentity
{
    public function __construct(
        public string $requestId,
        public string $node,
        public int $vmid,
        public string $user,
        public DateTimeImmutable $windowStart,
        public DateTimeImmutable $windowEnd,
    ) {
        if (16 !== \strlen($requestId)
            || !PveTaskNodeNameValidator::isValid($node)
            || $vmid < 1
            || $vmid > 999_999_999
            || 1 !== \preg_match('/\A[^\s:\/\x00-\x1F\x7F]{1,255}\z/D', $user)
            || 0 !== $windowStart->getOffset()
            || 0 !== $windowEnd->getOffset()
            || $windowEnd < $windowStart
            || $windowEnd->getTimestamp() - $windowStart->getTimestamp() > 300) {
            throw new InvalidArgumentException('The ambiguous submission identity is invalid.');
        }
    }
}
