<?php

declare(strict_types=1);

namespace App\Application\Maintenance;

use App\Domain\Shared\Clock;

final readonly class CheckMaintenanceQuiescence
{
    public function __construct(
        private MaintenanceQuiescenceCatalog $catalog,
        private MaintenanceRemoteTasks $remote,
        private Clock $clock,
    ) {}

    public function check(): void
    {
        $started = $this->clock->now();
        if ($this->catalog->hasUnsettledLocalWork()) throw new MaintenanceQuiescenceFailure(true);
        foreach ($this->catalog->targets() as $target) {
            $this->remote->assertQuiet($target, $this->catalog->submissionNodes($target->connectionId));
        }
        $now = $this->clock->now();
        if ($now < $started || $now > $started->modify('+120 seconds') || $this->catalog->hasUnsettledLocalWork()) {
            throw new MaintenanceQuiescenceFailure();
        }
    }
}
