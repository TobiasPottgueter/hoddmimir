<?php

declare(strict_types=1);

namespace App\Presentation\Cli\Command;

use App\Application\Maintenance\MaintenanceAccess;
use App\Application\Maintenance\MaintenancePhase;
use App\Infrastructure\Maintenance\MaintenanceFunctionalValidator;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'hoddmimir:maintenance:validate', description: 'Validate real application data paths in a rolled-back transaction.')]
final class MaintenanceValidateCommand extends Command
{
    public function __construct(private readonly MaintenanceFunctionalValidator $validator, private readonly MaintenanceAccess $maintenance)
    {
        parent::__construct();
    }
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        if (MaintenancePhase::Frozen !== $this->maintenance->phase()) {
            $output->writeln('{"status":"frozen_maintenance_required"}');
            return self::FAILURE;
        }
        try {
            $this->validator->validate();
            $output->writeln('{"status":"validated"}');
            return self::SUCCESS;
        } catch (\Throwable) {
            $output->writeln('{"status":"validation_failed"}');
            return self::FAILURE;
        }
    }
}
