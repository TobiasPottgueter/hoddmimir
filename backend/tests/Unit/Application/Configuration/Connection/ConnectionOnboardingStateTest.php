<?php

declare(strict_types=1);

namespace App\Tests\Unit\Application\Configuration\Connection;

use App\Application\Configuration\Connection\ConnectionOnboardingState;
use App\Application\Configuration\Connection\ConnectionOnboardingStatus;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ConnectionOnboardingStateTest extends TestCase
{
    #[DataProvider('statuses')]
    public function testClosedStatusesProduceCanonicalSecretFreeReadModels(ConnectionOnboardingStatus $status): void
    {
        $state = new ConnectionOnboardingState(
            $status,
            '2026-07-13T10:11:12.123456Z',
            '2026-07-13T10:13:14.654321Z',
            '00000000-0000-4000-8000-000000000001',
        );

        self::assertSame([
            'status' => $status->value,
            'verifiedAt' => '2026-07-13T10:11:12.123456Z',
            'inventoryStatusChangedAt' => '2026-07-13T10:13:14.654321Z',
            'lastInventoryRunId' => '00000000-0000-4000-8000-000000000001',
        ], $state->toArray());
    }

    /** @return iterable<string, array{ConnectionOnboardingStatus}> */
    public static function statuses(): iterable
    {
        foreach (ConnectionOnboardingStatus::cases() as $status) {
            yield $status->value => [$status];
        }
    }

    #[DataProvider('invalidStates')]
    public function testInvalidTimestampsAndRunIdentifiersFailClosed(string $verified, string $changed, ?string $run): void
    {
        $this->expectException(InvalidArgumentException::class);
        new ConnectionOnboardingState(ConnectionOnboardingStatus::InventoryFailed, $verified, $changed, $run);
    }

    /** @return iterable<string, array{string, string, ?string}> */
    public static function invalidStates(): iterable
    {
        $valid = '2026-07-13T10:11:12.123456Z';
        yield 'verified timestamp' => ['2026-07-13 10:11:12', $valid, null];
        yield 'short verified timestamp' => ['2026-07-13T10:11:12Z', $valid, null];
        yield 'invalid separator' => ['2026/07-13T10:11:12.123456Z', $valid, null];
        yield 'non-digit' => ['202X-07-13T10:11:12.123456Z', $valid, null];
        yield 'month low' => ['2026-00-13T10:11:12.123456Z', $valid, null];
        yield 'month high' => ['2026-13-13T10:11:12.123456Z', $valid, null];
        yield 'day low' => ['2026-07-00T10:11:12.123456Z', $valid, null];
        yield 'day high' => ['2026-07-32T10:11:12.123456Z', $valid, null];
        yield 'hour high' => ['2026-07-13T24:11:12.123456Z', $valid, null];
        yield 'minute high' => ['2026-07-13T10:60:12.123456Z', $valid, null];
        yield 'second high' => ['2026-07-13T10:11:60.123456Z', $valid, null];
        yield 'changed timestamp' => [$valid, '2026-07-13T10:11:12Z', null];
        yield 'run identifier' => [$valid, $valid, 'NOT-A-UUID'];
    }

    public function testAbsentInventoryRunIsExplicitlyNull(): void
    {
        $state = new ConnectionOnboardingState(
            ConnectionOnboardingStatus::FirstAutomaticScanPending,
            '2026-07-13T10:11:12.123456Z',
            '2026-07-13T10:11:12.123456Z',
            null,
        );

        self::assertNull($state->toArray()['lastInventoryRunId']);
    }
}
