<?php

declare(strict_types=1);

namespace App\Application\Readiness;

final readonly class ReadinessReport
{
    /** @param array<string, ReadinessCheckResult> $results */
    public function __construct(public array $results)
    {
    }

    public function isReady(): bool
    {
        foreach ($this->results as $result) {
            if (!$result->ready) {
                return false;
            }
        }

        return true;
    }

    /** @return array<string, array{status: 'ready'}|array{status: 'unavailable', reason: string}> */
    public function checks(): array
    {
        $checks = [];
        foreach ($this->results as $name => $result) {
            $checks[$name] = $result->toArray();
        }

        return $checks;
    }
}
