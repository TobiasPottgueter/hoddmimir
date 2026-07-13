<?php

declare(strict_types=1);

namespace App\Tests\Smoke;

use App\Application\Collector\CollectorWorkerRunCode;
use App\Application\Collector\CollectorWorkerRunResult;
use App\Application\Collector\CollectorWorkerRunner;
use App\Application\Backup\Worker\BackupWorkerRuntimeSafety;
use App\Application\Backup\Execution\BackupExecutionGate;
use App\Application\Backup\Notification\BackupNotificationConfiguration;
use App\Application\Backup\Notification\BackupNotificationDeliveryGate;
use App\Application\Readiness\ReadinessAggregator;
use App\Application\Readiness\ReadinessCheckResult;
use App\Kernel;
use App\Tests\Fakes\FixedReadinessCheck;
use JsonException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\TestContainer;
use Symfony\Component\Console\Tester\CommandTester;

final class WorkerCommandsTest extends TestCase
{
    protected function setUp(): void
    {
        if (\function_exists('pcntl_alarm')) {
            \pcntl_async_signals(true);
            \pcntl_signal(\SIGALRM, static function (): never {
                throw new \RuntimeException('The worker command did not terminate.');
            });
            \pcntl_alarm(5);
        }
    }

    protected function tearDown(): void
    {
        if (\function_exists('pcntl_alarm')) {
            \pcntl_alarm(0);
            \pcntl_signal(\SIGALRM, \SIG_DFL);
        }
    }

    /** @return iterable<string, array{string}> */
    public static function workerKinds(): iterable
    {
        yield 'collector' => ['collector'];
        yield 'backup' => ['backup'];
    }

    /** @throws JsonException */
    public function testBackupWorkerCommandCanBoot(): void
    {
        $kernel = $this->readyKernel();
        $application = new Application($kernel);
        $tester = new CommandTester($application->find('hoddmimir:worker:backup'));

        self::assertSame(0, $tester->execute(['--once' => true]));

        /** @var array{component?: mixed, status?: mixed} $payload */
        $payload = json_decode(trim($tester->getDisplay()), true, 512, JSON_THROW_ON_ERROR);

        self::assertSame('backup', $payload['component'] ?? null);
        self::assertSame('ready', $payload['status'] ?? null);

        $kernel->shutdown();
    }

    public function testBackupWorkerRefusesExecutionWithoutProblemDelivery(): void
    {
        $kernel = $this->readyKernel();
        try {
            $testContainer = $kernel->getContainer()->get('test.service_container');
            self::assertInstanceOf(TestContainer::class, $testContainer);
            $testContainer->set(BackupWorkerRuntimeSafety::class, new BackupWorkerRuntimeSafety(
                new SmokeSafetyFlag(true),
                new SmokeSafetyFlag(false),
                new SmokeSafetyFlag(false),
            ));
            $application = new Application($kernel);
            $tester = new CommandTester($application->find('hoddmimir:worker:backup'));
            self::assertSame(1, $tester->execute(['--once' => true]));
            self::assertStringContainsString('requires enabled and valid problem notification delivery', $tester->getDisplay());
        } finally {
            $kernel->shutdown();
        }
    }

    public function testCollectorCommandUsesInjectedRuntimeAndHasNoIntervalOption(): void
    {
        $kernel = $this->readyKernel();
        $testContainer = $kernel->getContainer()->get('test.service_container');
        self::assertInstanceOf(TestContainer::class, $testContainer);
        $runner = new SmokeCollectorRunner();
        $testContainer->set(CollectorWorkerRunner::class, $runner);
        $application = new Application($kernel);
        $tester = new CommandTester($application->find('hoddmimir:worker:data'));

        self::assertFalse($application->find('hoddmimir:worker:data')->getDefinition()->hasOption('interval'));
        self::assertSame(0, $tester->execute(['--once' => true]));
        self::assertTrue($runner->once);
        self::assertStringContainsString('cycle_succeeded', $tester->getDisplay());

        $kernel->shutdown();
    }

    /** @throws JsonException */
    public function testBackupWorkerMainCommandFailsOnceWhenSchemaIsUnavailable(): void
    {
        $kernel = $this->kernelWithReadiness(ReadinessCheckResult::unavailable(
            'database_schema',
            'database_unavailable',
        ));
        $application = new Application($kernel);
        $tester = new CommandTester($application->find('hoddmimir:worker:backup'));

        self::assertSame(1, $tester->execute(['--once' => true]));
        /** @var array{component?: mixed, status?: mixed, checks?: mixed} $payload */
        $payload = json_decode(trim($tester->getDisplay()), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('backup', $payload['component'] ?? null);
        self::assertSame('unavailable', $payload['status'] ?? null);
        self::assertSame([
            'database_schema' => [
                'status' => 'unavailable',
                'reason' => 'database_unavailable',
            ],
        ], $payload['checks'] ?? null);

        $kernel->shutdown();
    }

    public function testCollectorAndExactHealthCommandsAreRegistered(): void
    {
        $kernel = $this->readyKernel();
        $application = new Application($kernel);
        self::assertSame('hoddmimir:worker:data', $application->find('hoddmimir:worker:data')->getName());
        self::assertSame('hoddmimir:worker:health', $application->find('hoddmimir:worker:health')->getName());

        $kernel->shutdown();
    }

    /** @throws JsonException */
    #[DataProvider('workerKinds')]
    public function testReadinessCommandIsRegisteredWithoutRunningAWorkerIteration(string $component): void
    {
        $kernel = $this->readyKernel();
        $application = new Application($kernel);
        $tester = new CommandTester($application->find('hoddmimir:worker:readiness'));

        self::assertSame(0, $tester->execute(['worker' => $component]));

        /** @var array{component?: mixed, status?: mixed} $payload */
        $payload = json_decode(trim($tester->getDisplay()), true, 512, JSON_THROW_ON_ERROR);

        self::assertSame($component, $payload['component'] ?? null);
        self::assertSame('ready', $payload['status'] ?? null);

        $kernel->shutdown();
    }

    private function readyKernel(): Kernel
    {
        return $this->kernelWithReadiness(ReadinessCheckResult::ready('database_schema'));
    }

    private function kernelWithReadiness(ReadinessCheckResult $result): Kernel
    {
        $kernel = new Kernel('test', false);
        $kernel->boot();
        $testContainer = $kernel->getContainer()->get('test.service_container');
        self::assertInstanceOf(TestContainer::class, $testContainer);
        $testContainer->set(
            ReadinessAggregator::class,
            new ReadinessAggregator([
                new FixedReadinessCheck($result),
            ]),
        );

        return $kernel;
    }
}

final readonly class SmokeSafetyFlag implements BackupExecutionGate, BackupNotificationDeliveryGate, BackupNotificationConfiguration
{
    public function __construct(private bool $value) {}
    public function enabled(): bool { return $this->value; }
    public function isValid(): bool { return $this->value; }
}

final class SmokeCollectorRunner implements CollectorWorkerRunner
{
    public bool $once = false;

    public function run(bool $once): CollectorWorkerRunResult
    {
        $this->once = $once;

        return new CollectorWorkerRunResult(CollectorWorkerRunCode::CycleSucceeded);
    }
}
