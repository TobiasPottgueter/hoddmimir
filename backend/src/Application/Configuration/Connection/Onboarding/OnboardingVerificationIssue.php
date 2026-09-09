<?php

declare(strict_types=1);

namespace App\Application\Configuration\Connection\Onboarding;

use InvalidArgumentException;

final readonly class OnboardingVerificationIssue
{
    public function __construct(
        public OnboardingIssueCode $code,
        public OnboardingIssueSeverity $severity,
        public ?OnboardingCredentialKind $credential = null,
        public ?string $path = null,
        public ?string $privilege = null,
    ) {
        if ((null === $path) !== (null === $privilege)) {
            throw new InvalidArgumentException('The onboarding verification issue is inconsistent.');
        }
    }

    /** @return array{code: string, severity: string, credential: ?string, path: ?string, privilege: ?string} */
    public function toArray(): array
    {
        return [
            'code' => $this->code->value,
            'severity' => $this->severity->value,
            'credential' => $this->credential?->value,
            'path' => $this->path,
            'privilege' => $this->privilege,
        ];
    }
}
