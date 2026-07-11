<?php

declare(strict_types=1);

namespace App\Tests\Integration\Database;

use App\Application\Collector\CollectorCycleStatus;
use App\Application\Collector\CollectorCycleCoordinator;
use App\Application\Collector\CollectorCycleToken;
use App\Application\Collector\CollectorLease;
use App\Application\Collector\CollectorLeaseOwnershipLost;
use App\Application\Collector\CollectorScheduleConfigurationMismatch;
use App\Application\Collector\CollectorWorkerId;
use App\Application\Collector\CollectorWorkerStatus;
use App\Application\Collector\StopRequested;
use App\Application\Inventory\Connection\ClaimedCycleCheckpoint;
use App\Infrastructure\Persistence\MariaDb\DbalCollectorHeartbeatStore;
use App\Infrastructure\Persistence\MariaDb\DbalCollectorScheduleStore;
use App\Infrastructure\Time\SystemMonotonicClock;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;

final class CollectorScheduleStoreTest extends KernelTestCase
{
    private const string NOW = '2026-07-11 10:00:00.000000';

    private Connection $connection;

    protected function setUp(): void
    {
        parent::setUp();
        self::bootKernel();
        $connection = self::getContainer()->get('doctrine.dbal.default_connection');
        self::assertInstanceOf(Connection::class, $connection);
        $this->connection = $connection;
        $this->clean();
    }

    protected function tearDown(): void
    {
        if (isset($this->connection)) {
            $this->clean();
            $this->connection->close();
        }
        parent::tearDown();
    }

    public function testBootstrapIsImmediatePersistentUtcAndWidthMismatchFailsClosed(): void
    {
        $store = new DbalCollectorScheduleStore($this->connection);
        $first = $store->bootstrap(120);
        $second = $store->bootstrap(120);

        self::assertSame($first->grid->startedAt()->format('U.u'), $first->nextScanAt->format('U.u'));
        self::assertSame($first->grid->startedAt()->format('U.u'), $second->grid->startedAt()->format('U.u'));
        self::assertSame('+00:00', $first->databaseNow->format('P'));

        $this->expectException(CollectorScheduleConfigurationMismatch::class);
        $store->bootstrap(121);
    }

    public function testTwoIndependentMariaDbConnectionsConvergeOnOneColdStartAnchor(): void
    {
        $directory = sys_get_temp_dir().'/hoddmimir-collector-bootstrap-'.bin2hex(random_bytes(6));
        self::assertTrue(mkdir($directory, 0700));
        $params = $this->connection->getParams();
        $this->connection->close();
        $children = [];
        try {
            foreach ([0, 1] as $index) {
                $pid = pcntl_fork();
                self::assertNotSame(-1, $pid);
                if (0 === $pid) {
                    $child = DriverManager::getConnection($params);
                    file_put_contents($directory.'/ready-'.$index, '1');
                    $this->waitForFile($directory.'/go');
                    try {
                        $snapshot = (new DbalCollectorScheduleStore($child))->bootstrap(120);
                        file_put_contents($directory.'/result-'.$index, $snapshot->grid->startedAt()->format('U.u'));
                    } catch (\Throwable $exception) {
                        file_put_contents($directory.'/result-'.$index, 'error:'.$exception::class);
                        $child->close();
                        exit(2);
                    }
                    $child->close();
                    exit(0);
                }
                $children[] = $pid;
            }

            $this->waitForFile($directory.'/ready-0');
            $this->waitForFile($directory.'/ready-1');
            file_put_contents($directory.'/go', '1');
            $this->waitForChildren($children);
            $children = [];

            $first = trim((string) file_get_contents($directory.'/result-0'));
            $second = trim((string) file_get_contents($directory.'/result-1'));
            self::assertSame($first, $second);
            self::assertStringNotContainsString('error:', $first);
            $this->connection = DriverManager::getConnection($params);
            self::assertSame(1, $this->databaseInteger('SELECT COUNT(*) FROM collector_schedule'));
        } finally {
            $this->terminateChildren($children);
            $this->removeDirectory($directory);
            if (!$this->connection->isConnected()) {
                $this->connection = DriverManager::getConnection($params);
            }
        }
    }

