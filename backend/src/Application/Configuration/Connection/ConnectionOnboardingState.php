<?php

declare(strict_types=1);

namespace App\Application\Configuration\Connection;

use App\Application\Configuration\Connection\Onboarding\OnboardingAsciiValidator;
use InvalidArgumentException;

final readonly class ConnectionOnboardingState
{
    public function __construct(
        public ConnectionOnboardingStatus $status,
        public string $verifiedAt,
        public string $inventoryStatusChangedAt,
        public ?string $lastInventoryRunId,
    ) {
        if (!$this->isUtc($verifiedAt) || !$this->isUtc($inventoryStatusChangedAt)
            || (null !== $lastInventoryRunId && !$this->isUuid($lastInventoryRunId))) {
            throw new InvalidArgumentException('The connection onboarding state is invalid.');
        }
    }

    /** @return array{status: string, verifiedAt: string, inventoryStatusChangedAt: string, lastInventoryRunId: ?string} */
    public function toArray(): array
    {
        return [
            'status' => $this->status->value,
            'verifiedAt' => $this->verifiedAt,
            'inventoryStatusChangedAt' => $this->inventoryStatusChangedAt,
            'lastInventoryRunId' => $this->lastInventoryRunId,
        ];
    }

    private function isUtc(string $value): bool
    {
        return OnboardingAsciiValidator::isUtcTimestamp($value);
    }

    private function isUuid(string $value): bool
    {
        return OnboardingAsciiValidator::isLowerUuid($value);
    }
}
