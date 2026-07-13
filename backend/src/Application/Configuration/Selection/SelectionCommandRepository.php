<?php

declare(strict_types=1);

namespace App\Application\Configuration\Selection;

use App\Application\Configuration\ConfigurationCommand;
use App\Application\Configuration\ConfigurationCommandResult;
use App\Application\Security\Auth\AuthenticatedPrincipal;

interface SelectionCommandRepository
{
    public function execute(ConfigurationCommand $command, AuthenticatedPrincipal $principal): ConfigurationCommandResult;
    public function record(ConfigurationCommand $command, AuthenticatedPrincipal $principal, ConfigurationCommandResult $result): ConfigurationCommandResult;
}
