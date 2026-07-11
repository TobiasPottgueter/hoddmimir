<?php

declare(strict_types=1);

namespace App\Infrastructure\Proxmox;

use App\Application\Worker\Sleeper;
use InvalidArgumentException;

final readonly class PveExponentialJitterDelay implements PveRetryDelay
{
    public function __construct(
        private Sleeper $sleeper,
        private PveJitterSource $jitterSource,
    ) {
    }

    public function pause(int $retryNumber): void
    {
        if ($retryNumber < 1) {
            throw new InvalidArgumentException('The PVE retry number is invalid.');
        }

        $exponent = $retryNumber - 1;
        if ($exponent > 3) {
            $exponent = 3;
        }
        $baseSeconds = 2 ** $exponent;
        $this->sleeper->sleep($baseSeconds + $this->jitterSource->seconds());
    }
}
