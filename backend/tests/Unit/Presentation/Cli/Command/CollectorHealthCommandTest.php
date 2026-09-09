<?php

declare(strict_types=1);

namespace App\Tests\Unit\Presentation\Cli\Command;

use App\Application\Collector\CollectorCycleToken;
use App\Application\Collector\CollectorHeartbeatStore;
use App\Application\Collector\CollectorWorkerId;
use App\Application\Collector\CollectorWorkerIdentityReader;
use App\Application\Collector\CollectorWorkerStatus;
use App\Application\Readiness\ReadinessAggregator;
use App\Application\Readiness\ReadinessCheckResult;
use App\Application\Worker\WorkerReadinessProbe;
use App\Presentation\Cli\Command\CollectorHealthCommand;
use App\Tests\Fakes\FixedReadinessCheck;
use App\Tests\Fakes\FrozenClock;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\Console\Tester\CommandTester;

final class CollectorHealthCommandTest extends TestCase
{
    public function testHealthyRequiresBaseReadinessExactIdentityAndFreshHeartbeat(): void
    {
        $tester = $this->tester(true, new HealthIdentityReader($this->workerId()), true);
        self::assertSame(0, $tester->execute(['worker' => 'collector']));
        self::assertJsonStringEqualsJsonString(
            '{"component":"collector","status":"healthy","code":"collector_heartbeat_fresh"}',
            trim($tester->getDisplay()),
        );
    }

    public function testUnsupportedWorkerIsInvalid(): void
    {
        $tester = $this->tester(true, new HealthIdentityReader($this->workerId()), true);
        self::assertSame(2, $tester->execute(['worker' => 'backup']));
        self::assertStringContainsString('unsupported_worker', $tester->getDisplay());
    }

    public function testReadinessIdentityAndHeartbeatFailuresAreDistinctAndFailClosed(): void
    {
        $cases = [
            [false, new HealthIdentityReader($this->workerId()), true, 'collector_not_ready'],
            [true, new HealthIdentityReader(null), true, 'collector_identity_missing'],
            [true, new HealthIdentityReader($this->workerId()), false, 'collector_heartbeat_unhealthy'],
        ];
        foreach ($cases as [$ready, $identity, $fresh, $code]) {
            $tester = $this->tester($ready, $identity, $fresh);
            self::assertSame(1, $tester->execute(['worker' => 'collector']));
            self::assertStringContainsString($code, $tester->getDisplay());
        }
    }

    public function testMalformedIdentityOrDatabaseFailureDoesNotLeakExceptionText(): void
    {
        $identity = new HealthIdentityReader(null, new RuntimeException('TOKEN-SENTINEL'));
        $tester = $this->tester(true, $identity, true);
        self::assertSame(1, $tester->execute(['worker' => 'collector']));
        self::assertStringContainsString('collector_healthcheck_failed', $tester->getDisplay());
        self::assertStringNotContainsString('TOKEN-SENTINEL', $tester->getDisplay());
    }

    private function tester(
        bool $ready,
        CollectorWorkerIdentityReader $identity,
        bool $fresh,
    ): CommandTester {
        $readiness = $ready
            ? ReadinessCheckResult::ready('database_schema')
            : ReadinessCheckResult::unavailable('database_schema', 'database_unavailable');
        $probe = new WorkerReadinessProbe(
            new FrozenClock(new DateTimeImmutable('2026-07-11T00:00:00Z')),
            new ReadinessAggregator([new FixedReadinessCheck($readiness)]),
        );

        return new CommandTester(new CollectorHealthCommand(
            $probe,
            $identity,
            new HealthHeartbeatStore($fresh),
        ));
    }

    private function workerId(): CollectorWorkerId
    {
        return new CollectorWorkerId(str_repeat('w', 16));
    }
}

final readonly class HealthIdentityReader implements CollectorWorkerIdentityReader
{
    public function __construct(
        private ?CollectorWorkerId $workerId,
        private ?RuntimeException $failure = null,
    ) {
    }

    public function existingWorkerId(): ?CollectorWorkerId
    {
        if (null !== $this->failure) {
            throw $this->failure;
        }

        return $this->workerId;
    }
}

final readonly class HealthHeartbeatStore implements CollectorHeartbeatStore
{
    public function __construct(private bool $fresh)
    {
    }

    public function record(
        CollectorWorkerId $workerId,
        CollectorWorkerStatus $status,
        int $ttlSeconds,
        string $buildVersion,
        ?CollectorCycleToken $cycleToken = null,
        ?DateTimeImmutable $nextActionAt = null,
    ): void {
    }

    public function isFresh(CollectorWorkerId $workerId): bool
    {
        return $this->fresh;
    }
}
