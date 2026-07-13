<?php

declare(strict_types=1);

namespace App\Application\Configuration\Selection;

use App\Application\Configuration\ConfigurationCommand;
use App\Application\Configuration\ConfigurationCommandResult;
use App\Application\Configuration\ConfigurationCommandType;
use App\Application\Security\Auth\AuthenticatedPrincipal;
use App\Application\Security\Auth\PermissionAuthorizer;
use App\Domain\Security\Permission;
use App\Application\Security\Auth\AuthorizationDenied;
use InvalidArgumentException;

final readonly class SelectionCommandHandler
{
    public function __construct(private PermissionAuthorizer $authorizer, private SelectionCommandRepository $repository)
    {
    }

    public function handle(ConfigurationCommand $command, AuthenticatedPrincipal $principal): ConfigurationCommandResult
    {
        $selectionCommand = match ($command->type) {
            ConfigurationCommandType::SelectionUpsert, ConfigurationCommandType::SelectionDisable,
            ConfigurationCommandType::GuestOverrideUpsert, ConfigurationCommandType::GuestOverrideDisable => true,
            default => false,
        };
        if (!$selectionCommand) {
            throw new InvalidArgumentException('A selection handler received an unrelated command.');
        }
        try {
            $this->authorizer->require($principal, Permission::BackupConfigurationManage);
        } catch (AuthorizationDenied $denied) {
            $this->repository->record($command, $principal, ConfigurationCommandResult::denied());
            throw $denied;
        }
        $command->boundedEntries('entries');
        return $this->repository->execute($command, $principal);
    }
}
