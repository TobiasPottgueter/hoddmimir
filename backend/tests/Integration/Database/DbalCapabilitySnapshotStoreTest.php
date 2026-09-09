<?php

declare(strict_types=1);

namespace App\Tests\Integration\Database;

use App\Application\Collector\CollectorCycleToken;
use App\Application\Collector\CollectorLease;
use App\Application\Collector\CollectorLeaseOwnershipLost;
use App\Application\Collector\CollectorWorkerId;
use App\Application\Inventory\Capability\CapabilityProfile;
use App\Application\Inventory\Capability\CapabilitySnapshotConflict;
use App\Application\Inventory\Capability\CapabilitySnapshotObservation;
use App\Application\Inventory\Connection\EndpointId;
use App\Application\Inventory\Connection\ProxmoxProduct;
use App\Application\Inventory\InventoryIdentifier;
use App\Application\Inventory\InventoryIdentifierGenerator;
use App\Infrastructure\Persistence\MariaDb\DbalCapabilitySnapshotStore;
use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Throwable;

final class DbalCapabilitySnapshotStoreTest extends KernelTestCase
{
    private Connection $database;
    private InventoryIdentifier $connectionId;
    private EndpointId $primaryEndpoint;
    private EndpointId $secondaryEndpoint;
    private TestCapabilityIdentifierGenerator $ids;
    private DbalCapabilitySnapshotStore $store;
    private int $fence = 0;

    protected function setUp(): void
    {
        parent::setUp();
        self::bootKernel();
        $database = self::getContainer()->get('doctrine.dbal.default_connection');
        self::assertInstanceOf(Connection::class, $database);
        $this->database = $database;
        $this->cleanDatabase();

        $this->connectionId = self::id('connection');
        $this->primaryEndpoint = new EndpointId(self::bytes('endpoint-primary'));
        $this->secondaryEndpoint = new EndpointId(self::bytes('endpoint-secondary'));
        $this->insertConnection();
        $this->ids = new TestCapabilityIdentifierGenerator('parent');
        $this->store = new DbalCapabilitySnapshotStore($this->database, $this->ids);
    }

    protected function tearDown(): void
    {
        if (isset($this->database)) {
            $this->cleanDatabase();
            $this->database->close();
        }
        parent::tearDown();
    }

    public function testSameHashIsIdempotentAndKeepsHistoricalRunReferencesStable(): void
    {
        [$firstLease, $firstRun] = $this->startRun('first');
        $profile = $this->profile();
        $first = $this->store->persist($firstLease, $this->observation($firstRun, $profile, self::at(1)));

        [$secondLease, $secondRun] = $this->startRun('second');
        $second = $this->store->persist($secondLease, $this->observation($secondRun, $profile, self::at(2)));

        self::assertTrue($first->equals($second));
        self::assertSame(1, $this->countSnapshots());
        self::assertSame(
            [$first->binary(), $first->binary()],
            $this->database->fetchFirstColumn(
                'SELECT capability_snapshot_id FROM inventory_sync_runs WHERE id IN (:first, :second) ORDER BY started_at',
                ['first' => $firstRun->binary(), 'second' => $secondRun->binary()],
                ['first' => \Doctrine\DBAL\ParameterType::BINARY, 'second' => \Doctrine\DBAL\ParameterType::BINARY],
            ),
        );
        self::assertSame(
            ['first_observed_at' => self::format(self::at(1)), 'last_observed_at' => self::format(self::at(2))],
            $this->database->fetchAssociative(
                'SELECT first_observed_at, last_observed_at FROM proxmox_capability_snapshots WHERE id = :id',
                ['id' => $first->binary()],
            ),
        );
    }

    public function testCapabilityOrEndpointChangeCreatesANewSnapshotWithoutRewritingHistory(): void
    {
        [$firstLease, $firstRun] = $this->startRun('first');
        $first = $this->store->persist($firstLease, $this->observation($firstRun, $this->profile(), self::at(1)));

        [$secondLease, $secondRun] = $this->startRun('second');
        $changed = $this->profile(['legacyMaxFilesSupported' => false]);
        $second = $this->store->persist($secondLease, $this->observation($secondRun, $changed, self::at(2)));

        [$thirdLease, $thirdRun] = $this->startRun('third', $this->secondaryEndpoint);
        $third = $this->store->persist($thirdLease, $this->observation(
            $thirdRun,
            $changed,
            self::at(3),
            $this->secondaryEndpoint,
        ));

        self::assertFalse($first->equals($second));
        self::assertFalse($second->equals($third));
        self::assertSame(3, $this->countSnapshots());
        self::assertSame($first->binary(), $this->runSnapshot($firstRun));
        self::assertSame($second->binary(), $this->runSnapshot($secondRun));
        self::assertSame($third->binary(), $this->runSnapshot($thirdRun));
    }

