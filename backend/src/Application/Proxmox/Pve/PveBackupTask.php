<?php

declare(strict_types=1);

namespace App\Application\Proxmox\Pve;

use InvalidArgumentException;

final readonly class PveBackupTask
{
    public const MAXIMUM_LIST_STATUS_LENGTH = 255;

    public bool $seenActive;
    public bool $seenArchive;

    public function __construct(
        public PveUpid $upid,
        public PveTaskSource $source,
        public ?int $endTime,
        public ?string $listStatus,
        ?bool $seenActive = null,
        ?bool $seenArchive = null,
    ) {
        if (null !== $endTime && $endTime < $upid->startTime) {
            throw new InvalidArgumentException('The PVE backup task end time is invalid.');
        }
        if (null !== $listStatus && !$this->validListStatus($listStatus)) {
            throw new InvalidArgumentException('The PVE backup task list status is invalid.');
        }
        $this->seenActive = $seenActive ?? PveTaskSource::Active === $source;
        $this->seenArchive = $seenArchive ?? PveTaskSource::Archive === $source;
        if (!$this->seenActive && !$this->seenArchive
            || PveTaskSource::Active === $source && !$this->seenActive
            || PveTaskSource::Archive === $source && !$this->seenArchive) {
            throw new InvalidArgumentException('The PVE backup task source evidence is invalid.');
        }
    }

    /** @return array{string, ?int, ?string} */
    public function signature(): array
    {
        return [$this->upid->raw, $this->endTime, $this->listStatus];
    }

    /**
     * Merge two observations of the same UPID without regressing a final state.
     * Null means that two final observations conflict and must remain partial.
     */
    public function enrich(self $observation): ?self
    {
        if ($this->upid->raw !== $observation->upid->raw) {
            return null;
        }

        if (null !== $this->endTime && null !== $observation->endTime
            && $this->endTime !== $observation->endTime) {
            return null;
        }
        $endTime = $this->endTime ?? $observation->endTime;

        $status = $this->mergeStatus($observation->listStatus);
        if (false === $status) {
            return null;
        }

        $source = PveTaskSource::Archive === $this->source || PveTaskSource::Archive === $observation->source
            ? PveTaskSource::Archive
            : PveTaskSource::Active;

        return new self(
            $this->upid,
            $source,
            $endTime,
            $status,
            $this->seenActive || $observation->seenActive,
            $this->seenArchive || $observation->seenArchive,
        );
    }

    private function mergeStatus(?string $observation): string|null|false
    {
        if ($this->listStatus === $observation || null === $observation) {
            return $this->listStatus;
        }
        if (null === $this->listStatus) {
            return $observation;
        }
        if ('RUNNING' === $this->listStatus) {
            return $observation;
        }
        if ('RUNNING' === $observation) {
            return $this->listStatus;
        }

        return false;
    }

    private function validListStatus(string $status): bool
    {
        return '' !== $status
            && strlen($status) <= self::MAXIMUM_LIST_STATUS_LENGTH
            && strlen($status) === strspn(
                $status,
                ' !"#$%&\'()*+,-./0123456789:;<=>?@ABCDEFGHIJKLMNOPQRSTUVWXYZ[\\]^_`abcdefghijklmnopqrstuvwxyz{|}~',
            );
    }
}
