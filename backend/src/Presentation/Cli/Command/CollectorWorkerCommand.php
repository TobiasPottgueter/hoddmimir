<?php

declare(strict_types=1);

namespace App\Presentation\Cli\Command;

use App\Application\Worker\WorkerLoop;
use App\Application\Worker\WorkerReadinessReport;
use App\Domain\Worker\WorkerKind;
use JsonException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'hoddmimir:worker:data',
    description: 'Boot the collector worker process.',
)]
final class CollectorWorkerCommand extends Command
{
    private const int DEFAULT_INTERVAL_SECONDS = 60;

    public function __construct(private readonly WorkerLoop $workerLoop)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('once', null, InputOption::VALUE_NONE, 'Run one iteration and exit.')
            ->addOption(
                'interval',
                null,
                InputOption::VALUE_REQUIRED,
                'Seconds to wait between iterations.',
                (string) self::DEFAULT_INTERVAL_SECONDS,
            );
    }

    /** @throws JsonException */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $interval = filter_var(
            $input->getOption('interval'),
            FILTER_VALIDATE_INT,
            ['options' => ['min_range' => 1]],
        );

        if (false === $interval) {
            $output->writeln('<error>The --interval value must be a positive integer.</error>');

            return self::INVALID;
        }

        $this->workerLoop->run(
            WorkerKind::Collector,
            $interval,
            (bool) $input->getOption('once'),
            static function (WorkerReadinessReport $report) use ($output): void {
                $output->writeln(json_encode($report->toArray(), JSON_THROW_ON_ERROR));
            },
        );

        return self::SUCCESS;
    }
}
