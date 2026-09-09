<?php

declare(strict_types=1);

namespace App\Application\Configuration\Target;

use App\Application\Configuration\ConfigurationCommand;
use App\Application\Configuration\ConfigurationCommandResult;
use App\Application\Security\Auth\AuthenticatedPrincipal;
use App\Domain\Target\BackupTarget;
use App\Domain\Target\BackupTargetId;

interface TargetCommandRepository
{
    public function find(BackupTargetId $id): ?BackupTarget;
    public function execute(ConfigurationCommand $command, AuthenticatedPrincipal $principal): ConfigurationCommandResult;
    public function record(ConfigurationCommand $command, AuthenticatedPrincipal $principal, ConfigurationCommandResult $result): ConfigurationCommandResult;
}
