<?php

declare(strict_types=1);

namespace App\Tests\Unit\Application\Inventory\Connection;

use App\Application\Inventory\Connection\ConnectionId;
use App\Application\Inventory\Connection\ConnectionInstallationRead;
use App\Application\Inventory\Connection\ConnectionReadFailure;
use App\Application\Inventory\Connection\ConnectionReadFailureCode;
use App\Application\Inventory\Connection\ConnectionReadCheckpoint;
use App\Application\Inventory\Connection\ConnectionScanTarget;
use App\Application\Inventory\Connection\EndpointId;
use App\Application\Inventory\Connection\EndpointInstallationReader;
use App\Application\Inventory\Connection\EndpointReadFailure;
use App\Application\Inventory\Connection\EndpointReadFailureCode;
use App\Application\Inventory\Connection\EndpointReadAttemptOutcome;
use App\Application\Inventory\Connection\EndpointReadAttemptSink;
use App\Application\Inventory\Connection\EndpointReadAttemptStarted;
use App\Application\Inventory\Connection\EndpointScanReference;
use App\Application\Inventory\Connection\InstallationBinding;
use App\Application\Inventory\Connection\ProxmoxProduct;
use App\Application\Inventory\Connection\ReadConnectionWithFailover;
use App\Application\Proxmox\Pbs\PbsDatastoreScanScope;
use App\Application\Proxmox\Pbs\PbsDatastoreConfigurationSnapshot;
use App\Application\Proxmox\Pbs\PbsInstallationSnapshot;
use App\Application\Proxmox\Pbs\PbsInstanceIdentity;
use App\Application\Proxmox\Pbs\PbsNodeStatus;
use App\Application\Proxmox\Pbs\PbsVersion;
use App\Application\Proxmox\Pve\PveClusterMode;
use App\Application\Proxmox\Pve\PveClusterNode;
use App\Application\Proxmox\Pve\PveClusterTopology;
use App\Application\Proxmox\Pve\PveInstallationSnapshot;
use App\Application\Proxmox\Pve\PveInventorySnapshot;
use App\Application\Proxmox\Pve\PveMissingPermission;
use App\Application\Proxmox\Pve\PveNodeResource;
use App\Application\Proxmox\Pve\PvePermissionAssessment;
use App\Application\Proxmox\Pve\PveRequiredPermission;
use App\Application\Proxmox\Pve\PveResourceInventory;
use App\Application\Proxmox\Pve\PveStorageInventorySnapshot;
use App\Application\Proxmox\Pve\PveVersion;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use DateTimeImmutable;

final class ReadConnectionWithFailoverTest extends TestCase
{
    #[DataProvider('failoverFailureProvider')]
    public function testItFailsOverEndpointScopedFailuresInDeterministicOrder(EndpointReadFailureCode $code): void
    {
        $snapshot = self::pveSnapshot('forest');
        $reader = new QueuedEndpointInstallationReader([
            EndpointReadFailure::for($code),
            $snapshot,
        ]);
        $target = self::target(ProxmoxProduct::Pve, [
            self::endpoint(2, 100),
            self::endpoint(3, 200),
        ]);

        $result = (new ReadConnectionWithFailover($reader))->read(
            $target,
            InstallationBinding::pveCluster('forest', ['node-a']),
            new CountingConnectionReadCheckpoint(),
        );

        self::assertSame(self::endpointId(3)->bytes, $result->endpointId->bytes);
        self::assertSame($snapshot, $result->snapshot);
        self::assertSame([self::endpointId(2)->toHex(), self::endpointId(3)->toHex()], $reader->endpointIds);
    }

    /** @return iterable<string, array{EndpointReadFailureCode}> */
    public static function failoverFailureProvider(): iterable
    {
        yield 'transport' => [EndpointReadFailureCode::Transport];
        yield 'TLS' => [EndpointReadFailureCode::Tls];
        yield 'unsupported product or version' => [EndpointReadFailureCode::UnsupportedProductOrVersion];
        yield 'root unusable' => [EndpointReadFailureCode::RootUnusable];
        yield 'wrong identity classification' => [EndpointReadFailureCode::WrongIdentity];
    }

