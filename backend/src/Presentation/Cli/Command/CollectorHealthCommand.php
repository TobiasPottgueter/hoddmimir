<?php

declare(strict_types=1);

namespace App\Presentation\Cli\Command;

use App\Application\Collector\CollectorHeartbeatStore;
use App\Application\Collector\CollectorWorkerIdentityReader;
use App\Application\Worker\WorkerReadinessProbe;
use App\Domain\Worker\WorkerKind;
use JsonException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

#[AsCommand(
    name: 'hoddmimir:worker:health',
    description: 'Check the exact running collector process and its base readiness.',
)]
final class CollectorHealthCommand extends Command
{
    public function __construct(
        private readonly WorkerReadinessProbe $readinessProbe,
        private readonly CollectorWorkerIdentityReader $identityReader,
        private readonly CollectorHeartbeatStore $heartbeatStore,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('worker', InputArgument::REQUIRED, 'Worker to check (collector).');
    }

    /** @throws JsonException */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        if ('collector' !== $input->getArgument('worker')) {
            $this->write($output, 'unhealthy', 'unsupported_worker');

            return self::INVALID;
        }

        try {
            $readiness = $this->readinessProbe->probe(WorkerKind::Collector);
            if (!$readiness->isReady()) {
                $this->write($output, 'unhealthy', 'collector_not_ready');

                return self::FAILURE;
            }

            $workerId = $this->identityReader->existingWorkerId();
            if (null === $workerId) {
                $this->write($output, 'unhealthy', 'collector_identity_missing');

                return self::FAILURE;
            }
            if (!$this->heartbeatStore->isFresh($workerId)) {
                $this->write($output, 'unhealthy', 'collector_heartbeat_unhealthy');

                return self::FAILURE;
            }
        } catch (Throwable) {
            $this->write($output, 'unhealthy', 'collector_healthcheck_failed');

            return self::FAILURE;
        }

        $this->write($output, 'healthy', 'collector_heartbeat_fresh');

        return self::SUCCESS;
    }

    /** @throws JsonException */
    private function write(OutputInterface $output, string $status, string $code): void
    {
        $output->writeln(json_encode([
            'component' => 'collector',
            'status' => $status,
            'code' => $code,
        ], JSON_THROW_ON_ERROR));
    }
}
