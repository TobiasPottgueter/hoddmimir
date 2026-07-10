<?php

declare(strict_types=1);

namespace App\Tests\Smoke;

use App\Kernel;
use JsonException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;

final class WorkerCommandsTest extends TestCase
{
    /** @return iterable<string, array{string, string}> */
    public static function commands(): iterable
    {
        yield 'collector' => ['hoddmimir:worker:data', 'collector'];
        yield 'backup' => ['hoddmimir:worker:backup', 'backup'];
    }

    /** @return iterable<string, array{string}> */
    public static function workerKinds(): iterable
    {
        yield 'collector' => ['collector'];
        yield 'backup' => ['backup'];
    }

    /** @throws JsonException */
    #[DataProvider('commands')]
    public function testWorkerCommandCanBoot(string $commandName, string $component): void
    {
        $kernel = new Kernel('test', false);
        $application = new Application($kernel);
        $tester = new CommandTester($application->find($commandName));

        self::assertSame(0, $tester->execute(['--once' => true]));

        /** @var array{component?: mixed, status?: mixed} $payload */
        $payload = json_decode(trim($tester->getDisplay()), true, 512, JSON_THROW_ON_ERROR);

        self::assertSame($component, $payload['component'] ?? null);
        self::assertSame('ready', $payload['status'] ?? null);

        $kernel->shutdown();
    }

    public function testWorkerCommandRejectsAnInvalidInterval(): void
    {
        $kernel = new Kernel('test', false);
        $application = new Application($kernel);
        $tester = new CommandTester($application->find('hoddmimir:worker:data'));

        self::assertSame(2, $tester->execute(['--once' => true, '--interval' => '0']));
        self::assertStringContainsString('positive integer', $tester->getDisplay());

        $kernel->shutdown();
    }

    /** @throws JsonException */
    #[DataProvider('workerKinds')]
    public function testReadinessCommandIsRegisteredWithoutRunningAWorkerIteration(string $component): void
    {
        $kernel = new Kernel('test', false);
        $application = new Application($kernel);
        $tester = new CommandTester($application->find('hoddmimir:worker:readiness'));

        self::assertSame(0, $tester->execute(['worker' => $component]));

        /** @var array{component?: mixed, status?: mixed} $payload */
        $payload = json_decode(trim($tester->getDisplay()), true, 512, JSON_THROW_ON_ERROR);

        self::assertSame($component, $payload['component'] ?? null);
        self::assertSame('ready', $payload['status'] ?? null);

        $kernel->shutdown();
    }
}
