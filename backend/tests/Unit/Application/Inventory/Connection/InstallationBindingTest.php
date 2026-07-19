<?php

declare(strict_types=1);

namespace App\Tests\Unit\Application\Inventory\Connection;

use App\Application\Inventory\Connection\EndpointId;
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
use App\Application\Proxmox\Pve\PveInventorySnapshot;
use App\Application\Proxmox\Pve\PvePermissionAssessment;
use App\Application\Proxmox\Pve\PveResourceInventory;
use App\Application\Proxmox\Pve\PveStorageInventorySnapshot;
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
        $pbs4 = InstallationBinding::pbsInstance(str_repeat('a', 32));
        $pbs3 = InstallationBinding::pbsLegacyEndpoint(new \App\Application\Inventory\Connection\EndpointId(str_repeat('l', 16)));

        self::assertSame(ProxmoxProduct::Pve, $cluster->product);
        self::assertSame(InstallationBindingKind::PveCluster, $cluster->kind);
        self::assertSame('forest', $cluster->identity);
        self::assertSame(['node-a', 'node-b'], $cluster->knownMemberNodes);
        self::assertSame(InstallationBindingKind::PveStandalone, $standalone->kind);
        self::assertSame(InstallationBindingKind::PbsInstance, $pbs4->kind);
        self::assertSame(InstallationBindingKind::PbsLegacyNode, $pbs3->kind);
        self::assertSame(ProxmoxProduct::Pbs, $pbs3->product);
        self::assertSame('localhost', $pbs3->identity);
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
        foreach ([str_repeat('A', 32), 'too-short'] as $identity) {
            try {
                InstallationBinding::pbsInstance($identity);
                self::fail('An invalid PBS instance identity was accepted.');
            } catch (InvalidArgumentException $failure) {
                self::assertSame('The PBS 4 instance identity is invalid.', $failure->getMessage());
            }
        }
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

    public function testConstructorRejectsInconsistentLegacyEndpointState(): void
    {
        $constructor = (new \ReflectionClass(InstallationBinding::class))->getConstructor();
        self::assertNotNull($constructor);

        foreach ([
            [ProxmoxProduct::Pbs, InstallationBindingKind::PbsLegacyNode, 'pbs3', [], null,
                'A legacy PBS binding requires its exact endpoint.'],
            [ProxmoxProduct::Pbs, InstallationBindingKind::PbsLegacyNode, 'pbs3', [], new EndpointId(str_repeat('e', 16)),
                'A legacy PBS binding requires the canonical local identity.'],
            [ProxmoxProduct::Pve, InstallationBindingKind::PveStandalone, 'node', [], new EndpointId(str_repeat('e', 16)),
                'Only a legacy PBS binding may carry an endpoint.'],
        ] as [$product, $kind, $identity, $nodes, $endpoint, $message]) {
            $binding = (new \ReflectionClass(InstallationBinding::class))->newInstanceWithoutConstructor();
            try {
                $constructor->invoke($binding, $product, $kind, $identity, $nodes, $endpoint);
                self::fail('An inconsistent installation binding was accepted.');
            } catch (InvalidArgumentException $failure) {
                self::assertSame($message, $failure->getMessage());
            }
        }
    }

    public function testEqualityIncludesProductKindAndIdentity(): void
    {
        self::assertTrue(InstallationBinding::pveCluster('same', ['aa'])->equals(InstallationBinding::pveCluster('same', ['aa'])));
        self::assertFalse(InstallationBinding::pveCluster('same', ['aa'])->equals(InstallationBinding::pveCluster('same', ['bb'])));
        self::assertFalse(InstallationBinding::pveCluster('same', ['aa'])->equals(InstallationBinding::pveCluster('other', ['aa'])));
        self::assertFalse(InstallationBinding::pveCluster('same', ['aa'])->equals(InstallationBinding::pveStandalone('same')));
        self::assertFalse(InstallationBinding::pveCluster('same', ['aa'])->equals(InstallationBinding::pbsLegacyEndpoint(new \App\Application\Inventory\Connection\EndpointId(str_repeat('l', 16)))));
        self::assertFalse(InstallationBinding::pbsLegacyEndpoint(new EndpointId(str_repeat('l', 16)))->equals(
            InstallationBinding::pbsLegacyEndpoint(new EndpointId(str_repeat('x', 16))),
        ));

        self::assertTrue(InstallationBinding::pveCluster('same', ['aa', 'bb'])->matchesObservation(
            InstallationBinding::pveCluster('same', ['bb', 'cc']),
        ));
        self::assertFalse(InstallationBinding::pveCluster('same', ['aa'])->matchesObservation(
            InstallationBinding::pveCluster('same', ['bb']),
        ));
        self::assertFalse(InstallationBinding::pveCluster('same', ['aa'])->matchesObservation(
            InstallationBinding::pbsLegacyEndpoint(new EndpointId(str_repeat('l', 16))),
        ));
        self::assertFalse(InstallationBinding::pveCluster('same', ['aa'])->matchesObservation(
            InstallationBinding::pveStandalone('same'),
        ));
        self::assertFalse(InstallationBinding::pveStandalone('same')->matchesObservation(
            InstallationBinding::pveCluster('same', ['same']),
        ));
        self::assertFalse(InstallationBinding::pveCluster('same', ['aa'])->matchesObservation(
            InstallationBinding::pveCluster('other', ['aa']),
        ));
        $legacy = InstallationBinding::pbsLegacyEndpoint(new EndpointId(str_repeat('l', 16)));
        self::assertTrue($legacy->matchesObservation(InstallationBinding::pbsLegacyEndpoint(new EndpointId(str_repeat('l', 16)))));
        self::assertFalse($legacy->matchesObservation(InstallationBinding::pbsLegacyEndpoint(new EndpointId(str_repeat('x', 16)))));
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

    public function testItExtractsPveIdentityFromTheCompositeCoreSnapshot(): void
    {
        $core = self::pveSnapshot(new PveClusterTopology(
            PveClusterMode::Clustered,
            'forest',
            1,
            1,
            true,
            [new PveClusterNode('node-a', true, 1, true)],
            [],
        ));
        $composite = new PveInventorySnapshot(
            $core,
            new PveStorageInventorySnapshot(null, null, [], []),
            ['node-a'],
        );

        $binding = InstallationBinding::fromSnapshot($composite);

        self::assertNotNull($binding);
        self::assertTrue(InstallationBinding::pveCluster('forest', ['node-a'])->equals($binding));
    }

    public function testItExtractsPbsLegacyNodeAndPbsInstanceBindingsOnly(): void
    {
        $pbs3 = self::pbsSnapshot(new PbsVersion(3, 4, 1, '3.4.1', '3.4', 'repo'), null);
        $pbs4 = self::pbsSnapshot(
            new PbsVersion(4, 2, 0, '4.2.0', '4.2', 'repo'),
            new PbsInstanceIdentity(str_repeat('b', 32)),
        );
        $pbs4WithoutIdentity = self::pbsSnapshot(new PbsVersion(4, 1, 0, '4.1.0', '4.1', 'repo'), null);
        $pbs5 = self::pbsSnapshot(
            new PbsVersion(5, 0, 0, '5.0.0', '5.0', 'repo'),
            new PbsInstanceIdentity(str_repeat('c', 32)),
        );

        $legacyEndpoint = new EndpointId(str_repeat('l', 16));
        $pbs3Binding = InstallationBinding::fromSnapshot($pbs3, $legacyEndpoint);
        $pbs4Binding = InstallationBinding::fromSnapshot($pbs4);
        self::assertNotNull($pbs3Binding);
        self::assertNotNull($pbs4Binding);
        self::assertTrue(InstallationBinding::pbsLegacyEndpoint($legacyEndpoint)->equals($pbs3Binding));
        self::assertTrue(InstallationBinding::pbsInstance(str_repeat('b', 32))->equals($pbs4Binding));
        $pbs41Binding = InstallationBinding::fromSnapshot($pbs4WithoutIdentity, $legacyEndpoint);
        self::assertNotNull($pbs41Binding);
        self::assertTrue(InstallationBinding::pbsLegacyEndpoint($legacyEndpoint)->equals($pbs41Binding));
        self::assertNull(InstallationBinding::fromSnapshot($pbs5));
        self::assertNull(InstallationBinding::fromSnapshot($pbs3));
        self::assertNull(InstallationBinding::fromSnapshot(self::pbsSnapshot(
            new PbsVersion(4, 2, 0, '4.2.0', '4.2', 'repo'),
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
        ?PbsInstanceIdentity $identity,
    ): PbsInstallationSnapshot {
        return new PbsInstallationSnapshot(
            $version,
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
