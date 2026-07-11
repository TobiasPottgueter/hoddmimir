<?php

declare(strict_types=1);

namespace App\Tests\Unit\Infrastructure\Process;

use App\Application\Collector\StopRequested;
use App\Infrastructure\Filesystem\NativeAtomicFileMaterializer;
use App\Infrastructure\Process\PcntlStopRequested;
use App\Infrastructure\Process\PersistentCollectorWorkerIdentity;
use App\Infrastructure\Process\SignalAwareCollectorRuntimeWaiter;
use App\Infrastructure\Process\SystemCollectorCycleTokenFactory;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class CollectorRuntimeInfrastructureTest extends TestCase
{
    /** @var list<string> */
    private array $temporaryDirectories = [];

    protected function tearDown(): void
    {
        foreach ($this->temporaryDirectories as $directory) {
            $this->remove($directory);
        }
    }

    public function testWorkerIdentityIsRandomPersistedStableAndReadOnlyHealthReadable(): void
    {
        $directory = $this->temporaryDirectory();
        $identity = new PersistentCollectorWorkerIdentity(new NativeAtomicFileMaterializer(), $directory);

        $first = $identity->workerId();
        $second = $identity->workerId();
        $path = $directory.'/collector-runtime/worker-id';

        self::assertSame($first, $second);
        self::assertSame($first->toHex()."\n", file_get_contents($path));
        self::assertSame(0600, fileperms($path) & 0777);
        self::assertSame(
            $first->toHex(),
            (new PersistentCollectorWorkerIdentity(new NativeAtomicFileMaterializer(), $directory))
                ->existingWorkerId()?->toHex(),
        );
        $nextProcess = new PersistentCollectorWorkerIdentity(new NativeAtomicFileMaterializer(), $directory);
        self::assertNotSame($first->toHex(), $nextProcess->workerId()->toHex());
    }

    public function testExistingIdentityIsMissingMalformedOrUnsafeFailClosed(): void
    {
        $directory = $this->temporaryDirectory();
        $identity = new PersistentCollectorWorkerIdentity(new NativeAtomicFileMaterializer(), $directory);
        self::assertNull($identity->existingWorkerId());

        mkdir($directory.'/collector-runtime', 0700, true);
        file_put_contents($directory.'/collector-runtime/worker-id', "NOT-AN-ID\n");
        chmod($directory.'/collector-runtime/worker-id', 0600);
        try {
            $identity->existingWorkerId();
            self::fail('Malformed identity accepted.');
        } catch (RuntimeException $failure) {
            self::assertSame('The collector worker identity file is malformed.', $failure->getMessage());
        }

        file_put_contents($directory.'/collector-runtime/worker-id', str_repeat('a', 32)."\n");
        chmod($directory.'/collector-runtime/worker-id', 0644);
        $this->expectException(RuntimeException::class);
        (new PersistentCollectorWorkerIdentity(new NativeAtomicFileMaterializer(), $directory))->existingWorkerId();
    }

    public function testCycleTokenFactoryProducesIndependentOpaqueTokens(): void
    {
        $factory = new SystemCollectorCycleTokenFactory();
        self::assertNotSame($factory->generate()->binary(), $factory->generate()->binary());
    }

    public function testWaiterRejectsOutOfBoundsAndReturnsImmediatelyAfterStop(): void
    {
        $stop = new MutableStopRequested(true);
        $waiter = new SignalAwareCollectorRuntimeWaiter($stop);
        $started = hrtime(true);
        $waiter->wait(30);
        self::assertLessThan(100_000_000, hrtime(true) - $started);

        foreach ([0, 31] as $seconds) {
            try {
                $waiter->wait($seconds);
                self::fail('Out-of-range runtime wait accepted.');
            } catch (InvalidArgumentException) {
                self::addToAssertionCount(1);
            }
        }
    }

    public function testPcntlHandlerOwnsTheShutdownFlag(): void
    {
        $stop = new PcntlStopRequested();
        self::assertFalse($stop->isStopRequested());
        $pid = getmypid();
        self::assertIsInt($pid);
        posix_kill($pid, SIGTERM);
        self::assertTrue($stop->isStopRequested());
        pcntl_signal(SIGTERM, SIG_DFL);
        pcntl_signal(SIGINT, SIG_DFL);
    }

    private function temporaryDirectory(): string
    {
        $directory = sys_get_temp_dir().'/hoddmimir-collector-'.bin2hex(random_bytes(8));
        mkdir($directory, 0700, true);
        $this->temporaryDirectories[] = $directory;

        return $directory;
    }

    private function remove(string $path): void
    {
        if (is_dir($path) && !is_link($path)) {
            foreach (scandir($path) ?: [] as $entry) {
                if ('.' !== $entry && '..' !== $entry) {
                    $this->remove($path.'/'.$entry);
                }
            }
            rmdir($path);
            return;
        }
        if (file_exists($path) || is_link($path)) {
            unlink($path);
        }
    }
}

final class MutableStopRequested implements StopRequested
{
    private int $reads = 0;

    public function __construct(private bool $requested)
    {
    }

    public function isStopRequested(): bool
    {
        ++$this->reads;

        return $this->requested;
    }
}
