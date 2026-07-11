<?php

declare(strict_types=1);

namespace App\Infrastructure\Proxmox;

use InvalidArgumentException;

final readonly class PveRetryPolicy
{
    public function __construct(private int $maximumAttempts = 3)
    {
        if ($maximumAttempts < 1 || $maximumAttempts > 5) {
            throw new InvalidArgumentException('The PVE retry limit is invalid.');
        }
    }

    public function shouldRetry(
        PveHttpMethod $method,
        int $attempt,
        bool $transportFailure,
        ?int $statusCode,
    ): bool {
        if (!$method->isReadOnly() || $attempt >= $this->maximumAttempts) {
            return false;
        }

        if ($transportFailure) {
            return true;
        }

        if (null === $statusCode) {
            return false;
        }

        return match ($statusCode) {
            408, 429, 502, 503, 504 => true,
            default => false,
        };
    }
}
