<?php

declare(strict_types=1);

namespace App\Tests\Unit\Application\Inventory\Connection;

use App\Application\Inventory\Connection\InstallationBinding;
use App\Application\Inventory\Connection\InstallationBindingKind;
use App\Application\Inventory\Connection\InstallationIdentityValidator;
use App\Application\Inventory\Connection\ProxmoxProduct;
use App\Application\Proxmox\Pbs\PbsDatastoreScanScope;
use App\Application\Proxmox\Pbs\PbsInstallationSnapshot;
use App\Application\Proxmox\Pbs\PbsInstanceIdentity;
use App\Application\Proxmox\Pbs\PbsVersion;
use App\Application\Proxmox\Pve\PveClusterMode;
use App\Application\Proxmox\Pve\PveClusterNode;
use App\Application\Proxmox\Pve\PveClusterTopology;
use App\Application\Proxmox\Pve\PveInstallationSnapshot;
use App\Application\Proxmox\Pve\PvePermissionAssessment;
use App\Application\Proxmox\Pve\PveResourceInventory;
use App\Application\Proxmox\Pve\PveVersion;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class InstallationBindingTest extends TestCase
{
    public function testFactoriesExposeExplicitProductKindsAndIdentity(): void
    {
        $cluster = InstallationBinding::pveCluster('forest', ['node-b', 'node-a']);
        $standalone = InstallationBinding::pveStandalone('solo.test');
        $pbs4 = InstallationBinding::pbs4Instance(str_repeat('a', 32));
        $pbs3 = InstallationBinding::pbs3Node('pbs3.test');

        self::assertSame(ProxmoxProduct::Pve, $cluster->product);
        self::assertSame(InstallationBindingKind::PveCluster, $cluster->kind);
        self::assertSame('forest', $cluster->identity);
        self::assertSame(['node-a', 'node-b'], $cluster->knownMemberNodes);
        self::assertSame(InstallationBindingKind::PveStandalone, $standalone->kind);
        self::assertSame(InstallationBindingKind::Pbs4Instance, $pbs4->kind);
        self::assertSame(InstallationBindingKind::Pbs3Node, $pbs3->kind);
        self::assertSame(ProxmoxProduct::Pbs, $pbs3->product);
    }

    public function testSingleCharacterInstallationNamesRemainValidWhenAlphanumeric(): void
    {
        self::assertTrue(InstallationIdentityValidator::isValid('a'));
        self::assertFalse(InstallationIdentityValidator::isValid('@'));
        self::assertTrue(InstallationIdentityValidator::isValid('9node_a.test'));
        self::assertFalse(InstallationIdentityValidator::isValid('node/name'));
    }

    #[DataProvider('invalidIdentityProvider')]
    public function testGenericBindingIdentitiesAreBoundedAndSafe(string $identity): void
    {
        $this->expectException(InvalidArgumentException::class);
        InstallationBinding::pveCluster($identity, ['node-a']);
    }

    /** @return iterable<string, array{string}> */
    public static function invalidIdentityProvider(): iterable
    {
        yield 'empty' => [''];
        yield 'leading whitespace' => [' cluster'];
        yield 'trailing whitespace' => ['cluster '];
        yield 'control' => ["cluster\nname"];
        yield 'delete' => ["cluster\x7f"];
        yield 'too long' => [str_repeat('a', 256)];
    }

    public function testPbs4BindingRequiresCanonicalInstanceIdentity(): void
    {
        $this->expectException(InvalidArgumentException::class);
        InstallationBinding::pbs4Instance(str_repeat('A', 32));
    }

    public function testClusterBindingRequiresAtLeastOneValidKnownMember(): void
    {
        try {
            /** @phpstan-ignore argument.type (exercise the runtime boundary) */
            InstallationBinding::pveCluster('forest', []);
            self::fail('An empty cluster membership was accepted.');
        } catch (InvalidArgumentException $failure) {
            self::assertSame('A clustered PVE binding requires known member nodes.', $failure->getMessage());
        }

        try {
            InstallationBinding::pveCluster('forest', ["node\nname"]);
            self::fail('An invalid member name was accepted.');
        } catch (InvalidArgumentException $failure) {
            self::assertSame('A clustered PVE member node is invalid.', $failure->getMessage());
        }
    }

    public function testEqualityIncludesProductKindAndIdentity(): void
    {
        self::assertTrue(InstallationBinding::pveCluster('same', ['aa'])->equals(InstallationBinding::pveCluster('same', ['aa'])));
        self::assertFalse(InstallationBinding::pveCluster('same', ['aa'])->equals(InstallationBinding::pveCluster('same', ['bb'])));
        self::assertFalse(InstallationBinding::pveCluster('same', ['aa'])->equals(InstallationBinding::pveCluster('other', ['aa'])));
        self::assertFalse(InstallationBinding::pveCluster('same', ['aa'])->equals(InstallationBinding::pveStandalone('same')));
        self::assertFalse(InstallationBinding::pveCluster('same', ['aa'])->equals(InstallationBinding::pbs3Node('same')));

        self::assertTrue(InstallationBinding::pveCluster('same', ['aa', 'bb'])->matchesObservation(
            InstallationBinding::pveCluster('same', ['bb', 'cc']),
        ));
        self::assertFalse(InstallationBinding::pveCluster('same', ['aa'])->matchesObservation(
            InstallationBinding::pveCluster('same', ['bb']),
        ));
        self::assertTrue(InstallationBinding::pbs3Node('same')->matchesObservation(InstallationBinding::pbs3Node('same')));
    }

    public function testItExtractsPveClusterAndStrictStandaloneBindings(): void
    {
        $cluster = self::pveSnapshot(new PveClusterTopology(
            PveClusterMode::Clustered,
            'forest',
            1,
            1,
            false,
            [new PveClusterNode('node-a', true, 1, true)],
            [],
        ));
        $standalone = self::pveSnapshot(new PveClusterTopology(
            PveClusterMode::Standalone,
            null,
            null,
            null,
            null,
            [new PveClusterNode('solo', false, 0, true)],
            [],
        ));

        $clusterBinding = InstallationBinding::fromSnapshot($cluster);
        $standaloneBinding = InstallationBinding::fromSnapshot($standalone);
        self::assertNotNull($clusterBinding);
        self::assertNotNull($standaloneBinding);
        self::assertTrue(InstallationBinding::pveCluster('forest', ['node-a'])->equals($clusterBinding));
        self::assertTrue(InstallationBinding::pveStandalone('solo')->equals($standaloneBinding));

        self::assertNull(InstallationBinding::fromSnapshot(self::pveSnapshot(new PveClusterTopology(
            PveClusterMode::Clustered,
            null,
            null,
            null,
            null,
            [],
            [],
        ))));
        self::assertNull(InstallationBinding::fromSnapshot(self::pveSnapshot(new PveClusterTopology(
            PveClusterMode::Clustered,
            ' forest ',
            1,
            1,
            true,
            [new PveClusterNode('node-a', true, 1, true)],
            [],
        ))));
        self::assertNull(InstallationBinding::fromSnapshot(self::pveSnapshot(new PveClusterTopology(
            PveClusterMode::Clustered,
            'forest',
            0,
            1,
            true,
            [],
            [],
        ))));
        self::assertNull(InstallationBinding::fromSnapshot(self::pveSnapshot(new PveClusterTopology(
            PveClusterMode::Clustered,
            'forest',
            1,
            1,
            true,
            [new PveClusterNode("node\nname", true, 1, true)],
            [],
        ))));
        self::assertNull(InstallationBinding::fromSnapshot(self::pveSnapshot(new PveClusterTopology(
            PveClusterMode::Standalone,
            null,
            null,
            null,
            null,
            [],
            [],
        ))));
        self::assertNull(InstallationBinding::fromSnapshot(self::pveSnapshot(new PveClusterTopology(
            PveClusterMode::Standalone,
            null,
            null,
            null,
            null,
            [new PveClusterNode('solo', true, 0, false)],
            [],
        ))));
        self::assertNull(InstallationBinding::fromSnapshot(self::pveSnapshot(new PveClusterTopology(
            PveClusterMode::Standalone,
            null,
            null,
            null,
            null,
            [new PveClusterNode('solo', true, 1, true)],
            [],
        ))));
        self::assertNull(InstallationBinding::fromSnapshot(self::pveSnapshot(new PveClusterTopology(
            PveClusterMode::Standalone,
            null,
            null,
            null,
            null,
            [new PveClusterNode("solo\n", true, 0, true)],
            [],
        ))));
    }

    public function testItExtractsPbs3NodeAndPbs4InstanceBindingsOnly(): void
    {
        $pbs3 = self::pbsSnapshot(new PbsVersion(3, 4, 1, '3.4.1', '3.4', 'repo'), 'pbs3', null);
        $pbs4 = self::pbsSnapshot(
            new PbsVersion(4, 2, 0, '4.2.0', '4.2', 'repo'),
            'pbs4',
            new PbsInstanceIdentity(str_repeat('b', 32)),
        );
        $pbs4WithoutIdentity = self::pbsSnapshot(new PbsVersion(4, 1, 0, '4.1.0', '4.1', 'repo'), 'pbs4', null);
        $pbs5 = self::pbsSnapshot(
            new PbsVersion(5, 0, 0, '5.0.0', '5.0', 'repo'),
            'pbs5',
            new PbsInstanceIdentity(str_repeat('c', 32)),
        );

        $pbs3Binding = InstallationBinding::fromSnapshot($pbs3);
        $pbs4Binding = InstallationBinding::fromSnapshot($pbs4);
        self::assertNotNull($pbs3Binding);
        self::assertNotNull($pbs4Binding);
        self::assertTrue(InstallationBinding::pbs3Node('pbs3')->equals($pbs3Binding));
        self::assertTrue(InstallationBinding::pbs4Instance(str_repeat('b', 32))->equals($pbs4Binding));
        self::assertNull(InstallationBinding::fromSnapshot($pbs4WithoutIdentity));
        self::assertNull(InstallationBinding::fromSnapshot($pbs5));
        self::assertNull(InstallationBinding::fromSnapshot(self::pbsSnapshot(
            new PbsVersion(3, 4, 1, '3.4.1', '3.4', 'repo'),
            "pbs3\n",
            null,
        )));
    }

    private static function pveSnapshot(PveClusterTopology $topology): PveInstallationSnapshot
    {
        return new PveInstallationSnapshot(
            new PveVersion(9, 0, 0, '9.0', '9.0.0', 'repo'),
            new PvePermissionAssessment([]),
            $topology,
            new PveResourceInventory([], [], [], []),
        );
    }

    private static function pbsSnapshot(
        PbsVersion $version,
        string $node,
        ?PbsInstanceIdentity $identity,
    ): PbsInstallationSnapshot {
        return new PbsInstallationSnapshot(
            $version,
            $node,
            null,
            $identity,
            PbsDatastoreScanScope::installationWide(),
            null,
            null,
            [],
            [],
            [],
        );
    }
}
