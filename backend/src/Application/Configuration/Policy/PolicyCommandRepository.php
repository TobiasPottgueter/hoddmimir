<?php

declare(strict_types=1);

namespace App\Application\Configuration\Policy;

use App\Application\Configuration\ConfigurationCommand;
use App\Application\Configuration\ConfigurationCommandResult;
use App\Application\Security\Auth\AuthenticatedPrincipal;
use App\Domain\Policy\BackupPolicy;
use App\Domain\Policy\PolicyId;

interface PolicyCommandRepository
{
    public function findPolicy(PolicyId $id): ?BackupPolicy;
    public function execute(ConfigurationCommand $command, AuthenticatedPrincipal $principal): ConfigurationCommandResult;
    public function record(ConfigurationCommand $command, AuthenticatedPrincipal $principal, ConfigurationCommandResult $result): ConfigurationCommandResult;
}
