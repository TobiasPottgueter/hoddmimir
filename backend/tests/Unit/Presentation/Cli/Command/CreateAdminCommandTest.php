<?php

declare(strict_types=1);

namespace App\Tests\Unit\Presentation\Cli\Command;

use App\Application\Security\Auth\AdminBootstrapConflict;
use App\Application\Security\Auth\FirstAdminCreator;
use App\Presentation\Cli\Command\CreateAdminCommand;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

final class CreateAdminCommandTest extends TestCase
{
    public function testRejectsPasswordInArgvAndEnvironmentByExposingOnlySecureFileOption(): void
    {
        $creator = $this->createMock(FirstAdminCreator::class);
        $creator->expects(self::never())->method('create');
        $command = new CreateAdminCommand($creator);
        $definition = $command->getDefinition();
        self::assertFalse($definition->hasOption('password'));
        self::assertFalse($definition->hasOption('password-env'));
        self::assertTrue($definition->hasOption('password-file'));

        $tester = new CommandTester($command);
        self::assertSame(Command::FAILURE, $tester->execute([
            'username' => 'admin',
            'display-name' => 'Administrator',
            '--password-file' => '/tmp/password',
        ], ['interactive' => false]));
        self::assertStringNotContainsString('/tmp/password', $tester->getDisplay());
    }

    public function testReadsDirectDockerSecretAndPassesExplicitIdempotencyWithoutEchoingPassword(): void
    {
        $path = '/run/secrets/hoddmimir-unit-admin-password';
        if (!$this->secretDirectoryAvailable()) {
            self::markTestSkipped('/run/secrets is unavailable in this runtime.');
        }
        file_put_contents($path, "super-secret\n");
        try {
            $creator = $this->createMock(FirstAdminCreator::class);
            $creator->expects(self::once())->method('create')->with('admin', 'Administrator', 'super-secret', true)->willReturn(false);
            $tester = new CommandTester(new CreateAdminCommand($creator));
            self::assertSame(Command::SUCCESS, $tester->execute([
                'username' => 'admin',
                'display-name' => 'Administrator',
                '--password-file' => $path,
                '--idempotent' => true,
            ], ['interactive' => false]));
            self::assertStringNotContainsString('super-secret', $tester->getDisplay());
        } finally {
            @unlink($path);
        }
    }

    public function testReportsIdempotencyConflictWithoutSecretDetails(): void
    {
        $creator = $this->createStub(FirstAdminCreator::class);
        $creator->method('create')->willThrowException(new AdminBootstrapConflict('Persisted administrator differs.'));
        $path = '/run/secrets/hoddmimir-unit-admin-conflict';
        if (!$this->secretDirectoryAvailable()) {
            self::markTestSkipped('/run/secrets is unavailable in this runtime.');
        }
        file_put_contents($path, 'not-printed');
        try {
            $tester = new CommandTester(new CreateAdminCommand($creator));
            self::assertSame(Command::FAILURE, $tester->execute([
                'username' => 'admin',
                'display-name' => 'Administrator',
                '--password-file' => $path,
                '--idempotent' => true,
            ], ['interactive' => false]));
            self::assertStringNotContainsString('not-printed', $tester->getDisplay());
        } finally {
            @unlink($path);
        }
    }

    public function testRejectsSymlinkAndWritableDockerSecretFiles(): void
    {
        if (!$this->secretDirectoryAvailable()) {
            self::markTestSkipped('/run/secrets is unavailable in this runtime.');
        }
        $target = '/run/secrets/hoddmimir-unit-target';
        $link = '/run/secrets/hoddmimir-unit-link';
        $writable = '/run/secrets/hoddmimir-unit-writable';
        file_put_contents($target, 'secure-password');
        symlink($target, $link);
        file_put_contents($writable, 'secure-password');
        chmod($writable, 0666);
        try {
            foreach ([$link, $writable] as $path) {
                $creator = $this->createMock(FirstAdminCreator::class);
                $creator->expects(self::never())->method('create');
                $tester = new CommandTester(new CreateAdminCommand($creator));
                self::assertSame(Command::FAILURE, $tester->execute([
                    'username' => 'admin',
                    'display-name' => 'Administrator',
                    '--password-file' => $path,
                ], ['interactive' => false]));
            }
        } finally {
            @unlink($link);
            @unlink($target);
            @unlink($writable);
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
