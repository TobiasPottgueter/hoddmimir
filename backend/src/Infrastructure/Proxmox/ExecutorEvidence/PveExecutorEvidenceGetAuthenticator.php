<?php

declare(strict_types=1);

namespace App\Infrastructure\Proxmox\ExecutorEvidence;

interface PveExecutorEvidenceGetAuthenticator
{
    /**
     * @template TResult
     * @param callable(string): TResult $request
     * @return TResult
     */
    public function authorize(callable $request): mixed;
}
