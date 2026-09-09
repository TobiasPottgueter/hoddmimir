<?php

declare(strict_types=1);

namespace App\Application\Proxmox\Pbs;

use InvalidArgumentException;

final readonly class PbsJobObservation
{
    public function __construct(
        public PbsJobKind $kind,
        public PbsJobId $id,
        public PbsDatastoreId $localStore,
        public ?PbsNamespace $localNamespace,
        public ?string $schedule,
        public bool $disabled,
        public ?PbsSyncDirection $syncDirection,
        public ?PbsJobId $remote,
        public ?PbsDatastoreId $remoteStore,
        public ?PbsNamespace $remoteNamespace,
        public ?PbsUpid $lastRunUpid,
        public ?PbsTaskOutcome $lastRunOutcome,
        public ?int $lastRunEndTime,
        public ?int $nextRunTime,
    ) {
        $isSync = PbsJobKind::Sync === $kind;
        if ($isSync && (null === $syncDirection || null === $remoteStore)
            || !$isSync && (null !== $syncDirection || null !== $remote
                || null !== $remoteStore || null !== $remoteNamespace)
            || (null === $lastRunUpid) !== (null === $lastRunOutcome)
            || null === $lastRunUpid && null !== $lastRunEndTime
            || null !== $schedule && ('' === $schedule || strlen($schedule) > 256
                || strlen($schedule) !== strspn(
                    $schedule,
                    ' !"#$%&\'()*+,-./0123456789:;<=>?@ABCDEFGHIJKLMNOPQRSTUVWXYZ[\\]^_`abcdefghijklmnopqrstuvwxyz{|}~',
                ))
            || null !== $lastRunEndTime && $lastRunEndTime < 0
            || null !== $nextRunTime && $nextRunTime < 0) {
            throw new InvalidArgumentException('The PBS job observation is inconsistent.');
        }
    }

    public function key(): string
    {
        return $this->kind->value."\0".$this->id->value;
    }
}
