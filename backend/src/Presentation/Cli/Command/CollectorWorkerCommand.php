<?php

declare(strict_types=1);

namespace App\Presentation\Cli\Command;

use App\Application\Collector\CollectorWorkerRunner;
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
    public function __construct(private readonly CollectorWorkerRunner $workerRunner)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('once', null, InputOption::VALUE_NONE, 'Claim one due cycle at most, then exit.');
    }

    /** @throws JsonException */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $result = $this->workerRunner->run((bool) $input->getOption('once'));
        $output->writeln(json_encode($result->toArray(), JSON_THROW_ON_ERROR));

        return $result->exitCode();
    }
}
