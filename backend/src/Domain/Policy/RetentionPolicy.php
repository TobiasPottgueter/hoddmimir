<?php

declare(strict_types=1);

namespace App\Domain\Policy;

use InvalidArgumentException;

final readonly class RetentionPolicy
{
    private const int MAXIMUM_COUNT = 1_000_000;

    private function __construct(
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

    public static function legacyMaxFiles(int $maximumFiles): self
    {
        self::assertCount($maximumFiles);

        return new self($maximumFiles, null, null, null, null, null, null, null);
    }

    public static function prune(
        ?bool $keepAll,
        ?int $keepLast,
        ?int $keepHourly,
        ?int $keepDaily,
        ?int $keepWeekly,
        ?int $keepMonthly,
        ?int $keepYearly,
    ): self {
        $policy = new self(
            null,
            $keepAll,
            $keepLast,
            $keepHourly,
            $keepDaily,
            $keepWeekly,
            $keepMonthly,
            $keepYearly,
        );
        $pruneValues = $policy->pruneSignature();
        if ([] === $pruneValues) {
            throw new InvalidArgumentException('A retention policy must be explicit.');
        }
        foreach ($pruneValues as $value) {
            if (is_int($value)) {
                self::assertCount($value);
            }
        }
        $countRules = array_filter($pruneValues, is_int(...));
        if (true === $keepAll && [] !== $countRules) {
            throw new InvalidArgumentException('Keep-all cannot be combined with counted prune rules.');
        }
        if (false === $keepAll && [] === $countRules) {
            throw new InvalidArgumentException('Keep-all false requires at least one counted prune rule.');
        }

        return $policy;
    }

    public function supportsPveMajor(int $major): bool
    {
        if (null === $this->legacyMaxFiles) {
            return true;
        }

        return $major >= 7 && $major <= 8;
    }

    /** @return array{maxfiles: int}|array{prune-backups: array<string, bool|int>} */
    public function signature(): array
    {
        if (null !== $this->legacyMaxFiles) {
            return ['maxfiles' => $this->legacyMaxFiles];
        }

        return ['prune-backups' => $this->pruneSignature()];
    }

    /** @return array<string, bool|int> */
    private function pruneSignature(): array
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

    private static function assertCount(int $value): void
    {
        if ($value < 1 || $value > self::MAXIMUM_COUNT) {
            throw new InvalidArgumentException('Retention counts must be between one and one million.');
        }
    }
}
