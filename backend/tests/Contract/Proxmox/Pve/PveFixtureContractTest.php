<?php

declare(strict_types=1);

namespace App\Tests\Contract\Proxmox\Pve;

use App\Application\Proxmox\Pve\PveClusterMode;
use App\Application\Proxmox\Pve\PveGuestType;
use App\Application\Proxmox\Pve\PveInstallationSnapshot;
use App\Infrastructure\Proxmox\PveClusterResourcesReader;
use App\Infrastructure\Proxmox\PveClusterStatusReader;
use App\Infrastructure\Proxmox\PveJsonEnvelopeDecoder;
use App\Infrastructure\Proxmox\PvePermissionReader;
use App\Infrastructure\Proxmox\PveVersionReader;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class PveFixtureContractTest extends TestCase
{
    #[DataProvider('majorProvider')]
    public function testSanitizedMajorFixturesSatisfyTheReadContract(
        int $major,
        int $qemuProvisionedSizeBytes,
        int $lxcProvisionedSizeBytes,
    ): void
    {
        $decoder = new PveJsonEnvelopeDecoder();
        $version = (new PveVersionReader())->read($decoder->decode($this->fixture($major, 'version')));
        $permissions = (new PvePermissionReader())->read($decoder->decode($this->fixture($major, 'access-permissions')));
        $clustered = (new PveClusterStatusReader())->read($decoder->decode($this->fixture($major, 'cluster-status-clustered')));
        $standalone = (new PveClusterStatusReader())->read($decoder->decode($this->fixture($major, 'cluster-status-standalone')));
        $resourceReader = new PveClusterResourcesReader();
        $clusteredResources = $resourceReader->read($decoder->decode($this->fixture($major, 'cluster-resources')));
        $standaloneResources = $resourceReader->read(
            $decoder->decode($this->fixture($major, 'cluster-resources-standalone')),
        );
        $clusteredSnapshot = new PveInstallationSnapshot(
            $version,
            $permissions,
            $clustered,
            $clusteredResources,
        );
        $standaloneSnapshot = new PveInstallationSnapshot(
            $version,
            $permissions,
            $standalone,
            $standaloneResources,
        );

        self::assertSame($major, $version->major);
        self::assertSame((string) $major, $version->release[0]);
        self::assertTrue($permissions->isComplete());
        self::assertSame(PveClusterMode::Clustered, $clustered->mode);
        self::assertTrue($clustered->isComplete());
        self::assertCount(2, $clustered->nodes);
        self::assertSame(2, $clustered->declaredNodeCount);
        self::assertSame($major, $clustered->configurationVersion);
        self::assertTrue($clustered->quorate);
        self::assertSame(PveClusterMode::Standalone, $standalone->mode);
        self::assertTrue($standalone->isComplete());
        self::assertCount(1, $standalone->nodes);
        self::assertTrue($standalone->nodes[0]->local);
        self::assertSame(0, $standalone->nodes[0]->localNodeId);
        self::assertTrue($clusteredResources->isComplete());
        self::assertCount(2, $clusteredResources->nodes);
        self::assertCount(2, $clusteredResources->guests);
        self::assertCount(1, $clusteredResources->storages);
        self::assertSame(PveGuestType::Qemu, $clusteredResources->guests[0]->type);
        self::assertSame(PveGuestType::Lxc, $clusteredResources->guests[1]->type);
        self::assertGreaterThan(0, $clusteredResources->guests[0]->diskWriteBytes);
        self::assertGreaterThan(0, $clusteredResources->guests[1]->diskWriteBytes);
        self::assertSame($qemuProvisionedSizeBytes, $clusteredResources->guests[0]->provisionedSizeBytes);
        self::assertSame($lxcProvisionedSizeBytes, $clusteredResources->guests[1]->provisionedSizeBytes);
        self::assertGreaterThan(0, $standaloneResources->guests[0]->diskWriteBytes);
        self::assertGreaterThan(0, $standaloneResources->guests[1]->diskWriteBytes);
        self::assertNull($standaloneResources->guests[0]->provisionedSizeBytes);
        self::assertNull($standaloneResources->guests[1]->provisionedSizeBytes);
        self::assertNull($clusteredResources->storages[0]->availableBytes);
        self::assertTrue($standaloneResources->isComplete());
        self::assertCount(1, $standaloneResources->nodes);
        self::assertCount(2, $standaloneResources->guests);
        self::assertCount(1, $standaloneResources->storages);
        self::assertTrue($clusteredSnapshot->isComplete());
        self::assertTrue($standaloneSnapshot->isComplete());

        if (7 === $major) {
            self::assertNull($version->patch);
            self::assertNull($clusteredResources->guests[0]->template);
            self::assertNull($standaloneResources->guests[0]->template);
        } else {
            self::assertNotNull($version->patch);
            self::assertIsBool($clusteredResources->guests[0]->template);
            self::assertIsBool($standaloneResources->guests[0]->template);
        }
    }

    /** @return iterable<string, array{int, int, int}> */
    public static function majorProvider(): iterable
    {
        yield 'PVE 7' => [7, 34_359_738_368, 17_179_869_184];
        yield 'PVE 8' => [8, 68_719_476_736, 34_359_738_368];
        yield 'PVE 9' => [9, 137_438_953_472, 68_719_476_736];
    }

    private function fixture(int $major, string $name): string
    {
        $path = dirname(__DIR__, 3).sprintf('/Fixtures/Proxmox/Pve/%d/%s.json', $major, $name);
        $contents = file_get_contents($path);
        if (false === $contents) {
            throw new RuntimeException('The sanitized PVE fixture is unavailable.');
        }

        return $contents;
    }
}
