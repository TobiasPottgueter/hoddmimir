<?php

declare(strict_types=1);

namespace App\Presentation\Cli\Command;

use App\Application\Security\Auth\AdminBootstrapConflict;
use App\Application\Security\Auth\FirstAdminCreator;
use RuntimeException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\Question;
use Symfony\Component\Console\Helper\QuestionHelper;
use Throwable;

#[AsCommand(name: 'hoddmimir:user:create-admin', description: 'Create the first local administrator without exposing its password through argv or env.')]
final class CreateAdminCommand extends Command
{
    public function __construct(private readonly FirstAdminCreator $createAdmin)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('username', InputArgument::REQUIRED)
            ->addArgument('display-name', InputArgument::REQUIRED)
            ->addOption('password-file', null, InputOption::VALUE_REQUIRED, 'Absolute /run/secrets file path.')
            ->addOption('idempotent', null, InputOption::VALUE_NONE, 'Succeed only for an exact existing admin match.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            $username = $input->getArgument('username');
            $displayName = $input->getArgument('display-name');
            if (!is_string($username) || !is_string($displayName)) {
                throw new RuntimeException('Administrator identity arguments are invalid.');
            }
            $created = $this->createAdmin->create(
                $username,
                $displayName,
                $this->password($input, $output),
                true === $input->getOption('idempotent'),
            );
            $output->writeln($created ? 'Administrator created.' : 'Administrator already exists with the exact requested state.');

            return self::SUCCESS;
        } catch (AdminBootstrapConflict $exception) {
            $output->writeln('<error>'.$exception->getMessage().'</error>');

            return self::FAILURE;
        } catch (Throwable) {
            $output->writeln('<error>Administrator creation failed.</error>');

            return self::FAILURE;
        }
    }

    private function password(InputInterface $input, OutputInterface $output): string
    {
        $path = $input->getOption('password-file');
        if (null !== $path) {
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

            return rtrim($value, "\r\n");
        }
        if (!$input->isInteractive()) {
            throw new RuntimeException('Interactive input or --password-file is required.');
        }
        $question = new Question('Password: ');
        $question->setHidden(true);
        $question->setHiddenFallback(false);
        $helper = $this->getHelper('question');
        if (!$helper instanceof QuestionHelper) {
            throw new RuntimeException('Secure interactive input is unavailable.');
        }
        $value = $helper->ask($input, $output, $question);
        if (!is_string($value)) {
            throw new RuntimeException('A password is required.');
        }

        return $value;
    }
}