    public function testProductionCollectorCommandPersistsAnHonestEmptySuccessfulCycle(): void
    {
        self::assertNotNull(self::$kernel);
        $application = new Application(self::$kernel);
        $tester = new CommandTester($application->find('hoddmimir:worker:data'));

        self::assertSame(0, $tester->execute(['--once' => true]));
        self::assertSame(1, $this->databaseInteger("SELECT COUNT(*) FROM collector_cycles WHERE status = 'succeeded'"));
        self::assertSame(0, $this->databaseInteger('SELECT COUNT(*) FROM inventory_sync_runs'));
        self::assertStringContainsString('"component":"collector"', $tester->getDisplay());
        self::assertStringContainsString('"code":"cycle_succeeded"', $tester->getDisplay());
        self::assertMatchesRegularExpression(
            '/^[0-9a-f]{32}\n$/D',
            (string) file_get_contents('/app/var/collector-runtime/worker-id'),
        );
        self::assertSame(0600, fileperms('/app/var/collector-runtime/worker-id') & 0777);

        $health = new CommandTester($application->find('hoddmimir:worker:health'));
        self::assertSame(0, $health->execute(['worker' => 'collector']));
        self::assertStringContainsString('collector_heartbeat_fresh', $health->getDisplay());
    }

    public function testClaimRenewAndFinalizeUseFullLeaseIdentity(): void
    {
        $worker = $this->worker('a');
        $token = $this->token('b');
        $this->recordHeartbeat($worker);
        $store = new DbalCollectorScheduleStore($this->connection);
        $store->bootstrap(120);

        $decision = $store->claimDue($worker, $token, 10);
        self::assertTrue($decision->isClaimed());
        self::assertNotNull($decision->lease);
        self::assertSame(1, $decision->lease->fencingToken);

        $waiting = $store->claimDue($this->worker('c'), $this->token('d'), 10);
        self::assertFalse($waiting->isClaimed());
        self::assertSame($decision->lease->expiresAt->format('U.u'), $waiting->retryAt->format('U.u'));

        $renewed = $store->renew($decision->lease, 20);
        self::assertGreaterThan($decision->lease->expiresAt, $renewed->expiresAt);
        $beforeFinish = $this->databaseNow();
        $next = $store->finalize($renewed, CollectorCycleStatus::Succeeded, 1234);
        self::assertGreaterThan($beforeFinish, $next);
        self::assertLessThanOrEqual(120, (int) $next->format('U') - (int) $beforeFinish->format('U'));
        self::assertSame(
            ['status' => 'succeeded', 'duration_ms' => '1234'],
            $this->connection->fetchAssociative(
                'SELECT status, CAST(duration_ms AS CHAR) AS duration_ms FROM collector_cycles WHERE cycle_token = :token',
                ['token' => $token->binary()],
            ),
        );
        self::assertNull($this->connection->fetchOne("SELECT lease_owner FROM collector_schedule WHERE schedule_name = 'inventory'"));

        $this->expectException(CollectorLeaseOwnershipLost::class);
        $store->finalize($renewed, CollectorCycleStatus::Succeeded, 1234);
    }

    public function testSeparateRuntimeAndExecutorCheckpointsCanRenewAndFinalizeOneRealLease(): void
    {
        $worker = $this->worker('a');
        $coordinator = new CollectorCycleCoordinator(
            new DbalCollectorScheduleStore($this->connection),
            new DbalCollectorHeartbeatStore($this->connection),
            new SystemMonotonicClock(),
        );
        $coordinator->initialize($worker, 120);
        $started = $coordinator->tryStart($worker, $this->token('b'));
        self::assertNotNull($started->cycle);

        $stop = new IntegrationNeverStopRequested();
        $runtimeCheckpoint = new ClaimedCycleCheckpoint($started->cycle, $coordinator, $stop);
        $executorCheckpoint = new ClaimedCycleCheckpoint($started->cycle, $coordinator, $stop);
        $executorCheckpoint->checkpoint();
        $runtimeCheckpoint->checkpoint();
        $runtimeCheckpoint->finish(CollectorCycleStatus::Succeeded);

        self::assertSame(
            'succeeded',
            $this->connection->fetchOne(
                'SELECT status FROM collector_cycles WHERE cycle_token = :token',
                ['token' => $this->token('b')->binary()],
            ),
        );
        self::assertNull($this->connection->fetchOne(
            "SELECT lease_owner FROM collector_schedule WHERE schedule_name = 'inventory'",
        ));
    }

