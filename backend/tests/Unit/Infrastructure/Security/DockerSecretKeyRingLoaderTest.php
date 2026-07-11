<?php

declare(strict_types=1);

namespace App\Tests\Unit\Infrastructure\Security;

use App\Infrastructure\Security\DockerSecretKeyRingLoader;
use App\Infrastructure\Security\EncryptionConfigurationException;
use App\Infrastructure\Security\EncryptionConfigurationFailure;
use PHPUnit\Framework\TestCase;

final class DockerSecretKeyRingLoaderTest extends TestCase
{
    public function testItLoadsTheDockerSecretAndChecksItsRevision(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'hoddmimir-keyring-');
        self::assertIsString($path);

        try {
            file_put_contents($path, self::validJson(4));
            $keyRing = (new DockerSecretKeyRingLoader($path, '4'))->load();

            self::assertSame(4, $keyRing->revision());
            self::assertSame('key_1', $keyRing->primaryKeyId());
        } finally {
            @unlink($path);
        }
    }

    public function testItMemoizesTheFirstSuccessfulProcessSnapshot(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'hoddmimir-keyring-');
        self::assertIsString($path);

        try {
            file_put_contents($path, self::validJson(4));
            $loader = new DockerSecretKeyRingLoader($path, '4');
            $first = $loader->load();
            file_put_contents($path, '{"material":"CHANGED-SENTINEL"');

            self::assertSame($first, $loader->load());
            self::assertSame(4, $loader->load()->revision());
        } finally {
            @unlink($path);
        }
    }

    public function testItMemoizesTheFirstSafeFailureUntilProcessRestart(): void
    {
        $path = sys_get_temp_dir().'/hoddmimir-keyring-missing-'.bin2hex(random_bytes(8));
        $loader = new DockerSecretKeyRingLoader($path, '1');
        $this->expectSafeFailure(
            static fn (): object => $loader->load(),
            EncryptionConfigurationFailure::SecretFileUnavailable,
        );
        file_put_contents($path, self::validJson(1));

        try {
            $this->expectSafeFailure(
                static fn (): object => $loader->load(),
                EncryptionConfigurationFailure::SecretFileUnavailable,
            );
        } finally {
            @unlink($path);
        }
    }

    public function testItMemoizesAnInvalidDocumentFailureWithoutLeakingTheDocument(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'hoddmimir-keyring-');
        self::assertIsString($path);

        try {
            file_put_contents($path, '{"material":"MEMOIZED-FILE-SENTINEL"');
            $loader = new DockerSecretKeyRingLoader($path, '1');
            $this->expectSafeFailure(
                static fn (): object => $loader->load(),
                EncryptionConfigurationFailure::InvalidJson,
            );
            file_put_contents($path, self::validJson(1));
            $this->expectSafeFailure(
                static fn (): object => $loader->load(),
                EncryptionConfigurationFailure::InvalidJson,
            );
        } finally {
            @unlink($path);
        }
    }

    public function testItFailsSafelyForAnUnavailableSecretFile(): void
    {
        $this->expectSafeFailure(
            static fn (): object => (new DockerSecretKeyRingLoader('/definitely/missing/keyring', '1'))->load(),
            EncryptionConfigurationFailure::SecretFileUnavailable,
        );
    }

    public function testItRejectsRelativePathsAndInvalidExpectedRevisionsDuringLoad(): void
    {
        $this->expectSafeFailure(
            static fn (): object => (new DockerSecretKeyRingLoader('relative/keyring', '1'))->load(),
            EncryptionConfigurationFailure::SecretFileUnavailable,
        );
        foreach (['', '0', '-1', '01', 'abc', '9223372036854775808', str_repeat('1', 20)] as $revision) {
            $this->expectSafeFailure(
                static fn (): object => (new DockerSecretKeyRingLoader('/run/secrets/keyring', $revision))->load(),
                EncryptionConfigurationFailure::InvalidRevision,
            );
        }
    }

    public function testItDoesNotLeakInvalidFileContents(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'hoddmimir-keyring-');
        self::assertIsString($path);

        try {
            file_put_contents($path, '{"material":"FILE-SENTINEL"');
            $this->expectSafeFailure(
                static fn (): object => (new DockerSecretKeyRingLoader($path, '1'))->load(),
                EncryptionConfigurationFailure::InvalidJson,
            );
        } finally {
            @unlink($path);
        }
    }

    public function testItCapsTheAmountReadFromTheSecretFile(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'hoddmimir-keyring-');
        self::assertIsString($path);

        try {
            file_put_contents($path, str_repeat('S', 70000));
            $this->expectSafeFailure(
                static fn (): object => (new DockerSecretKeyRingLoader($path, '1'))->load(),
                EncryptionConfigurationFailure::InvalidJson,
            );
        } finally {
            @unlink($path);
        }
    }

    /**
     * @param callable(): object $operation
     */
    private function expectSafeFailure(callable $operation, EncryptionConfigurationFailure $failure): void
    {
        try {
            $operation();
            self::fail('Invalid Docker Secret configuration was accepted.');
        } catch (EncryptionConfigurationException $exception) {
            self::assertSame($failure, $exception->failure);
            self::assertSame('Encryption key configuration is invalid.', $exception->getMessage());
            self::assertStringNotContainsString('SENTINEL', $exception->getMessage());
            self::assertNull($exception->getPrevious());
        }
    }

    private static function validJson(int $revision): string
    {
        return json_encode([
            'format' => 1,
            'revision' => $revision,
            'primaryKeyId' => 'key_1',
            'keys' => [
                ['id' => 'key_1', 'material' => str_repeat('a', 64)],
            ],
        ], JSON_THROW_ON_ERROR);
    }
}
