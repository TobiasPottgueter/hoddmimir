<?php

declare(strict_types=1);

use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

return static function (ContainerConfigurator $container): void {
    $container->extension('doctrine', [
        'dbal' => [
            'default_connection' => 'default',
            'connections' => [
                'default' => [
                    'driver' => 'pdo_mysql',
                    'host' => '%env(DATABASE_HOST)%',
                    'port' => '%env(int:DATABASE_PORT)%',
                    'dbname' => '%env(DATABASE_NAME)%',
                    'user' => '%env(DATABASE_USER)%',
                    'password' => '%env(trim:file:DATABASE_PASSWORD_FILE)%',
                    'server_version' => '11.4.12-MariaDB',
                    'charset' => 'utf8mb4',
                    'options' => [
                        \Pdo\Mysql::ATTR_INIT_COMMAND => "SET SESSION time_zone = '+00:00', SESSION sql_mode = 'STRICT_TRANS_TABLES,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION'",
                    ],
                ],
            ],
        ],
    ]);
};
