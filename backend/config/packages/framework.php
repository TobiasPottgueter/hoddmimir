<?php

declare(strict_types=1);

use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

return static function (ContainerConfigurator $container): void {
    $container->extension('framework', [
        'secret' => '%env(trim:file:APP_SECRET_FILE)%',
        'handle_all_throwables' => true,
        'http_method_override' => false,
        'trusted_proxies' => '%env(hoddmimir_trusted_proxy:HODDMIMIR_TRUSTED_PROXY)%',
        'trusted_headers' => [
            'x-forwarded-for',
            'x-forwarded-proto',
        ],
        'router' => [
            'utf8' => true,
        ],
    ]);
};