    /** @return iterable<string, array{string, string, int}> */
    public static function staleIdentities(): iterable
    {
        yield 'owner' => ['x', 'b', 1];
        yield 'token' => ['a', 'x', 1];
        yield 'fence' => ['a', 'b', 2];
    }

    #[DataProvider('staleIdentities')]
    public function testWrongOwnerTokenOrFenceCannotRenew(string $ownerByte, string $tokenByte, int $fence): void
    {
        $worker = $this->worker('a');
        $this->recordHeartbeat($worker);
        $store = new DbalCollectorScheduleStore($this->connection);
        $store->bootstrap(120);
        $claimed = $store->claimDue($worker, $this->token('b'), 10);
        self::assertNotNull($claimed->lease);
        $stale = new CollectorLease($this->worker($ownerByte), $this->token($tokenByte), $fence, $claimed->lease->expiresAt);

        $this->expectException(CollectorLeaseOwnershipLost::class);
        $store->renew($stale, 10);
    }

    public function testExpiredLeaseIsAbandonedAndReplicasWaitForTheSameStrictlyFutureTick(): void
    {
        $workerA = $this->worker('a');
        $workerB = $this->worker('b');
        $this->recordHeartbeat($workerA);
        $this->recordHeartbeat($workerB);
        $store = new DbalCollectorScheduleStore($this->connection);
        $store->bootstrap(120);
        $first = $store->claimDue($workerA, $this->token('a'), 1);
        self::assertNotNull($first->lease);

        $expiryDeadline = microtime(true) + 3;
        while ($this->databaseNow() < $first->lease->expiresAt && microtime(true) < $expiryDeadline) {
            usleep(10_000);
        }
        self::assertGreaterThanOrEqual($first->lease->expiresAt, $this->databaseNow());
        $second = $store->claimDue($workerB, $this->token('b'), 10);
        self::assertNull($second->lease);
        self::assertGreaterThan($second->databaseNow, $second->retryAt);
        self::assertSame(1, $this->databaseInteger('SELECT COUNT(*) FROM collector_cycles'));
        self::assertNull($this->connection->fetchOne(
            "SELECT lease_owner FROM collector_schedule WHERE schedule_name = 'inventory'",
        ));
        self::assertSame(
            'abandoned',
            $this->connection->fetchOne(
                'SELECT status FROM collector_cycles WHERE cycle_token = :token',
                ['token' => $this->token('a')->binary()],
            ),
        );

        $replicaConnection = DriverManager::getConnection($this->connection->getParams());
        try {
            $replicaDecision = (new DbalCollectorScheduleStore($replicaConnection))->claimDue(
                $workerB,
                $this->token('c'),
                10,
            );
        } finally {
            $replicaConnection->close();
        }
        self::assertNull($replicaDecision->lease);
        self::assertSame($second->retryAt->format('U.u'), $replicaDecision->retryAt->format('U.u'));

        foreach (['renew', 'finalize'] as $operation) {
            try {
                match ($operation) {
                    'renew' => $store->renew($first->lease, 10),
                    'finalize' => $store->finalize($first->lease, CollectorCycleStatus::Failed, 1),
                };
                self::fail(sprintf('Stale operation %s should fail.', $operation));
            } catch (CollectorLeaseOwnershipLost) {
                self::addToAssertionCount(1);
            }
        }
    }