    #[DataProvider('terminalFailureProvider')]
    public function testItNeverFailsOverConnectionScopedSecurityFailures(EndpointReadFailureCode $code): void
    {
        $reader = new QueuedEndpointInstallationReader([
            EndpointReadFailure::for($code),
            self::pveSnapshot('forest'),
        ]);

        try {
            (new ReadConnectionWithFailover($reader))->read(
                self::target(ProxmoxProduct::Pve, [self::endpoint(1, 1), self::endpoint(2, 2)]),
                InstallationBinding::pveCluster('forest', ['node-a']),
                new CountingConnectionReadCheckpoint(),
            );
            self::fail('A terminal failure triggered endpoint failover.');
        } catch (ConnectionReadFailure $failure) {
            self::assertSame(ConnectionReadFailureCode::TerminalEndpointFailure, $failure->failureCode);
            self::assertSame($code, $failure->endpointFailureCode);
            self::assertSame(self::endpointId(1)->bytes, $failure->endpointId?->bytes);
            self::assertSame('The Proxmox connection read failed without endpoint failover.', $failure->getMessage());
        }

        self::assertSame([self::endpointId(1)->toHex()], $reader->endpointIds);
    }

    /** @return iterable<string, array{EndpointReadFailureCode}> */
    public static function terminalFailureProvider(): iterable
    {
        yield 'decrypt or credential unavailable' => [EndpointReadFailureCode::CredentialUnavailable];
        yield 'authentication' => [EndpointReadFailureCode::Authentication];
        yield '403 permission denied' => [EndpointReadFailureCode::PermissionDenied];
    }

    public function testItFailsOverWrongIdentityAndProductWithoutMergingSnapshots(): void
    {
        $wrongIdentity = self::pveSnapshot('other');
        $wrongProduct = self::pbsSnapshot(4, 'pbs', str_repeat('a', 32));
        $expected = self::pveSnapshot('forest');
        $reader = new QueuedEndpointInstallationReader([$wrongIdentity, $wrongProduct, $expected]);
        $target = self::target(ProxmoxProduct::Pve, [
            self::endpoint(1, 1),
            self::endpoint(2, 2),
            self::endpoint(3, 3),
        ]);
        $attempts = new RecordingEndpointReadAttemptSink();

        $result = (new ReadConnectionWithFailover($reader))->read(
            $target,
            InstallationBinding::pveCluster('forest', ['node-a']),
            new CountingConnectionReadCheckpoint(),
            $attempts,
        );

        self::assertSame($expected, $result->snapshot);
        self::assertSame(self::endpointId(3)->bytes, $result->endpointId->bytes);
        self::assertCount(3, $reader->endpointIds);
        self::assertSame([
            [1, EndpointReadAttemptOutcome::Failover, EndpointReadFailureCode::WrongIdentity],
            [2, EndpointReadAttemptOutcome::Failover, EndpointReadFailureCode::RootUnusable],
            [3, EndpointReadAttemptOutcome::Selected, null],
        ], $attempts->finished);
    }

    public function testSameClusterNameRequiresKnownMemberIntersection(): void
    {
        $disjoint = self::pveSnapshot('forest', true, ['node-z']);
        $overlapping = self::pveSnapshot('forest', true, ['node-a', 'node-new']);
        $reader = new QueuedEndpointInstallationReader([$disjoint, $overlapping]);

        $result = (new ReadConnectionWithFailover($reader))->read(
            self::target(ProxmoxProduct::Pve, [self::endpoint(1, 1), self::endpoint(2, 2)]),
            InstallationBinding::pveCluster('forest', ['node-a', 'node-old']),
            new CountingConnectionReadCheckpoint(),
        );

        self::assertSame($overlapping, $result->snapshot);
        self::assertSame(['node-a', 'node-new'], $result->binding->knownMemberNodes);
        self::assertCount(2, $reader->endpointIds);
    }

    public function testItStopsOnAnIdentityValidatedPartialSnapshot(): void
    {
        $partial = self::pveSnapshot('forest', false);
        $completeFromAnotherEndpoint = self::pveSnapshot('forest');
        $reader = new QueuedEndpointInstallationReader([$partial, $completeFromAnotherEndpoint]);

        $result = (new ReadConnectionWithFailover($reader))->read(
            self::target(ProxmoxProduct::Pve, [self::endpoint(1, 1), self::endpoint(2, 2)]),
            InstallationBinding::pveCluster('forest', ['node-a']),
            new CountingConnectionReadCheckpoint(),
        );

        self::assertSame($partial, $result->snapshot);
        self::assertFalse($result->isComplete());
        self::assertSame([self::endpointId(1)->toHex()], $reader->endpointIds);
    }

