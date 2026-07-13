<?php

declare(strict_types=1);

use App\Application\Configuration\Connection\Onboarding\OnboardingRemoteGateway;
use App\Presentation\Http\Controller\AuthController;
use App\Tests\Fakes\DeterministicE2eOnboardingRemoteGateway;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

return static function (ContainerConfigurator $container): void {
    $services = $container->services();
    $services->set(DeterministicE2eOnboardingRemoteGateway::class)->public();
    $services->alias(OnboardingRemoteGateway::class, DeterministicE2eOnboardingRemoteGateway::class)->public();
    // The isolated browser stack is HTTP-only. Authentication remains the
    // production implementation; only its transport-cookie flag is relaxed.
    $services->set(AuthController::class)
        ->autowire()
        ->autoconfigure()
        ->arg('$environment', 'test')
        ->public();
};
