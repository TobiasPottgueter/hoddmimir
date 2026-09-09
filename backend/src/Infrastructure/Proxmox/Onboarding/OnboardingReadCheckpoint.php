<?php

declare(strict_types=1);

namespace App\Infrastructure\Proxmox\Onboarding;

use App\Application\Inventory\Connection\ConnectionReadCheckpoint;

/** The onboarding request is bounded by the synchronous HTTP request itself. */
final readonly class OnboardingReadCheckpoint implements ConnectionReadCheckpoint
{
    public function checkpoint(): void
    {
    }
}
