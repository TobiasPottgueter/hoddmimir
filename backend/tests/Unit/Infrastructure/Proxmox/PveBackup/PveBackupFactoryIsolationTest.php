<?php

declare(strict_types=1);

namespace App\Tests\Unit\Infrastructure\Proxmox\PveBackup;

use App\Application\Proxmox\Pve\PveBackupApiFailure;
use App\Application\Proxmox\Pve\PveBackupApiFailureCode;
use App\Application\Proxmox\Pve\PveBackupClient;
use App\Application\Proxmox\Pve\PveVersion;
use App\Application\Security\EncryptedSecret;
use App\Application\Security\PlaintextSecret;
use App\Application\Security\SecretCipher;
use App\Application\Security\SecretContext;
use App\Application\Security\SecretPurpose;
use App\Infrastructure\Proxmox\PveApiTokenIdentity;
use App\Infrastructure\Proxmox\PveBackup\PveBackupEndpointConfiguration;
use App\Infrastructure\Proxmox\PveBackup\PveBackupTokenAuthenticator;
use App\Infrastructure\Proxmox\PveBackup\PveNativeBackupClientFactory;
use App\Infrastructure\Proxmox\PveHttpClientFactory;
use App\Infrastructure\Proxmox\PveRetryDelay;
use App\Infrastructure\Proxmox\PveRetryPolicy;
use App\Infrastructure\Proxmox\PveTlsConfiguration;
use App\Presentation\Cli\Command\BackupWorkerCommand;
use InvalidArgumentException;
use LogicException;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use RuntimeException;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final class PveBackupFactoryIsolationTest extends TestCase
{
    public function testAuthenticatorRequiresDedicatedBackupPurposeAndScopesPlaintext(): void
    {
        $cipher = new BackupFixedSecretCipher('TOKEN-SENTINEL');
        $authenticator = new PveBackupTokenAuthenticator(
            PveApiTokenIdentity::fromUserAndTokenId('backup@pve', 'hoddmimir'),
            EncryptedSecret::fromEncoded('encrypted-envelope'),
            SecretContext::forCredential('credential-1', SecretPurpose::PveBackupToken),
            $cipher,
        );

        self::assertSame(
            'PVEAPIToken=backup@pve!hoddmimir=TOKEN-SENTINEL',
            $authenticator->authorize(static fn (string $authorization): string => $authorization),
        );
        self::assertSame(1, $cipher->decryptions);

        $this->expectException(InvalidArgumentException::class);
        new PveBackupTokenAuthenticator(
            PveApiTokenIdentity::fromUserAndTokenId('backup@pve', 'hoddmimir'),
            EncryptedSecret::fromEncoded('encrypted-envelope'),
            SecretContext::forCredential('credential-1', SecretPurpose::PveCollectorToken),
            $cipher,
        );
    }

    public function testDecryptFailureIsStableAndSecretFree(): void
    {
        $authenticator = new PveBackupTokenAuthenticator(
            PveApiTokenIdentity::fromUserAndTokenId('backup@pve', 'hoddmimir'),
            EncryptedSecret::fromEncoded('encrypted-envelope'),
            SecretContext::forCredential('credential-1', SecretPurpose::PveBackupToken),
            new BackupThrowingSecretCipher(),
        );

        try {
            $authenticator->authorize(static fn (string $authorization): string => $authorization);
            self::fail('Expected a typed credential failure.');
        } catch (PveBackupApiFailure $failure) {
            self::assertSame(PveBackupApiFailureCode::CredentialUnavailable, $failure->failureCode);
            self::assertStringNotContainsString('TOKEN-SENTINEL', $failure->getMessage());
        }
    }

    public function testEndpointConfigurationIsRedactedAndCannotBeSerialized(): void
    {
        $configuration = $this->configuration();

        self::assertSame(['value' => '[REDACTED]'], $configuration->__debugInfo());
        self::assertStringNotContainsString('encrypted-envelope', print_r($configuration, true));

        $this->expectException(LogicException::class);
        serialize($configuration);
    }

    public function testNativeFactoryBuildsDormantTypedClientAndPreservesTlsObject(): void
    {
        $httpFactory = new BackupRecordingHttpClientFactory(new MockHttpClient());
        $factory = new PveNativeBackupClientFactory(
            $httpFactory,
            new BackupFixedSecretCipher('secret'),
            new PveRetryPolicy(),
            new BackupNoopRetryDelay(),
        );

        self::assertInstanceOf(PveBackupClient::class, $factory->create($this->configuration(), $this->version(8)));
        self::assertSame(PveTlsConfiguration::systemCa()->mode, $httpFactory->lastTls?->mode);
    }

    public function testFactoryMapsUnsupportedVersionAndHttpFactoryFailure(): void
    {
        $factory = new PveNativeBackupClientFactory(
            new BackupThrowingHttpClientFactory(),
            new BackupFixedSecretCipher('secret'),
            new PveRetryPolicy(),
            new BackupNoopRetryDelay(),
        );

        $this->assertFactoryFailure($factory, $this->version(7), PveBackupApiFailureCode::Configuration);
        $this->assertFactoryFailure($factory, $this->version(8), PveBackupApiFailureCode::Configuration);
        $this->assertFactoryFailure($factory, $this->version(9), PveBackupApiFailureCode::Configuration);
        $this->assertFactoryFailure($factory, $this->version(10), PveBackupApiFailureCode::UnsupportedVersion);
    }

    public function testBackupWorkerHasNoWriteClientDependency(): void
    {
        $constructor = (new ReflectionClass(BackupWorkerCommand::class))->getConstructor();

        self::assertNotNull($constructor);
        self::assertCount(1, $constructor->getParameters());
        self::assertSame('workerLoop', $constructor->getParameters()[0]->getName());
    }

    private function assertFactoryFailure(
        PveNativeBackupClientFactory $factory,
        PveVersion $version,
        PveBackupApiFailureCode $expected,
    ): void {
        try {
            $factory->create($this->configuration(), $version);
            self::fail('Expected a typed factory failure.');
        } catch (PveBackupApiFailure $failure) {
            self::assertSame($expected, $failure->failureCode);
        }
    }

    private function configuration(): PveBackupEndpointConfiguration
    {
        return new PveBackupEndpointConfiguration(
            'pve.test',
            8006,
            PveTlsConfiguration::systemCa(),
            PveApiTokenIdentity::fromUserAndTokenId('backup@pve', 'hoddmimir'),
            EncryptedSecret::fromEncoded('encrypted-envelope'),
            SecretContext::forCredential('credential-1', SecretPurpose::PveBackupToken),
        );
    }

    private function version(int $major): PveVersion
    {
        return new PveVersion($major, 0, null, 'test', $major.'.0', 'repo');
    }
}