    public function testRenewUsesPostLockDatabaseTimeAndRejectsALeaseThatExpiresWhileWaiting(): void
    {
        $worker = $this->worker('a');
        $this->recordHeartbeat($worker);
        $store = new DbalCollectorScheduleStore($this->connection);
        $store->bootstrap(120);
        $claimed = $store->claimDue($worker, $this->token('b'), 10);
        self::assertNotNull($claimed->lease);
        $this->connection->executeStatement(
            "UPDATE collector_schedule SET lease_expires_at = UTC_TIMESTAMP(6) + INTERVAL 2 SECOND WHERE schedule_name = 'inventory'",
        );
        $expiryValue = $this->connection->fetchOne(
            "SELECT DATE_FORMAT(lease_expires_at, '%Y-%m-%d %H:%i:%s.%f') FROM collector_schedule WHERE schedule_name = 'inventory'",
        );
        self::assertIsString($expiryValue);
        $expiry = $this->parseDatabaseDate($expiryValue);
        $lease = new CollectorLease(
            $claimed->lease->ownerId,
            $claimed->lease->token,
            $claimed->lease->fencingToken,
            $expiry,
        );

        $directory = sys_get_temp_dir().'/hoddmimir-collector-renew-lock-'.bin2hex(random_bytes(6));
        self::assertTrue(mkdir($directory, 0700));
        $params = $this->connection->getParams();
        $this->connection->close();
        $children = [];
        try {
            $pid = pcntl_fork();
            self::assertNotSame(-1, $pid);
            if (0 === $pid) {
                $child = DriverManager::getConnection($params);
                file_put_contents($directory.'/ready', '1');
                $this->waitForFile($directory.'/go');
                file_put_contents($directory.'/attempting', '1');
                try {
                    (new DbalCollectorScheduleStore($child))->renew($lease, 10);
                    file_put_contents($directory.'/result', 'renewed');
                } catch (CollectorLeaseOwnershipLost) {
                    file_put_contents($directory.'/result', 'lost');
                }
                $child->close();
                exit(0);
            }
            $children[] = $pid;

            $this->waitForFile($directory.'/ready');
            $locker = DriverManager::getConnection($params);
            $locker->beginTransaction();
            $locker->fetchAssociative("SELECT * FROM collector_schedule WHERE schedule_name = 'inventory' FOR UPDATE");
            file_put_contents($directory.'/go', '1');
            $this->waitForFile($directory.'/attempting');
            usleep(100_000);
            $deadline = microtime(true) + 4;
            while ($this->databaseNowFrom($locker) < $expiry && microtime(true) < $deadline) {
                usleep(10_000);
            }
            self::assertGreaterThanOrEqual($expiry, $this->databaseNowFrom($locker));
            $locker->commit();
            $locker->close();
            $this->waitForChildren($children);
            $children = [];
            self::assertSame('lost', trim((string) file_get_contents($directory.'/result')));
        } finally {
            $this->terminateChildren($children);
            $this->removeDirectory($directory);
            $this->connection = DriverManager::getConnection($params);
        }
    }

    public function testFinalizeUsesPostLockDatabaseTimeForTheNextStrictlyFutureGridTick(): void
    {
        $worker = $this->worker('a');
        $this->recordHeartbeat($worker);
        $store = new DbalCollectorScheduleStore($this->connection);
        $store->bootstrap(1);
        $claimed = $store->claimDue($worker, $this->token('b'), 10);
        self::assertNotNull($claimed->lease);

        $directory = sys_get_temp_dir().'/hoddmimir-collector-finalize-lock-'.bin2hex(random_bytes(6));
        self::assertTrue(mkdir($directory, 0700));
        $params = $this->connection->getParams();
        $this->connection->close();
        $children = [];
        try {
            $pid = pcntl_fork();
            self::assertNotSame(-1, $pid);
            if (0 === $pid) {
                $child = DriverManager::getConnection($params);
                file_put_contents($directory.'/ready', '1');
                $this->waitForFile($directory.'/go');
                file_put_contents($directory.'/attempting', '1');
                try {
                    $next = (new DbalCollectorScheduleStore($child))->finalize(
                        $claimed->lease,
                        CollectorCycleStatus::Succeeded,
                        1,
                    );
                    file_put_contents($directory.'/result', $next->format('Y-m-d H:i:s.u'));
                } catch (\Throwable $exception) {
                    file_put_contents($directory.'/result', 'error:'.$exception::class);
                    $child->close();
                    exit(2);
                }
                $child->close();
                exit(0);
            }
            $children[] = $pid;

            $this->waitForFile($directory.'/ready');
            $locker = DriverManager::getConnection($params);
            $locker->beginTransaction();
            $locker->fetchAssociative("SELECT * FROM collector_schedule WHERE schedule_name = 'inventory' FOR UPDATE");
            file_put_contents($directory.'/go', '1');
            $this->waitForFile($directory.'/attempting');
            usleep(100_000);
            $releaseAfter = $this->databaseNowFrom($locker)->modify('+2 seconds');
            while ($this->databaseNowFrom($locker) < $releaseAfter) {
                usleep(10_000);
            }
            $releasedAt = $this->databaseNowFrom($locker);
            $locker->commit();
            $locker->close();
            $this->waitForChildren($children);
            $children = [];

            $result = trim((string) file_get_contents($directory.'/result'));
            self::assertStringNotContainsString('error:', $result);
            $next = $this->parseDatabaseDate($result);
            self::assertGreaterThan($releasedAt, $next);
            $this->connection = DriverManager::getConnection($params);
            self::assertSame(
                $next->format('Y-m-d H:i:s.u'),
                $this->connection->fetchOne(
                    "SELECT DATE_FORMAT(next_scan_at, '%Y-%m-%d %H:%i:%s.%f') FROM collector_schedule WHERE schedule_name = 'inventory'",
                ),
            );
        } finally {
            $this->terminateChildren($children);
            $this->removeDirectory($directory);
            if (!$this->connection->isConnected()) {
                $this->connection = DriverManager::getConnection($params);
            }
        }
    }

