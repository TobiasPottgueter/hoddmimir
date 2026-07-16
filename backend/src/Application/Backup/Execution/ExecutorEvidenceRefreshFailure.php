<?php

declare(strict_types=1);

namespace App\Application\Backup\Execution;

use RuntimeException;

final class ExecutorEvidenceRefreshFailure extends RuntimeException
{
    private function __construct(public readonly ExecutorEvidenceRefreshFailureCode $failureCode)
    {
        parent::__construct('Executor permission evidence refresh failed.');
    }

    public static function for(ExecutorEvidenceRefreshFailureCode $code): self
    {
        return new self($code);
    }
}
