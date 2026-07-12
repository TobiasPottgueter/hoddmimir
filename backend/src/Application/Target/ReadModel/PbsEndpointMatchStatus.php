<?php

declare(strict_types=1);

namespace App\Application\Target\ReadModel;

enum PbsEndpointMatchStatus: string
{
    case Matched = 'matched';
    case Unresolved = 'unresolved';
    case Ambiguous = 'ambiguous';
}
