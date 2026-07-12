<?php

declare(strict_types=1);

namespace App\Application\Monitoring;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;

final readonly class MonitoringScopeResult
{
    public ?DateTimeImmutable $windowSince;
    public ?DateTimeImmutable $windowUntil;
    public DateTimeImmutable $observedAt;

    public function __construct(
        public MonitoringScopeType $scope,
        public string $key,
        public MonitoringSourceKind $source,
        public ?string $filter,
        public MonitoringScopeStatus $status,
        ?DateTimeImmutable $windowSince,
        ?DateTimeImmutable $windowUntil,
        public int $pagesRead,
        public int $rowsRead,
        public int $itemsSeen,
        public bool $truncated,
        public bool $historyGap,
        public ?string $errorCode,
        DateTimeImmutable $observedAt,
    ) {
        if ('' === $this->key || strlen($this->key) > 255
            || null !== $this->filter && ('' === $this->filter || strlen($this->filter) > 64)
            || min($this->pagesRead, $this->rowsRead, $this->itemsSeen) < 0
            || (null === $windowSince) !== (null === $windowUntil)
            || null !== $windowSince && $windowUntil < $windowSince
            || MonitoringScopeStatus::Complete === $this->status && $this->truncated
            || MonitoringScopeStatus::Complete === $this->status && $this->historyGap
            || $this->historyGap
                && MonitoringScopeType::PveTasksArchive !== $this->scope
                && MonitoringScopeType::PbsTasksWindow !== $this->scope
            || (MonitoringScopeStatus::Complete === $this->status) !== (null === $this->errorCode)
            || null !== $this->errorCode && ('' === $this->errorCode || strlen($this->errorCode) > 64
                || strlen($this->errorCode) !== strspn(
                    $this->errorCode,
                    'abcdefghijklmnopqrstuvwxyz0123456789_',
                ))) {
            throw new InvalidArgumentException('The monitoring scope result is invalid.');
        }
        if (!$this->matchesScopeContract($windowSince, $windowUntil)) {
            throw new InvalidArgumentException('The monitoring scope source, filter, or window is invalid.');
        }
        $utc = new DateTimeZone('UTC');
        $this->windowSince = $windowSince?->setTimezone($utc);
        $this->windowUntil = $windowUntil?->setTimezone($utc);
        $this->observedAt = $observedAt->setTimezone($utc);
    }

    private function matchesScopeContract(
        ?DateTimeImmutable $windowSince,
        ?DateTimeImmutable $windowUntil,
    ): bool {
        $windowed = null !== $windowSince && null !== $windowUntil;
        if (MonitoringScopeType::PveBackupJobs === $this->scope) {
            return MonitoringSourceKind::Jobs === $this->source && null === $this->filter && !$windowed;
        }
        if (MonitoringScopeType::PveTasksActive === $this->scope) {
            return MonitoringSourceKind::Active === $this->source && 'vzdump' === $this->filter && !$windowed;
        }
        if (MonitoringScopeType::PveTasksArchive === $this->scope) {
            return MonitoringSourceKind::Archive === $this->source && 'vzdump' === $this->filter && $windowed;
        }
        if (MonitoringScopeType::PbsPruneJobs === $this->scope) {
            return MonitoringSourceKind::Jobs === $this->source && 'prune' === $this->filter && !$windowed;
        }
        if (MonitoringScopeType::PbsSyncJobs === $this->scope) {
            return MonitoringSourceKind::Jobs === $this->source && 'sync' === $this->filter && !$windowed;
        }
        if (MonitoringScopeType::PbsVerifyJobs === $this->scope) {
            return MonitoringSourceKind::Jobs === $this->source && 'verify' === $this->filter && !$windowed;
        }
        if (MonitoringScopeType::PbsTasksRunning === $this->scope) {
            return MonitoringSourceKind::Running === $this->source && $this->validPbsTaskFilter() && !$windowed;
        }
        return MonitoringSourceKind::History === $this->source && $this->validPbsTaskFilter() && $windowed;
    }

    private function validPbsTaskFilter(): bool
    {
        return 'backup' === $this->filter
            || 'prune' === $this->filter
            || 'syncjob' === $this->filter
            || 'verif' === $this->filter;
    }
}