    public function testUnboundClusterRequiresACompleteSnapshotToEstablishMembership(): void
    {
        $partial = self::pveSnapshot('forest', false);
        $complete = self::pveSnapshot('forest');
        $reader = new QueuedEndpointInstallationReader([$partial, $complete]);
        $attempts = new RecordingEndpointReadAttemptSink();

        $result = (new ReadConnectionWithFailover($reader))->read(
            self::target(ProxmoxProduct::Pve, [self::endpoint(1, 1), self::endpoint(2, 2)]),
            null,
            new CountingConnectionReadCheckpoint(),
            $attempts,
        );

        self::assertSame($complete, $result->snapshot);
        self::assertCount(2, $reader->endpointIds);
        self::assertSame([
            [1, EndpointReadAttemptOutcome::Failover, EndpointReadFailureCode::RootUnusable],
            [2, EndpointReadAttemptOutcome::Selected, null],
        ], $attempts->finished);
    }

    public function testUnboundCompositeUsesCoreCompletenessForBindingAndKeepsPartialStorageDiagnostic(): void
    {
        $core = self::pveSnapshot('forest');
        $composite = new PveInventorySnapshot(
            $core,
            new PveStorageInventorySnapshot(null, null, [], []),
            ['node-a'],
        );
        $reader = new QueuedEndpointInstallationReader([$composite, self::pveSnapshot('other')]);

        $result = (new ReadConnectionWithFailover($reader))->read(
            self::target(ProxmoxProduct::Pve, [self::endpoint(1, 1), self::endpoint(2, 2)]),
            null,
            new CountingConnectionReadCheckpoint(),
        );

        self::assertSame($composite, $result->snapshot);
        self::assertFalse($result->isComplete());
        self::assertTrue(InstallationBinding::pveCluster('forest', ['node-a'])->equals($result->binding));
        self::assertCount(1, $reader->endpointIds);
    }

    public function testSuccessfulReadCarriesOpaqueIdsRevisionBindingAndCompleteness(): void
    {
        $snapshot = self::pveSnapshot('forest');
        $target = self::target(ProxmoxProduct::Pve, [self::endpoint(1, 1)], 19);
        $reader = new QueuedEndpointInstallationReader([$snapshot]);

        $checkpoint = new CountingConnectionReadCheckpoint();
        $result = (new ReadConnectionWithFailover($reader))->read($target, null, $checkpoint);

        self::assertInstanceOf(ConnectionInstallationRead::class, $result);
        self::assertSame($target->connectionId, $result->connectionId);
        self::assertSame(19, $result->expectedRevision);
        self::assertSame(self::endpointId(1)->bytes, $result->endpointId->bytes);
        self::assertTrue(InstallationBinding::pveCluster('forest', ['node-a'])->equals($result->binding));
        self::assertTrue($result->isComplete());
        self::assertSame(ProxmoxProduct::Pve, $reader->products[0]);
        self::assertSame($target->connectionId, $reader->connectionIds[0]);
        self::assertSame([19], $reader->expectedRevisions);
        self::assertSame([$checkpoint], $reader->checkpoints);
    }

    public function testRootWithoutValidatedIdentityFailsOver(): void
    {
        $unusable = self::pveSnapshot(null);
        $usable = self::pveSnapshot('forest');
        $reader = new QueuedEndpointInstallationReader([$unusable, $usable]);

        $result = (new ReadConnectionWithFailover($reader))->read(
            self::target(ProxmoxProduct::Pve, [self::endpoint(1, 1), self::endpoint(2, 2)]),
            null,
            new CountingConnectionReadCheckpoint(),
        );

        self::assertSame($usable, $result->snapshot);
        self::assertCount(2, $reader->endpointIds);
    }

