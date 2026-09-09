<?php

declare(strict_types=1);

namespace App\Application\Backup\Execution;

use DateTimeImmutable;
use InvalidArgumentException;

final readonly class ExecutorEvidenceRefreshClaim
{
    public const int MAXIMUM_ENDPOINTS = 16;

    /** @param non-empty-list<ExecutorEvidenceRefreshEndpoint> $endpoints */
    public function __construct(
        public string $connectionId,
        public int $connectionRevision,
        public int $backupCredentialRevision,
        public int $scanCredentialRevision,
        public string $leaseOwner,
        public string $leaseToken,
        public int $leaseFence,
        public DateTimeImmutable $leaseExpiresAt,
        public array $endpoints,
    ) {
        foreach ([$connectionId, $leaseOwner, $leaseToken] as $id) {
            if (16 !== \strlen($id)) {
                throw new InvalidArgumentException('Executor evidence refresh identifiers must contain 16 bytes.');
            }
        }
        /** @phpstan-ignore identical.alwaysFalse (runtime callers are not guaranteed to honour PHPDoc) */
        if ([] === $endpoints || \count($endpoints) > self::MAXIMUM_ENDPOINTS) {
            throw new InvalidArgumentException('Executor evidence refresh claim is inconsistent.');
        }
        if ($connectionRevision < 1 || $backupCredentialRevision < 1 || $scanCredentialRevision < 1 || $leaseFence < 1
            || 0 !== $leaseExpiresAt->getOffset()) {
            throw new InvalidArgumentException('Executor evidence refresh claim is inconsistent.');
        }
        $previous = null;
        $ids = [];
        foreach ($endpoints as $endpoint) {
            /** @phpstan-ignore instanceof.alwaysTrue (enforce the boundary at runtime as well) */
            if (!$endpoint instanceof ExecutorEvidenceRefreshEndpoint || isset($ids[$endpoint->id])) {
                throw new InvalidArgumentException('Executor evidence refresh claim contains invalid endpoints.');
            }
            if (null !== $previous
                && ($endpoint->priority < $previous->priority
                    || ($endpoint->priority === $previous->priority && $endpoint->id <= $previous->id))) {
                throw new InvalidArgumentException('Executor evidence refresh endpoints are not stably ordered.');
            }
            $ids[$endpoint->id] = true;
            $previous = $endpoint;
        }
    }
}
