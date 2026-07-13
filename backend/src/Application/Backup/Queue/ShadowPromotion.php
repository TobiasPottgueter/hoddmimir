<?php

declare(strict_types=1);

namespace App\Application\Backup\Queue;

use DateTimeImmutable;

final readonly class ShadowPromotion
{
    public function __construct(
        public string $requestId,
        public string $shadowDecisionId,
        public DateTimeImmutable $scheduledAt,
        public string $resolvedPolicyJson,
        public string $resolvedPolicyHash,
    ) {
        foreach ([$requestId, $shadowDecisionId] as $id) {
            if (16 !== strlen($id)) {
                throw new \InvalidArgumentException('Promotion IDs must contain 16 bytes.');
            }
        }
        if (0 !== $scheduledAt->getOffset() || 32 !== strlen($resolvedPolicyHash)
            || !json_validate($resolvedPolicyJson)
            || !hash_equals(hash('sha256', $resolvedPolicyJson, true), $resolvedPolicyHash)) {
            throw new \InvalidArgumentException('Resolved policy snapshot is invalid.');
        }
    }
}
