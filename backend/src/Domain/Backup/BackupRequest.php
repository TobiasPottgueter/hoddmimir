<?php

declare(strict_types=1);

namespace App\Domain\Backup;

use App\Domain\Scheduler\ReasonPriority;
use App\Domain\Scheduler\RequestOrigin;
use DateTimeImmutable;
use InvalidArgumentException;
use OverflowException;

final readonly class BackupRequest
{
    private function __construct(
        public BackupRequestId $id,
        public BackupRequestId $rootRequestId,
        public int $attempt,
        public RequestOrigin $origin,
        public ReasonPriority $reasonPriority,
        public BackupRequestState $state,
        public ?ClaimLease $lease,
        public ?ClaimFence $lastFence,
        public ?BackupRunId $runId,
        public RetryDisposition $retryDisposition,
    ) {
        if ($attempt < 1) {
            throw new InvalidArgumentException('A backup-request attempt must be positive.');
        }
        $claimed = isset([
            'leased' => true,
            'starting' => true,
            'running' => true,
            'reconcile_required' => true,
        ][$state->value]);
        if ($claimed !== (null !== $lease)) {
            throw new InvalidArgumentException('The backup-request lease does not match its state.');
        }
        $requiresRun = isset([
            'starting' => true,
            'running' => true,
            'reconcile_required' => true,
            'succeeded' => true,
            'failed' => true,
            'unknown' => true,
        ][$state->value]);
        $forbidsRun = isset([
            'pending' => true,
            'leased' => true,
            'retry_wait' => true,
        ][$state->value]);
        if (($requiresRun && null === $runId) || ($forbidsRun && null !== $runId)) {
            throw new InvalidArgumentException('The backup-request run does not match its state.');
        }
        if (null !== $lease) {
            if (null === $lastFence || $lease->authority->fence->value !== $lastFence->value) {
                throw new InvalidArgumentException('The backup-request fence is inconsistent.');
            }
        }
    }

    public static function initial(
        BackupRequestId $id,
        RequestOrigin $origin,
        ReasonPriority $reasonPriority,
    ): self {
        if (RequestOrigin::Retry === $origin) {
            throw new InvalidArgumentException('A retry must be created from its original request.');
        }

        return new self(
            $id,
            $id,
            1,
            $origin,
            $reasonPriority,
            BackupRequestState::Pending,
            null,
            null,
            null,
            RetryDisposition::NotApplicable,
        );
    }

    public static function restore(
        BackupRequestId $id,
        BackupRequestId $rootRequestId,
        int $attempt,
        RequestOrigin $origin,
        ReasonPriority $reasonPriority,
        BackupRequestState $state,
        ?ClaimLease $lease,
        ?ClaimFence $lastFence,
        ?BackupRunId $runId,
        RetryDisposition $retryDisposition,
    ): self {
        return new self(
            $id,
            $rootRequestId,
            $attempt,
            $origin,
            $reasonPriority,
            $state,
            $lease,
            $lastFence,
            $runId,
            $retryDisposition,
        );
    }

    public static function controlledRetry(BackupRequestId $id, self $original): self
    {
        if (BackupRequestState::Failed !== $original->state
            || RetryDisposition::ControlledAllowed !== $original->retryDisposition
            || $id->equals($original->id)
        ) {
            throw new InvalidArgumentException('A controlled retry requires a failed request and a new ID.');
        }
        if (PHP_INT_MAX === $original->attempt) {
            throw new OverflowException('A backup-request attempt cannot exceed the platform integer range.');
        }

        return new self(
            $id,
            $original->rootRequestId,
            $original->attempt + 1,
            RequestOrigin::Retry,
            $original->reasonPriority,
            BackupRequestState::Pending,
            null,
            null,
            null,
            RetryDisposition::NotApplicable,
        );
    }

    public function claim(ClaimLease $lease, DateTimeImmutable $now): self
    {
        if (!isset(['pending' => true, 'retry_wait' => true][$this->state->value])
            || null !== $this->lease || !$lease->isActiveAt($now)
            || !$lease->authority->fence->isAfter($this->lastFence)
        ) {
            throw new InvalidArgumentException('The backup request cannot be claimed.');
        }

        return $this->copy(
            state: BackupRequestState::Leased,
            lease: $lease,
            lastFence: $lease->authority->fence,
        );
    }

    public function renew(
        ClaimAuthority $authority,
        DateTimeImmutable $now,
        DateTimeImmutable $newExpiry,
    ): self {
        $lease = $this->currentLease();

        return $this->copy(lease: $lease->renew($authority, $now, $newExpiry));
    }

    public function release(ClaimAuthority $authority, DateTimeImmutable $now): self
    {
        $this->assertLeasedBeforeSubmission($authority, $now);

        return $this->copy(state: BackupRequestState::Pending, lease: null);
    }

    public function defer(ClaimAuthority $authority, DateTimeImmutable $now): self
    {
        $this->assertLeasedBeforeSubmission($authority, $now);

        return $this->copy(state: BackupRequestState::RetryWait, lease: null);
    }

    public function beginRun(
        ClaimAuthority $authority,
        DateTimeImmutable $now,
        BackupRunId $runId,
    ): self {
        $this->assertLeasedBeforeSubmission($authority, $now);

        return $this->copy(state: BackupRequestState::Starting, runId: $runId);
    }

    public function synchronizeRun(
        ClaimAuthority $authority,
        DateTimeImmutable $now,
        BackupRun $run,
    ): self {
        $this->currentLease()->assertCurrent($authority, $now);
        if (null === $this->runId || !$this->runId->equals($run->id)
            || !$this->id->equals($run->requestId) || !$run->authority->equals($authority)
        ) {
            throw new InvalidArgumentException('The backup run does not belong to this claim.');
        }
        if (BackupRunState::AwaitingSubmission === $run->state) {
            $state = BackupRequestState::Starting;
        } elseif (BackupRunState::ReconcileRequired === $run->state) {
            $state = BackupRequestState::ReconcileRequired;
        } elseif (isset(['running' => true, 'cancel_requested' => true][$run->state->value])) {
            $state = BackupRequestState::Running;
        } elseif (BackupRunState::Succeeded === $run->state) {
            $state = BackupRequestState::Succeeded;
        } elseif (BackupRunState::Failed === $run->state) {
            $state = BackupRequestState::Failed;
        } elseif (BackupRunState::Cancelled === $run->state) {
            $state = BackupRequestState::Cancelled;
        } else {
            $state = BackupRequestState::Unknown;
        }

        if (SubmissionProvenance::Ambiguous === $run->submissionProvenance) {
            $retryDisposition = RetryDisposition::ForbiddenAmbiguous;
        } elseif (isset([
            'accepted' => true,
            'definitive_rejection' => true,
        ][$run->submissionProvenance->value])) {
            $retryDisposition = RetryDisposition::ControlledAllowed;
        } else {
            $retryDisposition = RetryDisposition::NotApplicable;
        }

        return $this->copy(
            state: $state,
            lease: $state->isTerminal() ? null : $this->lease,
            retryDisposition: $retryDisposition,
        );
    }

    public function takeover(ClaimLease $lease, DateTimeImmutable $now): self
    {
        $current = $this->currentLease();
        if ($this->state->isTerminal() || $current->isActiveAt($now) || !$lease->isActiveAt($now)
            || !$lease->authority->fence->isAfter($this->lastFence)
        ) {
            throw new InvalidArgumentException('The backup request cannot be taken over.');
        }

        return $this->copy(lease: $lease, lastFence: $lease->authority->fence);
    }

    public function cancelUnclaimed(): self
    {
        if (!isset(['pending' => true, 'retry_wait' => true][$this->state->value])) {
            throw new InvalidArgumentException('Only an unclaimed backup request can be cancelled directly.');
        }

        return $this->copy(state: BackupRequestState::Cancelled);
    }

    public function cancelLeased(ClaimAuthority $authority, DateTimeImmutable $now): self
    {
        $this->assertLeasedBeforeSubmission($authority, $now);

        return $this->copy(state: BackupRequestState::Cancelled, lease: null);
    }

    private function assertLeasedBeforeSubmission(ClaimAuthority $authority, DateTimeImmutable $now): void
    {
        if (BackupRequestState::Leased !== $this->state) {
            throw new InvalidArgumentException('The backup request is not leased before submission.');
        }
        $this->currentLease()->assertCurrent($authority, $now);
    }

    private function currentLease(): ClaimLease
    {
        return $this->lease ?? throw new InvalidArgumentException('The backup request has no active lease.');
    }

    private function copy(
        ?BackupRequestState $state = null,
        ClaimLease|null|false $lease = false,
        ClaimFence|null|false $lastFence = false,
        BackupRunId|null|false $runId = false,
        ?RetryDisposition $retryDisposition = null,
    ): self {
        return new self(
            $this->id,
            $this->rootRequestId,
            $this->attempt,
            $this->origin,
            $this->reasonPriority,
            $state ?? $this->state,
            false === $lease ? $this->lease : $lease,
            false === $lastFence ? $this->lastFence : $lastFence,
            false === $runId ? $this->runId : $runId,
            $retryDisposition ?? $this->retryDisposition,
        );
    }
}
