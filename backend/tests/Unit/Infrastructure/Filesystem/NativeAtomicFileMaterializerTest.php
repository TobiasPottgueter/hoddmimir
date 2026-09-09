<?php

declare(strict_types=1);

namespace App\Tests\Unit\Infrastructure\Filesystem;

use App\Infrastructure\Filesystem\NativeAtomicFileMaterializer;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class NativeAtomicFileMaterializerTest extends TestCase
{
    private ?string $temporaryDirectory = null;

    protected function tearDown(): void
    {
        if (null !== $this->temporaryDirectory && is_dir($this->temporaryDirectory)) {
            foreach (scandir($this->temporaryDirectory) ?: [] as $entry) {
                if ('.' !== $entry && '..' !== $entry) {
                    @unlink($this->temporaryDirectory.'/'.$entry);
                }
            }
            @rmdir($this->temporaryDirectory);
        }
    }

    public function testMaterializationCompletesWithinASignalBudgetAndPreservesAllBytes(): void
    {
        $this->temporaryDirectory = sys_get_temp_dir().'/hoddmimir-materializer-'.bin2hex(random_bytes(8));
        $previousAsyncSignals = pcntl_async_signals(true);
        $previousAlarmHandler = pcntl_signal_get_handler(SIGALRM);
        pcntl_signal(SIGALRM, static function (): never {
            throw new RuntimeException('Atomic materialization signal budget exceeded.');
        });
        pcntl_alarm(1);

        try {
            $path = (new NativeAtomicFileMaterializer())->materialize(
                $this->temporaryDirectory,
                'payload',
                'x',
                0700,
                0600,
            );
            self::assertSame('x', file_get_contents($path));
            self::assertSame(0600, fileperms($path) & 0777);
        } finally {
            pcntl_alarm(0);
            pcntl_signal(SIGALRM, $previousAlarmHandler);
            pcntl_async_signals($previousAsyncSignals);
        }
    }
}