    public function testPbs4BindingAllowsFailoverButPbs3BindingDoesNot(): void
    {
        $pbs4 = self::pbsSnapshot(4, 'pbs4', str_repeat('a', 32));
        $pbs4Reader = new QueuedEndpointInstallationReader([
            EndpointReadFailure::for(EndpointReadFailureCode::Transport),
            $pbs4,
        ]);
        $endpoints = [self::endpoint(1, 1), self::endpoint(2, 2)];

        $pbs4Result = (new ReadConnectionWithFailover($pbs4Reader))->read(
            self::target(ProxmoxProduct::Pbs, $endpoints),
            InstallationBinding::pbsInstance(str_repeat('a', 32)),
            new CountingConnectionReadCheckpoint(),
        );

        self::assertSame($pbs4, $pbs4Result->snapshot);
        self::assertCount(2, $pbs4Reader->endpointIds);

        $pbs3Reader = new QueuedEndpointInstallationReader([
            EndpointReadFailure::for(EndpointReadFailureCode::Transport),
            self::pbsSnapshot(3, 'pbs3', null),
        ]);
        try {
            (new ReadConnectionWithFailover($pbs3Reader))->read(
                self::target(ProxmoxProduct::Pbs, $endpoints),
                InstallationBinding::pbsLegacyNode('pbs3', new EndpointId(str_repeat(chr(1), 16))),
                new CountingConnectionReadCheckpoint(),
            );
            self::fail('PBS 3 failed over to an alias endpoint.');
        } catch (ConnectionReadFailure $failure) {
            self::assertSame(ConnectionReadFailureCode::EndpointsExhausted, $failure->failureCode);
        }
        self::assertCount(1, $pbs3Reader->endpointIds);
    }

    public function testBoundPbsInstanceFailsOverPastAEndpointThatOnlyExposesLegacyIdentity(): void
    {
        $instanceIdentity = str_repeat('a', 32);
        $reader = new QueuedEndpointInstallationReader([
            self::pbsSnapshot(3, 'legacy-endpoint', null),
            self::pbsSnapshot(4, 'pbs4', $instanceIdentity),
        ]);
        $attempts = new RecordingEndpointReadAttemptSink();

        $result = (new ReadConnectionWithFailover($reader))->read(
            self::target(ProxmoxProduct::Pbs, [self::endpoint(1, 1), self::endpoint(2, 2)]),
            InstallationBinding::pbsInstance($instanceIdentity),
            new CountingConnectionReadCheckpoint(),
            $attempts,
        );

        self::assertSame(self::endpointId(2)->bytes, $result->endpointId->bytes);
        self::assertCount(2, $reader->endpointIds);
        self::assertSame([
            [1, EndpointReadAttemptOutcome::Failover, EndpointReadFailureCode::WrongIdentity],
            [2, EndpointReadAttemptOutcome::Selected, null],
        ], $attempts->finished);
    }

    public function testCompletePbsInstanceObservationUpgradesTheExactLegacyEndpointBinding(): void
    {
        $endpoint = self::endpoint(1, 1);
        $snapshot = self::completePbsInstanceSnapshot('pbs4', str_repeat('a', 32));
        $reader = new QueuedEndpointInstallationReader([$snapshot]);

        $result = (new ReadConnectionWithFailover($reader))->read(
            self::target(ProxmoxProduct::Pbs, [$endpoint]),
            InstallationBinding::pbsLegacyNode('pbs4', $endpoint->endpointId),
            new CountingConnectionReadCheckpoint(),
        );

        self::assertSame($snapshot, $result->snapshot);
        self::assertTrue(InstallationBinding::pbsInstance(str_repeat('a', 32))->equals($result->binding));
    }

    public function testIncompletePbsInstanceCannotUpgradeALegacyBinding(): void
    {
        $endpoint = self::endpoint(1, 1);
        $attempts = new RecordingEndpointReadAttemptSink();

        try {
            (new ReadConnectionWithFailover(new QueuedEndpointInstallationReader([
                self::pbsSnapshot(4, 'pbs4', str_repeat('a', 32)),
            ])))->read(
                self::target(ProxmoxProduct::Pbs, [$endpoint]),
                InstallationBinding::pbsLegacyNode('pbs4', $endpoint->endpointId),
                new CountingConnectionReadCheckpoint(),
                $attempts,
            );
            self::fail('An incomplete instance snapshot upgraded a legacy binding.');
        } catch (ConnectionReadFailure $failure) {
            self::assertSame(ConnectionReadFailureCode::EndpointsExhausted, $failure->failureCode);
            self::assertSame(EndpointReadFailureCode::WrongIdentity, $failure->endpointFailureCode);
        }

        self::assertSame(
            [[1, EndpointReadAttemptOutcome::Terminal, EndpointReadFailureCode::WrongIdentity]],
            $attempts->finished,
        );
    }

