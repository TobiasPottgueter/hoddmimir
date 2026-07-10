<?php

declare(strict_types=1);

use App\Domain\Shared\Clock;
use App\Application\Worker\Sleeper;
use App\Infrastructure\Process\NativeSleeper;
use App\Infrastructure\Time\SystemClock;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

return static function (ContainerConfigurator $container): void {
    $services = $container->services();

    $services
        ->defaults()
        ->autowire()
        ->autoconfigure();

    $services
        ->load('App\\', '../src/')
        ->exclude([
            '../src/Domain/',
            '../src/Kernel.php',
        ]);

    $services->set(Clock::class, SystemClock::class);
    $services->set(Sleeper::class, NativeSleeper::class);
};
