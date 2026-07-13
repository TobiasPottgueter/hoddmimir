<?php

declare(strict_types=1);

namespace App\Tests\Smoke;

use App\Application\Configuration\Connection\Onboarding\OnboardingRemoteGateway;
use App\Kernel;
use App\Presentation\Http\Controller\AuthController;
use App\Tests\Fakes\DeterministicE2eOnboardingRemoteGateway;
use PHPUnit\Framework\TestCase;

final class E2eServiceConfigurationTest extends TestCase
{
    /** @var array<string, string|false> */
    private array $previousEnvironment = [];

    /** @var list<string> */
    private array $temporaryFiles = [];

    protected function tearDown(): void
    {
        foreach ($this->previousEnvironment as $name => $value) {
            if (false === $value) {
                putenv($name);
                unset($_ENV[$name], $_SERVER[$name]);
            } else {
                putenv($name.'='.$value);
                $_ENV[$name] = $value;
                $_SERVER[$name] = $value;
            }
        }
        foreach ($this->temporaryFiles as $file) {
            @unlink($file);
        }

        parent::tearDown();
    }

    public function testDedicatedE2eEnvironmentKeepsRealAuthControllerConstructibleAndIsolatesRemoteFake(): void
    {
        $password = $this->temporaryFile('unused-database-password');
        $appSecret = $this->temporaryFile(str_repeat('e2e-app-secret-', 3));
        $keyring = $this->temporaryFile('{"format":1,"revision":1,"primaryKeyId":"e2e_test","keys":[{"id":"e2e_test","material":"'.str_repeat('a', 64).'"}]}');
        foreach ([
            'DATABASE_HOST' => '127.0.0.1',
            'DATABASE_PORT' => '1',
            'DATABASE_NAME' => 'hoddmimir_e2e_container_test',
            'DATABASE_USER' => 'hoddmimir_e2e_container_test',
            'DATABASE_PASSWORD_FILE' => $password,
            'APP_SECRET_FILE' => $appSecret,
            'ENCRYPTION_KEY_FILE' => $keyring,
            'ENCRYPTION_KEYRING_REVISION' => '1',
        ] as $name => $value) {
            $this->previousEnvironment[$name] = getenv($name);
            putenv($name.'='.$value);
            $_ENV[$name] = $value;
            $_SERVER[$name] = $value;
        }
        $kernel = new Kernel('e2e', false);
        $kernel->boot();

        try {
            $container = $kernel->getContainer();
            self::assertInstanceOf(AuthController::class, $container->get(AuthController::class));
            self::assertInstanceOf(
                DeterministicE2eOnboardingRemoteGateway::class,
                $container->get(OnboardingRemoteGateway::class),
            );
        } finally {
            $kernel->shutdown();
        }
    }

    private function temporaryFile(string $contents): string
    {
        $path = tempnam(sys_get_temp_dir(), 'hoddmimir-e2e-container-');
        self::assertIsString($path);
        file_put_contents($path, $contents);
        $this->temporaryFiles[] = $path;

        return $path;
    }
}
