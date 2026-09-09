<?php

declare(strict_types=1);

namespace App\Application\Proxmox\Pbs;

use InvalidArgumentException;

final readonly class PbsTaskPage
{
    /** @var list<PbsTaskObservation> */ public array $tasks;
    public int $rawRowCount;
    public string $rawFingerprint;

    /** @param list<PbsTaskObservation> $tasks */
    public function __construct(
        array $tasks,
        public ?int $total,
        ?int $rawRowCount = null,
        ?string $rawFingerprint = null,
    )
    {
        if (null === $rawRowCount) {
            $rawRowCount = count($tasks);
        }
        if (null === $rawFingerprint) {
            $fingerprintInput = $rawRowCount."\0";
            $separator = '';
            foreach ($tasks as $task) {
                $fingerprintInput .= $separator.$task->upid->value;
                $separator = "\0";
            }
            $rawFingerprint = hash('sha256', $fingerprintInput);
        }
        if (null !== $total && $total < 0 || $rawRowCount < count($tasks)
            || 64 !== strlen($rawFingerprint)
            || 64 !== strspn($rawFingerprint, '0123456789abcdef')) {
            throw new InvalidArgumentException('The PBS task page total is invalid.');
        }
        $seen = [];
        foreach ($tasks as $task) {
            if (isset($seen[$task->upid->value])) {
                throw new InvalidArgumentException('The PBS task page contains duplicate UPIDs.');
            }
            $seen[$task->upid->value] = true;
        }
        $this->tasks = $tasks;
        $this->rawRowCount = $rawRowCount;
        $this->rawFingerprint = $rawFingerprint;
    }
}
