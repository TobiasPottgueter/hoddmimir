<?php

declare(strict_types=1);

namespace App\Domain\Scheduler;

enum GateScope: string
{
    case Connection = 'connection';
    case Cluster = 'cluster';
    case Node = 'node';
    case Guest = 'guest';
    case Policy = 'policy';
    case Target = 'target';
    case Placement = 'placement';
    case Capacity = 'capacity';
    case Concurrency = 'concurrency';
    case Request = 'request';
    case PbsMapping = 'pbs_mapping';
}
