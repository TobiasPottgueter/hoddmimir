<?php

declare(strict_types=1);

namespace App\Tests\Unit\Application\Inventory\Pve;

use App\Application\Inventory\Connection\ConnectionReadFailureCode;
use App\Application\Inventory\InventoryIdentifier;
use App\Application\Inventory\Pve\EndpointAttemptOutcome;
use App\Application\Inventory\Pve\InventoryScopeStatus;
use App\Application\Inventory\Pve\PveCoreBindingKind;
use App\Application\Inventory\Pve\PveCoreInstallationBinding;
use App\Application\Inventory\Pve\PveCoreInventoryCommit;
use App\Application\Inventory\Pve\PveCoreScope;
use App\Application\Inventory\Pve\PveCoreScopeResult;
use App\Application\Inventory\Pve\PveEndpointAttempt;
use App\Application\Inventory\Pve\PveGuestObservation;
use App\Application\Inventory\Pve\PveNodeObservation;
use App\Application\Inventory\Pve\PveSyncRunStart;
use App\Application\Inventory\Pve\PveSyncRunFailure;
use App\Application\Inventory\Pve\PveCoreTextValidator;
use App\Application\Proxmox\Pve\PveGuestType;
use DateTimeImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class PveCoreInventoryModelTest extends TestCase
{
    private const string NOW = '2026-07-11T12:00:00+00:00';

    public function testIdentifiersBindingsObservationsAndStartExposeNormalizedValues(): void
    {
        $id = $this->id('a');
        self::assertSame(str_repeat('a', 16), $id->binary());
        self::assertTrue($id->equals($this->id('a')));
        self::assertFalse($id->equals($this->id('b')));

        $cluster = new PveCoreInstallationBinding(PveCoreBindingKind::Cluster, 'cluster-a');
        $standalone = new PveCoreInstallationBinding(PveCoreBindingKind::Standalone, 'node-a');
        self::assertSame('clustered', $cluster->topology());
        self::assertSame('standalone', $standalone->topology());

        $guest = new PveGuestObservation(PveGuestType::Qemu, 100, 'node-a', null, null, 0);
        self::assertSame('qemu:100', $guest->key());
        self::assertSame(0, $guest->diskWriteBytes);
        self::assertSame('online', (new PveNodeObservation('node-a', 'online'))->apiStatus);
        self::assertSame('offline', (new PveNodeObservation('node-b', 'offline'))->apiStatus);

        $start = new PveSyncRunStart($id, $this->id('b'), 2, new DateTimeImmutable('2026-07-11T14:00:00+02:00'));
        self::assertSame('+00:00', $start->startedAt->format('P'));

        $failure = new PveSyncRunFailure(
            $id,
            $this->id('b'),
            2,
            ConnectionReadFailureCode::EndpointsExhausted,
            new DateTimeImmutable('2026-07-11T14:00:00+02:00'),
        );
        self::assertSame('+00:00', $failure->finishedAt->format('P'));
        self::assertSame(ConnectionReadFailureCode::EndpointsExhausted, $failure->failureCode);
    }

    public function testCommitSortsValidatesAndDerivesStrictOverallAuthority(): void
    {
        $completeTopology = $this->scope(InventoryScopeStatus::Complete, true);
        $completeGuests = $this->scope(InventoryScopeStatus::Complete, false);
        $partial = $this->scope(InventoryScopeStatus::Partial, false);
        $failedTopology = $this->scope(InventoryScopeStatus::Failed, true);
        $failedGuests = $this->scope(InventoryScopeStatus::Failed, false);
        $authoritative = $this->commit($completeTopology, $completeGuests, [
            new PveNodeObservation('node-b', 'unknown'),
            new PveNodeObservation('node-a', 'online'),
        ], [
            new PveGuestObservation(PveGuestType::Lxc, 200, 'node-b', 'ct', false),
            new PveGuestObservation(PveGuestType::Qemu, 100, 'node-a', 'vm', true),
        ]);
        self::assertTrue($authoritative->isFullyAuthoritative());
        self::assertSame('succeeded', $authoritative->overallStatus());
        self::assertSame(['node-a', 'node-b'], array_map(static fn (PveNodeObservation $node): string => $node->name, $authoritative->nodes));
        self::assertSame(['lxc:200', 'qemu:100'], array_map(static fn (PveGuestObservation $guest): string => $guest->key(), $authoritative->guests));

        self::assertSame('partial', $this->commit($completeTopology, $partial)->overallStatus());
        self::assertSame('failed', $this->commit($failedTopology, $failedGuests, [], [])->overallStatus());
        self::assertSame('partial', $this->commit($failedTopology, $failedGuests, [new PveNodeObservation('node-a', 'online')], [])->overallStatus());
        self::assertFalse($partial->isComplete());
    }

    public function testEndpointAttemptIsStructuredUtcAndContainsNoMessageSurface(): void
    {
        $attempt = new PveEndpointAttempt(
            $this->id('a'), $this->id('b'), $this->id('c'), $this->id('d'), 1,
            EndpointAttemptOutcome::Failover, 'transport_error',
            new DateTimeImmutable('2026-07-11T14:00:00+02:00'),
            new DateTimeImmutable('2026-07-11T12:00:01+00:00'),
        );
        self::assertSame('+00:00', $attempt->startedAt->format('P'));
        self::assertSame('transport_error', $attempt->errorCode);
        self::assertNull((new PveEndpointAttempt(
            $this->id('e'), $this->id('b'), $this->id('c'), $this->id('d'), 2,
            EndpointAttemptOutcome::Selected, null,
            new DateTimeImmutable(self::NOW), new DateTimeImmutable(self::NOW),
        ))->errorCode);
    }

    public function testCoreTextValidationCoversEveryFailClosedBoundary(): void
    {
        foreach ([
            '' => false,
            'printable' => true,
            "bad\n" => false,
            str_repeat('x', 190) => true,
            str_repeat('x', 191) => false,
        ] as $value => $expected) {
            self::assertSame($expected, PveCoreTextValidator::isPrintableBinding($value));
        }

        foreach ([
            '' => false,
            'node-1.example' => true,
            '_node' => false,
            'node/bad' => false,
            str_repeat('n', 190) => true,
            str_repeat('n', 191) => false,
        ] as $value => $expected) {
            self::assertSame($expected, PveCoreTextValidator::isNodeName($value));
        }

        foreach ([
            '' => false,
            'transport_error' => true,
            '1error' => false,
            'error-code' => false,
            str_repeat('e', 64) => true,
            str_repeat('e', 65) => false,
        ] as $value => $expected) {
            self::assertSame($expected, PveCoreTextValidator::isErrorCode($value));
        }
    }

    /** @return iterable<string, array{callable(): mixed}> */
    public static function invalidValues(): iterable
    {
        yield 'identifier' => [static fn () => new InventoryIdentifier('short')];
        yield 'binding empty' => [static fn () => new PveCoreInstallationBinding(PveCoreBindingKind::Cluster, '')];
        yield 'binding control' => [static fn () => new PveCoreInstallationBinding(PveCoreBindingKind::Cluster, "bad\n")];
        yield 'node name' => [static fn () => new PveNodeObservation('/bad', 'online')];
        yield 'node status' => [static fn () => new PveNodeObservation('node', 'running')];
        yield 'vmid' => [static fn () => new PveGuestObservation(PveGuestType::Qemu, 0, 'node', null, null)];
        yield 'guest node' => [static fn () => new PveGuestObservation(PveGuestType::Qemu, 1, '/bad', null, null)];
        yield 'guest empty name' => [static fn () => new PveGuestObservation(PveGuestType::Qemu, 1, 'node', '', null)];
        yield 'guest long name' => [static fn () => new PveGuestObservation(PveGuestType::Qemu, 1, 'node', str_repeat('x', 256), null)];
        yield 'guest negative disk write' => [static fn () => new PveGuestObservation(PveGuestType::Qemu, 1, 'node', null, null, -1)];
        yield 'guest negative provisioned size' => [static fn () => new PveGuestObservation(PveGuestType::Qemu, 1, 'node', null, null, null, -1)];
        yield 'start revision' => [static fn () => new PveSyncRunStart(
            new InventoryIdentifier(str_repeat('a', 16)), new InventoryIdentifier(str_repeat('b', 16)), 0, new DateTimeImmutable(self::NOW),
        )];
        yield 'failure revision' => [static fn () => new PveSyncRunFailure(
            new InventoryIdentifier(str_repeat('a', 16)), new InventoryIdentifier(str_repeat('b', 16)), 0,
            ConnectionReadFailureCode::EndpointsExhausted, new DateTimeImmutable(self::NOW),
        )];
        yield 'attempt number' => [static fn () => self::attempt(0, EndpointAttemptOutcome::Selected, null)];
        yield 'attempt missing error' => [static fn () => self::attempt(1, EndpointAttemptOutcome::Failover, null)];
        yield 'attempt selected error' => [static fn () => self::attempt(1, EndpointAttemptOutcome::Selected, 'error')];
        yield 'attempt code' => [static fn () => self::attempt(1, EndpointAttemptOutcome::Terminal, 'TOKEN=SENSITIVE')];
        yield 'attempt time' => [static fn () => new PveEndpointAttempt(
            new InventoryIdentifier(str_repeat('a', 16)), new InventoryIdentifier(str_repeat('b', 16)),
            new InventoryIdentifier(str_repeat('c', 16)), new InventoryIdentifier(str_repeat('d', 16)), 1,
            EndpointAttemptOutcome::Selected, null, new DateTimeImmutable('2026-07-11T12:00:01Z'),
            new DateTimeImmutable('2026-07-11T12:00:00Z'),
        )];
    }

    #[DataProvider('invalidValues')]
    public function testInvalidValuesFailClosed(callable $operation): void
    {
        $this->expectException(InvalidArgumentException::class);
        $operation();
    }

    /** @return iterable<string, array{callable(): mixed}> */
    public static function invalidCommits(): iterable
    {
        $id = static fn (string $byte): InventoryIdentifier => new InventoryIdentifier(str_repeat($byte, 16));
        $scope = static fn (PveCoreScope $kind, InventoryScopeStatus $status): PveCoreScopeResult => new PveCoreScopeResult($kind, $status);
        $base = static fn (): array => [
            $id('a'), $id('b'), $id('c'), 1,
            new PveCoreInstallationBinding(PveCoreBindingKind::Cluster, 'cluster'),
        ];

        yield 'revision' => [static fn () => new PveCoreInventoryCommit(
            $id('a'), $id('b'), $id('c'), 0, new PveCoreInstallationBinding(PveCoreBindingKind::Cluster, 'cluster'),
            $scope(PveCoreScope::Topology, InventoryScopeStatus::Complete),
            $scope(PveCoreScope::Guests, InventoryScopeStatus::Complete), [], [], new DateTimeImmutable(self::NOW),
        )];
        yield 'swapped scopes' => [static fn () => new PveCoreInventoryCommit(
            ...array_merge($base(), [
                $scope(PveCoreScope::Guests, InventoryScopeStatus::Complete),
                $scope(PveCoreScope::Topology, InventoryScopeStatus::Complete), [], [], new DateTimeImmutable(self::NOW),
            ]),
        )];
        yield 'duplicate node' => [static fn () => new PveCoreInventoryCommit(
            ...array_merge($base(), [
                $scope(PveCoreScope::Topology, InventoryScopeStatus::Partial),
                $scope(PveCoreScope::Guests, InventoryScopeStatus::Partial),
                [new PveNodeObservation('node', 'online'), new PveNodeObservation('node', 'offline')], [], new DateTimeImmutable(self::NOW),
            ]),
        )];
        yield 'complete empty topology' => [static fn () => new PveCoreInventoryCommit(
            ...array_merge($base(), [
                $scope(PveCoreScope::Topology, InventoryScopeStatus::Complete),
                $scope(PveCoreScope::Guests, InventoryScopeStatus::Complete), [], [], new DateTimeImmutable(self::NOW),
            ]),
        )];
        yield 'standalone mismatch' => [static fn () => new PveCoreInventoryCommit(
            $id('a'), $id('b'), $id('c'), 1, new PveCoreInstallationBinding(PveCoreBindingKind::Standalone, 'node-a'),
            $scope(PveCoreScope::Topology, InventoryScopeStatus::Complete),
            $scope(PveCoreScope::Guests, InventoryScopeStatus::Complete),
            [new PveNodeObservation('node-b', 'online')], [], new DateTimeImmutable(self::NOW),
        )];
        yield 'duplicate guest' => [static fn () => new PveCoreInventoryCommit(
            ...array_merge($base(), [
                $scope(PveCoreScope::Topology, InventoryScopeStatus::Partial),
                $scope(PveCoreScope::Guests, InventoryScopeStatus::Partial),
                [new PveNodeObservation('node', 'online')],
                [
                    new PveGuestObservation(PveGuestType::Qemu, 1, 'node', null, null),
                    new PveGuestObservation(PveGuestType::Qemu, 1, 'node', null, null),
                ], new DateTimeImmutable(self::NOW),
            ]),
        )];
        yield 'unknown guest node' => [static fn () => new PveCoreInventoryCommit(
            ...array_merge($base(), [
                $scope(PveCoreScope::Topology, InventoryScopeStatus::Partial),
                $scope(PveCoreScope::Guests, InventoryScopeStatus::Partial),
                [new PveNodeObservation('node-a', 'online')],
                [new PveGuestObservation(PveGuestType::Qemu, 1, 'node-b', null, null)], new DateTimeImmutable(self::NOW),
            ]),
        )];
    }

    #[DataProvider('invalidCommits')]
    public function testInvalidCommitsFailClosed(callable $operation): void
    {
        $this->expectException(InvalidArgumentException::class);
        $operation();
    }

    /**
     * @param null|list<PveNodeObservation>  $nodes
     * @param null|list<PveGuestObservation> $guestRows
     */
    private function commit(
        PveCoreScopeResult $topology,
        PveCoreScopeResult $guests,
        ?array $nodes = null,
        ?array $guestRows = null,
    ): PveCoreInventoryCommit {
        return new PveCoreInventoryCommit(
            $this->id('a'), $this->id('b'), $this->id('c'), 1,
            new PveCoreInstallationBinding(PveCoreBindingKind::Cluster, 'cluster-a'),
            $topology, $guests,
            $nodes ?? [new PveNodeObservation('node-a', 'online')],
            $guestRows ?? [], new DateTimeImmutable(self::NOW),
        );
    }

    private function scope(InventoryScopeStatus $status, bool $topology): PveCoreScopeResult
    {
        return new PveCoreScopeResult($topology ? PveCoreScope::Topology : PveCoreScope::Guests, $status);
    }

    private function id(string $byte): InventoryIdentifier
    {
        return new InventoryIdentifier(str_repeat($byte, 16));
    }

    private static function attempt(int $number, EndpointAttemptOutcome $outcome, ?string $code): PveEndpointAttempt
    {
        return new PveEndpointAttempt(
            new InventoryIdentifier(str_repeat('a', 16)), new InventoryIdentifier(str_repeat('b', 16)),
            new InventoryIdentifier(str_repeat('c', 16)), new InventoryIdentifier(str_repeat('d', 16)),
            $number, $outcome, $code, new DateTimeImmutable(self::NOW), new DateTimeImmutable(self::NOW),
        );
    }
}
