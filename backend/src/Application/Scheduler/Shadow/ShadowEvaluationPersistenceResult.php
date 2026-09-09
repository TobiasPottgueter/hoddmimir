<?php

declare(strict_types=1);

namespace App\Application\Scheduler\Shadow;

enum ShadowEvaluationPersistenceResult: string
{
    case Persisted = 'persisted';
    case AlreadyPersisted = 'already_persisted';
}