    /** @return iterable<string, array{CollectorCycleStatus, string, string}> */
    public static function closingOutcomes(): iterable
    {
        yield 'failed' => [CollectorCycleStatus::Failed, 'failed', 'collector_cycle_failed'];
        yield 'cancelled' => [CollectorCycleStatus::Cancelled, 'cancelled', 'collector_stopped'];
    }

    #[DataProvider('closingOutcomes')]
    public function testNormalFinalizeRejectsRunningSyncButFailureOrCancellationClosesItAtomically(
        CollectorCycleStatus $outcome,
        string $expectedStatus,
        string $expectedCode,
    ): void
    {
        $worker = $this->worker('a');
        $token = $this->token('b');
        $this->recordHeartbeat($worker);
        $store = new DbalCollectorScheduleStore($this->connection);
        $store->bootstrap(120);
        $claimed = $store->claimDue($worker, $token, 10);
        self::assertNotNull($claimed->lease);
        $connectionId = random_bytes(16);
        $this->connection->insert('proxmox_connections', [
            'id' => $connectionId,
            'display_name' => 'Running sync finalization guard '.bin2hex($connectionId),
            'product' => 'pve',
            'enabled' => 1,
            'revision' => 1,
            'created_at' => self::NOW,
            'updated_at' => self::NOW,
        ]);
        $this->connection->insert('inventory_sync_runs', [
            'id' => random_bytes(16),
            'cycle_token' => $token->binary(),
            'collector_fencing_token' => $claimed->lease->fencingToken,
            'connection_id' => $connectionId,
            'expected_connection_revision' => 1,
            'status' => 'running',
            'authoritative' => 0,
            'started_at' => self::NOW,
            'heartbeat_at' => self::NOW,
        ]);

        try {
            $store->finalize($claimed->lease, CollectorCycleStatus::Succeeded, 1);
            self::fail('A successful cycle must not hide a running sync.');
        } catch (\RuntimeException $exception) {
            self::assertStringContainsString('still running', $exception->getMessage());
        }
        self::assertSame('running', $this->connection->fetchOne(
            'SELECT status FROM inventory_sync_runs WHERE cycle_token = :token',
            ['token' => $token->binary()],
        ));

        $store->finalize($claimed->lease, $outcome, 2);
        self::assertSame(
            ['status' => $expectedStatus, 'error_code' => $expectedCode],
            $this->connection->fetchAssociative(
                'SELECT status, error_code FROM inventory_sync_runs WHERE cycle_token = :token',
                ['token' => $token->binary()],
            ),
        );
    }

    public function testHeartbeatFreshnessAndStoppingStateUseDatabaseUtc(): void
    {
        $worker = $this->worker('a');
        $store = new DbalCollectorHeartbeatStore($this->connection);
        $store->record($worker, CollectorWorkerStatus::Ready, 10, 'test-build');
        self::assertTrue($store->isFresh($worker));

        $store->record($worker, CollectorWorkerStatus::Busy, 10, 'test-build', $this->token('b'));
        self::assertTrue($store->isFresh($worker));
        $store->record($worker, CollectorWorkerStatus::Degraded, 10, 'test-build');
        self::assertTrue($store->isFresh($worker));
        $store->record($worker, CollectorWorkerStatus::Starting, 10, 'test-build');
        self::assertFalse($store->isFresh($worker));
        $store->record($worker, CollectorWorkerStatus::Stopping, 10, 'test-build');
        self::assertFalse($store->isFresh($worker));

        $stored = $this->connection->fetchOne(
            'SELECT TIMESTAMPDIFF(MICROSECOND, heartbeat_at, expires_at) FROM worker_heartbeats WHERE worker_instance_id = :id',
            ['id' => $worker->bytes],
        );
        self::assertSame(10_000_000, $this->integer($stored));
    }

