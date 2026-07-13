<?php

declare(strict_types=1);

namespace App\Application\Security\Audit;

enum AuditOutcome: string
{
    case Succeeded = 'succeeded';
    case Denied = 'denied';
}
