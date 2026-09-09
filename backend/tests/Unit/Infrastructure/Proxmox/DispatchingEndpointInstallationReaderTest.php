<?php

declare(strict_types=1);

namespace App\Tests\Unit\Infrastructure\Proxmox;

use App\Application\Inventory\Connection\ConnectionId;
use App\Application\Inventory\Connection\ConnectionReadCheckpoint;
use App\Application\Inventory\Connection\EndpointId;
use App\Application\Inventory\Connection\PbsEndpointReadFailureMapper;
use App\Application\Inventory\Connection\ProxmoxProduct;
use App\Application\Inventory\Connection\PveEndpointReadFailureMapper;
use App\Application\Proxmox\Pbs\PbsReadConnector;
use App\Application\Proxmox\Pve\PveReadConnector;
use App\Domain\Shared\Clock;
use App\Infrastructure\Proxmox\DispatchingEndpointInstallationReader;
use App\Infrastructure\Proxmox\Pbs\PbsEndpointInstallationReader;
use App\Infrastructure\Proxmox\Pbs\PbsEndpointReadConfiguration;
use App\Infrastructure\Proxmox\Pbs\PbsEndpointReadConfigurationSource;
use App\Infrastructure\Proxmox\Pbs\PbsReadConnectorFactory;
use App\Infrastructure\Proxmox\PveCoreEndpointInstallationReader;
use App\Infrastructure\Proxmox\PveCoreReadConnectorFactory;
use App\Infrastructure\Proxmox\PveEndpointReadConfiguration;
use App\Infrastructure\Proxmox\PveEndpointReadConfigurationSource;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class DispatchingEndpointInstallationReaderTest extends TestCase
{
    #[DataProvider('productsProvider')]
    public function testDispatcherInvokesOnlyTheReaderForTheDeclaredProduct(
        ProxmoxProduct $product,
        int $expectedPveLoads,
        int $expectedPbsLoads,
        string $expectedSentinel,
    ): void {
        $pveSource = new SentinelPveConfigurationSource();
        $pbsSource = new SentinelPbsConfigurationSource();
        $dispatcher = new DispatchingEndpointInstallationReader(
            new PveCoreEndpointInstallationReader(
                $pveSource,
                new UnreachablePveConnectorFactory(),
                new PveEndpointReadFailureMapper(),
                new DispatcherFixedClock(),
                128,
            ),
            new PbsEndpointInstallationReader(
                $pbsSource,
                new UnreachablePbsConnectorFactory(),
                new PbsEndpointReadFailureMapper(),
                128,
            ),
        );

        try {
            $dispatcher->read(
                new ConnectionId(str_repeat('c', 16)),
                new EndpointId(str_repeat('e', 16)),
                7,
                $product,
                new DispatcherNoopCheckpoint(),
            );
            self::fail('The selected reader sentinel was not propagated.');
        } catch (RuntimeException $failure) {
            self::assertSame($expectedSentinel, $failure->getMessage());
        }

        self::assertSame($expectedPveLoads, $pveSource->loads);
        self::assertSame($expectedPbsLoads, $pbsSource->loads);
    }

    /** @return iterable<string, array{ProxmoxProduct, int, int, string}> */
    public static function productsProvider(): iterable
    {
        yield 'PVE only' => [ProxmoxProduct::Pve, 1, 0, 'PVE selected'];
        yield 'PBS only' => [ProxmoxProduct::Pbs, 0, 1, 'PBS selected'];
    }
}

/** @internal */
final class SentinelPveConfigurationSource implements PveEndpointReadConfigurationSource
{
    public int $loads = 0;

    public function load(
        ConnectionId $connectionId,
        EndpointId $endpointId,
        int $expectedRevision,
    ): PveEndpointReadConfiguration {
        ++$this->loads;
        throw new RuntimeException('PVE selected');
    }
}

/** @internal */
final class SentinelPbsConfigurationSource implements PbsEndpointReadConfigurationSource
{
    public int $loads = 0;

    public function load(
        ConnectionId $connectionId,
        EndpointId $endpointId,
        int $expectedRevision,
    ): PbsEndpointReadConfiguration {
        ++$this->loads;
        throw new RuntimeException('PBS selected');
    }
}

/** @internal */
final class UnreachablePveConnectorFactory implements PveCoreReadConnectorFactory
{
    public function create(
        PveEndpointReadConfiguration $configuration,
        ConnectionReadCheckpoint $checkpoint,
    ): PveReadConnector {
        throw new RuntimeException('PVE connector factory must not be reached.');
    }
}

/** @internal */
final class UnreachablePbsConnectorFactory implements PbsReadConnectorFactory
{
    public function create(
        PbsEndpointReadConfiguration $configuration,
        ConnectionReadCheckpoint $checkpoint,
    ): PbsReadConnector {
        throw new RuntimeException('PBS connector factory must not be reached.');
    }
}

/** @internal */
final class DispatcherFixedClock implements Clock
{
    public function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('2026-07-11T00:00:00Z');
    }
}

/** @internal */
final class DispatcherNoopCheckpoint implements ConnectionReadCheckpoint
{
    public function checkpoint(): void {}
}
