<?php

declare(strict_types=1);

namespace App\Domain\Backup;

use InvalidArgumentException;

final readonly class BackupRun
{
    private function __construct(
        public BackupRunId $id,
        public BackupRequestId $requestId,
        public ClaimAuthority $authority,
        public BackupRunState $state,
        public ?TaskUpid $upid,
        public SubmissionProvenance $submissionProvenance,
    ) {
        $requiresUpid = isset([
            'running' => true,
            'cancel_requested' => true,
            'succeeded' => true,
            'cancelled' => true,
        ][$state->value]);
        if ($requiresUpid && null === $upid) {
            throw new InvalidArgumentException('An accepted backup run must retain exactly one UPID.');
        }
        if (!$requiresUpid && BackupRunState::Failed !== $state && null !== $upid) {
            throw new InvalidArgumentException('A backup run cannot invent a UPID.');
        }
        if ((SubmissionProvenance::Accepted === $submissionProvenance && null === $upid)
            || (isset([
                'not_submitted' => true,
                'definitive_rejection' => true,
            ][$submissionProvenance->value]) && null !== $upid)
        ) {
            throw new InvalidArgumentException('The backup-run submission provenance is inconsistent.');
        }
        if (BackupRunState::AwaitingSubmission === $state
            && SubmissionProvenance::NotSubmitted !== $submissionProvenance
        ) {
            throw new InvalidArgumentException('An awaiting backup run cannot have submission provenance.');
        }
        if (BackupRunState::AwaitingSubmission !== $state
            && SubmissionProvenance::NotSubmitted === $submissionProvenance
        ) {
            throw new InvalidArgumentException('A transitioned backup run must retain submission provenance.');
        }
    }

    public static function awaitingSubmission(
        BackupRunId $id,
        BackupRequestId $requestId,
        ClaimAuthority $authority,
    ): self {
        return new self(
            $id,
            $requestId,
            $authority,
            BackupRunState::AwaitingSubmission,
            null,
            SubmissionProvenance::NotSubmitted,
        );
    }

    public static function restore(
        BackupRunId $id,
        BackupRequestId $requestId,
        ClaimAuthority $authority,
        BackupRunState $state,
        ?TaskUpid $upid,
        SubmissionProvenance $submissionProvenance,
    ): self {
        return new self($id, $requestId, $authority, $state, $upid, $submissionProvenance);
    }

    public function applySubmission(ClaimAuthority $authority, SubmissionOutcome $outcome): self
    {
        $this->assertAuthority($authority);
        $this->assertState(BackupRunState::AwaitingSubmission);

        if (SubmissionOutcomeKind::Accepted === $outcome->kind) {
            return new self(
                $this->id,
                $this->requestId,
                $this->authority,
                BackupRunState::Running,
                $outcome->upid,
                SubmissionProvenance::Accepted,
            );
        }
        if (SubmissionOutcomeKind::Rejected === $outcome->kind) {
            return new self(
                $this->id,
                $this->requestId,
                $this->authority,
                BackupRunState::Failed,
                null,
                SubmissionProvenance::DefinitiveRejection,
            );
        }

        return new self(
            $this->id,
            $this->requestId,
            $this->authority,
            BackupRunState::ReconcileRequired,
            null,
            SubmissionProvenance::Ambiguous,
        );
    }

    public function reconcile(ClaimAuthority $authority, RecoveryOutcome $outcome): self
    {
        $this->assertAuthority($authority);
        $this->assertState(BackupRunState::ReconcileRequired);

        if (RecoveryOutcomeKind::Matched === $outcome->kind) {
            return new self(
                $this->id,
                $this->requestId,
                $this->authority,
                BackupRunState::Running,
                $outcome->upid,
                SubmissionProvenance::Ambiguous,
            );
        }
        return new self(
            $this->id,
            $this->requestId,
            $this->authority,
            BackupRunState::Unknown,
            null,
            SubmissionProvenance::Ambiguous,
        );
    }

    public function requestCancel(ClaimAuthority $authority): self
    {
        $this->assertAuthority($authority);
        $this->assertState(BackupRunState::Running);

        return new self(
            $this->id,
            $this->requestId,
            $this->authority,
            BackupRunState::CancelRequested,
            $this->upid,
            $this->submissionProvenance,
        );
    }

    public function observe(ClaimAuthority $authority, MonitoringOutcome $outcome): self
    {
        $this->assertAuthority($authority);
        if (!isset(['running' => true, 'cancel_requested' => true][$this->state->value])) {
            throw new InvalidArgumentException('Only an accepted backup run can be monitored.');
        }
        if (isset(['running' => true, 'temporarily_unavailable' => true][$outcome->value])) {
            return $this;
        }

        if (MonitoringOutcome::Succeeded === $outcome) {
            $state = BackupRunState::Succeeded;
        } elseif (MonitoringOutcome::Failed === $outcome) {
            $state = BackupRunState::Failed;
        } else {
            $state = BackupRunState::Cancelled;
        }

        return new self(
            $this->id,
            $this->requestId,
            $this->authority,
            $state,
            $this->upid,
            $this->submissionProvenance,
        );
    }

    public function takeover(ClaimAuthority $authority): self
    {
        if ($this->state->isTerminal() || !$authority->fence->isAfter($this->authority->fence)) {
            throw new InvalidArgumentException('A backup run can only be taken over with a newer fence.');
        }

        return new self(
            $this->id,
            $this->requestId,
            $authority,
            $this->state,
            $this->upid,
            $this->submissionProvenance,
        );
    }

    private function assertAuthority(ClaimAuthority $authority): void
    {
        if (!$this->authority->equals($authority)) {
            throw new InvalidArgumentException('A stale worker cannot mutate a backup run.');
        }
    }

    private function assertState(BackupRunState $expected): void
    {
        if ($expected !== $this->state) {
            throw new InvalidArgumentException('The backup-run transition is not allowed.');
        }
    }
}
