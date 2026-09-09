<?php

declare(strict_types=1);

namespace App\Application\Inventory\PbsContent;

enum PbsContentScopeStatus: string
{
    case Complete = 'complete';
    case Partial = 'partial';
    case Failed = 'failed';
}
