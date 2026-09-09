<?php

declare(strict_types=1);

namespace App\Application\Proxmox\Pbs;

use InvalidArgumentException;

final readonly class PbsJobListSnapshot
{
    /** @var list<PbsJobObservation> */ public array $jobs;

    /** @param list<PbsJobObservation> $jobs */
    public function __construct(public PbsJobKind $kind, public string $digest, array $jobs)
    {
        if (64 !== strlen($digest) || 64 !== strspn($digest, '0123456789abcdef')) {
            throw new InvalidArgumentException('The PBS job list digest is invalid.');
        }
        $byId = [];
        foreach ($jobs as $job) {
            if ($job->kind !== $kind) {
                throw new InvalidArgumentException('The PBS job list is invalid.');
            }
            if (isset($byId[$job->id->value])) {
                throw new InvalidArgumentException('The PBS job list is invalid.');
            }
            $byId[$job->id->value] = $job;
        }
        ksort($byId, SORT_STRING);
        $this->jobs = array_values($byId);
    }
}
