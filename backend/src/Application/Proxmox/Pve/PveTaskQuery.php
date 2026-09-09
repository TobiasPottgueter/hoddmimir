<?php

declare(strict_types=1);

namespace App\Application\Proxmox\Pve;

use InvalidArgumentException;

final readonly class PveTaskQuery
{
    public const MAXIMUM_PAGE_SIZE = 100;

    private function __construct(
        public PveTaskSource $source,
        public int $start,
        public int $limit,
        public ?int $since,
        public ?int $until,
    ) {
        if ($start < 0 || $limit < 1 || $limit > self::MAXIMUM_PAGE_SIZE || $start > PHP_INT_MAX - $limit) {
            throw new InvalidArgumentException('The PVE task page bounds are invalid.');
        }

        if (PveTaskSource::Archive === $source
            && (null === $since || null === $until || $since < 0 || $until < $since)) {
            throw new InvalidArgumentException('Archive task queries require bounded inclusive timestamps.');
        }
    }

    public static function active(int $start = 0, int $limit = self::MAXIMUM_PAGE_SIZE): self
    {
        return new self(PveTaskSource::Active, $start, $limit, null, null);
    }

    public static function archive(
        int $since,
        int $until,
        int $start = 0,
        int $limit = self::MAXIMUM_PAGE_SIZE,
    ): self {
        return new self(PveTaskSource::Archive, $start, $limit, $since, $until);
    }

    /** @return array<string, string|int> */
    public function parameters(): array
    {
        $parameters = [
            'typefilter' => 'vzdump',
            'source' => $this->source->value,
            'start' => $this->start,
            'limit' => $this->limit,
        ];

        if (PveTaskSource::Archive === $this->source) {
            $parameters['since'] = $this->since ?? throw new \LogicException('Archive since bound is missing.');
            $parameters['until'] = $this->until ?? throw new \LogicException('Archive until bound is missing.');
        }

        return $parameters;
    }

    public function nextPage(): self
    {
        return PveTaskSource::Active === $this->source
            ? self::active($this->start + $this->limit, $this->limit)
            : self::archive(
                $this->since ?? throw new \LogicException('Archive since bound is missing.'),
                $this->until ?? throw new \LogicException('Archive until bound is missing.'),
                $this->start + $this->limit,
                $this->limit,
            );
    }
}
