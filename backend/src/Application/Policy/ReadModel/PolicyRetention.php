<?php

declare(strict_types=1);

namespace App\Application\Policy\ReadModel;

final readonly class PolicyRetention
{
    public function __construct(
        public ?int $legacyMaxFiles,
        public ?bool $keepAll,
        public ?int $keepLast,
        public ?int $keepHourly,
        public ?int $keepDaily,
        public ?int $keepWeekly,
        public ?int $keepMonthly,
        public ?int $keepYearly,
    ) {
    }

    /** @return array<string, bool|int|null> */
    public function toArray(): array
    {
        return [
            'legacyMaxFiles' => $this->legacyMaxFiles,
            'keepAll' => $this->keepAll,
            'keepLast' => $this->keepLast,
            'keepHourly' => $this->keepHourly,
            'keepDaily' => $this->keepDaily,
            'keepWeekly' => $this->keepWeekly,
            'keepMonthly' => $this->keepMonthly,
            'keepYearly' => $this->keepYearly,
        ];
    }
}
