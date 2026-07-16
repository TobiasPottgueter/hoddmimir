<?php

declare(strict_types=1);

namespace App\Tests\Unit\Application\Backup\Execution;

use App\Application\Backup\Execution\ExecutorEvidenceLeaseOwnershipLost;
use App\Application\Backup\Execution\ExecutorEvidenceRefreshClaim;
use App\Application\Backup\Execution\ExecutorEvidenceRefreshEndpoint;
use App\Application\Backup\Execution\ExecutorEvidenceRefreshFailure;
use App\Application\Backup\Execution\ExecutorEvidenceRefreshFailureCode;
use App\Application\Backup\Execution\ExecutorEvidenceRefreshSource;
use App\Application\Backup\Execution\ExecutorEvidenceRefreshStatus;
use App\Application\Backup\Execution\ExecutorEvidenceRefreshStore;
use App\Application\Backup\Execution\ExecutorEvidenceRefreshSubject;
use App\Application\Backup\Execution\ExecutorPermissionProjection;
use App\Application\Backup\Execution\ProjectExecutorPermissionEvidence;
use App\Application\Backup\Execution\PveExecutorPermissionMatrixPath;
use App\Application\Backup\Execution\PveExecutorPermissionSnapshot;
use App\Application\Backup\Execution\RefreshExecutorPermissionEvidence;
use App\Domain\Shared\Clock;
use DateTimeImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class RefreshExecutorPermissionEvidenceTest extends TestCase
{
    private const string CONNECTION = 'connection-id-01';
    private const string ENDPOINT_A = 'endpoint-id-0001';
    private const string ENDPOINT_B = 'endpoint-id-0002';
    private const string WORKER = 'worker-id-000001';

    public function testNoDueConnectionPerformsNoRemoteOrPublicationWork(): void
    {
        $store = new RefreshStore(null);
        $source = new RefreshSource([]);
        self::assertSame(ExecutorEvidenceRefreshStatus::NoDueConnection, $this->service($store, $source)->refreshDue(self::WORKER));
        self::assertSame(1, $store->claimCalls);
        self::assertSame([], $source->calls);
        self::assertSame([], $store->staged);
        self::assertSame(0, $store->publishCalls);
    }

    public function testEndpointFailureUsesACompleteNextAttemptThenPublishesBoundedPages(): void
    {
        $claim = $this->claim();
        $store = new RefreshStore($claim, [
            [$this->subject(101), $this->subject(102)],
            [$this->subject(103)],
            [],
        ]);
        $source = new RefreshSource([
            ExecutorEvidenceRefreshFailure::for(ExecutorEvidenceRefreshFailureCode::Tls),
            $this->snapshot(self::ENDPOINT_B),
        ]);

        self::assertSame(ExecutorEvidenceRefreshStatus::Published, $this->service($store, $source, 2)->refreshDue(self::WORKER));
        self::assertSame([self::ENDPOINT_A, self::ENDPOINT_B], $source->calls);
        self::assertCount(2, $store->staged);
        self::assertCount(2, $store->staged[0]);
        self::assertCount(1, $store->staged[1]);
        self::assertSame(1, $store->publishCalls);
        self::assertSame(self::ENDPOINT_B, $store->boundEndpoint);
        self::assertSame([], $store->failures);
        self::assertSame([null, $this->subject(102)->cursor(), $this->subject(103)->cursor()], $store->cursors);
        self::assertSame(6, $store->renewCalls);
    }

    public function testAllEndpointFailuresPreserveOnlyTheLastSanitizedCode(): void
    {
        $store = new RefreshStore($this->claim());
        $source = new RefreshSource([
            ExecutorEvidenceRefreshFailure::for(ExecutorEvidenceRefreshFailureCode::Tls),
            ExecutorEvidenceRefreshFailure::for(ExecutorEvidenceRefreshFailureCode::RemoteUnavailable),
        ]);
        self::assertSame(ExecutorEvidenceRefreshStatus::Failed, $this->service($store, $source)->refreshDue(self::WORKER));
        self::assertSame([ExecutorEvidenceRefreshFailureCode::RemoteUnavailable], $store->failures);
        self::assertSame(0, $store->publishCalls);
    }

    #[DataProvider('terminalFailureProvider')]
    public function testConnectionGlobalFailuresNeverFailOver(ExecutorEvidenceRefreshFailureCode $code): void
    {
        $store = new RefreshStore($this->claim());
        $source = new RefreshSource([
            ExecutorEvidenceRefreshFailure::for($code),
            $this->snapshot(self::ENDPOINT_B),
        ]);
        self::assertSame(ExecutorEvidenceRefreshStatus::Failed, $this->service($store, $source)->refreshDue(self::WORKER));
        self::assertSame([self::ENDPOINT_A], $source->calls);
        self::assertSame([$code], $store->failures);
    }

    /** @return iterable<string, array{ExecutorEvidenceRefreshFailureCode}> */
    public static function terminalFailureProvider(): iterable
    {
        yield 'credential unavailable' => [ExecutorEvidenceRefreshFailureCode::CredentialUnavailable];
        yield 'authentication' => [ExecutorEvidenceRefreshFailureCode::Authentication];
        yield 'permission denied' => [ExecutorEvidenceRefreshFailureCode::PermissionDenied];
        yield 'configuration changed' => [ExecutorEvidenceRefreshFailureCode::ConfigurationChanged];
    }

    #[DataProvider('bindingMismatchProvider')]
    public function testSnapshotMustMatchClaimAndAttemptedEndpoint(string $field): void
    {
        $claim = $this->claim(singleEndpoint: true);
        $snapshot = $this->snapshot(self::ENDPOINT_A);
        $connectionId = 'connectionId' === $field ? 'different-id-000' : $snapshot->connectionId;
        $endpointId = 'endpointId' === $field ? 'different-id-000' : $snapshot->endpointId;
        $connectionRevision = 'connectionRevision' === $field ? 99 : $snapshot->connectionRevision;
        $backupRevision = 'backupCredentialRevision' === $field ? 99 : $snapshot->backupCredentialRevision;
        $scanRevision = 'scanCredentialRevision' === $field ? 99 : $snapshot->scanCredentialRevision;
        $store = new RefreshStore($claim);
        $source = new RefreshSource([new PveExecutorPermissionSnapshot(
            $connectionId, $endpointId, $connectionRevision, $backupRevision, $scanRevision, 9,
            'backup@pve!hoddmimir', 'backup@pve', $snapshot->backupPermissionMatrix, [],
        )]);
        self::assertSame(ExecutorEvidenceRefreshStatus::Failed, $this->service($store, $source)->refreshDue(self::WORKER));
        self::assertSame([ExecutorEvidenceRefreshFailureCode::ConfigurationChanged], $store->failures);
    }

    /** @return iterable<string, array{string}> */
    public static function bindingMismatchProvider(): iterable
    {
        foreach (['connectionId', 'endpointId', 'connectionRevision', 'backupCredentialRevision', 'scanCredentialRevision'] as $field) {
            yield $field => [$field];
        }
    }

    /** @param array<int, mixed> $page */
    #[DataProvider('invalidPageProvider')]
    public function testMalformedOrUnorderedSubjectPageFailsWithoutPublishing(array $page): void
    {
        $store = new RefreshStore($this->claim(singleEndpoint: true), [$page]);
        $source = new RefreshSource([$this->snapshot(self::ENDPOINT_A)]);
        self::assertSame(ExecutorEvidenceRefreshStatus::Failed, $this->service($store, $source, 2)->refreshDue(self::WORKER));
        self::assertSame([ExecutorEvidenceRefreshFailureCode::InvalidResponse], $store->failures);
        self::assertSame([], $store->staged);
        self::assertSame(0, $store->publishCalls);
    }

    public function testSnapshotObservationTimeIsCapturedBeforePotentiallySlowPaging(): void
    {
        $store = new RefreshStore($this->claim(singleEndpoint: true), [[$this->subject(101)], []]);
        $clock = new RefreshClock([
            new DateTimeImmutable('2026-07-16T08:00:00Z'),
            new DateTimeImmutable('2026-07-16T08:00:00.500000Z'),
            new DateTimeImmutable('2026-07-16T08:00:01Z'),
            new DateTimeImmutable('2026-07-16T08:10:00Z'),
        ]);
        $service = new RefreshExecutorPermissionEvidence(
            $store, new RefreshSource([$this->snapshot(self::ENDPOINT_A)]),
            new ProjectExecutorPermissionEvidence(), $clock,
        );
        self::assertSame(ExecutorEvidenceRefreshStatus::Published, $service->refreshDue(self::WORKER));
        self::assertSame('2026-07-16T08:00:01+00:00', $store->publishedAt?->format('c'));
        self::assertSame($store->publishedAt, $store->boundObservedAt);
    }

    public function testTotalSubjectsAreBoundedAcrossPagesWithoutPartialPublication(): void
    {
        $store = new RefreshStore($this->claim(singleEndpoint: true), [
            [$this->subject(101), $this->subject(102)],
            [$this->subject(103)],
        ]);
        $service = new RefreshExecutorPermissionEvidence(
            $store, new RefreshSource([$this->snapshot(self::ENDPOINT_A)]),
            new ProjectExecutorPermissionEvidence(), new RefreshClock(), 2, 2,
        );
        self::assertSame(ExecutorEvidenceRefreshStatus::Failed, $service->refreshDue(self::WORKER));
        self::assertCount(1, $store->staged);
        self::assertSame(0, $store->publishCalls);
        self::assertSame([ExecutorEvidenceRefreshFailureCode::InvalidResponse], $store->failures);
    }

    /** @return iterable<string, array{array<int, mixed>}> */
    public static function invalidPageProvider(): iterable
    {
        $test = new self('placeholder');
        yield 'too large' => [[$test->subject(101), $test->subject(102), $test->subject(103)]];
        yield 'wrong element' => [['not-a-subject']];
        yield 'foreign connection' => [[new ExecutorEvidenceRefreshSubject(
            self::ENDPOINT_A, self::CONNECTION, self::CONNECTION, self::CONNECTION, self::CONNECTION,
            'store-1', self::CONNECTION, 101,
        )]];
        yield 'duplicate cursor' => [[$test->subject(101), $test->subject(101)]];
        yield 'descending cursor' => [[$test->subject(102), $test->subject(101)]];
    }

    public function testLeaseOwnershipLossPropagatesFromEveryStoreBoundary(): void
    {
        foreach (['claim', 'renew', 'bind', 'subjects', 'stage', 'publish', 'fail'] as $boundary) {
            $store = new RefreshStore($this->claim(singleEndpoint: true), [[$this->subject(101)], []]);
            $store->leaseLossAt = $boundary;
            $source = 'fail' === $boundary
                ? new RefreshSource([ExecutorEvidenceRefreshFailure::for(ExecutorEvidenceRefreshFailureCode::Transport)])
                : new RefreshSource([$this->snapshot(self::ENDPOINT_A)]);
            try {
                $this->service($store, $source)->refreshDue(self::WORKER);
                self::fail('Expected lease ownership loss at '.$boundary);
            } catch (ExecutorEvidenceLeaseOwnershipLost) {
                self::addToAssertionCount(1);
            }
        }
    }

    #[DataProvider('invalidPageSizeProvider')]
    public function testPageSizeIsBounded(int $pageSize): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->service(new RefreshStore(null), new RefreshSource([]), $pageSize);
    }

    public function testWorkerIdentifierAndMaximumSubjectCountAreBounded(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->service(new RefreshStore(null), new RefreshSource([]))->refreshDue('bad');
    }

    #[DataProvider('invalidMaximumProvider')]
    public function testMaximumSubjectCountIsBounded(int $pageSize, int $maximum): void
    {
        $this->expectException(InvalidArgumentException::class);
        new RefreshExecutorPermissionEvidence(
            new RefreshStore(null), new RefreshSource([]), new ProjectExecutorPermissionEvidence(),
            new RefreshClock(), $pageSize, $maximum,
        );
    }

    /** @return iterable<string, array{int, int}> */
    public static function invalidMaximumProvider(): iterable
    {
        yield 'smaller than page' => [2, 1];
        yield 'too large' => [256, 262_145];
    }

    /** @return iterable<string, array{int}> */
    public static function invalidPageSizeProvider(): iterable
    {
        yield 'zero' => [0];
        yield 'too large' => [1_025];
    }

    private function service(RefreshStore $store, RefreshSource $source, int $pageSize = 256): RefreshExecutorPermissionEvidence
    {
        return new RefreshExecutorPermissionEvidence($store, $source, new ProjectExecutorPermissionEvidence(), new RefreshClock(), $pageSize);
    }

    private function claim(bool $singleEndpoint = false): ExecutorEvidenceRefreshClaim
    {
        $endpoints = [new ExecutorEvidenceRefreshEndpoint(self::ENDPOINT_A, 10)];
        if (!$singleEndpoint) {
            $endpoints[] = new ExecutorEvidenceRefreshEndpoint(self::ENDPOINT_B, 20);
        }
        return new ExecutorEvidenceRefreshClaim(
            self::CONNECTION, 4, 5, 6, self::WORKER, 'lease-token-0001', 7,
            new DateTimeImmutable('2026-07-16T08:05:00Z'), $endpoints,
        );
    }

    private function snapshot(string $endpoint): PveExecutorPermissionSnapshot
    {
        return new PveExecutorPermissionSnapshot(
            self::CONNECTION, $endpoint, 4, 5, 6, 9, 'backup@pve!hoddmimir', 'backup@pve', [
                new PveExecutorPermissionMatrixPath('/vms', ['VM.Backup' => 1]),
                new PveExecutorPermissionMatrixPath('/storage', ['Datastore.AllocateSpace' => 1]),
            ], [],
        );
    }

    private function subject(int $vmid): ExecutorEvidenceRefreshSubject
    {
        $guest = \str_repeat("\0", 12).\pack('N', $vmid);
        return new ExecutorEvidenceRefreshSubject(
            self::CONNECTION, self::CONNECTION, self::CONNECTION, self::CONNECTION, self::CONNECTION,
            'store-1', $guest, $vmid,
        );
    }
}