/** @internal */
final class BackupFixedSecretCipher implements SecretCipher
{
    public int $decryptions = 0;

    public function __construct(private readonly string $secret)
    {
    }

    public function encrypt(PlaintextSecret $plaintext, SecretContext $context): EncryptedSecret
    {
        return EncryptedSecret::fromEncoded('unused');
    }

    public function decrypt(EncryptedSecret $encrypted, SecretContext $context): PlaintextSecret
    {
        ++$this->decryptions;

        return PlaintextSecret::fromString($this->secret);
    }

    public function primaryKeyId(): string
    {
        return 'test';
    }
}

/** @internal */
final readonly class BackupThrowingSecretCipher implements SecretCipher
{
    public function encrypt(PlaintextSecret $plaintext, SecretContext $context): EncryptedSecret
    {
        throw new RuntimeException('TOKEN-SENTINEL');
    }

    public function decrypt(EncryptedSecret $encrypted, SecretContext $context): PlaintextSecret
    {
        throw new RuntimeException('TOKEN-SENTINEL');
    }

    public function primaryKeyId(): string
    {
        throw new RuntimeException('TOKEN-SENTINEL');
    }
}

/** @internal */
final class BackupRecordingHttpClientFactory implements PveHttpClientFactory
{
    public ?PveTlsConfiguration $lastTls = null;

    public function __construct(private readonly HttpClientInterface $client)
    {
    }

    public function create(PveTlsConfiguration $tls): HttpClientInterface
    {
        $this->lastTls = $tls;

        return $this->client;
    }
}

/** @internal */
final readonly class BackupThrowingHttpClientFactory implements PveHttpClientFactory
{
    public function create(PveTlsConfiguration $tls): HttpClientInterface
    {
        throw new RuntimeException('factory failure');
    }
}

/** @internal */
final readonly class BackupNoopRetryDelay implements PveRetryDelay
{
    public function pause(int $retryNumber): void
    {
    }
}
