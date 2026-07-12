<?php

declare(strict_types=1);

namespace App\Application\Inventory\PbsContent;

enum PbsContentRunStatus: string
{
    case Running = 'running';
    case Succeeded = 'succeeded';
    case Partial = 'partial';
    case Failed = 'failed';
}
