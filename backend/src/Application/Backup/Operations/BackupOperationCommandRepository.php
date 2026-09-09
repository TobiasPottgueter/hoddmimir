<?php

declare(strict_types=1);

namespace App\Application\Backup\Operations;

use App\Application\Security\Auth\AuthenticatedPrincipal;

interface BackupOperationCommandRepository
{
    public function execute(BackupOperationCommand $command, AuthenticatedPrincipal $principal): BackupOperationCommandResult;
}