    public function testUnboundCompletePbsInstanceSelectsOnlyTheFirstOfMultipleEndpoints(): void
    {
        $snapshot = self::completePbsInstanceSnapshot('pbs4', str_repeat('a', 32));
        $reader = new QueuedEndpointInstallationReader([$snapshot]);

        $result = (new ReadConnectionWithFailover($reader))->read(
            self::target(ProxmoxProduct::Pbs, [self::endpoint(1, 1), self::endpoint(2, 2)]),
            null,
            new CountingConnectionReadCheckpoint(),
        );

        self::assertSame($snapshot, $result->snapshot);
        self::assertCount(1, $reader->endpointIds);
        self::assertTrue(InstallationBinding::pbsInstance(str_repeat('a', 32))->equals($result->binding));
    }

    public function testUnboundPbsReadsOnlyTheFirstEndpointUntilItsIdentityIsBound(): void
    {
        $reader = new QueuedEndpointInstallationReader([
            EndpointReadFailure::for(EndpointReadFailureCode::Transport),
            self::pbsSnapshot(4, 'pbs4', str_repeat('a', 32)),
        ]);

        try {
            (new ReadConnectionWithFailover($reader))->read(
                self::target(ProxmoxProduct::Pbs, [self::endpoint(1, 1), self::endpoint(2, 2)]),
                null,
                new CountingConnectionReadCheckpoint(),
            );
            self::fail('An unbound PBS connection failed over before its major and identity were known.');
        } catch (ConnectionReadFailure $failure) {
            self::assertSame(ConnectionReadFailureCode::EndpointsExhausted, $failure->failureCode);
            self::assertSame(EndpointReadFailureCode::Transport, $failure->endpointFailureCode);
        }

        self::assertCount(1, $reader->endpointIds);
    }

    public function testUnboundLegacyPbsRejectsAdditionalEnabledEndpoints(): void
    {
        $snapshot = self::pbsSnapshot(3, 'pbs3', null);
        $reader = new QueuedEndpointInstallationReader([$snapshot]);
        $attempts = new RecordingEndpointReadAttemptSink();

        try {
            (new ReadConnectionWithFailover($reader))->read(
                self::target(ProxmoxProduct::Pbs, [self::endpoint(1, 1), self::endpoint(2, 2)]),
                null,
                new CountingConnectionReadCheckpoint(),
                $attempts,
            );
            self::fail('A legacy PBS installation accepted multiple enabled endpoints.');
        } catch (ConnectionReadFailure $failure) {
            self::assertSame(ConnectionReadFailureCode::InvalidEndpointConfiguration, $failure->failureCode);
        }
        self::assertCount(1, $reader->endpointIds);
        self::assertSame(
            [[1, EndpointReadAttemptOutcome::Terminal, EndpointReadFailureCode::RootUnusable]],
            $attempts->finished,
        );
    }

    public function testLegacyBindingRejectsWhenItsExactEndpointIsNoLongerConfigured(): void
    {
        $reader = new QueuedEndpointInstallationReader([]);

        try {
            (new ReadConnectionWithFailover($reader))->read(
                self::target(ProxmoxProduct::Pbs, [self::endpoint(2, 1)]),
                InstallationBinding::pbsLegacyNode('pbs3', self::endpointId(1)),
                new CountingConnectionReadCheckpoint(),
            );
            self::fail('A legacy PBS binding read from a different endpoint.');
        } catch (ConnectionReadFailure $failure) {
            self::assertSame(ConnectionReadFailureCode::InvalidEndpointConfiguration, $failure->failureCode);
        }

        self::assertSame([], $reader->endpointIds);
    }

    public function testPbsProductMismatchIsEndpointScopedRootUnusable(): void
    {
        $reader = new QueuedEndpointInstallationReader([self::pveSnapshot('forest')]);
        $attempts = new RecordingEndpointReadAttemptSink();

        try {
            (new ReadConnectionWithFailover($reader))->read(
                self::target(ProxmoxProduct::Pbs, [self::endpoint(1, 1)]),
                InstallationBinding::pbsLegacyNode('pbs3', new EndpointId(str_repeat(chr(1), 16))),
                new CountingConnectionReadCheckpoint(),
                $attempts,
            );
            self::fail('A PVE snapshot was accepted for a PBS target.');
        } catch (ConnectionReadFailure $failure) {
            self::assertSame(ConnectionReadFailureCode::EndpointsExhausted, $failure->failureCode);
            self::assertSame(EndpointReadFailureCode::RootUnusable, $failure->endpointFailureCode);
        }
        self::assertSame(
            [[1, EndpointReadAttemptOutcome::Terminal, EndpointReadFailureCode::RootUnusable]],
            $attempts->finished,
        );
    }

