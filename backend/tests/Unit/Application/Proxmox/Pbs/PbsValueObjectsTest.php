<?php

declare(strict_types=1);

namespace App\Tests\Unit\Application\Proxmox\Pbs;

use App\Application\Proxmox\Pbs\PbsCapacitySemantics;
use App\Application\Proxmox\Pbs\PbsDatastoreBackendType;
use App\Application\Proxmox\Pbs\PbsDatastoreCapacity;
use App\Application\Proxmox\Pbs\PbsDatastoreConfigurationSnapshot;
use App\Application\Proxmox\Pbs\PbsDatastoreDefinition;
use App\Application\Proxmox\Pbs\PbsDatastoreId;
use App\Application\Proxmox\Pbs\PbsDatastoreScanScope;
use App\Application\Proxmox\Pbs\PbsEffectivePermission;
use App\Application\Proxmox\Pbs\PbsInstallationSnapshot;
use App\Application\Proxmox\Pbs\PbsInstanceIdentity;
use App\Application\Proxmox\Pbs\PbsInventoryIssue;
use App\Application\Proxmox\Pbs\PbsInventoryIssueCode;
use App\Application\Proxmox\Pbs\PbsMaintenanceMode;
use App\Application\Proxmox\Pbs\PbsMountStatus;
use App\Application\Proxmox\Pbs\PbsNodeRoute;
use App\Application\Proxmox\Pbs\PbsNodeStatus;
use App\Application\Proxmox\Pbs\PbsReadFailure;
use App\Application\Proxmox\Pbs\PbsReadFailureCode;
use App\Application\Proxmox\Pbs\PbsVersion;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class PbsValueObjectsTest extends TestCase
{
    public function testVersionCapabilitiesAndIdentifiers(): void
    {
        self::assertFalse((new PbsVersion(3, 4, 4, '3.4.4', '1', 'abcdef12'))->supportsInstanceIdentity());
        self::assertFalse((new PbsVersion(4, 1, null, '4.1', '1', 'abcdef12'))->supportsInstanceIdentity());
        self::assertTrue((new PbsVersion(4, 2, 0, '4.2.0', '1', 'abcdef12'))->supportsInstanceIdentity());
        self::assertTrue((new PbsVersion(5, 0, 0, '5.0.0', '1', 'abcdef12'))->supportsInstanceIdentity());
        self::assertSame('store_1', (new PbsDatastoreId('store_1'))->value);
        self::assertSame('_ab', (new PbsDatastoreId('_ab'))->value);
        self::assertSame('0123456789abcdef0123456789abcdef', (new PbsInstanceIdentity('0123456789abcdef0123456789abcdef'))->value);

        foreach (['', 'ab', '-bad', 'bad/', str_repeat('a', 33)] as $invalid) {
            try { new PbsDatastoreId($invalid); self::fail('invalid datastore'); } catch (InvalidArgumentException) {}
        }
        foreach (['', str_repeat('a', 31), str_repeat('A', 32), str_repeat('g', 32)] as $invalid) {
            try { new PbsInstanceIdentity($invalid); self::fail('invalid identity'); } catch (InvalidArgumentException) {}
        }
    }

    public function testScopesPermissionsAndConfigurationComparison(): void
    {
        $wide = PbsDatastoreScanScope::installationWide();
        self::assertTrue($wide->installationWide);
        self::assertSame([], $wide->datastores);
        $explicit = PbsDatastoreScanScope::explicit([new PbsDatastoreId('store_b'), new PbsDatastoreId('store_a')]);
        self::assertSame(['store_a', 'store_b'], array_map(static fn (PbsDatastoreId $id): string => $id->value, $explicit->datastores));
        try {
            /** @phpstan-ignore argument.type */
            PbsDatastoreScanScope::explicit([]);
            self::fail('empty scope');
        } catch (InvalidArgumentException) {}
        try {
            PbsDatastoreScanScope::explicit([new PbsDatastoreId('same_id'), new PbsDatastoreId('same_id')]);
            self::fail('duplicate scope');
        } catch (InvalidArgumentException) {}

        $permission = new PbsEffectivePermission('/datastore', ['Datastore.Audit' => true, 'Sys.Audit' => false]);
        self::assertTrue($permission->grants('Datastore.Audit'));
        self::assertTrue($permission->propagates('Datastore.Audit'));
        self::assertTrue($permission->grants('Sys.Audit'));
        self::assertFalse($permission->propagates('Sys.Audit'));
        self::assertFalse($permission->grants('Missing'));
        self::assertFalse($permission->propagates('Missing'));

        $a = new PbsDatastoreConfigurationSnapshot(str_repeat('a', 64), [new PbsDatastoreId('store_a')]);
        self::assertTrue($a->sameConfiguration(new PbsDatastoreConfigurationSnapshot(str_repeat('a', 64), [new PbsDatastoreId('store_a')])));
        self::assertFalse($a->sameConfiguration(new PbsDatastoreConfigurationSnapshot(str_repeat('b', 64), [new PbsDatastoreId('store_a')])));
        self::assertFalse($a->sameConfiguration(new PbsDatastoreConfigurationSnapshot(str_repeat('a', 64), [new PbsDatastoreId('store_b')])));
    }

    public function testDatastoreAvailabilityAndCapacitySemanticsFailClosed(): void
    {
        self::assertTrue(PbsMountStatus::Mounted->isAvailable());
        self::assertTrue(PbsMountStatus::NonRemovable->isAvailable());
        self::assertFalse(PbsMountStatus::NotMounted->isAvailable());
        $id = new PbsDatastoreId('store_a');
        self::assertTrue((new PbsDatastoreDefinition($id, PbsDatastoreBackendType::Filesystem, PbsMountStatus::Mounted, null))->allowsBackupWrites());
        self::assertFalse((new PbsDatastoreDefinition($id, PbsDatastoreBackendType::Filesystem, PbsMountStatus::NotMounted, null))->allowsBackupWrites());
        self::assertFalse((new PbsDatastoreDefinition($id, PbsDatastoreBackendType::Filesystem, PbsMountStatus::Mounted, PbsMaintenanceMode::ReadOnly))->allowsBackupWrites());

        $filesystem = new PbsDatastoreCapacity($id, PbsDatastoreBackendType::Filesystem, 100, 20, 80);
        self::assertSame(PbsCapacitySemantics::DatastoreFilesystem, $filesystem->semantics);
        self::assertTrue($filesystem->maySatisfyTargetFreeSpaceGate());
        $s3 = new PbsDatastoreCapacity($id, PbsDatastoreBackendType::S3, 100, 20, 80);
        self::assertSame(PbsCapacitySemantics::LocalCache, $s3->semantics);
        self::assertFalse($s3->maySatisfyTargetFreeSpaceGate());
    }

    public function testSnapshotCompletenessRequiresAllCapabilityEvidence(): void
    {
        $version3 = new PbsVersion(3, 4, 4, '3.4.4', '1', 'abcdef12');
        $version4 = new PbsVersion(4, 2, 2, '4.2.2', '1', 'abcdef12');
        $scope = PbsDatastoreScanScope::installationWide();
        $id = new PbsDatastoreId('store_a');
        $config = new PbsDatastoreConfigurationSnapshot(str_repeat('a', 64), [$id]);
        $node = new PbsNodeStatus(PbsNodeRoute::Local->value, 1, 2, 1, 10, 2, 8);
        $definition = new PbsDatastoreDefinition($id, PbsDatastoreBackendType::Filesystem, PbsMountStatus::Mounted, null);
        $capacity = new PbsDatastoreCapacity($id, PbsDatastoreBackendType::Filesystem, 10, 2, 8);
        $snapshot = new PbsInstallationSnapshot($version3, $node, null, $scope, $config, $config, [$definition], [$capacity], []);
        self::assertSame(PbsNodeRoute::Local->value, $snapshot->node);
        self::assertTrue($snapshot->isComplete());
        self::assertTrue($snapshot->permitsDeletionDecisions());
        self::assertFalse((new PbsInstallationSnapshot($version4, $node, null, $scope, $config, $config, [$definition], [$capacity], []))->isComplete());
        self::assertFalse((new PbsInstallationSnapshot($version3, null, null, $scope, $config, $config, [$definition], [$capacity], []))->isComplete());
        self::assertFalse((new PbsInstallationSnapshot($version3, $node, null, $scope, null, $config, [$definition], [$capacity], []))->isComplete());
        self::assertFalse((new PbsInstallationSnapshot($version3, $node, null, $scope, $config, null, [$definition], [$capacity], []))->isComplete());
        self::assertFalse((new PbsInstallationSnapshot($version3, $node, null, $scope, $config, new PbsDatastoreConfigurationSnapshot(str_repeat('b', 64), [$id]), [$definition], [$capacity], []))->isComplete());
        self::assertFalse((new PbsInstallationSnapshot($version3, $node, null, $scope, $config, $config, [$definition], [], []))->isComplete());
        self::assertFalse((new PbsInstallationSnapshot($version3, $node, null, $scope, $config, $config, [$definition], [$capacity], [new PbsInventoryIssue(PbsInventoryIssueCode::ConfigurationChanged, '/config/datastore')]))->permitsDeletionDecisions());
    }

    public function testSnapshotCompletenessRequiresExactAndConsistentEvidenceSets(): void
    {
        $version = new PbsVersion(3, 4, 4, '3.4.4', '1', 'abcdef12');
        $scope = PbsDatastoreScanScope::installationWide();
        $a = new PbsDatastoreId('store_a');
        $b = new PbsDatastoreId('store_b');
        $config = new PbsDatastoreConfigurationSnapshot(str_repeat('a', 64), [$a]);
        $node = new PbsNodeStatus(PbsNodeRoute::Local->value, 1, 2, 1, 10, 2, 8);
        $wrongNode = new PbsNodeStatus('other', 1, 2, 1, 10, 2, 8);
        $definitionA = new PbsDatastoreDefinition($a, PbsDatastoreBackendType::Filesystem, PbsMountStatus::Mounted, null);
        $definitionB = new PbsDatastoreDefinition($b, PbsDatastoreBackendType::Filesystem, PbsMountStatus::Mounted, null);
        $capacityA = new PbsDatastoreCapacity($a, PbsDatastoreBackendType::Filesystem, 10, 2, 8);
        $capacityB = new PbsDatastoreCapacity($b, PbsDatastoreBackendType::Filesystem, 10, 2, 8);

        self::assertFalse((new PbsInstallationSnapshot($version, $wrongNode, null, $scope, $config, $config, [$definitionA], [$capacityA], []))->isComplete());
        self::assertFalse((new PbsInstallationSnapshot($version, $node, null, $scope, $config, $config, [$definitionB], [$capacityB], []))->isComplete());
        self::assertFalse((new PbsInstallationSnapshot($version, $node, null, $scope, $config, $config, [$definitionA], [$capacityB], []))->isComplete());

        foreach ([
            static fn () => new PbsInstallationSnapshot($version, $node, null, $scope, $config, $config, [$definitionA, $definitionA], [$capacityA], []),
            static fn () => new PbsInstallationSnapshot($version, $node, null, $scope, $config, $config, [$definitionA], [$capacityA, $capacityA], []),
            static fn () => new PbsInstallationSnapshot($version, $node, null, $scope, $config, $config, [$definitionA], [new PbsDatastoreCapacity($a, PbsDatastoreBackendType::S3, 10, 2, 8)], []),
            /** @phpstan-ignore argument.type */
            static fn () => new PbsInstallationSnapshot($version, $node, null, $scope, $config, $config, [new \stdClass()], [], []),
            /** @phpstan-ignore argument.type */
            static fn () => new PbsInstallationSnapshot($version, $node, null, $scope, $config, $config, [], [new \stdClass()], []),
        ] as $invalidSnapshot) {
            try {
                $invalidSnapshot();
                self::fail('Invalid snapshot evidence.');
            } catch (InvalidArgumentException) {
            }
        }
    }

    public function testFailureIsTypedAndSecretFree(): void
    {
        $failure = PbsReadFailure::for(PbsReadFailureCode::Authentication);
        self::assertSame(PbsReadFailureCode::Authentication, $failure->failureCode);
        self::assertSame('The PBS read operation failed.', $failure->getMessage());
    }
}