    public function testHeartbeatHealthNeverMasksTheExactWorkerWithAnotherReplica(): void
    {
        $exactWorker = $this->worker('a');
        $otherWorker = $this->worker('b');
        $store = new DbalCollectorHeartbeatStore($this->connection);
        $store->record($exactWorker, CollectorWorkerStatus::Ready, 10, 'test-build');
        $store->record($otherWorker, CollectorWorkerStatus::Ready, 10, 'test-build');
        $store->record($exactWorker, CollectorWorkerStatus::Stopping, 10, 'test-build');

        self::assertFalse($store->isFresh($exactWorker));
        self::assertTrue($store->isFresh($otherWorker));

        $this->connection->executeStatement(
            <<<'SQL'
                UPDATE worker_heartbeats
                SET started_at = UTC_TIMESTAMP(6) - INTERVAL 20 SECOND,
                    heartbeat_at = UTC_TIMESTAMP(6) - INTERVAL 20 SECOND,
                    expires_at = UTC_TIMESTAMP(6) - INTERVAL 10 SECOND
                WHERE worker_instance_id = :worker_id
                SQL,
            ['worker_id' => $otherWorker->bytes],
        );
        self::assertFalse($store->isFresh($otherWorker));
    }

    public function testTwoIndependentMariaDbConnectionsRaceToOneWinner(): void
    {
        $store = new DbalCollectorScheduleStore($this->connection);
        $store->bootstrap(120);
        $workers = [$this->worker('a'), $this->worker('b')];
        foreach ($workers as $worker) {
            $this->recordHeartbeat($worker);
        }

        $directory = sys_get_temp_dir().'/hoddmimir-collector-race-'.bin2hex(random_bytes(6));
        self::assertTrue(mkdir($directory, 0700));
        $params = $this->connection->getParams();
        // Do not fork an open PDO socket: child shutdown would send QUIT on the
        // inherited connection and invalidate the parent's file descriptor.
        $this->connection->close();
        $children = [];
        try {
            foreach ([0, 1] as $index) {
                $pid = pcntl_fork();
                self::assertNotSame(-1, $pid);
                if (0 === $pid) {
                    $child = DriverManager::getConnection($params);
                    file_put_contents($directory.'/ready-'.$index, '1');
                    $childDeadline = microtime(true) + 5;
                    while (!is_file($directory.'/go') && microtime(true) < $childDeadline) {
                        usleep(1_000);
                    }
                    if (!is_file($directory.'/go')) {
                        $child->close();
                        exit(2);
                    }
                    $decision = (new DbalCollectorScheduleStore($child))->claimDue(
                        $workers[$index],
                        $this->token(0 === $index ? 'c' : 'd'),
                        10,
                    );
                    file_put_contents($directory.'/result-'.$index, $decision->isClaimed() ? 'won' : 'wait');
                    $child->close();
                    exit(0);
                }
                $children[] = $pid;
            }

            $deadline = microtime(true) + 5;
            while ((!is_file($directory.'/ready-0') || !is_file($directory.'/ready-1')) && microtime(true) < $deadline) {
                usleep(1_000);
            }
            self::assertFileExists($directory.'/ready-0');
            self::assertFileExists($directory.'/ready-1');
            file_put_contents($directory.'/go', '1');

            foreach ($children as $pid) {
                $status = 0;
                pcntl_waitpid($pid, $status);
                self::assertIsInt($status);
                self::assertTrue(pcntl_wifexited($status));
                self::assertSame(0, pcntl_wexitstatus($status));
            }
            $children = [];

            $results = [trim((string) file_get_contents($directory.'/result-0')), trim((string) file_get_contents($directory.'/result-1'))];
            sort($results);
            self::assertSame(['wait', 'won'], $results);
            self::assertSame(1, $this->databaseInteger('SELECT COUNT(*) FROM collector_cycles'));
        } finally {
            foreach ($children as $pid) {
                $status = 0;
                if (0 === pcntl_waitpid($pid, $status, WNOHANG)) {
                    posix_kill($pid, SIGKILL);
                    pcntl_waitpid($pid, $status);
                }
            }
            foreach (glob($directory.'/*') ?: [] as $file) {
                unlink($file);
            }
            if (is_dir($directory)) {
                rmdir($directory);
            }
        }
    }

