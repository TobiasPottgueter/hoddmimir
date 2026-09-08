<?php

declare(strict_types=1);

namespace App\Tests\Unit\Infrastructure\Maintenance;

use App\Application\Inventory\Connection\ConnectionId;
use App\Application\Inventory\Connection\ConnectionScanTarget;
use App\Application\Inventory\Connection\EndpointId;
use App\Application\Inventory\Connection\EndpointScanReference;
use App\Application\Inventory\Connection\ProxmoxProduct;
use App\Application\Maintenance\MaintenanceQuiescenceFailure;
use App\Application\Proxmox\Pve\PveBackupTask;
use App\Application\Proxmox\Pve\PveBackupInventoryIssue;
use App\Application\Proxmox\Pve\PveBackupInventoryIssueCode;
use App\Application\Proxmox\Pve\PveClusterMode;
use App\Application\Proxmox\Pve\PveClusterNode;
use App\Application\Proxmox\Pve\PveClusterTopology;
use App\Application\Proxmox\Pve\PveMissingPermission;
use App\Application\Proxmox\Pve\PvePermissionAssessment;
use App\Application\Proxmox\Pve\PveReadClient;
use App\Application\Proxmox\Pve\PveReadConnector;
use App\Application\Proxmox\Pve\PveRequiredPermission;
use App\Application\Proxmox\Pve\PveTaskPage;
use App\Application\Proxmox\Pve\PveTaskQuery;
use App\Application\Proxmox\Pve\PveTaskSource;
use App\Application\Proxmox\Pve\PveUpid;
use App\Application\Proxmox\Pbs\PbsAclEvidence;
use App\Application\Proxmox\Pbs\PbsEffectivePermission;
use App\Application\Proxmox\Pbs\PbsTaskPage;
use App\Infrastructure\Maintenance\NativeMaintenanceRemoteTasks;
use App\Infrastructure\Proxmox\PveCoreReadConnectorFactory;
use App\Infrastructure\Proxmox\Pbs\PbsMonitoringClient;
use App\Infrastructure\Proxmox\Pbs\PbsMonitoringClientFactory;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class NativeMaintenanceRemoteTasksTest extends TestCase
{
    #[DataProvider('pveCases')]
    public function testPveRequiresCompleteOnlineTopologyPermissionsAndEmptyUnfilteredTaskPages(string $mode, bool $success): void
    {
        $db = $this->createStub(Connection::class);
        $db->method('fetchAssociative')->willReturn($this->row('pve'));
        $client = $this->createStub(PveReadClient::class);
        $client->method('permissions')->willReturn(new PvePermissionAssessment('acl' === $mode ? [new PveMissingPermission(PveRequiredPermission::SystemAudit)] : []));
        $client->method('topology')->willReturn(new PveClusterTopology(PveClusterMode::Standalone, null, null, null, null,
            'topology' === $mode ? [] : [new PveClusterNode('node-a', 'offline' !== $mode, 0, true)], []));
        $nodes = [];
        $client->method('backupTaskPage')->willReturnCallback(function (string $node, PveTaskQuery $query) use ($mode, &$nodes): PveTaskPage {
            $nodes[] = $node;
            self::assertSame(PveTaskQuery::active()->parameters(), $query->parameters());
            if ('transport' === $mode) throw new \RuntimeException('sanitized transport error');
            $tasks = 'busy' === $mode ? [new PveBackupTask(PveUpid::parse('UPID:node-a:00000001:00000001:70000000:vzdump:101:root@pam:'), PveTaskSource::Active, null, null)] : [];
            $issues = 'partial' === $mode ? [new PveBackupInventoryIssue(PveBackupInventoryIssueCode::InvalidField, '/tasks', 'upid')] : [];
            return new PveTaskPage($query, 'unparsed' === $mode ? 1 : count($tasks), $tasks, $issues);
        });
        $connector = $this->createStub(PveReadConnector::class);
        $connector->method('connect')->willReturn($client);
        $factory = $this->createStub(PveCoreReadConnectorFactory::class);
        $factory->method('create')->willReturn($connector);
        $reader = new NativeMaintenanceRemoteTasks($db, $factory, $this->createStub(PbsMonitoringClientFactory::class));
        if (!$success) $this->expectException(MaintenanceQuiescenceFailure::class);
        $reader->assertQuiet($this->target(ProxmoxProduct::Pve), ['original-node', 'node-a']);
        if ($success) self::assertSame(['original-node', 'node-a'], $nodes);
    }
    /** @return iterable<string, array{string, bool}> */
    public static function pveCases(): iterable
    {
        foreach (['quiet', 'acl', 'topology', 'offline', 'transport', 'busy', 'partial', 'unparsed'] as $mode) yield $mode => [$mode, 'quiet' === $mode];
    }
    #[DataProvider('pbsCases')]
    public function testPbsTaskAbsenceNeedsGlobalReadRightsAndCompleteEmptyPage(bool $acl, int $raw, ?int $total, bool $success): void
    {
        $db = $this->createStub(Connection::class);
        $db->method('fetchAssociative')->willReturn($this->row('pbs'));
        $client = $this->createStub(PbsMonitoringClient::class);
        $client->method('aclEvidence')->willReturn(new PbsAclEvidence(new PbsEffectivePermission('/system/tasks', $acl ? ['Sys.Audit' => true] : []), new PbsEffectivePermission('/datastore', []), new PbsEffectivePermission('/remote', [])));
        $client->method('page')->willReturn(new PbsTaskPage([], $total, $raw));
        $factory = $this->createStub(PbsMonitoringClientFactory::class);
        $factory->method('createMonitoringClient')->willReturn($client);
        if (!$success) $this->expectException(MaintenanceQuiescenceFailure::class);
        (new NativeMaintenanceRemoteTasks($db, $this->createStub(PveCoreReadConnectorFactory::class), $factory))->assertQuiet($this->target(ProxmoxProduct::Pbs), []);
        if ($success) self::addToAssertionCount(1);
    }
    /** @return iterable<string, array{bool, int, ?int, bool}> */
    public static function pbsCases(): iterable
    {
        yield 'empty total' => [true, 0, 0, true];
        yield 'empty without total' => [true, 0, null, true];
        yield 'missing ACL' => [false, 0, 0, false];
        yield 'unparsed row' => [true, 1, 1, false];
        yield 'inconsistent total' => [true, 0, 1, false];
    }
    private function target(ProxmoxProduct $product): ConnectionScanTarget
    {
        return new ConnectionScanTarget(new ConnectionId(str_repeat('c', 16)), 1, $product, [new EndpointScanReference(new EndpointId(str_repeat('e', 16)), 1)]);
    }
    /** @return array<string, mixed> */
    private function row(string $product): array
    {
        return ['product' => $product, 'connection_enabled' => 0, 'connection_revision' => 1,
            'endpoint_id' => str_repeat('e', 16), 'host' => 'proxmox.example.test', 'port' => 8006,
            'tls_mode' => 'system_ca', 'custom_ca_pem' => null, 'sha256_fingerprint' => null,
            'credential_id' => str_repeat('d', 16), 'principal' => 'hoddmimir@'.$product,
            'token_name' => 'scan', 'secret_envelope' => 'opaque-encrypted-fixture'];
    }
}
