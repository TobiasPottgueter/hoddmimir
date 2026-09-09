<?php

declare(strict_types=1);

namespace App\Tests\Unit\Application\Inventory\Capability;

use App\Application\Inventory\Capability\BuildVerifiedCapabilityProfile;
use App\Application\Inventory\Capability\CapabilityProfile;
use App\Application\Inventory\Capability\CapabilitySnapshotConflict;
use App\Application\Inventory\Capability\CapabilitySnapshotObservation;
use App\Application\Inventory\Connection\ConnectionId;
use App\Application\Inventory\Connection\ConnectionInstallationRead;
use App\Application\Inventory\Connection\EndpointId;
use App\Application\Inventory\Connection\InstallationBinding;
use App\Application\Inventory\Connection\ProxmoxProduct;
use App\Application\Inventory\InventoryIdentifier;
use App\Application\Proxmox\Pbs\PbsDatastoreConfigurationSnapshot;
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
use DateTimeImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class CapabilityProfileTest extends TestCase
{
    #[DataProvider('pveVersions')]
    public function testBuildsOnlyVerifiedPveContracts(
        int $major,
        string $responseContract,
        bool $legacyMaxFiles,
        string $pruneShape,
    ): void {
        $endpoint = self::endpoint('pve-endpoint');
        $snapshot = self::pveSnapshot($major);
        $readSnapshot = 9 === $major
            ? new PveInventorySnapshot($snapshot, new PveStorageInventorySnapshot(null, null, [], []), ['pve-a'])
            : $snapshot;
        $profile = (new BuildVerifiedCapabilityProfile())->build(new ConnectionInstallationRead(
            new ConnectionId(self::bytes('connection')),
            1,
            $endpoint,
            InstallationBinding::pveStandalone('pve-a'),
            $readSnapshot,
        ));

        self::assertSame(ProxmoxProduct::Pve, $profile->product);
        self::assertSame($major, $profile->versionMajor);
        self::assertSame([
            'backupJobResponseContract' => $responseContract,
            'legacyMaxFilesSupported' => $legacyMaxFiles,
            'pruneResponseShape' => $pruneShape,
        ], $profile->capabilities);
    }

    /** @return iterable<string, array{int, string, bool, string}> */
    public static function pveVersions(): iterable
    {
        yield 'PVE 7' => [7, 'baseline_id_only', true, 'legacy_string_or_object'];
        yield 'PVE 8' => [8, 'baseline_id_only', true, 'legacy_string_or_object'];
        yield 'PVE 9' => [9, 'selected_typed_fields', false, 'object'];
    }

    #[DataProvider('pbsVersions')]
    public function testBuildsOnlyVerifiedPbsContracts(int $major, int $minor, bool $identitySupported): void
    {
        $endpoint = self::endpoint('pbs-endpoint');
        $snapshot = self::pbsSnapshot($major, $minor, $identitySupported);
        $binding = $identitySupported
            ? InstallationBinding::pbsInstance(str_repeat('a', 32))
            : InstallationBinding::pbsLegacyEndpoint($endpoint);

        $profile = (new BuildVerifiedCapabilityProfile())->build(new ConnectionInstallationRead(
            new ConnectionId(self::bytes('connection')),
            1,
            $endpoint,
            $binding,
            $snapshot,
        ));

        self::assertSame(ProxmoxProduct::Pbs, $profile->product);
        self::assertSame([
            'instanceIdentitySupported' => $identitySupported,
            'versionContract' => 'pbs_'.$major,
        ], $profile->capabilities);
    }

    /** @return iterable<string, array{int, int, bool}> */
    public static function pbsVersions(): iterable
    {
        yield 'PBS 3' => [3, 4, false];
        yield 'PBS 4 before identity endpoint' => [4, 1, false];
        yield 'PBS 4 with identity endpoint' => [4, 2, true];
    }

    public function testCanonicalJsonAndHashAreStableAcrossInputOrderAndTimezone(): void
    {
        $first = new CapabilityProfile(ProxmoxProduct::Pve, 9, 0, 1, '1', '9.0.1', ['zeta' => true, 'alpha' => 'v']);
        $second = new CapabilityProfile(ProxmoxProduct::Pve, 9, 0, 1, '1', '9.0.1', ['alpha' => 'v', 'zeta' => true]);
        $endpoint = self::endpoint('endpoint');
        $left = new CapabilitySnapshotObservation(
            new InventoryIdentifier(self::bytes('run')),
            new InventoryIdentifier(self::bytes('connection')),
            2,
            $endpoint,
            $first,
            new DateTimeImmutable('2026-07-12T12:34:56.123456+02:00'),
        );
        $right = new CapabilitySnapshotObservation(
            new InventoryIdentifier(self::bytes('run')),
            new InventoryIdentifier(self::bytes('connection')),
            2,
            $endpoint,
            $second,
            new DateTimeImmutable('2026-07-12T10:34:56.123456Z'),
        );

        self::assertSame('{"alpha":"v","zeta":true}', $first->capabilitiesJson());
        self::assertSame($left->snapshotHash(), $right->snapshotHash());
        self::assertSame(32, strlen($left->snapshotHash()));
        self::assertSame('UTC', $left->observedAt->getTimezone()->getName());
    }

    public function testAllAcceptedProfileBoundariesAndCanonicalFieldsAreExact(): void
    {
        $profile = new CapabilityProfile(
            ProxmoxProduct::Pbs,
            1,
            2,
            3,
            str_repeat('r', 128),
            str_repeat('v', 255),
            [
                'a' => 'https://pbs.example.test/api2/json',
                str_repeat('z', 64) => true,
            ],
        );

        self::assertSame(
            '{"a":"https://pbs.example.test/api2/json","'.str_repeat('z', 64).'":true}',
            $profile->capabilitiesJson(),
        );
        self::assertSame([
            'capabilities' => [
                'a' => 'https://pbs.example.test/api2/json',
                str_repeat('z', 64) => true,
            ],
            'endpointId' => str_repeat('ab', 16),
            'product' => 'pbs',
            'profileVersion' => 1,
            'rawVersion' => str_repeat('v', 255),
            'releaseName' => str_repeat('r', 128),
            'versionMajor' => 1,
            'versionMinor' => 2,
            'versionPatch' => 3,
        ], $profile->canonicalDocument(str_repeat('ab', 16)));
    }

    public function testObservationHashUsesTheFullCanonicalUnescapedDocument(): void
    {
        $endpoint = new EndpointId(pack('H*', str_repeat('ab', 16)));
        $profile = new CapabilityProfile(
            ProxmoxProduct::Pve,
            9,
            1,
            null,
            null,
            '9.1/edge',
            ['contract' => 'https://example.test/v1'],
        );
        $observation = $this->observation($endpoint, $profile);
        $expectedJson = json_encode(
            $profile->canonicalDocument($endpoint->toHex()),
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES,
        );

        self::assertSame(hash('sha256', $expectedJson, true), $observation->snapshotHash());
    }

    public function testCapabilitySnapshotConflictsPreserveTypeMessageCodeAndCause(): void
    {
        $changed = CapabilitySnapshotConflict::connectionChanged();
        self::assertTrue($changed->connectionChanged);
        self::assertSame('The capability connection changed.', $changed->getMessage());
        self::assertSame(0, $changed->getCode());
        self::assertNull($changed->getPrevious());

        $cause = new \RuntimeException('typed cause', 17);
        $invariant = CapabilitySnapshotConflict::invariant('invariant failed', $cause);
        self::assertFalse($invariant->connectionChanged);
        self::assertSame('invariant failed', $invariant->getMessage());
        self::assertSame(0, $invariant->getCode());
        self::assertSame($cause, $invariant->getPrevious());
    }

    public function testHashChangesForEndpointVersionOrCapabilities(): void
    {
        $baseline = $this->observation(self::endpoint('one'), new CapabilityProfile(
            ProxmoxProduct::Pve, 8, 2, 0, '1', '8.2.0', ['legacyMaxFilesSupported' => true],
        ));
        $differentEndpoint = $this->observation(self::endpoint('two'), $baseline->profile);
        $differentVersion = $this->observation(self::endpoint('one'), new CapabilityProfile(
            ProxmoxProduct::Pve, 8, 3, 0, '1', '8.3.0', ['legacyMaxFilesSupported' => true],
        ));
        $differentCapability = $this->observation(self::endpoint('one'), new CapabilityProfile(
            ProxmoxProduct::Pve, 8, 2, 0, '1', '8.2.0', ['legacyMaxFilesSupported' => false],
        ));

        self::assertNotSame($baseline->snapshotHash(), $differentEndpoint->snapshotHash());
        self::assertNotSame($baseline->snapshotHash(), $differentVersion->snapshotHash());
        self::assertNotSame($baseline->snapshotHash(), $differentCapability->snapshotHash());
    }

    #[DataProvider('invalidProfiles')]
    public function testRejectsInvalidProfiles(callable $factory): void
    {
        $this->expectException(InvalidArgumentException::class);
        $factory();
    }

    /** @return iterable<string, array{callable(): CapabilityProfile}> */
    public static function invalidProfiles(): iterable
    {
        yield 'major' => [static fn (): CapabilityProfile => new CapabilityProfile(ProxmoxProduct::Pve, 0, 0, null, null, 'x', ['a' => true])];
        yield 'minor' => [static fn (): CapabilityProfile => new CapabilityProfile(ProxmoxProduct::Pve, 1, -1, null, null, 'x', ['a' => true])];
        yield 'patch' => [static fn (): CapabilityProfile => new CapabilityProfile(ProxmoxProduct::Pve, 1, 0, -1, null, 'x', ['a' => true])];
        yield 'raw empty' => [static fn (): CapabilityProfile => new CapabilityProfile(ProxmoxProduct::Pve, 1, 0, null, null, '', ['a' => true])];
        yield 'raw too long' => [static fn (): CapabilityProfile => new CapabilityProfile(ProxmoxProduct::Pve, 1, 0, null, null, str_repeat('x', 256), ['a' => true])];
        yield 'release empty' => [static fn (): CapabilityProfile => new CapabilityProfile(ProxmoxProduct::Pve, 1, 0, null, '', 'x', ['a' => true])];
        yield 'release too long' => [static fn (): CapabilityProfile => new CapabilityProfile(ProxmoxProduct::Pve, 1, 0, null, str_repeat('x', 129), 'x', ['a' => true])];
        yield 'empty capabilities' => [static fn (): CapabilityProfile => new CapabilityProfile(ProxmoxProduct::Pve, 1, 0, null, null, 'x', [])];
        yield 'empty name' => [static fn (): CapabilityProfile => new CapabilityProfile(ProxmoxProduct::Pve, 1, 0, null, null, 'x', ['' => true])];
        yield 'long name' => [static fn (): CapabilityProfile => new CapabilityProfile(ProxmoxProduct::Pve, 1, 0, null, null, 'x', [str_repeat('a', 65) => true])];
        yield 'uppercase first character' => [static fn (): CapabilityProfile => new CapabilityProfile(ProxmoxProduct::Pve, 1, 0, null, null, 'x', ['Invalid' => true])];
        yield 'invalid later character' => [static fn (): CapabilityProfile => new CapabilityProfile(ProxmoxProduct::Pve, 1, 0, null, null, 'x', ['invalid-name' => true])];
    }

    public function testRejectsACapabilityValueOutsideTheRuntimeContract(): void
    {
        $this->expectException(InvalidArgumentException::class);
        // @phpstan-ignore argument.type (exercise the runtime boundary promised by the constructor PHPDoc)
        new CapabilityProfile(ProxmoxProduct::Pve, 9, 0, 0, null, '9.0.0', ['invalid' => 1.5]);
    }

    public function testAcceptsIntegerCapabilityValues(): void
    {
        $profile = new CapabilityProfile(ProxmoxProduct::Pve, 9, 0, 0, null, '9.0.0', ['limit' => 1]);
        self::assertSame('{"limit":1}', $profile->capabilitiesJson());
    }

    public function testRejectsInvalidObservationRevisionAndUnsupportedProducts(): void
    {
        try {
            $this->observation(self::endpoint('endpoint'), new CapabilityProfile(
                ProxmoxProduct::Pve, 9, 0, 0, null, '9.0.0', ['a' => true],
            ), 0);
            self::fail('Invalid revision accepted.');
        } catch (InvalidArgumentException) {
        }

        try {
            (new BuildVerifiedCapabilityProfile())->build(new ConnectionInstallationRead(
                new ConnectionId(self::bytes('connection')),
                1,
                self::endpoint('endpoint'),
                InstallationBinding::pveStandalone('pve-a'),
                self::pveSnapshot(6),
            ));
            self::fail('Unsupported PVE version accepted.');
        } catch (InvalidArgumentException $exception) {
            self::assertSame('Unsupported PVE major version.', $exception->getMessage());
        }

        $this->expectException(InvalidArgumentException::class);
        (new BuildVerifiedCapabilityProfile())->build(new ConnectionInstallationRead(
            new ConnectionId(self::bytes('connection')),
            1,
            self::endpoint('endpoint'),
            InstallationBinding::pveStandalone('pbs-a'),
            self::pbsSnapshot(5, 0, true),
        ));
    }

    private function observation(EndpointId $endpoint, CapabilityProfile $profile, int $revision = 1): CapabilitySnapshotObservation
    {
        return new CapabilitySnapshotObservation(
            new InventoryIdentifier(self::bytes('run')),
            new InventoryIdentifier(self::bytes('connection')),
            $revision,
            $endpoint,
            $profile,
            new DateTimeImmutable('2026-07-12T10:00:00Z'),
        );
    }

    private static function pveSnapshot(int $major): PveInstallationSnapshot
    {
        return new PveInstallationSnapshot(
            new PveVersion($major, 0, 0, (string) $major, $major.'.0.0', 'repo'),
            new PvePermissionAssessment([]),
            new PveClusterTopology(
                PveClusterMode::Standalone,
                null,
                null,
                null,
                null,
                [new PveClusterNode('pve-a', true, 0, true)],
                [],
            ),
            new PveResourceInventory([], [], [], []),
        );
    }

    private static function pbsSnapshot(int $major, int $minor, bool $identitySupported): PbsInstallationSnapshot
    {
        $configuration = new PbsDatastoreConfigurationSnapshot(str_repeat('a', 64), []);
        return new PbsInstallationSnapshot(
            new PbsVersion($major, $minor, 0, $major.'.'.$minor.'.0', '1', 'repo'),
            null,
            $identitySupported ? new PbsInstanceIdentity(str_repeat('a', 32)) : null,
            PbsDatastoreScanScope::installationWide(),
            $configuration,
            $configuration,
            [],
            [],
            [],
        );
    }

    private static function endpoint(string $label): EndpointId
    {
        return new EndpointId(self::bytes($label));
    }

    private static function bytes(string $label): string
    {
        return substr(hash('sha256', $label, true), 0, 16);
    }
}
