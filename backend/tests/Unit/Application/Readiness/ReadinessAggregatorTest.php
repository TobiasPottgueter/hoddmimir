<?php

declare(strict_types=1);

namespace App\Tests\Unit\Application\Readiness;

use App\Application\Readiness\ReadinessAggregator;
use App\Application\Readiness\ReadinessCheck;
use App\Application\Readiness\ReadinessCheckResult;
use App\Tests\Fakes\FixedReadinessCheck;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class ReadinessAggregatorTest extends TestCase
{
    public function testItAggregatesReadyAndUnavailableChecks(): void
    {
        $report = (new ReadinessAggregator([
            new FixedReadinessCheck(ReadinessCheckResult::ready('database_schema')),
            new FixedReadinessCheck(ReadinessCheckResult::unavailable('encryption_keyring', 'keyring_invalid')),
        ]))->assess();

        self::assertFalse($report->isReady());
        self::assertSame([
            'database_schema' => ['status' => 'ready'],
            'encryption_keyring' => ['status' => 'unavailable', 'reason' => 'keyring_invalid'],
        ], $report->checks());
    }

    public function testAnEmptyAggregatorFailsClosed(): void
    {
        $report = (new ReadinessAggregator([]))->assess();

        self::assertFalse($report->isReady());
        self::assertSame([
            'readiness_configuration' => [
                'status' => 'unavailable',
                'reason' => 'check_registration_invalid',
            ],
        ], $report->checks());
    }

    public function testThrownChecksFailClosedWithoutExposingTheException(): void
    {
        $check = new class implements ReadinessCheck {
            public function name(): string
            {
                return 'failing_check';
            }

            public function check(): ReadinessCheckResult
            {
                throw new RuntimeException('sensitive external detail');
            }
        };

        self::assertSame(
            ['failing_check' => ['status' => 'unavailable', 'reason' => 'check_failed']],
            (new ReadinessAggregator([$check]))->assess()->checks(),
        );
    }

    public function testMismatchedResultNamesFailClosed(): void
    {
        $check = new class implements ReadinessCheck {
            public function name(): string
            {
                return 'expected_name';
            }

            public function check(): ReadinessCheckResult
            {
                return ReadinessCheckResult::ready('another_name');
            }
        };

        self::assertSame([
            'readiness_configuration' => [
                'status' => 'unavailable',
                'reason' => 'check_registration_invalid',
            ],
        ], (new ReadinessAggregator([$check]))->assess()->checks());
    }

    public function testDuplicateNamesFailClosed(): void
    {
        $report = (new ReadinessAggregator([
            new FixedReadinessCheck(ReadinessCheckResult::ready('same_name')),
            new FixedReadinessCheck(ReadinessCheckResult::ready('same_name')),
        ]))->assess();

        self::assertFalse($report->isReady());
        self::assertSame([
            'same_name' => ['status' => 'ready'],
            'readiness_configuration' => [
                'status' => 'unavailable',
                'reason' => 'check_registration_invalid',
            ],
        ], $report->checks());
    }

    public function testThrowingOrInvalidNamesFailClosed(): void
    {
        $throwingName = new class implements ReadinessCheck {
            public function name(): string
            {
                throw new RuntimeException('unsafe registration detail');
            }

            public function check(): ReadinessCheckResult
            {
                return ReadinessCheckResult::ready('never_called');
            }
        };
        $invalidName = new class implements ReadinessCheck {
            public function name(): string
            {
                return 'INVALID NAME';
            }

            public function check(): ReadinessCheckResult
            {
                return ReadinessCheckResult::ready('never_called');
            }
        };

        foreach ([$throwingName, $invalidName] as $check) {
            self::assertSame([
                'readiness_configuration' => [
                    'status' => 'unavailable',
                    'reason' => 'check_registration_invalid',
                ],
            ], (new ReadinessAggregator([$check]))->assess()->checks());
        }
    }
}
