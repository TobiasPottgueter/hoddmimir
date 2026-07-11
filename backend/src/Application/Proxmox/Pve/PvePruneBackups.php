<?php

declare(strict_types=1);

namespace App\Application\Proxmox\Pve;

final readonly class PvePruneBackups
{
    public function __construct(
        public ?bool $keepAll = null,
        public ?int $keepLast = null,
        public ?int $keepHourly = null,
        public ?int $keepDaily = null,
        public ?int $keepWeekly = null,
        public ?int $keepMonthly = null,
        public ?int $keepYearly = null,
    ) {
    }

    /** @return array<string, bool|int> */
    public function signature(): array
    {
        $result = [];
        foreach ([
            'keep-all' => $this->keepAll,
            'keep-last' => $this->keepLast,
            'keep-hourly' => $this->keepHourly,
            'keep-daily' => $this->keepDaily,
            'keep-weekly' => $this->keepWeekly,
            'keep-monthly' => $this->keepMonthly,
            'keep-yearly' => $this->keepYearly,
        ] as $name => $value) {
            if (null !== $value) {
                $result[$name] = $value;
            }
        }

        return $result;
    }
}
