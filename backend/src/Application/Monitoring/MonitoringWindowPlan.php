<?php

declare(strict_types=1);

namespace App\Application\Monitoring;

use App\Application\Proxmox\Pbs\PbsTaskWindow;
use App\Application\Proxmox\Pve\PveTaskArchiveWindow;
use InvalidArgumentException;

final readonly class MonitoringWindowPlan
{
    public function __construct(
        public int $since,
        public int $until,
        public bool $historyGap,
    ) {
        if ($this->since < 0 || $this->until < $this->since) {
            throw new InvalidArgumentException('The monitoring window plan is invalid.');
        }
    }

    public function pve(): PveTaskArchiveWindow
    {
        return new PveTaskArchiveWindow($this->since, $this->until, $this->historyGap);
    }

    public function pbs(): PbsTaskWindow
    {
        return new PbsTaskWindow($this->since, $this->until);
    }
}
