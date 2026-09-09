<?php

declare(strict_types=1);

namespace App\Tests\Integration\Database;

use App\Application\Inventory\Connection\ConnectionId;
use App\Application\Inventory\Connection\EndpointId;
use App\Infrastructure\Maintenance\DbalMaintenanceQuiescenceCatalog;
use App\Infrastructure\Persistence\MariaDb\DbalConnectionScanCatalog;
use App\Infrastructure\Persistence\MariaDb\DbalPveEndpointReadConfigurationSource;

final class MaintenanceQuiescenceCatalogTest extends DatabaseTestCase
{
    public function testDisabledConnectionsAndEndpointsAreIncludedOnlyInMaintenanceCatalog(): void
    {
        $id = random_bytes(16);
        $this->connection()->insert('proxmox_connections', ['id' => $id, 'display_name' => 'Maintenance fixture', 'product' => 'pve', 'enabled' => 0,
            'revision' => 1, 'created_at' => '2026-09-07 20:00:00.000000', 'updated_at' => '2026-09-07 20:00:00.000000']);
        $this->seedExecutorEvidenceFixtureConfiguration($id);
        $this->connection()->executeStatement('UPDATE proxmox_connection_endpoints SET enabled=0 WHERE connection_id=:id', ['id' => $id]);
        $catalog = new DbalMaintenanceQuiescenceCatalog($this->connection());
        $found = array_values(array_filter($catalog->targets(), static fn ($target): bool => $target->connectionId->bytes === $id));
        self::assertCount(1, $found);
        self::assertCount(1, $found[0]->endpoints);
        $source = new DbalPveEndpointReadConfigurationSource($this->connection(), includeDisabled: true);
        $configuration = $source->load(new ConnectionId($id), $found[0]->endpoints[0]->endpointId, 1);
        self::assertStringEndsWith('.test', $configuration->host);
        foreach ((new DbalConnectionScanCatalog($this->connection()))->enabledTargets() as $target) self::assertNotSame($id, $target->connectionId->bytes);
        self::assertFalse($catalog->hasUnsettledLocalWork());
        self::assertSame([], $catalog->submissionNodes(new ConnectionId($id)));
    }
}
