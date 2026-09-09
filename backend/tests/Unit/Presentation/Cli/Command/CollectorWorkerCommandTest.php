<?php

declare(strict_types=1);

namespace App\Tests\Unit\Presentation\Cli\Command;

use App\Application\Collector\CollectorWorkerRunCode;
use App\Application\Collector\CollectorWorkerRunResult;
use App\Application\Collector\CollectorWorkerRunner;
use App\Presentation\Cli\Command\CollectorWorkerCommand;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

final class CollectorWorkerCommandTest extends TestCase
{
    /** @return iterable<string, array{CollectorWorkerRunCode, int}> */
    public static function outcomes(): iterable
    {
        yield 'success' => [CollectorWorkerRunCode::CycleSucceeded, 0];
        yield 'not due' => [CollectorWorkerRunCode::NoCycleDue, 0];
        yield 'partial' => [CollectorWorkerRunCode::CyclePartial, 1];
        yield 'failed' => [CollectorWorkerRunCode::CycleFailed, 1];
    }

    #[DataProvider('outcomes')]
    public function testItEmitsOnlyTheStableResultAndReturnsItsExitCode(
        CollectorWorkerRunCode $code,
        int $exitCode,
    ): void {
        $runner = new CommandCollectorRunner(new CollectorWorkerRunResult($code));
        $command = new CollectorWorkerCommand($runner);
        $tester = new CommandTester($command);

        self::assertSame($exitCode, $tester->execute(['--once' => true]));
        self::assertTrue($runner->once);
        self::assertSame([
            'component' => 'collector',
            'status' => 0 === $exitCode ? 'ok' : 'failed',
            'code' => $code->value,
        ], json_decode(trim($tester->getDisplay()), true, 512, JSON_THROW_ON_ERROR));
        self::assertFalse($command->getDefinition()->hasOption('interval'));
    }
}

final class CommandCollectorRunner implements CollectorWorkerRunner
{
    public bool $once = false;
    public function __construct(private readonly CollectorWorkerRunResult $result) {}
    public function run(bool $once): CollectorWorkerRunResult
    {
        $this->once = $once;

        return $this->result;
    }
}
