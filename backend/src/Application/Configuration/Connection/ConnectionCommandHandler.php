<?php

declare(strict_types=1);

namespace App\Application\Configuration\Connection;

use App\Application\Configuration\ConfigurationCommand;
use App\Application\Configuration\ConfigurationCommandResult;
use App\Application\Configuration\ConfigurationCommandType;
use App\Application\Security\Auth\AuthenticatedPrincipal;
use App\Application\Security\Auth\AuthorizationDenied;
use App\Application\Security\Auth\PermissionAuthorizer;
use App\Domain\Security\Permission;
use InvalidArgumentException;

final readonly class ConnectionCommandHandler
{
    public function __construct(private PermissionAuthorizer $authorizer, private ConnectionCommandRepository $repository)
    {
    }

    public function handle(ConfigurationCommand $command, AuthenticatedPrincipal $principal): ConfigurationCommandResult
    {
        if (!\in_array($command->type, [
            ConfigurationCommandType::ConnectionCreate, ConfigurationCommandType::ConnectionUpdate,
            ConfigurationCommandType::ConnectionEnable, ConfigurationCommandType::ConnectionDisable,
            ConfigurationCommandType::EndpointCreate, ConfigurationCommandType::EndpointUpdate,
            ConfigurationCommandType::EndpointDisable, ConfigurationCommandType::CredentialRotate,
        ], true)) {
            throw new InvalidArgumentException('A connection handler received an unrelated command.');
        }
        try {
            $this->authorizer->require($principal, Permission::BackupConfigurationManage);
        } catch (AuthorizationDenied $denied) {
            $this->repository->record($command, $principal, ConfigurationCommandResult::denied());
            throw $denied;
        }
        if (\in_array($command->type, [
            ConfigurationCommandType::ConnectionCreate,
            ConfigurationCommandType::ConnectionEnable,
            ConfigurationCommandType::EndpointCreate,
            ConfigurationCommandType::EndpointUpdate,
            ConfigurationCommandType::CredentialRotate,
        ], true)) {
            return $this->repository->record(
                $command,
                $principal,
                ConfigurationCommandResult::blocked('verified_onboarding_required'),
            );
        }
        return $this->repository->execute($command, $principal);
    }
}
