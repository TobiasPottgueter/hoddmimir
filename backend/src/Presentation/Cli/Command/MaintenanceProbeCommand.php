<?php

declare(strict_types=1);

namespace App\Presentation\Cli\Command;

use App\Application\Maintenance\CheckMaintenanceQuiescence;
use App\Application\Maintenance\MaintenanceAccess;
use App\Application\Maintenance\MaintenancePhase;
use App\Application\Maintenance\MaintenanceQuiescenceFailure;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'hoddmimir:maintenance:probe', description: 'Prove remote backup quiescence during a deployment maintenance window.')]
final class MaintenanceProbeCommand extends Command
{
    public function __construct(private readonly CheckMaintenanceQuiescence $check, private readonly MaintenanceAccess $maintenance)
    {
        parent::__construct();
    }
    protected function configure(): void
    {
        $this->addOption('capabilities', null, InputOption::VALUE_NONE, 'Report maintenance protocol support without checking remote systems.');
    }
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        if ($input->getOption('capabilities')) {
            $output->writeln('{"maintenanceProtocol":1}');
            return self::SUCCESS;
        }
        if (MaintenancePhase::Open === $this->maintenance->phase()) {
            $output->writeln('{"status":"maintenance_required"}');
            return self::FAILURE;
        }
        try {
            $this->check->check();
            $output->writeln('{"status":"quiet"}');
            return self::SUCCESS;
        } catch (MaintenanceQuiescenceFailure $failure) {
            $output->writeln($failure->busy ? '{"status":"busy"}' : '{"status":"unavailable"}');
            return $failure->busy ? 2 : self::FAILURE;
        } catch (\Throwable) {
            $output->writeln('{"status":"unavailable"}');
            return self::FAILURE;
        }
    }
}
