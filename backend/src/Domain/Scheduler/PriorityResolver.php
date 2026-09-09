<?php

declare(strict_types=1);

namespace App\Domain\Scheduler;

use InvalidArgumentException;

final readonly class PriorityResolver
{
    public function resolve(
        RequestOrigin $origin,
        ?BackupReason $reason,
        ?ReasonPriority $originalRequest,
    ): ReasonPriority {
        if (RequestOrigin::Manual === $origin) {
            return $this->manual($reason, $originalRequest);
        }
        if (RequestOrigin::Automatic === $origin) {
            return $this->automatic($reason, $originalRequest);
        }

        return $this->retry($reason, $originalRequest);
    }

    private function manual(?BackupReason $reason, ?ReasonPriority $originalRequest): ReasonPriority
    {
        if (BackupReason::Manual !== $reason || null !== $originalRequest) {
            throw new InvalidArgumentException('A manual request requires only the manual reason.');
        }

        return new ReasonPriority($reason, Priority::Manual);
    }

    private function automatic(?BackupReason $reason, ?ReasonPriority $originalRequest): ReasonPriority
    {
        if (null === $reason || BackupReason::Manual === $reason || null !== $originalRequest) {
            throw new InvalidArgumentException('An automatic request requires one automatic reason.');
        }

        return new ReasonPriority($reason, ReasonPriority::expected($reason));
    }

    private function retry(?BackupReason $reason, ?ReasonPriority $originalRequest): ReasonPriority
    {
        if (null !== $reason || null === $originalRequest) {
            throw new InvalidArgumentException('A retry requires only its original reason and priority.');
        }

        return $originalRequest;
    }
}
