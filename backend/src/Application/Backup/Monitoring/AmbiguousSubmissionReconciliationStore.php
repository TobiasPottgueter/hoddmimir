<?php

declare(strict_types=1);

namespace App\Application\Backup\Monitoring;

use App\Domain\Backup\RecoveryOutcome;

interface AmbiguousSubmissionReconciliationStore
{
    /** Renews the current fenced claim before another bounded evidence page is read. */
    public function renew(ReconcileAmbiguousSubmissionCommand $command): bool;

    /** Returns only a currently fenced reconcile_required run. */
    public function prepare(ReconcileAmbiguousSubmissionCommand $command): ?AmbiguousSubmissionIdentity;

    /** Applies the outcome with the same token/fence and never creates a retry. */
    public function record(
        ReconcileAmbiguousSubmissionCommand $command,
        RecoveryOutcome $outcome,
    ): void;
}
