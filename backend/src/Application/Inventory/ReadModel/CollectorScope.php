<?php

declare(strict_types=1);

namespace App\Application\Inventory\ReadModel;

final readonly class CollectorScope
{
    public function __construct(
        public string $runId,
        public string $scopeType,
        public string $scopeKey,
        public string $status,
        public string $observedAt,
        public ?string $errorCode = null,
    ) {}

    /** @return array<string, ?string> */
    public function toArray(): array
    {
        return [
            'runId' => $this->runId,
            'scopeType' => $this->scopeType,
            'scopeKey' => $this->scopeKey,
            'status' => $this->status,
            'observedAt' => $this->observedAt,
            'errorCode' => $this->errorCode,
        ];
    }
}
