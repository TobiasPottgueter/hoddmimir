<?php

declare(strict_types=1);

namespace App\Application\Readiness;

use Throwable;

final readonly class ReadinessAggregator
{
    /** @var list<ReadinessCheck> */
    private array $checks;

    /** @param iterable<ReadinessCheck> $checks */
    public function __construct(iterable $checks)
    {
        $normalizedChecks = [];
        foreach ($checks as $check) {
            $normalizedChecks[] = $check;
        }
        $this->checks = $normalizedChecks;
    }

    public function assess(): ReadinessReport
    {
        $results = [] === $this->checks
            ? [
                'readiness_configuration' => ReadinessCheckResult::unavailable(
                    'readiness_configuration',
                    'check_registration_invalid',
                ),
            ]
            : [];
        foreach ($this->checks as $check) {
            try {
                $name = $check->name();
                ReadinessCheckResult::ready($name);
            } catch (Throwable) {
                $results['readiness_configuration'] = ReadinessCheckResult::unavailable(
                    'readiness_configuration',
                    'check_registration_invalid',
                );
                continue;
            }

            if (isset($results[$name])) {
                $results['readiness_configuration'] = ReadinessCheckResult::unavailable(
                    'readiness_configuration',
                    'check_registration_invalid',
                );
                continue;
            }

            try {
                $result = $check->check();
                if ($result->name !== $name) {
                    $results['readiness_configuration'] = ReadinessCheckResult::unavailable(
                        'readiness_configuration',
                        'check_registration_invalid',
                    );
                    continue;
                }
                $results[$name] = $result;
            } catch (Throwable) {
                $results[$name] = ReadinessCheckResult::unavailable($name, 'check_failed');
            }
        }

        return new ReadinessReport($results);
    }
}