    #[DataProvider('conflicts')]
    public function testRevisionEndpointAndConnectionConflictsFailClosed(string $kind): void
    {
        [$lease, $run] = $this->startRun('conflict');
        $observation = $this->observation($run, $this->profile(), self::at(1));

        if ('run-revision' === $kind) {
            $observation = new CapabilitySnapshotObservation(
                $run,
                $this->connectionId,
                2,
                $this->primaryEndpoint,
                $this->profile(),
                self::at(1),
            );
        } elseif ('endpoint' === $kind) {
            $observation = $this->observation($run, $this->profile(), self::at(1), $this->secondaryEndpoint);
        } else {
            $this->database->update('proxmox_connections', ['revision' => 2], ['id' => $this->connectionId->binary()]);
        }

        try {
            $this->store->persist($lease, $observation);
            self::fail('Capability persistence accepted drift.');
        } catch (CapabilitySnapshotConflict $conflict) {
            self::assertSame('connection-revision' === $kind, $conflict->connectionChanged);
        }

        self::assertSame(0, $this->countSnapshots());
        self::assertNull($this->runSnapshot($run));
    }

    /** @return iterable<string, array{string}> */
    public static function conflicts(): iterable
    {
        yield 'run revision' => ['run-revision'];
        yield 'selected endpoint' => ['endpoint'];
        yield 'connection revision' => ['connection-revision'];
    }

    public function testLostFenceRollsBackWithoutAnOrphanSnapshot(): void
    {
        [$lease, $run] = $this->startRun('lost-fence');
        $this->database->update('collector_schedule', ['lease_fencing_token' => $lease->fencingToken + 1], [
            'schedule_name' => 'inventory',
        ]);

        $this->expectException(CollectorLeaseOwnershipLost::class);
        try {
            $this->store->persist($lease, $this->observation($run, $this->profile(), self::at(1)));
        } finally {
            self::assertSame(0, $this->countSnapshots());
            self::assertNull($this->runSnapshot($run));
        }
    }

    public function testForeignKeysRejectCrossConnectionEndpointAndRunLinks(): void
    {
        [$lease, $run] = $this->startRun('foreign-key');
        $snapshot = $this->store->persist($lease, $this->observation($run, $this->profile(), self::at(1)));
        $otherConnection = self::id('other-connection');
        $now = self::format(self::at(0));
        $this->database->insert('proxmox_connections', [
            'id' => $otherConnection->binary(), 'display_name' => 'Other', 'product' => 'pve',
            'enabled' => 1, 'revision' => 1, 'created_at' => $now, 'updated_at' => $now,
        ]);

        $this->expectException(\Doctrine\DBAL\Exception\ForeignKeyConstraintViolationException::class);
        $this->database->insert('proxmox_capability_snapshots', [
            'id' => self::id('invalid')->binary(),
            'connection_id' => $otherConnection->binary(),
            'endpoint_id' => $this->primaryEndpoint->bytes,
            'product' => 'pve', 'version_major' => 9, 'version_minor' => 0, 'version_patch' => 0,
            'release_name' => '1', 'raw_version' => '9.0.0', 'profile_version' => 1,
            'capabilities_json' => '{"x":true}', 'snapshot_hash' => random_bytes(32),
            'first_observed_at' => $now, 'last_observed_at' => $now,
        ]);
        self::assertNotSame('', $snapshot->binary());
    }

    public function testConcurrentSameHashPersistsExactlyOneSnapshot(): void
    {
        if (!function_exists('pcntl_fork')) {
            self::markTestSkipped('pcntl is required for the concurrency proof.');
        }
        [$lease, $run] = $this->startRun('concurrent');
        $observation = $this->observation($run, $this->profile(), self::at(1));
        $parameters = $this->database->getParams();
        /** @var list<array{int, resource}> $children */
        $children = [
            $this->startChild('a', $parameters, $lease, $observation),
            $this->startChild('b', $parameters, $lease, $observation),
        ];
        foreach ($children as [, $socket]) {
            self::assertSame(1, fwrite($socket, '1'));
        }

        $results = [];
        foreach ($children as [$pid, $socket]) {
            $results[] = $this->finishChild($pid, $socket);
        }
        self::assertSame($results[0], $results[1]);
        self::assertSame(1, $this->countSnapshots());
        self::assertSame(hex2bin($results[0]), $this->runSnapshot($run));
    }

