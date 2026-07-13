<?php

declare(strict_types=1);

namespace App\Tests\Unit\Application\Configuration\Connection;

use App\Application\Configuration\ConfigurationCommand;
use App\Application\Configuration\ConfigurationCommandResult;
use App\Application\Configuration\ConfigurationCommandStatus;
use App\Application\Configuration\ConfigurationCommandType;
use App\Application\Configuration\Connection\ConnectionCommandHandler;
use App\Application\Configuration\Connection\ConnectionCommandRepository;
use App\Application\Security\Auth\AuthenticatedPrincipal;
use App\Application\Security\Auth\AuthorizationDenied;
use App\Application\Security\Auth\PermissionAuthorizer;
use App\Domain\Security\NormalizedUsername;
use App\Domain\Security\Permission;
use App\Domain\Security\UserId;
use PHPUnit\Framework\TestCase;

final class ConnectionCommandHandlerTest extends TestCase
{
    public function testOnlyDisplayNameAndSafeDisableCommandsBypassVerifiedOnboarding(): void
    {
        $repository = new InMemoryConnectionCommandRepository();
        $handler = new ConnectionCommandHandler(new PermissionAuthorizer(), $repository);
        $principal = $this->principal([Permission::BackupConfigurationManage]);
        foreach ([ConfigurationCommandType::ConnectionUpdate, ConfigurationCommandType::ConnectionDisable, ConfigurationCommandType::EndpointDisable] as $index => $type) {
            $result = $handler->handle(new ConfigurationCommand($type, str_repeat('s',16), 0, 'key-'.$index, str_repeat('c',16)), $principal);
            self::assertSame(ConfigurationCommandStatus::Applied, $result->status);
        }
        foreach ([
            ConfigurationCommandType::ConnectionCreate,
            ConfigurationCommandType::ConnectionEnable,
            ConfigurationCommandType::EndpointCreate,
            ConfigurationCommandType::EndpointUpdate,
            ConfigurationCommandType::CredentialRotate,
        ] as $index => $type) {
            $result = $handler->handle(new ConfigurationCommand($type, str_repeat('s', 16), 0, 'verified-only-'.$index, str_repeat('c', 16)), $principal);
            self::assertSame(ConfigurationCommandStatus::Blocked, $result->status);
            self::assertSame(['verified_onboarding_required'], $result->blockers);
        }
        self::assertSame(3, $repository->executed);
        self::assertSame(5, $repository->recorded);
    }

    public function testItRecordsDeniedCommandsAndRejectsUnrelatedCommands(): void
    {
        $repository = new InMemoryConnectionCommandRepository();
        $handler = new ConnectionCommandHandler(new PermissionAuthorizer(), $repository);
        $command = new ConfigurationCommand(ConfigurationCommandType::ConnectionDisable, str_repeat('s',16), 1, 'denied', str_repeat('c',16));
        try { $handler->handle($command, $this->principal([])); self::fail('Expected denial.'); } catch (AuthorizationDenied) {}
        self::assertSame(1, $repository->recorded);
        $this->expectException(\InvalidArgumentException::class);
        $handler->handle(new ConfigurationCommand(ConfigurationCommandType::TargetCreate, str_repeat('s',16), 0, 'unrelated', str_repeat('c',16)), $this->principal([Permission::BackupConfigurationManage]));
    }

    /** @param list<Permission> $permissions */
    private function principal(array $permissions): AuthenticatedPrincipal
    {
        return new AuthenticatedPrincipal(new UserId(str_repeat('u',16)), new NormalizedUsername('admin'), $permissions);
    }
}

final class InMemoryConnectionCommandRepository implements ConnectionCommandRepository
{
    public int $executed=0;
    public int $recorded=0;
    public function execute(ConfigurationCommand $command, AuthenticatedPrincipal $principal): ConfigurationCommandResult { ++$this->executed; return new ConfigurationCommandResult(ConfigurationCommandStatus::Applied,1); }
    public function record(ConfigurationCommand $command, AuthenticatedPrincipal $principal, ConfigurationCommandResult $result): ConfigurationCommandResult { ++$this->recorded; return $result; }
}
