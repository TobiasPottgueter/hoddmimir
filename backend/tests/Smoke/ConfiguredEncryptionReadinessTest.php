<?php

declare(strict_types=1);

namespace App\Tests\Smoke;

use App\Kernel;
use JsonException;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\Console\Tester\CommandTester;

final class ConfiguredEncryptionReadinessTest extends TestCase
{
    /** @var array<string, string|false> */
    private array $previousEnvironment = [];

    /** @var list<string> */
    private array $temporaryFiles = [];

    protected function tearDown(): void
    {
        foreach ($this->previousEnvironment as $name => $value) {
            if ($value === false) {
                putenv($name);
                unset($_ENV[$name], $_SERVER[$name]);
                continue;
            }

            putenv($name.'='.$value);
            $_ENV[$name] = $value;
            $_SERVER[$name] = $value;
        }
        foreach ($this->temporaryFiles as $file) {
            @unlink($file);
        }

        parent::tearDown();
    }

    /** @throws JsonException */
    public function testInvalidConfiguredRevisionReachesHttpAsFixedUnavailableReason(): void
    {
        $keyring = $this->temporaryFile(json_encode([
            'format' => 1,
            'revision' => 1,
            'primaryKeyId' => 'key_one',
            'keys' => [['id' => 'key_one', 'material' => str_repeat('a', 64)]],
        ], JSON_THROW_ON_ERROR));
        $this->configureRuntime($keyring, 'not-an-integer');

        $kernel = new Kernel('test', false);
        $client = new KernelBrowser($kernel);
        $client->request('GET', '/api/health');

        self::assertSame(503, $client->getResponse()->getStatusCode());
        /** @var array{checks?: array<string, array{status: string, reason?: string}>} $payload */
        $payload = json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame(
            ['status' => 'unavailable', 'reason' => 'revision_mismatch'],
            $payload['checks']['encryption_keyring'] ?? null,
        );
        self::assertStringNotContainsString('not-an-integer', (string) $client->getResponse()->getContent());
        $kernel->shutdown();
    }

    /** @throws JsonException */
    public function testRelativeConfiguredPathReachesWorkerCliAsFixedFailure(): void
    {
        $this->configureRuntime('relative-SENTINEL/keyring', '1');
        $kernel = new Kernel('test', false);
        $kernel->boot();
        $application = new Application($kernel);
        $tester = new CommandTester($application->find('hoddmimir:worker:readiness'));

        self::assertSame(1, $tester->execute(['worker' => 'collector']));
        /** @var array{checks?: array<string, array{status: string, reason?: string}>} $payload */
        $payload = json_decode(trim($tester->getDisplay()), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame(
            ['status' => 'unavailable', 'reason' => 'missing'],
            $payload['checks']['encryption_keyring'] ?? null,
        );
        self::assertStringNotContainsString('SENTINEL', $tester->getDisplay());
        $kernel->shutdown();
    }

    private function configureRuntime(string $keyringFile, string $revision): void
    {
        $passwordFile = $this->temporaryFile('not-a-production-password');
        foreach ([
            'DATABASE_HOST' => '127.0.0.1',
            'DATABASE_PORT' => '1',
            'DATABASE_NAME' => 'hoddmimir_unavailable_test',
            'DATABASE_USER' => 'hoddmimir_test',
            'DATABASE_PASSWORD_FILE' => $passwordFile,
            'ENCRYPTION_KEY_FILE' => $keyringFile,
            'ENCRYPTION_KEYRING_REVISION' => $revision,
        ] as $name => $value) {
            if (!array_key_exists($name, $this->previousEnvironment)) {
                $this->previousEnvironment[$name] = getenv($name);
            }
            putenv($name.'='.$value);
            $_ENV[$name] = $value;
            $_SERVER[$name] = $value;
        }
    }

    private function temporaryFile(string $contents): string
    {
        $path = tempnam(sys_get_temp_dir(), 'hoddmimir-readiness-smoke-');
        self::assertIsString($path);
        file_put_contents($path, $contents);
        $this->temporaryFiles[] = $path;

        return $path;
    }
}
