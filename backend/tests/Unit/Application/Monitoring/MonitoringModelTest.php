<?php

declare(strict_types=1);

namespace App\Tests\Unit\Application\Monitoring;

use App\Application\Inventory\Connection\EndpointId;
use App\Application\Inventory\Connection\InstallationBinding;
use App\Application\Inventory\Connection\ProxmoxProduct;
use App\Application\Inventory\InventoryIdentifier;
use App\Application\Monitoring\MonitoringApplyResult;
use App\Application\Monitoring\MonitoringCommit;
use App\Application\Monitoring\MonitoringConflict;
use App\Application\Monitoring\MonitoringRunFailure;
use App\Application\Monitoring\MonitoringRunKind;
use App\Application\Monitoring\MonitoringRunStart;
use App\Application\Monitoring\MonitoringRunStatus;
use App\Application\Monitoring\MonitoringScopeResult;
use App\Application\Monitoring\MonitoringScopeStatus;
use App\Application\Monitoring\MonitoringScopeType;
use App\Application\Monitoring\MonitoringSourceKind;
use DateTimeImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class MonitoringModelTest extends TestCase
{
    public function testRunStartNormalizesUtcAndEnforcesRevisionProductAndLegacyEndpoint(): void
    {
        $start = new MonitoringRunStart(
            self::id('run'),
            self::id('parent'),
            self::id('connection'),
            self::endpoint('endpoint'),
            ProxmoxProduct::Pve,
            InstallationBinding::pveStandalone('pve-a'),
            MonitoringRunKind::ExternalJobs,
            2,
            new DateTimeImmutable('2026-07-11T23:00:00+02:00'),
        );
        self::assertSame('2026-07-11T21:00:00+00:00', $start->startedAt->format('c'));

        $this->expectException(InvalidArgumentException::class);
        new MonitoringRunStart(
            self::id('run-invalid'), self::id('parent-invalid'), self::id('connection-invalid'),
            self::endpoint('endpoint-invalid'), ProxmoxProduct::Pve,
            InstallationBinding::pveStandalone('pve-a'), MonitoringRunKind::ExternalJobs, 0,
            new DateTimeImmutable(),
        );
    }

    public function testRunStartRejectsProductAndLegacyEndpointMismatch(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new MonitoringRunStart(
            self::id('run-product'), self::id('parent-product'), self::id('connection-product'),
            self::endpoint('endpoint-product'), ProxmoxProduct::Pve,
            InstallationBinding::pbsInstance(str_repeat('a', 32)), MonitoringRunKind::ExternalJobs, 1,
            new DateTimeImmutable(),
        );
    }

    public function testLegacyRunStartRejectsAnotherSelectedEndpoint(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new MonitoringRunStart(
            self::id('run-legacy'), self::id('parent-legacy'), self::id('connection-legacy'),
            self::endpoint('selected'), ProxmoxProduct::Pbs,
            InstallationBinding::pbsLegacyNode('pbs-a', self::endpoint('bound')),
            MonitoringRunKind::ObservedTasks, 1, new DateTimeImmutable(),
        );
    }

    public function testScopeResultNormalizesUtcAndAcceptsCompleteBoundedWindow(): void
    {
        $scope = self::scope(MonitoringScopeStatus::Complete);
        self::assertSame('2026-07-11T20:00:00+00:00', $scope->windowSince?->format('c'));
        self::assertSame('2026-07-11T21:00:00+00:00', $scope->windowUntil?->format('c'));
        self::assertSame('2026-07-11T21:00:00+00:00', $scope->observedAt->format('c'));
    }

    /** @param array<string, mixed> $override */
    #[DataProvider('invalidScopeProvider')]
    public function testScopeResultRejectsInvalidContracts(array $override): void
    {
        $arguments = [
            'scope' => MonitoringScopeType::PveTasksArchive,
            'key' => 'pve-a',
            'source' => MonitoringSourceKind::Archive,
            'filter' => 'vzdump',
            'status' => MonitoringScopeStatus::Partial,
            'windowSince' => new DateTimeImmutable('2026-07-11T20:00:00Z'),
            'windowUntil' => new DateTimeImmutable('2026-07-11T21:00:00Z'),
            'pagesRead' => 1,
            'rowsRead' => 2,
            'itemsSeen' => 2,
            'truncated' => true,
            'historyGap' => false,
            'errorCode' => 'limit_reached',
            'observedAt' => new DateTimeImmutable('2026-07-11T21:00:00Z'),
        ];
        foreach ($override as $key => $value) {
            $arguments[$key] = $value;
        }
        $this->expectException(InvalidArgumentException::class);
        // @phpstan-ignore argument.type (the provider mutates a named constructor argument map intentionally)
        new MonitoringScopeResult(...$arguments);
    }

    /** @return iterable<string, array{array<string, mixed>}> */
    public static function invalidScopeProvider(): iterable
    {
        yield 'empty key' => [['key' => '']];
        yield 'long key' => [['key' => str_repeat('x', 256)]];
        yield 'empty filter' => [['filter' => '']];
        yield 'long filter' => [['filter' => str_repeat('x', 65)]];
        yield 'negative pages' => [['pagesRead' => -1]];
        yield 'one window edge missing' => [['windowUntil' => null]];
        yield 'window backwards' => [[
            'windowSince' => new DateTimeImmutable('2026-07-11T22:00:00Z'),
            'windowUntil' => new DateTimeImmutable('2026-07-11T21:00:00Z'),
        ]];
        yield 'complete truncated' => [[
            'status' => MonitoringScopeStatus::Complete,
            'truncated' => true,
            'errorCode' => null,
        ]];
        yield 'complete error' => [[
            'status' => MonitoringScopeStatus::Complete,
            'truncated' => false,
            'errorCode' => 'unexpected',
        ]];
        yield 'complete history gap' => [[
            'status' => MonitoringScopeStatus::Complete,
            'truncated' => false,
            'historyGap' => true,
            'errorCode' => null,
        ]];
        yield 'history gap without history window' => [[
            'scope' => MonitoringScopeType::PveTasksActive,
            'source' => MonitoringSourceKind::Active,
            'filter' => 'vzdump',
            'windowSince' => null,
            'windowUntil' => null,
            'historyGap' => true,
        ]];
        yield 'partial without error' => [['errorCode' => null]];
        yield 'invalid error' => [['errorCode' => 'Invalid Error']];
    }

    public function testCommitDerivesSucceededPartialAndFailedWithoutRequiringPayload(): void
    {
        self::assertSame(MonitoringRunStatus::Succeeded, $this->commit([
            self::scope(MonitoringScopeStatus::Complete),
        ])->status());
        self::assertSame(MonitoringRunStatus::Partial, $this->commit([
            self::scope(MonitoringScopeStatus::Complete),
            self::scope(MonitoringScopeStatus::Failed, 'pve-b'),
        ])->status());
        self::assertSame(MonitoringRunStatus::Failed, $this->commit([
            self::scope(MonitoringScopeStatus::Failed),
        ])->status());
    }

    public function testCommitRejectsDuplicateOrMismatchingScopesAndHeaders(): void
    {
        $scope = self::scope(MonitoringScopeStatus::Complete);
        $this->expectException(InvalidArgumentException::class);
        $this->commit([$scope, $scope]);
    }

    public function testCommitRejectsScopeProductMismatch(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->commit([new MonitoringScopeResult(
            MonitoringScopeType::PbsTasksWindow,
            'pbs-a', MonitoringSourceKind::History, 'backup', MonitoringScopeStatus::Complete,
            new DateTimeImmutable('2026-07-11T20:00:00Z'),
            new DateTimeImmutable('2026-07-11T21:00:00Z'),
            1, 1, 1, false, false, null, new DateTimeImmutable('2026-07-11T21:00:00Z'),
        )]);
    }

    public function testFailureAndApplyResultValidateInputs(): void
    {
        $failure = new MonitoringRunFailure(
            self::id('failure-run'), self::id('failure-connection'), 'read_failed',
            new DateTimeImmutable('2026-07-11T23:00:00+02:00'),
        );
        self::assertSame('2026-07-11T21:00:00+00:00', $failure->finishedAt->format('c'));
        self::assertSame(1, (new MonitoringApplyResult(MonitoringRunStatus::Partial, 1, 2, 3))->created);

        try {
            new MonitoringRunFailure(self::id('bad-run'), self::id('bad-connection'), 'Bad error', new DateTimeImmutable());
            self::fail('Invalid failure was accepted.');
        } catch (InvalidArgumentException) {
        }
        $this->expectException(InvalidArgumentException::class);
        new MonitoringApplyResult(MonitoringRunStatus::Succeeded, -1, 0, 0);
    }

    /** @param list<MonitoringScopeResult> $scopes */
    private function commit(array $scopes): MonitoringCommit
    {
        return new MonitoringCommit(
            self::id('commit-run'), self::id('commit-parent'), self::id('commit-connection'),
            self::endpoint('commit-endpoint'), ProxmoxProduct::Pve,
            InstallationBinding::pveStandalone('pve-a'), MonitoringRunKind::ObservedTasks, 1,
            $scopes, [], [], [], [], new DateTimeImmutable('2026-07-11T21:00:00Z'),
        );
    }

    private static function scope(
        MonitoringScopeStatus $status,
        string $key = 'pve-a',
    ): MonitoringScopeResult {
        return new MonitoringScopeResult(
            MonitoringScopeType::PveTasksArchive,
            $key,
            MonitoringSourceKind::Archive,
            'vzdump',
            $status,
            new DateTimeImmutable('2026-07-11T22:00:00+02:00'),
            new DateTimeImmutable('2026-07-11T23:00:00+02:00'),
            1,
            2,
            2,
            MonitoringScopeStatus::Partial === $status,
            false,
            MonitoringScopeStatus::Complete === $status ? null : 'read_failed',
            new DateTimeImmutable('2026-07-11T23:00:00+02:00'),
        );
    }

    private static function id(string $seed): InventoryIdentifier
    {
        return new InventoryIdentifier(substr(hash('sha256', $seed, true), 0, 16));
    }

    private static function endpoint(string $seed): EndpointId
    {
        return new EndpointId(substr(hash('sha256', $seed, true), 0, 16));
    }
}
