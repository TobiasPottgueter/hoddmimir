<?php

declare(strict_types=1);

use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

return static function (ContainerConfigurator $container): void {
    $container->extension('framework', [
        'handle_all_throwables' => true,
        'http_method_override' => false,
        'router' => [
            'utf8' => true,
        ],
    ]);
};