    /**
     * @param array<string, mixed> $parameters
     * @return array{int, resource}
     */
    private function startChild(
        string $label,
        array $parameters,
        CollectorLease $lease,
        CapabilitySnapshotObservation $observation,
    ): array {
        $sockets = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        if (false === $sockets) {
            throw new \RuntimeException('Could not create capability concurrency sockets.');
        }
        [$parent, $child] = $sockets;
        $pid = pcntl_fork();
        if (-1 === $pid) {
            throw new \RuntimeException('Could not fork capability writer.');
        }
        if (0 === $pid) {
            fclose($parent);
            if ('1' !== fread($child, 1)) {
                exit(2);
            }
            // @phpstan-ignore argument.type (parameters originate from a live DBAL connection)
            $connection = DriverManager::getConnection($parameters);
            try {
                $id = (new DbalCapabilitySnapshotStore(
                    $connection,
                    new TestCapabilityIdentifierGenerator('child-'.$label),
                ))->persist($lease, $observation);
                fwrite($child, bin2hex($id->binary()));
            } catch (Throwable $exception) {
                fwrite($child, $exception::class.':'.$exception->getMessage());
            } finally {
                $connection->close();
                fclose($child);
            }
            pcntl_exec('/bin/true');
            posix_kill(posix_getpid(), SIGKILL);
            exit(3);
        }
        fclose($child);
        stream_set_timeout($parent, 15);
        return [$pid, $parent];
    }

    /** @param resource $socket */
    private function finishChild(int $pid, $socket): string
    {
        $result = stream_get_contents($socket);
        fclose($socket);
        $status = null;
        pcntl_waitpid($pid, $status);
        if (!is_int($status)) {
            throw new \RuntimeException('Capability writer returned no process status.');
        }
        self::assertTrue(pcntl_wifexited($status));
        self::assertSame(0, pcntl_wexitstatus($status));
        self::assertIsString($result);
        self::assertMatchesRegularExpression('/^[0-9a-f]{32}$/D', $result);
        return $result;
    }

    /** @return array{CollectorLease, InventoryIdentifier} */
    private function startRun(string $label, ?EndpointId $endpoint = null): array
    {
        $lease = $this->seedLease($label);
        $run = self::id('run-'.$label);
        $now = self::format($this->databaseNow());
        $this->database->insert('inventory_sync_runs', [
            'id' => $run->binary(),
            'cycle_token' => $lease->token->binary(),
            'collector_fencing_token' => $lease->fencingToken,
            'connection_id' => $this->connectionId->binary(),
            'expected_connection_revision' => 1,
            'endpoint_id' => ($endpoint ?? $this->primaryEndpoint)->bytes,
            'status' => 'running',
            'started_at' => $now,
            'heartbeat_at' => $now,
        ]);
        return [$lease, $run];
    }

    private function seedLease(string $label): CollectorLease
    {
        ++$this->fence;
        $now = $this->databaseNow();
        $expires = $now->modify('+1 hour');
        $worker = new CollectorWorkerId(self::bytes('worker'));
        $token = new CollectorCycleToken(self::bytes('cycle-'.$label));
        $this->database->executeStatement(
            "UPDATE collector_cycles SET status = 'succeeded', heartbeat_at = :now, finished_at = :now, duration_ms = 0 WHERE status = 'running'",
            ['now' => self::format($now)],
        );
        $this->database->executeStatement(
            <<<'SQL'
                INSERT INTO worker_heartbeats (
                    worker_instance_id, worker_kind, status, started_at, heartbeat_at, expires_at,
                    current_activity, current_cycle_token, next_action_at, build_version
                ) VALUES (:worker, 'collector', 'ready', :now, :now, :expires, NULL, NULL, NULL, 'test')
                ON DUPLICATE KEY UPDATE heartbeat_at = VALUES(heartbeat_at), expires_at = VALUES(expires_at)
                SQL,
            ['worker' => $worker->bytes, 'now' => self::format($now), 'expires' => self::format($expires)],
        );
        $this->database->executeStatement(
            <<<'SQL'
                INSERT INTO collector_schedule (
                    schedule_name, grid_started_at, interval_seconds, next_scan_at, lease_owner, lease_token,
                    lease_fencing_token, lease_acquired_at, lease_expires_at, last_cycle_started_at, updated_at
                ) VALUES ('inventory', :now, 120, :now, :worker, :token, :fence, :now, :expires, :now, :now)
                ON DUPLICATE KEY UPDATE lease_owner = VALUES(lease_owner), lease_token = VALUES(lease_token),
                    lease_fencing_token = VALUES(lease_fencing_token), lease_acquired_at = VALUES(lease_acquired_at),
                    lease_expires_at = VALUES(lease_expires_at), last_cycle_started_at = VALUES(last_cycle_started_at),
                    updated_at = VALUES(updated_at)
                SQL,
            [
                'now' => self::format($now), 'worker' => $worker->bytes, 'token' => $token->binary(),
                'fence' => $this->fence, 'expires' => self::format($expires),
            ],
        );
        $this->database->insert('collector_cycles', [
            'cycle_token' => $token->binary(), 'schedule_name' => 'inventory',
            'worker_instance_id' => $worker->bytes, 'worker_kind' => 'collector',
            'fencing_token' => $this->fence, 'scheduled_for' => self::format($now),
            'started_at' => self::format($now), 'heartbeat_at' => self::format($now), 'status' => 'running',
        ]);
        return new CollectorLease($worker, $token, $this->fence, $expires);
    }

