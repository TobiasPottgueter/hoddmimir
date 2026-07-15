<?php

declare(strict_types=1);

namespace App\Application\Scheduler\Shadow;

enum ShadowEvaluationConflictCode: string
{
    case RunIdReused = 'run_id_reused';
    case CycleAlreadyEvaluated = 'cycle_already_evaluated';
    case PayloadMismatch = 'payload_mismatch';
    case CycleTokenMismatch = 'cycle_token_mismatch';
    case ActiveRequestChanged = 'active_request_changed';
}
