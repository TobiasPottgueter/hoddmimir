<?php

declare(strict_types=1);

namespace App\Application\Configuration\Connection\Onboarding;

use InvalidArgumentException;

final readonly class OnboardingGuidanceCommand
{
    public function __construct(
        public string $id,
        public string $command,
        public string $purpose,
        public bool $mutatesRemote,
    ) {
        if (!OnboardingAsciiValidator::isGuidanceId($id)) {
            throw new InvalidArgumentException('The onboarding guidance command is invalid.');
        }
        if ('' === $command) {
            throw new InvalidArgumentException('The onboarding guidance command is invalid.');
        }
        if (OnboardingAsciiValidator::containsNewline($command)) {
            throw new InvalidArgumentException('The onboarding guidance command is invalid.');
        }
        if (strlen($command) > 1024) {
            throw new InvalidArgumentException('The onboarding guidance command is invalid.');
        }
        if ('' === $purpose) {
            throw new InvalidArgumentException('The onboarding guidance command is invalid.');
        }
        if (!OnboardingAsciiValidator::hasNormalizedBoundary($purpose)) {
            throw new InvalidArgumentException('The onboarding guidance command is invalid.');
        }
        if (strlen($purpose) > 255) {
            throw new InvalidArgumentException('The onboarding guidance command is invalid.');
        }
    }

    /** @return array{id: string, command: string, purpose: string, mutatesRemote: bool, containsSecret: false} */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'command' => $this->command,
            'purpose' => $this->purpose,
            'mutatesRemote' => $this->mutatesRemote,
            'containsSecret' => false,
        ];
    }
}
