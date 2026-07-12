<?php

declare(strict_types=1);

namespace App\Application\Proxmox\Pbs;

enum PbsTaskScanIssueCode: string
{
    case PageCapExceeded = 'page_cap_exceeded';
    case RowCapExceeded = 'row_cap_exceeded';
    case RepeatedPage = 'repeated_page';
    case NoProgress = 'no_progress';
    case ReadFailed = 'read_failed';
    case ConflictingTaskEvidence = 'conflicting_task_evidence';
}