    public function testNoEndpointAndWrongProductBindingFailBeforeRemoteIo(): void
    {
        $reader = new QueuedEndpointInstallationReader([]);
        try {
            (new ReadConnectionWithFailover($reader))->read(
                self::target(ProxmoxProduct::Pve, []),
                null,
                new CountingConnectionReadCheckpoint(),
            );
            self::fail('A target without endpoints was accepted.');
        } catch (ConnectionReadFailure $failure) {
            self::assertSame(ConnectionReadFailureCode::NoEndpoints, $failure->failureCode);
            self::assertSame('The Proxmox connection has no enabled endpoint.', $failure->getMessage());
            self::assertNull($failure->endpointId);
            self::assertNull($failure->endpointFailureCode);
        }

        try {
            (new ReadConnectionWithFailover($reader))->read(
                self::target(ProxmoxProduct::Pve, [self::endpoint(1, 1)]),
                InstallationBinding::pbsLegacyNode('pbs3', new \App\Application\Inventory\Connection\EndpointId(str_repeat('l', 16))),
                new CountingConnectionReadCheckpoint(),
            );
            self::fail('A binding for the wrong product was accepted.');
        } catch (ConnectionReadFailure $failure) {
            self::assertSame(ConnectionReadFailureCode::InvalidBinding, $failure->failureCode);
            self::assertSame('The installation binding does not belong to the connection product.', $failure->getMessage());
        }

        self::assertSame([], $reader->endpointIds);
    }

    public function testExhaustionReportsOnlyStableOpaqueFailureContext(): void
    {
        $reader = new QueuedEndpointInstallationReader([
            EndpointReadFailure::for(EndpointReadFailureCode::Tls),
            self::pveSnapshot('wrong'),
        ]);

        try {
            (new ReadConnectionWithFailover($reader))->read(
                self::target(ProxmoxProduct::Pve, [self::endpoint(1, 1), self::endpoint(2, 2)]),
                InstallationBinding::pveCluster('expected', ['node-a']),
                new CountingConnectionReadCheckpoint(),
            );
            self::fail('Exhausted endpoints returned a result.');
        } catch (ConnectionReadFailure $failure) {
            self::assertSame(ConnectionReadFailureCode::EndpointsExhausted, $failure->failureCode);
            self::assertSame(self::endpointId(2)->bytes, $failure->endpointId?->bytes);
            self::assertSame(EndpointReadFailureCode::WrongIdentity, $failure->endpointFailureCode);
            self::assertSame('No eligible Proxmox endpoint produced a usable installation snapshot.', $failure->getMessage());
        }
    }

    public function testItCheckpointsBeforeAndAfterIoAndAttemptPersistence(): void
    {
        $reader = new QueuedEndpointInstallationReader([
            EndpointReadFailure::for(EndpointReadFailureCode::Transport),
            self::pveSnapshot('forest'),
        ]);
        $sink = new RecordingEndpointReadAttemptSink();
        $checkpoint = new CountingConnectionReadCheckpoint();

        $result = (new ReadConnectionWithFailover($reader))->read(
            self::target(ProxmoxProduct::Pve, [self::endpoint(1, 1), self::endpoint(2, 2)]),
            InstallationBinding::pveCluster('forest', ['node-a']),
            $checkpoint,
            $sink,
        );

        self::assertSame(self::endpointId(2)->bytes, $result->endpointId->bytes);
        self::assertSame(6, $checkpoint->count);
        self::assertSame(
            [
                [1, EndpointReadAttemptOutcome::Failover, EndpointReadFailureCode::Transport],
                [2, EndpointReadAttemptOutcome::Selected, null],
            ],
            $sink->finished,
        );
    }

