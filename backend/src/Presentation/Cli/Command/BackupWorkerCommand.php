<?php

declare(strict_types=1);

namespace App\Presentation\Cli\Command;

use App\Application\Backup\Worker\BackupRunIdentifierSource;
use App\Application\Backup\Worker\BackupWorkerRuntime;
use App\Application\Backup\Worker\BackupWorkerHeartbeatStore;
use App\Application\Backup\Worker\BackupWorkerHeartbeatStatus;
use App\Application\Backup\Worker\BackupWorkerRuntimeSafety;
use App\Application\Collector\StopRequested;
use App\Application\Worker\WorkerReadinessProbe;
use App\Application\Worker\Sleeper;
use App\Domain\Worker\WorkerKind;
use App\Domain\Shared\Clock;
use JsonException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'hoddmimir:worker:backup',
    description: 'Boot the backup worker process.',
)]
final class BackupWorkerCommand extends Command
{
    private const int DEFAULT_INTERVAL_SECONDS = 5;

    public function __construct(
        private readonly BackupWorkerRuntime $runner,
        private readonly BackupRunIdentifierSource $identifiers,
        private readonly BackupWorkerHeartbeatStore $heartbeats,
        private readonly Clock $clock,
        private readonly WorkerReadinessProbe $readiness,
        private readonly StopRequested $stopRequested,
        private readonly Sleeper $sleeper,
        private readonly BackupWorkerRuntimeSafety $safety,
    ) {
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
        if (!$this->safety->allowsStartup()) {
            $output->writeln('<error>Backup execution requires enabled and valid problem notification delivery.</error>');

            return self::FAILURE;
        }
        $interval = filter_var(
            $input->getOption('interval'),
            FILTER_VALIDATE_INT,
            ['options' => ['min_range' => 1]],
        );

        if (false === $interval) {
            $output->writeln('<error>The --interval value must be a positive integer.</error>');

            return self::INVALID;
        }

        $workerId = $this->identifiers->next();
        if (16 !== strlen($workerId)) {
            $output->writeln('<error>The backup worker identifier source failed.</error>');
            return self::FAILURE;
        }
        $once = (bool) $input->getOption('once');
        $startedAt = $this->clock->now();
        $this->heartbeats->record($workerId, BackupWorkerHeartbeatStatus::Starting, 'process_starting', $startedAt, $startedAt);
        do {
            $report = $this->readiness->probe(WorkerKind::Backup);
            $nextAction = $report->checkedAt->modify('+'.$interval.' seconds');
            if (!$report->isReady()) {
                $this->heartbeats->record($workerId, BackupWorkerHeartbeatStatus::Degraded, 'readiness_unavailable', $report->checkedAt, $nextAction);
                $output->writeln(json_encode($report->toArray(), JSON_THROW_ON_ERROR));
                if ($once) return self::FAILURE;
            } else {
                $this->heartbeats->record($workerId, BackupWorkerHeartbeatStatus::Busy, 'worker_tick', $report->checkedAt, $nextAction);
                try {
                    $status = $this->runner->runOnce($workerId);
                } catch (\Throwable $failure) {
                    $failedAt = $this->clock->now();
                    $this->heartbeats->record($workerId, BackupWorkerHeartbeatStatus::Degraded, 'tick_failed', $failedAt, $failedAt->modify('+'.$interval.' seconds'));
                    throw $failure;
                }
                $completedAt = $this->clock->now();
                $this->heartbeats->record($workerId, BackupWorkerHeartbeatStatus::Ready, $status->value, $completedAt, $completedAt->modify('+'.$interval.' seconds'));
                $payload = $report->toArray();
                $payload['workStatus'] = $status->value;
                $output->writeln(json_encode($payload, JSON_THROW_ON_ERROR));
            }
            if ($once || $this->stopRequested->isStopRequested()) break;
            $this->sleeper->sleep($interval);
        } while (!$this->stopRequested->isStopRequested());

        $stoppedAt = $this->clock->now();
        $this->heartbeats->record($workerId, BackupWorkerHeartbeatStatus::Stopping, 'process_stopping', $stoppedAt, $stoppedAt);

        return self::SUCCESS;
    }
}
