<?php

declare(strict_types=1);

namespace App\Application\Administration;

use App\Application\Security\Auth\AuthenticatedPrincipal;

interface SecurityCommandRepository
{
    public function execute(SecurityCommand $command, AuthenticatedPrincipal $principal): SecurityCommandResult;
    public function record(SecurityCommand $command, AuthenticatedPrincipal $principal, SecurityCommandResult $result): SecurityCommandResult;
}
