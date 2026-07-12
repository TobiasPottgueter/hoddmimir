<?php

declare(strict_types=1);

namespace App\Application\Scheduler\Shadow;

use RuntimeException;

final class ShadowEvaluationConflict extends RuntimeException
{
    public function __construct(
        public readonly ShadowEvaluationConflictCode $failureCode,
        string $message = 'The shadow evaluation conflicts with persisted state.',
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }
}
