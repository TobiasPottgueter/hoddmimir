<?php

declare(strict_types=1);

namespace App\Application\Backup\Monitoring;

interface AmbiguousSubmissionTaskSource
{
    /** @param callable(): bool $beforePage */
    public function read(AmbiguousSubmissionIdentity $identity, callable $beforePage): AmbiguousSubmissionEvidence;
}
