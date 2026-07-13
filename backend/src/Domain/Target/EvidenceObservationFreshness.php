<?php

declare(strict_types=1);

namespace App\Domain\Target;

enum EvidenceObservationFreshness
{
    case Missing;
    case Fresh;
    case Stale;
    case Future;
}