    private function insertConnection(): void
    {
        $now = self::format(self::at(0));
        $this->database->insert('proxmox_connections', [
            'id' => $this->connectionId->binary(), 'display_name' => 'Capability integration',
            'product' => 'pve', 'enabled' => 1, 'revision' => 1, 'created_at' => $now, 'updated_at' => $now,
        ]);
        foreach ([[$this->primaryEndpoint, 'pve-a.test'], [$this->secondaryEndpoint, 'pve-b.test']] as [$endpoint, $host]) {
            $this->database->insert('proxmox_connection_endpoints', [
                'id' => $endpoint->bytes, 'connection_id' => $this->connectionId->binary(),
                'host' => $host, 'port' => 8006, 'priority' => 1, 'enabled' => 1,
                'tls_mode' => 'system_ca', 'created_at' => $now, 'updated_at' => $now,
            ]);
        }
    }

    /** @param array<string, bool|int|string>|null $capabilities */
    private function profile(?array $capabilities = null): CapabilityProfile
    {
        return new CapabilityProfile(
            ProxmoxProduct::Pve, 8, 2, 0, '1', '8.2.0',
            $capabilities ?? ['legacyMaxFilesSupported' => true],
        );
    }

    private function observation(
        InventoryIdentifier $run,
        CapabilityProfile $profile,
        DateTimeImmutable $at,
        ?EndpointId $endpoint = null,
    ): CapabilitySnapshotObservation {
        return new CapabilitySnapshotObservation(
            $run, $this->connectionId, 1, $endpoint ?? $this->primaryEndpoint, $profile, $at,
        );
    }

    private function runSnapshot(InventoryIdentifier $run): ?string
    {
        $value = $this->database->fetchOne(
            'SELECT capability_snapshot_id FROM inventory_sync_runs WHERE id = :id',
            ['id' => $run->binary()],
        );
        return is_string($value) ? $value : null;
    }

    private function countSnapshots(): int
    {
        $count = $this->database->fetchOne('SELECT COUNT(*) FROM proxmox_capability_snapshots');
        if (!is_int($count) && !(is_string($count) && ctype_digit($count))) {
            throw new \RuntimeException('MariaDB returned an invalid snapshot count.');
        }
        return (int) $count;
    }

    private function cleanDatabase(): void
    {
        foreach ([
            'inventory_sync_failures', 'inventory_sync_endpoint_attempts', 'inventory_sync_scope_results',
            'proxmox_installation_bindings', 'inventory_sync_runs', 'proxmox_capability_snapshots',
            'proxmox_connection_endpoints', 'proxmox_connections', 'collector_cycles', 'collector_schedule',
            'worker_heartbeats',
        ] as $table) {
            $this->database->executeStatement('DELETE FROM '.$table);
        }
    }

    private function databaseNow(): DateTimeImmutable
    {
        $value = $this->database->fetchOne('SELECT UTC_TIMESTAMP(6)');
        self::assertIsString($value);
        $result = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s.u', $value, new DateTimeZone('UTC'));
        self::assertInstanceOf(DateTimeImmutable::class, $result);
        return $result;
    }

    private static function at(int $second): DateTimeImmutable
    {
        return new DateTimeImmutable(sprintf('2026-07-12T10:00:%02dZ', $second));
    }

    private static function format(DateTimeImmutable $at): string
    {
        return $at->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.u');
    }

    private static function id(string $label): InventoryIdentifier
    {
        return new InventoryIdentifier(self::bytes($label));
    }

    private static function bytes(string $label): string
    {
        return substr(hash('sha256', $label, true), 0, 16);
    }
}

final class TestCapabilityIdentifierGenerator implements InventoryIdentifierGenerator
{
    private int $sequence = 0;

    public function __construct(private readonly string $prefix) {}

    public function generate(): InventoryIdentifier
    {
        ++$this->sequence;
        return new InventoryIdentifier(substr(hash('sha256', $this->prefix.'-'.$this->sequence, true), 0, 16));
    }
}
