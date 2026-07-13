<?php

declare(strict_types=1);

namespace App\Presentation\Cli\Command;

use App\Application\Qa\QaFixtureSeeder;
use App\Application\Security\Auth\FirstAdminCreator;
use RuntimeException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\HttpKernel\KernelInterface;
use Throwable;

#[AsCommand(name: 'hoddmimir:qa:seed', description: 'Seed the isolated test-only browser QA fixture. Never available as an HTTP endpoint.')]
final class SeedQaCommand extends Command
{
    public function __construct(
        private readonly KernelInterface $kernel,
        private readonly FirstAdminCreator $createAdmin,
        private readonly QaFixtureSeeder $fixtures,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('password-file', null, InputOption::VALUE_REQUIRED, 'Direct /run/secrets file containing the QA administrator password.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            if ('test' !== $this->kernel->getEnvironment()) {
                throw new RuntimeException('QA fixtures are restricted to the test environment.');
            }
            $this->createAdmin->create('qa-admin', 'QA Administrator', $this->password($input), true);
            $this->fixtures->seed();
            $output->writeln('QA fixture is ready.');

            return self::SUCCESS;
        } catch (Throwable) {
            $output->writeln('<error>QA fixture seeding failed.</error>');

            return self::FAILURE;
        }
    }

    private function password(InputInterface $input): string
    {
        $path = $input->getOption('password-file');
        if (!is_string($path) || 1 !== preg_match('#\A/run/secrets/[A-Za-z0-9][A-Za-z0-9._-]{0,127}\z#D', $path)) {
            throw new RuntimeException('The password file must be a direct child of /run/secrets.');
        }
        $metadata = lstat($path);
        if (false === $metadata || is_link($path) || !is_file($path)) {
            throw new RuntimeException('The password file must be a regular non-symlink Docker secret.');
        }
        $mode = $metadata['mode'] & 0777;
        if (0 === ($mode & 0400) || 0 !== ($mode & 0133)) {
            throw new RuntimeException('The password file permissions are unsafe.');
        }
        $value = file_get_contents($path);
        if (!is_string($value)) {
            throw new RuntimeException('The password secret cannot be read.');
        }
        $password = rtrim($value, "\r\n");
        if ('' === $password) {
            throw new RuntimeException('The password secret is empty.');
        }

        return $password;
    }
}
