<?php

declare(strict_types=1);

namespace App\Tests\Unit\Presentation\Cli\Command;

use App\Application\Qa\QaFixtureSeeder;
use App\Application\Security\Auth\FirstAdminCreator;
use App\Presentation\Cli\Command\SeedQaCommand;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\HttpKernel\KernelInterface;

final class SeedQaCommandTest extends TestCase
{
    public function testRejectsEveryEnvironmentExceptTestBeforeReadingSecrets(): void
    {
        $kernel = $this->createStub(KernelInterface::class);
        $kernel->method('getEnvironment')->willReturn('prod');
        $creator = $this->createMock(FirstAdminCreator::class);
        $creator->expects(self::never())->method('create');
        $fixtures = $this->createMock(QaFixtureSeeder::class);
        $fixtures->expects(self::never())->method('seed');

        $tester = new CommandTester(new SeedQaCommand($kernel, $creator, $fixtures));
        self::assertSame(Command::FAILURE, $tester->execute([
            '--password-file' => '/run/secrets/not-read',
        ], ['interactive' => false]));
        self::assertSame("QA fixture seeding failed.\n", $tester->getDisplay());
    }

    public function testRejectsPasswordOutsideDockerSecretsWithoutEchoingIt(): void
    {
        $kernel = $this->createStub(KernelInterface::class);
        $kernel->method('getEnvironment')->willReturn('test');
        $creator = $this->createMock(FirstAdminCreator::class);
        $creator->expects(self::never())->method('create');
        $fixtures = $this->createMock(QaFixtureSeeder::class);
        $fixtures->expects(self::never())->method('seed');

        $tester = new CommandTester(new SeedQaCommand($kernel, $creator, $fixtures));
        self::assertSame(Command::FAILURE, $tester->execute([
            '--password-file' => '/tmp/qa-password',
        ], ['interactive' => false]));
        self::assertStringNotContainsString('/tmp/qa-password', $tester->getDisplay());
    }

    public function testCreatesExactQaAdminAndSeedsFixtureWithoutEchoingPassword(): void
    {
        $path = '/run/secrets/hoddmimir-unit-qa-password';
        if (!$this->secretDirectoryAvailable()) {
            self::markTestSkipped('/run/secrets is unavailable in this runtime.');
        }
        file_put_contents($path, "qa-password-only-for-tests\n");
        chmod($path, 0600);
        try {
            $kernel = $this->createStub(KernelInterface::class);
            $kernel->method('getEnvironment')->willReturn('test');
            $creator = $this->createMock(FirstAdminCreator::class);
            $creator->expects(self::once())->method('create')->with(
                'qa-admin',
                'QA Administrator',
                'qa-password-only-for-tests',
                true,
            );
            $fixtures = $this->createMock(QaFixtureSeeder::class);
            $fixtures->expects(self::once())->method('seed');

            $tester = new CommandTester(new SeedQaCommand($kernel, $creator, $fixtures));
            self::assertSame(Command::SUCCESS, $tester->execute([
                '--password-file' => $path,
            ], ['interactive' => false]));
            self::assertStringContainsString('QA fixture is ready.', $tester->getDisplay());
            self::assertStringNotContainsString('qa-password-only-for-tests', $tester->getDisplay());
        } finally {
            @unlink($path);
        }
    }

    private function secretDirectoryAvailable(): bool
    {
        if (!is_dir('/run/secrets')) {
            @mkdir('/run/secrets', 0700, true);
        }

        return is_dir('/run/secrets') && is_writable('/run/secrets');
    }
}
