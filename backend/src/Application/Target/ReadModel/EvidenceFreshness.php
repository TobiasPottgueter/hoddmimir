<?php

declare(strict_types=1);

namespace App\Application\Target\ReadModel;

enum EvidenceFreshness: string
{
    case Fresh = 'fresh';
    case Missing = 'missing';
    case Stale = 'stale';
    case Future = 'future';
}
