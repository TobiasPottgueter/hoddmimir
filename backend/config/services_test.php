<?php

declare(strict_types=1);

use App\Presentation\Http\Auth\HttpRequestAuthenticator;
use App\Presentation\Http\Auth\RequestAuthenticator;
use App\Application\Backup\Worker\BackupWorkerRuntime;
use App\Application\Backup\Worker\BackupWorkerRunner;
use App\Application\Backup\Worker\BackupWorkerHeartbeatStore;
use App\Application\Backup\Execution\BackupExecutionGate;
use App\Application\Configuration\Connection\Onboarding\OnboardingRemoteGateway;
use App\Infrastructure\Process\EnvironmentBackupExecutionGate;
use App\Tests\Fakes\NoOpBackupWorkerRuntime;
use App\Tests\Fakes\NoOpBackupWorkerHeartbeatStore;
use App\Tests\Fakes\TestHttpRequestAuthenticator;
use App\Tests\Fakes\DeterministicE2eOnboardingRemoteGateway;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

return static function (ContainerConfigurator $container): void {
    $services = $container->services();
    $services->set(TestHttpRequestAuthenticator::class);
    $services->set(RequestAuthenticator::class)->autowire()->autoconfigure()->public();
    $services->alias(HttpRequestAuthenticator::class, TestHttpRequestAuthenticator::class)->public();
    $services->set(NoOpBackupWorkerRuntime::class)->public();
    $services->alias(BackupWorkerRuntime::class, NoOpBackupWorkerRuntime::class)->public();
    $services->set(BackupWorkerRunner::class)->autowire()->autoconfigure()->public();
    $services->set(NoOpBackupWorkerHeartbeatStore::class)->public();
    $services->alias(BackupWorkerHeartbeatStore::class, NoOpBackupWorkerHeartbeatStore::class)->public();
    $services->set(EnvironmentBackupExecutionGate::class)->arg('$executionEnabled', false)->public();
    $services->alias(BackupExecutionGate::class, EnvironmentBackupExecutionGate::class)->public();
    $services->set(DeterministicE2eOnboardingRemoteGateway::class)->public();
    $services->alias(OnboardingRemoteGateway::class, DeterministicE2eOnboardingRemoteGateway::class)->public();
};
