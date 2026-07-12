<?php

declare(strict_types=1);

namespace App\Tests\Unit\Infrastructure\Persistence\MariaDb;

use App\Application\Inventory\Connection\ConnectionId;
use App\Application\Inventory\Connection\InstallationBindingKind;
use App\Infrastructure\Persistence\MariaDb\DbalInstallationBindingCatalog;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use RuntimeException;

#[AllowMockObjectsWithoutExpectations]
final class DbalInstallationBindingCatalogTest extends TestCase
{
    public function testItLoadsEverySupportedBindingWithoutEndpointOrCredentialData(): void
    {
        $cluster = (new DbalInstallationBindingCatalog($this->database(
            ['product' => 'pve', 'identity_kind' => 'pve_cluster', 'identity_value' => 'cluster-a'],
            ['node-b', 'node-a'],
        )))->bindingFor($this->connectionId());
        self::assertNotNull($cluster);
        self::assertSame(InstallationBindingKind::PveCluster, $cluster->kind);
        self::assertSame(['node-a', 'node-b'], $cluster->knownMemberNodes);

        foreach ([
            [['product' => 'pve', 'identity_kind' => 'pve_standalone', 'identity_value' => 'node-a', 'legacy_endpoint_id' => null], InstallationBindingKind::PveStandalone],
            [['product' => 'pbs', 'identity_kind' => 'pbs_instance', 'identity_value' => str_repeat('a', 32), 'legacy_endpoint_id' => null], InstallationBindingKind::PbsInstance],
            [['product' => 'pbs', 'identity_kind' => 'pbs_legacy_node', 'identity_value' => 'pbs-a', 'legacy_endpoint_id' => str_repeat('e', 16)], InstallationBindingKind::PbsLegacyNode],
        ] as [$row, $kind]) {
            $binding = (new DbalInstallationBindingCatalog($this->database($row)))->bindingFor($this->connectionId());
            self::assertSame($kind, $binding?->kind);
        }
    }

    public function testMissingBindingReturnsNullAndMalformedEvidenceFailsClosed(): void
    {
        self::assertNull((new DbalInstallationBindingCatalog($this->database(false)))->bindingFor($this->connectionId()));

        foreach ([
            [['product' => null, 'identity_kind' => 'pve_cluster', 'identity_value' => 'cluster'], []],
            [['product' => 'other', 'identity_kind' => 'other', 'identity_value' => 'value'], []],
            [['product' => 'pve', 'identity_kind' => 'pve_cluster', 'identity_value' => 'cluster'], []],
            [['product' => 'pve', 'identity_kind' => 'pve_cluster', 'identity_value' => 'cluster'], [1]],
        ] as [$row, $members]) {
            try {
                (new DbalInstallationBindingCatalog($this->database($row, $members)))->bindingFor($this->connectionId());
                self::fail('Malformed persisted binding evidence must fail closed.');
            } catch (RuntimeException) {
                self::addToAssertionCount(1);
            }
        }
    }

    /**
     * @param array<string, mixed>|false $row
     * @param list<mixed> $members
     */
    private function database(array|false $row, array $members = []): Connection&MockObject
    {
        $database = $this->createMock(Connection::class);
        $database->method('fetchAssociative')->willReturn($row);
        $database->method('fetchFirstColumn')->willReturn($members);
        return $database;
    }

    private function connectionId(): ConnectionId
    {
        return new ConnectionId(str_repeat('c', 16));
    }
}
