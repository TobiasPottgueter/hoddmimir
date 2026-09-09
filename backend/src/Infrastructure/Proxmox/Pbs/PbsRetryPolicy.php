<?php

declare(strict_types=1);

namespace App\Infrastructure\Proxmox\Pbs;

use InvalidArgumentException;

final readonly class PbsRetryPolicy
{
    public function __construct(private int $maximumAttempts = 3)
    {
        if ($maximumAttempts < 1 || $maximumAttempts > 5) {
            throw new InvalidArgumentException('The PBS retry limit is invalid.');
        }
    }

    public function shouldRetry(int $attempt, bool $transportFailure, ?int $statusCode): bool
    {
        if ($attempt >= $this->maximumAttempts) {
            return false;
        }
        if ($transportFailure) {
            return true;
        }
        return match ($statusCode) {
            408, 429, 502, 503, 504 => true,
            default => false,
        };
    }
}