    public function testItPersistsATerminalAttemptBeforeReportingTheFailure(): void
    {
        $sink = new RecordingEndpointReadAttemptSink();
        $checkpoint = new CountingConnectionReadCheckpoint();

        try {
            (new ReadConnectionWithFailover(new QueuedEndpointInstallationReader([
                EndpointReadFailure::for(EndpointReadFailureCode::Authentication),
            ])))->read(
                self::target(ProxmoxProduct::Pve, [self::endpoint(1, 1)]),
                InstallationBinding::pveCluster('forest', ['node-a']),
                $checkpoint,
                $sink,
            );
            self::fail('A terminal endpoint failure was accepted.');
        } catch (ConnectionReadFailure $failure) {
            self::assertSame(ConnectionReadFailureCode::TerminalEndpointFailure, $failure->failureCode);
        }

        self::assertSame(3, $checkpoint->count);
        self::assertSame(
            [[1, EndpointReadAttemptOutcome::Terminal, EndpointReadFailureCode::Authentication]],
            $sink->finished,
        );
    }

    public function testLastFailoverEligibleFailureIsPersistedAsTerminalBecauseNoFailoverOccurs(): void
    {
        $sink = new RecordingEndpointReadAttemptSink();
        $checkpoint = new CountingConnectionReadCheckpoint();

        try {
            (new ReadConnectionWithFailover(new QueuedEndpointInstallationReader([
                EndpointReadFailure::for(EndpointReadFailureCode::Transport),
            ])))->read(
                self::target(ProxmoxProduct::Pve, [self::endpoint(1, 1)]),
                InstallationBinding::pveCluster('forest', ['node-a']),
                $checkpoint,
                $sink,
            );
            self::fail('An exhausted transport failure returned a snapshot.');
        } catch (ConnectionReadFailure $failure) {
            self::assertSame(ConnectionReadFailureCode::EndpointsExhausted, $failure->failureCode);
        }

        self::assertSame(
            [[1, EndpointReadAttemptOutcome::Terminal, EndpointReadFailureCode::Transport]],
            $sink->finished,
        );
    }

    public function testAttemptSinkIsOptionalButCheckpointIsAlwaysUsed(): void
    {
        $checkpoint = new CountingConnectionReadCheckpoint();
        $reader = new QueuedEndpointInstallationReader([self::pveSnapshot('forest')]);

        (new ReadConnectionWithFailover($reader))->read(
            self::target(ProxmoxProduct::Pve, [self::endpoint(1, 1)]),
            InstallationBinding::pveCluster('forest', ['node-a']),
            $checkpoint,
        );

        self::assertSame(2, $checkpoint->count);
        self::assertSame([$checkpoint], $reader->checkpoints);
    }

    public function testAttemptStartRequiresPositiveNumberAndStoresUtc(): void
    {
        $started = new EndpointReadAttemptStarted(
            self::endpointId(1),
            1,
            new DateTimeImmutable('2026-07-11T03:04:05+02:00'),
        );
        self::assertSame('2026-07-11T01:04:05+00:00', $started->startedAt->format('c'));

        $this->expectException(\InvalidArgumentException::class);
        new EndpointReadAttemptStarted(self::endpointId(1), 0, new DateTimeImmutable());
    }

    /** @param list<EndpointScanReference> $endpoints */
    private static function target(
        ProxmoxProduct $product,
        array $endpoints,
        int $revision = 1,
    ): ConnectionScanTarget {
        return new ConnectionScanTarget(new ConnectionId(str_repeat('c', 16)), $revision, $product, $endpoints);
    }

    /** @param int<0, 255> $byte */
    private static function endpoint(int $byte, int $priority): EndpointScanReference
    {
        return new EndpointScanReference(self::endpointId($byte), $priority);
    }

    /** @param int<0, 255> $byte */
    private static function endpointId(int $byte): EndpointId
    {
        return new EndpointId(str_repeat(chr($byte), 16));
    }

    /** @param non-empty-list<string> $nodes */
    private static function pveSnapshot(
        ?string $clusterName,
        bool $complete = true,
        array $nodes = ['node-a'],
    ): PveInstallationSnapshot
    {
        $topologyNodes = [];
        $resourceNodes = [];
        foreach ($nodes as $offset => $node) {
            $topologyNodes[] = new PveClusterNode($node, true, $offset + 1, 0 === $offset);
            $resourceNodes[] = new PveNodeResource($node, 'online');
        }

        return new PveInstallationSnapshot(
            new PveVersion(9, 0, 0, '9.0', '9.0.0', 'repo'),
            new PvePermissionAssessment($complete ? [] : [new PveMissingPermission(PveRequiredPermission::SystemAudit)]),
            new PveClusterTopology(
                PveClusterMode::Clustered,
                $clusterName,
                count($nodes),
                1,
                true,
                $topologyNodes,
                [],
            ),
            new PveResourceInventory($resourceNodes, [], [], []),
        );
    }

