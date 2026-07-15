<?php

declare(strict_types=1);

namespace App\Application\Scheduler\Shadow;

use InvalidArgumentException;

final readonly class AutomaticShadowPromotion
{
    public function __construct(
        public string $requestId,
        public string $decisionId,
        public string $resolvedPolicyJson,
        private string $resolvedPolicyHash,
    ) {
        if (16 !== strlen($requestId) || 16 !== strlen($decisionId)) {
            throw new InvalidArgumentException('Automatic promotion IDs must contain 16 bytes.');
        }
        if (!json_validate($resolvedPolicyJson)
            || 32 !== strlen($resolvedPolicyHash)
            || !hash_equals(hash('sha256', $resolvedPolicyJson, true), $resolvedPolicyHash)) {
            throw new InvalidArgumentException('Automatic promotion policy evidence is invalid.');
        }
    }

    public function resolvedPolicyHash(): string
    {
        return $this->resolvedPolicyHash;
    }

    public function resolvedPolicyHashHex(): string
    {
        return bin2hex($this->resolvedPolicyHash);
    }
}
