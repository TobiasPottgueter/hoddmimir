<?php

declare(strict_types=1);

namespace App\Application\Backup\Execution;

use DateTimeImmutable;

interface ExecutorEvidenceRefreshStore
{
    /** @throws ExecutorEvidenceLeaseOwnershipLost */
    public function claimDue(string $workerId, DateTimeImmutable $now): ?ExecutorEvidenceRefreshClaim;

    /** @throws ExecutorEvidenceLeaseOwnershipLost */
    public function renew(ExecutorEvidenceRefreshClaim $claim, DateTimeImmutable $now): void;

    /** @throws ExecutorEvidenceLeaseOwnershipLost */
    public function bindSnapshotEndpoint(
        ExecutorEvidenceRefreshClaim $claim,
        string $endpointId,
        DateTimeImmutable $observedAt,
    ): void;

    /** @return list<ExecutorEvidenceRefreshSubject>
     *  @throws ExecutorEvidenceLeaseOwnershipLost
     */
    public function subjects(ExecutorEvidenceRefreshClaim $claim, ?string $afterSubjectKey, int $limit): array;

    /** @param list<ExecutorPermissionProjection> $projections
     *  @throws ExecutorEvidenceLeaseOwnershipLost
     */
    public function stage(ExecutorEvidenceRefreshClaim $claim, array $projections): void;

    /** @throws ExecutorEvidenceLeaseOwnershipLost */
    public function publish(ExecutorEvidenceRefreshClaim $claim, DateTimeImmutable $observedAt): void;

    /** @throws ExecutorEvidenceLeaseOwnershipLost */
    public function fail(ExecutorEvidenceRefreshClaim $claim, ExecutorEvidenceRefreshFailureCode $code, DateTimeImmutable $now): void;
}
