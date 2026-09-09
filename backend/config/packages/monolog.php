<?php

declare(strict_types=1);

use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

return static function (ContainerConfigurator $container): void {
    $container->extension('monolog', [
        'handlers' => [
            'main' => [
                'type' => 'stream',
                'path' => 'php://stderr',
                'level' => 'info',
                'formatter' => 'monolog.formatter.json',
            ],
        ],
    ]);
};