    public function testRuntimeCanRenewAndFinalizeAfterInnerCheckpointRenewedOnAnotherConnection(): void
    {
        $worker = $this->worker('a');
        $this->recordHeartbeat($worker);
        $runtimeStore = new DbalCollectorScheduleStore($this->connection);
        $runtimeStore->bootstrap(120);
        $claimed = $runtimeStore->claimDue($worker, $this->token('b'), 30);
        self::assertNotNull($claimed->lease);

        $innerConnection = DriverManager::getConnection($this->connection->getParams());
        try {
            $innerStore = new DbalCollectorScheduleStore($innerConnection);
            $innerStore->renew($claimed->lease, 30);

            // The outer runtime checkpoint intentionally still holds its own
            // lease value object. Ownership is the owner/token/fence tuple,
            // so it can renew from current DB state and then finalize safely.
            $runtimeLease = $runtimeStore->renew($claimed->lease, 30);
            $next = $runtimeStore->finalize($runtimeLease, CollectorCycleStatus::Succeeded, 1);
        } finally {
            $innerConnection->close();
        }

        self::assertGreaterThan($this->databaseNow(), $next);
        self::assertSame('succeeded', $this->connection->fetchOne(
            'SELECT status FROM collector_cycles WHERE cycle_token = :token',
            ['token' => $this->token('b')->binary()],
        ));
    }

    private function waitForFile(string $path): void
    {
        $deadline = microtime(true) + 5;
        while (!is_file($path) && microtime(true) < $deadline) {
            usleep(1_000);
        }
        self::assertFileExists($path);
    }

    /** @param list<int> $children */
    private function waitForChildren(array $children): void
    {
        foreach ($children as $pid) {
            $status = 0;
            pcntl_waitpid($pid, $status);
            self::assertIsInt($status);
            self::assertTrue(pcntl_wifexited($status));
            self::assertSame(0, pcntl_wexitstatus($status));
        }
    }

    /** @param list<int> $children */
    private function terminateChildren(array $children): void
    {
        foreach ($children as $pid) {
            $status = 0;
            if (0 === pcntl_waitpid($pid, $status, WNOHANG)) {
                posix_kill($pid, SIGKILL);
                pcntl_waitpid($pid, $status);
            }
        }
    }

    private function removeDirectory(string $directory): void
    {
        foreach (glob($directory.'/*') ?: [] as $file) {
            unlink($file);
        }
        if (is_dir($directory)) {
            rmdir($directory);
        }
    }

    private function recordHeartbeat(CollectorWorkerId $worker): void
    {
        (new DbalCollectorHeartbeatStore($this->connection))->record(
            $worker,
            CollectorWorkerStatus::Ready,
            30,
            'integration-test',
        );
    }

    private function worker(string $byte): CollectorWorkerId
    {
        return new CollectorWorkerId(str_repeat($byte, 16));
    }

    private function token(string $byte): CollectorCycleToken
    {
        return new CollectorCycleToken(str_repeat($byte, 16));
    }

    private function databaseNow(): \DateTimeImmutable
    {
        return $this->databaseNowFrom($this->connection);
    }

    private function databaseNowFrom(Connection $connection): \DateTimeImmutable
    {
        $value = $connection->fetchOne('SELECT UTC_TIMESTAMP(6)');
        self::assertIsString($value);
        return $this->parseDatabaseDate($value);
    }

    private function parseDatabaseDate(string $value): \DateTimeImmutable
    {
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d H:i:s.u', $value, new \DateTimeZone('UTC'));
        self::assertInstanceOf(\DateTimeImmutable::class, $date);
        return $date;
    }

    private function databaseInteger(string $sql): int
    {
        return $this->integer($this->connection->fetchOne($sql));
    }

    private function integer(mixed $value): int
    {
        if (!is_int($value) && !(is_string($value) && ctype_digit($value))) {
            self::fail('MariaDB returned a non-integer test value.');
        }
        return (int) $value;
    }

    private function clean(): void
    {
        $this->connection->executeStatement('SET FOREIGN_KEY_CHECKS = 0');
        foreach (['inventory_sync_failures', 'inventory_sync_runs', 'collector_cycles', 'worker_heartbeats', 'collector_schedule', 'proxmox_connections'] as $table) {
            $this->connection->executeStatement(sprintf('DELETE FROM %s', $table));
        }
        $this->connection->executeStatement('SET FOREIGN_KEY_CHECKS = 1');
    }
}

final class IntegrationNeverStopRequested implements StopRequested
{
    private int $reads = 0;

    public function isStopRequested(): bool
    {
        ++$this->reads;

        return false;
    }
}