    private static function pbsSnapshot(int $major, string $node, ?string $instanceIdentity): PbsInstallationSnapshot
    {
        return new PbsInstallationSnapshot(
            new PbsVersion($major, 2, 0, sprintf('%d.2.0', $major), sprintf('%d.2', $major), 'repo'),
            $node,
            null,
            null === $instanceIdentity ? null : new PbsInstanceIdentity($instanceIdentity),
            PbsDatastoreScanScope::installationWide(),
            null,
            null,
            [],
            [],
            [],
        );
    }

    private static function completePbsInstanceSnapshot(string $node, string $instanceIdentity): PbsInstallationSnapshot
    {
        $configuration = new PbsDatastoreConfigurationSnapshot('stable', []);

        return new PbsInstallationSnapshot(
            new PbsVersion(4, 2, 0, '4.2.0', '4.2', 'repo'),
            $node,
            new PbsNodeStatus($node, 1, 100, 10, 100, 10, 90),
            new PbsInstanceIdentity($instanceIdentity),
            PbsDatastoreScanScope::installationWide(),
            $configuration,
            $configuration,
            [],
            [],
            [],
        );
    }
}

/** @internal */
final class QueuedEndpointInstallationReader implements EndpointInstallationReader
{
    /** @var list<PveInstallationSnapshot|PveInventorySnapshot|PbsInstallationSnapshot|EndpointReadFailure> */
    private array $outcomes;

    /** @var list<string> */
    public array $endpointIds = [];

    /** @var list<ConnectionId> */
    public array $connectionIds = [];

    /** @var list<ProxmoxProduct> */
    public array $products = [];

    /** @var list<int> */
    public array $expectedRevisions = [];

    /** @var list<ConnectionReadCheckpoint> */
    public array $checkpoints = [];

    /** @param list<PveInstallationSnapshot|PveInventorySnapshot|PbsInstallationSnapshot|EndpointReadFailure> $outcomes */
    public function __construct(array $outcomes)
    {
        $this->outcomes = $outcomes;
    }

    public function read(
        ConnectionId $connectionId,
        EndpointId $endpointId,
        int $expectedRevision,
        ProxmoxProduct $product,
        ConnectionReadCheckpoint $checkpoint,
    ): PveInstallationSnapshot|PveInventorySnapshot|PbsInstallationSnapshot {
        $this->connectionIds[] = $connectionId;
        $this->endpointIds[] = $endpointId->toHex();
        $this->expectedRevisions[] = $expectedRevision;
        $this->products[] = $product;
        $this->checkpoints[] = $checkpoint;
        $outcome = array_shift($this->outcomes);
        if ($outcome instanceof EndpointReadFailure) {
            throw $outcome;
        }
        if (null === $outcome) {
            throw new \RuntimeException('The fake endpoint reader has no queued outcome.');
        }

        return $outcome;
    }
}

/** @internal */
final class CountingConnectionReadCheckpoint implements ConnectionReadCheckpoint
{
    public int $count = 0;

    public function checkpoint(): void
    {
        ++$this->count;
    }
}

/** @internal */
final class RecordingEndpointReadAttemptSink implements EndpointReadAttemptSink
{
    /** @var list<array{int, EndpointReadAttemptOutcome, ?EndpointReadFailureCode}> */
    public array $finished = [];

    public function start(EndpointId $endpointId, int $attemptNumber): EndpointReadAttemptStarted
    {
        return new EndpointReadAttemptStarted(
            $endpointId,
            $attemptNumber,
            new DateTimeImmutable('2026-07-11T00:00:00Z'),
        );
    }

    public function finish(
        EndpointReadAttemptStarted $attempt,
        EndpointReadAttemptOutcome $outcome,
        ?EndpointReadFailureCode $failureCode,
    ): void {
        $this->finished[] = [$attempt->attemptNumber, $outcome, $failureCode];
    }
}
