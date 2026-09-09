<?php

declare(strict_types=1);

namespace App\Domain\Backup;

enum RetryDisposition: string
{
    case NotApplicable = 'not_applicable';
    case ControlledAllowed = 'controlled_allowed';
    case ForbiddenAmbiguous = 'forbidden_ambiguous';
}
