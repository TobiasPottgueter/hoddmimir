<?php

declare(strict_types=1);

namespace App\Application\Proxmox\Pve;

use InvalidArgumentException;

/** Hard per-connection bounds for one PVE backup inventory read. */
final readonly class PveBackupInventoryLimits
{
    public const MAXIMUM_NODES = 128;
    public const MAXIMUM_ACTIVE_PAGE_CAP = 2;
    public const MAXIMUM_ARCHIVE_PAGE_CAP = 10;
    public const MAXIMUM_REQUESTS = 512;
    public const MAXIMUM_RAW_ROWS = 25_000;
    public const MAXIMUM_DISTINCT_TASKS = 25_000;
    public const MAXIMUM_ARCHIVE_WINDOW_SECONDS = 86_400;

    public function __construct(
        public int $pageSize = PveTaskQuery::MAXIMUM_PAGE_SIZE,
        public int $nodeLimit = self::MAXIMUM_NODES,
        public int $activePageCap = self::MAXIMUM_ACTIVE_PAGE_CAP,
        public int $archivePageCap = self::MAXIMUM_ARCHIVE_PAGE_CAP,
        public int $requestLimit = self::MAXIMUM_REQUESTS,
        public int $rawRowLimit = self::MAXIMUM_RAW_ROWS,
        public int $distinctTaskLimit = self::MAXIMUM_DISTINCT_TASKS,
        public int $archiveWindowSeconds = self::MAXIMUM_ARCHIVE_WINDOW_SECONDS,
    ) {
        if ($pageSize < 1 || $pageSize > PveTaskQuery::MAXIMUM_PAGE_SIZE
            || $nodeLimit < 1 || $nodeLimit > self::MAXIMUM_NODES
            || $activePageCap < 1 || $activePageCap > self::MAXIMUM_ACTIVE_PAGE_CAP
            || $archivePageCap < 1 || $archivePageCap > self::MAXIMUM_ARCHIVE_PAGE_CAP
            || $requestLimit < 1 || $requestLimit > self::MAXIMUM_REQUESTS
            || $rawRowLimit < $pageSize || $rawRowLimit > self::MAXIMUM_RAW_ROWS
            || $distinctTaskLimit < 1 || $distinctTaskLimit > $rawRowLimit
            || $archiveWindowSeconds < 1
            || $archiveWindowSeconds > self::MAXIMUM_ARCHIVE_WINDOW_SECONDS) {
            throw new InvalidArgumentException('The PVE backup inventory limits are invalid.');
        }
    }

    public function pageCap(PveTaskSource $source): int
    {
        return PveTaskSource::Active === $source ? $this->activePageCap : $this->archivePageCap;
    }
}
