<?php

declare(strict_types=1);

namespace App\Application\Configuration\Connection\Onboarding;

use InvalidArgumentException;

final readonly class OnboardingGuidance
{
    /** @var list<OnboardingGuidanceCommand> */
    public array $commands;
    /** @var list<string> */
    public array $warnings;

    /**
     * @param list<OnboardingGuidanceCommand> $commands
     * @param list<string>                    $warnings
     */
    public function __construct(public OnboardingProduct $product, array $commands, array $warnings)
    {
        if ([] === $commands) {
            throw new InvalidArgumentException('Onboarding guidance must contain commands.');
        }
        $ids = [];
        foreach ($commands as $command) {
            if (isset($ids[$command->id])) {
                throw new InvalidArgumentException('Onboarding guidance command identifiers must be unique.');
            }
            $ids[$command->id] = true;
        }
        foreach ($warnings as $warning) {
            if ('' === $warning) {
                throw new InvalidArgumentException('The onboarding guidance warning is invalid.');
            }
            if (!OnboardingAsciiValidator::hasNormalizedBoundary($warning)) {
                throw new InvalidArgumentException('The onboarding guidance warning is invalid.');
            }
            if (strlen($warning) > 512) {
                throw new InvalidArgumentException('The onboarding guidance warning is invalid.');
            }
        }
        $this->commands = $commands;
        $this->warnings = $warnings;
    }

    /** @return array{product: string, commands: list<array{id: string, command: string, purpose: string, mutatesRemote: bool, containsSecret: false}>, warnings: list<string>} */
    public function toArray(): array
    {
        return [
            'product' => $this->product->value,
            'commands' => array_map(static fn (OnboardingGuidanceCommand $command): array => $command->toArray(), $this->commands),
            'warnings' => $this->warnings,
        ];
    }
}
