<?php

declare(strict_types=1);

namespace App\Infrastructure\Proxmox\Pbs;

use InvalidArgumentException;

final readonly class PbsExponentialJitterDelay implements PbsRetryDelay
{
    public function __construct(private PbsJitterSource $jitter, private int $baseMilliseconds = 100)
    {
        if ($baseMilliseconds < 1 || $baseMilliseconds > 10_000) {
            throw new InvalidArgumentException('The PBS retry delay is invalid.');
        }
    }

    public function pause(int $attempt): void
    {
        if ($attempt < 1 || $attempt > 5) {
            throw new InvalidArgumentException('The PBS retry attempt is invalid.');
        }
        $maximum = $this->baseMilliseconds * (2 ** ($attempt - 1));
        usleep(($maximum + $this->jitter->milliseconds($maximum)) * 1000);
    }
}
