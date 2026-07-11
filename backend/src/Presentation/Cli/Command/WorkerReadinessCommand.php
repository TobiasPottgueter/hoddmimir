<?php

declare(strict_types=1);

namespace App\Presentation\Cli\Command;

use App\Application\Worker\WorkerReadinessProbe;
use App\Domain\Worker\WorkerKind;
use JsonException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'hoddmimir:worker:readiness',
    description: 'Check worker readiness without running a worker iteration.',
)]
final class WorkerReadinessCommand extends Command
{
    public function __construct(private readonly WorkerReadinessProbe $readinessProbe)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument(
            'worker',
            InputArgument::REQUIRED,
            'Worker to check (collector or backup).',
        );
    }

    /** @throws JsonException */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $workerName = $input->getArgument('worker');
        $worker = is_string($workerName) ? WorkerKind::tryFrom($workerName) : null;

        if (null === $worker) {
            $output->writeln('<error>The worker must be either "collector" or "backup".</error>');

            return self::INVALID;
        }

        $report = $this->readinessProbe->probe($worker);
        $output->writeln(json_encode($report->toArray(), JSON_THROW_ON_ERROR));

        return $report->isReady() ? self::SUCCESS : self::FAILURE;
    }
}
