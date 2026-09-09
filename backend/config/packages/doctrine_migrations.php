<?php

declare(strict_types=1);

use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

return static function (ContainerConfigurator $container): void {
    $container->extension('doctrine_migrations', [
        'migrations_paths' => [
            'DoctrineMigrations' => '%kernel.project_dir%/migrations',
        ],
        'storage' => [
            'table_storage' => [
                'table_name' => 'doctrine_migration_versions',
            ],
        ],
        'all_or_nothing' => false,
        'enable_profiler' => false,
    ]);
};