final class RefreshStore implements ExecutorEvidenceRefreshStore
{
    public int $claimCalls = 0;
    public int $renewCalls = 0;
    /** @var list<list<ExecutorPermissionProjection>> */ public array $staged = [];
    /** @var list<ExecutorEvidenceRefreshFailureCode> */ public array $failures = [];
    /** @var list<?string> */ public array $cursors = [];
    public int $publishCalls = 0;
    public ?DateTimeImmutable $publishedAt = null;
    public ?string $boundEndpoint = null;
    public ?DateTimeImmutable $boundObservedAt = null;
    public ?string $leaseLossAt = null;
    /** @param list<array<int, mixed>> $pages */
    public function __construct(private ?ExecutorEvidenceRefreshClaim $claim, private array $pages = []) {}
    public function claimDue(string $workerId, DateTimeImmutable $now): ?ExecutorEvidenceRefreshClaim
    {
        ++$this->claimCalls;
        $this->lose('claim');
        return $this->claim;
    }
    public function renew(ExecutorEvidenceRefreshClaim $claim, DateTimeImmutable $now): void
    {
        ++$this->renewCalls;
        $this->lose('renew');
    }
    public function bindSnapshotEndpoint(
        ExecutorEvidenceRefreshClaim $claim,
        string $endpointId,
        DateTimeImmutable $observedAt,
    ): void {
        $this->lose('bind');
        $this->boundEndpoint = $endpointId;
        $this->boundObservedAt = $observedAt;
    }
    public function subjects(ExecutorEvidenceRefreshClaim $claim, ?string $afterSubjectKey, int $limit): array
    {
        $this->lose('subjects');
        $this->cursors[] = $afterSubjectKey;
        /** @phpstan-ignore return.type (intentionally permits malformed adapter pages in boundary tests) */
        return \array_shift($this->pages) ?? [];
    }
    public function stage(ExecutorEvidenceRefreshClaim $claim, array $projections): void
    {
        $this->lose('stage');
        $this->staged[] = $projections;
    }
    public function publish(ExecutorEvidenceRefreshClaim $claim, DateTimeImmutable $observedAt): void
    {
        $this->lose('publish');
        ++$this->publishCalls;
        $this->publishedAt = $observedAt;
    }
    public function fail(ExecutorEvidenceRefreshClaim $claim, ExecutorEvidenceRefreshFailureCode $code, DateTimeImmutable $now): void
    {
        $this->lose('fail');
        $this->failures[] = $code;
    }
    private function lose(string $boundary): void
    {
        if ($boundary === $this->leaseLossAt) throw new ExecutorEvidenceLeaseOwnershipLost();
    }
}

final class RefreshSource implements ExecutorEvidenceRefreshSource
{
    /** @var list<string> */ public array $calls = [];
    /** @param list<PveExecutorPermissionSnapshot|ExecutorEvidenceRefreshFailure> $results */
    public function __construct(private array $results) {}
    public function read(ExecutorEvidenceRefreshClaim $claim, ExecutorEvidenceRefreshEndpoint $endpoint): PveExecutorPermissionSnapshot
    {
        $this->calls[] = $endpoint->id;
        $result = \array_shift($this->results);
        if ($result instanceof ExecutorEvidenceRefreshFailure) throw $result;
        if (null === $result) throw new \LogicException('Missing refresh source result.');
        return $result;
    }
}

final class RefreshClock implements Clock
{
    /** @param list<DateTimeImmutable> $times */
    public function __construct(private array $times = []) {}
    public function now(): DateTimeImmutable
    {
        return \array_shift($this->times) ?? new DateTimeImmutable('2026-07-16T08:00:00Z');
    }
}
