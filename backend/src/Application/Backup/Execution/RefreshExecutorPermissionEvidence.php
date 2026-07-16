<?php

declare(strict_types=1);

namespace App\Application\Backup\Execution;

use App\Domain\Shared\Clock;
use InvalidArgumentException;

final readonly class RefreshExecutorPermissionEvidence implements ExecutorEvidenceRefresh
{
    public function __construct(
        private ExecutorEvidenceRefreshStore $store,
        private ExecutorEvidenceRefreshSource $source,
        private ProjectExecutorPermissionEvidence $projector,
        private Clock $clock,
        private int $subjectPageSize = 256,
        private int $maximumSubjects = 65_536,
    ) {
        if ($subjectPageSize < 1 || $subjectPageSize > 1_024
            || $maximumSubjects < $subjectPageSize || $maximumSubjects > 262_144) {
            throw new InvalidArgumentException('Executor evidence subject page size is invalid.');
        }
    }

    public function refreshDue(string $workerId): ExecutorEvidenceRefreshStatus
    {
        if (16 !== \strlen($workerId)) {
            throw new InvalidArgumentException('Executor evidence worker identifier must contain 16 bytes.');
        }
        $claim = $this->store->claimDue($workerId, $this->clock->now());
        if (null === $claim) {
            return ExecutorEvidenceRefreshStatus::NoDueConnection;
        }

        /** @var null|array{PveExecutorPermissionSnapshot, \DateTimeImmutable} $accepted */
        $accepted = null;
        $lastFailure = ExecutorEvidenceRefreshFailureCode::InvalidResponse;
        foreach ($claim->endpoints as $endpoint) {
            $this->store->renew($claim, $this->clock->now());
            try {
                $candidate = $this->source->read($claim, $endpoint);
            } catch (ExecutorEvidenceRefreshFailure $failure) {
                $lastFailure = $failure->failureCode;
                if ($this->mayFailOver($lastFailure)) {
                    continue;
                }
                break;
            }
            if (!$this->matchesClaim($candidate, $claim, $endpoint)) {
                $lastFailure = ExecutorEvidenceRefreshFailureCode::ConfigurationChanged;
                break;
            }
            $accepted = [$candidate, $this->clock->now()];
            $this->store->renew($claim, $accepted[1]);
            break;
        }

        if (null === $accepted) {
            $this->store->fail($claim, $lastFailure, $this->clock->now());
            return ExecutorEvidenceRefreshStatus::Failed;
        }

        [$snapshot, $observedAt] = $accepted;
        $this->store->bindSnapshotEndpoint($claim, $snapshot->endpointId, $observedAt);
        $cursor = null;
        $subjectCount = 0;
        while (true) {
            $this->store->renew($claim, $this->clock->now());
            $subjects = $this->store->subjects($claim, $cursor, $this->subjectPageSize);
            if ([] === $subjects) {
                break;
            }
            if (\count($subjects) > $this->subjectPageSize) {
                return $this->invalidPage($claim);
            }
            $subjectCount += \count($subjects);
            if ($subjectCount > $this->maximumSubjects) {
                return $this->invalidPage($claim);
            }

            $projections = [];
            foreach ($subjects as $subject) {
                /** @phpstan-ignore instanceof.alwaysTrue (store adapters are a runtime trust boundary) */
                if (!$subject instanceof ExecutorEvidenceRefreshSubject
                    || $subject->connectionId !== $claim->connectionId
                    || (null !== $cursor && $subject->cursor() <= $cursor)) {
                    return $this->invalidPage($claim);
                }
                $projections[] = $this->projector->project($snapshot, $subject);
                $cursor = $subject->cursor();
            }
            $this->store->stage($claim, $projections);
        }

        $this->store->publish($claim, $observedAt);
        return ExecutorEvidenceRefreshStatus::Published;
    }

    private function mayFailOver(ExecutorEvidenceRefreshFailureCode $code): bool
    {
        return \in_array($code, [
            ExecutorEvidenceRefreshFailureCode::Tls,
            ExecutorEvidenceRefreshFailureCode::Transport,
            ExecutorEvidenceRefreshFailureCode::RemoteUnavailable,
            ExecutorEvidenceRefreshFailureCode::InvalidResponse,
        ], true);
    }

    private function matchesClaim(
        PveExecutorPermissionSnapshot $snapshot,
        ExecutorEvidenceRefreshClaim $claim,
        ExecutorEvidenceRefreshEndpoint $endpoint,
    ): bool {
        return $snapshot->connectionId === $claim->connectionId
            && $snapshot->endpointId === $endpoint->id
            && $snapshot->connectionRevision === $claim->connectionRevision
            && $snapshot->backupCredentialRevision === $claim->backupCredentialRevision
            && $snapshot->scanCredentialRevision === $claim->scanCredentialRevision;
    }

    private function invalidPage(ExecutorEvidenceRefreshClaim $claim): ExecutorEvidenceRefreshStatus
    {
        $this->store->fail($claim, ExecutorEvidenceRefreshFailureCode::InvalidResponse, $this->clock->now());
        return ExecutorEvidenceRefreshStatus::Failed;
    }
}
