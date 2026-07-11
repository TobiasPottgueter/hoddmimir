<?php

declare(strict_types=1);

namespace App\Tests\Unit\Infrastructure\Proxmox;

use App\Application\Proxmox\Pve\PveReadFailure;
use App\Application\Proxmox\Pve\PveReadFailureCode;
use App\Application\Security\EncryptedSecret;
use App\Application\Security\PlaintextSecret;
use App\Application\Security\SecretCipher;
use App\Application\Security\SecretContext;
use App\Application\Security\SecretPurpose;
use App\Infrastructure\Filesystem\AtomicFileMaterializer;
use App\Tests\Fakes\RecordingSleeper;
use App\Infrastructure\Filesystem\NativeAtomicFileMaterializer;
use App\Infrastructure\Proxmox\PveApiTokenIdentity;
use App\Infrastructure\Proxmox\PveApiUrlBuilder;
use App\Infrastructure\Proxmox\PveCertificateFingerprint;
use App\Infrastructure\Proxmox\PveCustomCaCertificate;
use App\Infrastructure\Proxmox\PveCustomCaMaterializer;
use App\Infrastructure\Proxmox\PveExponentialJitterDelay;
use App\Infrastructure\Proxmox\PveHttpMethod;
use App\Infrastructure\Proxmox\PveJitterSource;
use App\Infrastructure\Proxmox\PveNativeHttpClientFactory;
use App\Infrastructure\Proxmox\PveRetryPolicy;
use App\Infrastructure\Proxmox\PveSystemJitterSource;
use App\Infrastructure\Proxmox\PveTlsConfiguration;
use App\Infrastructure\Proxmox\PveTlsMode;
use App\Infrastructure\Proxmox\PveTokenAuthenticator;
use App\Infrastructure\Validation\MaterializationDirectoryValidator;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\HttpClient\NativeHttpClient;

final class PveConfigurationTest extends TestCase
{
    #[DataProvider('urlProvider')]
    public function testUrlBuilderHandlesDnsIpv4Ipv6PathAndQuery(string $host, string $expectedAuthority): void
    {
        $builder = new PveApiUrlBuilder($host, 8006);

        self::assertSame(
            'https://'.$expectedAuthority.':8006/api2/json/cluster/resources?type=qemu&enabled=1',
            $builder->build(['cluster', 'resources'], ['type' => 'qemu', 'enabled' => true, 'optional' => null]),
        );
        self::assertSame(
            'https://'.$expectedAuthority.':8006/api2/json/nodes%2Fa/status%20value',
            $builder->build(['nodes/a', 'status value']),
        );
        self::assertSame('https://'.$expectedAuthority.':8006/api2/json', $builder->build([]));
    }

    /** @return iterable<string, array{string, string}> */
    public static function urlProvider(): iterable
    {
        yield 'DNS' => ['PVE.Example.Test', 'pve.example.test'];
        yield 'IPv4' => ['192.0.2.10', '192.0.2.10'];
        yield 'IPv6' => ['2001:db8::10', '[2001:db8::10]'];
        yield 'bracketed IPv6' => ['[2001:db8::11]', '[2001:db8::11]'];
    }

    /** @param list<string>|null $segments */
    #[DataProvider('invalidUrlProvider')]
    public function testUrlBuilderRejectsInvalidHostPortAndSegments(string $host, int $port, ?array $segments): void
    {
        $this->expectException(InvalidArgumentException::class);
        $builder = new PveApiUrlBuilder($host, $port);
        if (null !== $segments) {
            $builder->build($segments);
        }
    }

    /** @return iterable<string, array{string, int, list<string>|null}> */
    public static function invalidUrlProvider(): iterable
    {
        yield 'scheme' => ['https://pve.test', 8006, null];
        yield 'path' => ['pve.test/path', 8006, null];
        yield 'bracketed DNS' => ['[pve.test]', 8006, null];
        yield 'half bracketed host' => ['[pve.test', 8006, null];
        yield 'closing bracket only' => ['pve.test]', 8006, null];
        yield 'bad label' => ['-pve.test', 8006, null];
        yield 'too long' => [str_repeat('a', 254), 8006, null];
        yield 'low port' => ['pve.test', 0, null];
        yield 'high port' => ['pve.test', 65536, null];
        yield 'empty path segment' => ['pve.test', 8006, ['cluster', '']];
    }

    public function testCertificateFingerprintsAreExactCertificateSha256Values(): void
    {
        $plain = str_repeat('a1', 32);
        $colon = implode(':', str_split(strtoupper($plain), 2));

        self::assertSame($plain, PveCertificateFingerprint::fromSha256($plain)->sha256);
        self::assertSame($plain, PveCertificateFingerprint::fromSha256($colon)->sha256);

        foreach (['', str_repeat('a', 63), 'aa:'.str_repeat('b', 62), str_repeat('gg', 32), 'aa::'.str_repeat('bb', 31)] as $invalid) {
            try {
                PveCertificateFingerprint::fromSha256($invalid);
                self::fail('Expected invalid fingerprint rejection.');
            } catch (InvalidArgumentException $exception) {
                self::assertSame('The PVE certificate fingerprint is invalid.', $exception->getMessage());
            }
        }
    }

    public function testInvalidFingerprintEscapesAsTypedConfigurationError(): void
    {
        $this->expectException(InvalidArgumentException::class);
        PveCertificateFingerprint::fromSha256('invalid');
    }

    public function testTlsModesAreExclusiveAndNativeOptionsNeverRelaxVerification(): void
    {
        $certificate = PveCustomCaCertificate::fromPem($this->certificatePem());
        $materializer = new PveCustomCaMaterializer(new NativeAtomicFileMaterializer(), $this->temporaryDirectory());
        $factory = new PveNativeHttpClientFactory($materializer);
        $fingerprint = PveCertificateFingerprint::fromSha256(str_repeat('ab', 32));
        $configurations = [
            PveTlsConfiguration::systemCa(),
            PveTlsConfiguration::customCa($certificate),
            PveTlsConfiguration::certificateFingerprint($fingerprint),
        ];

        foreach ($configurations as $configuration) {
            $options = $factory->options($configuration);
            self::assertTrue($options['verify_peer']);
            self::assertTrue($options['verify_host']);
            self::assertSame(0, $options['max_redirects']);
            self::assertSame(30.0, $options['timeout']);
            self::assertSame(30.0, $options['max_duration']);
        }

        self::assertSame(PveTlsMode::SystemCa, $configurations[0]->mode);
        self::assertArrayNotHasKey('cafile', $factory->options($configurations[0]));
        self::assertArrayNotHasKey('peer_fingerprint', $factory->options($configurations[0]));
        self::assertSame($materializer->materialize($certificate), $factory->options($configurations[1])['cafile']);
        self::assertSame(['sha256' => str_repeat('ab', 32)], $factory->options($configurations[2])['peer_fingerprint']);
        self::assertInstanceOf(NativeHttpClient::class, $factory->create(PveTlsConfiguration::systemCa()));
    }

    public function testCustomCaValidationAndAtomicRootOnlyMaterialization(): void
    {
        $pem = $this->certificatePem();
        $certificate = PveCustomCaCertificate::fromPem("\n".$pem."\n");
        self::assertSame(hash('sha256', $certificate->pem), $certificate->fingerprint());
        self::assertSame(2, substr_count(PveCustomCaCertificate::fromPem($pem.$pem)->pem, 'BEGIN CERTIFICATE'));

        $baseDirectory = $this->temporaryDirectory();
        self::assertTrue(chmod($baseDirectory, 0755));
        $directory = $baseDirectory.'/proxmox-ca';
        $materializer = new PveCustomCaMaterializer(new NativeAtomicFileMaterializer(), $baseDirectory);
        self::assertInstanceOf(
            PveCustomCaMaterializer::class,
            new PveCustomCaMaterializer(new NativeAtomicFileMaterializer()),
        );
        $path = $materializer->materialize($certificate);
        self::assertSame($path, $materializer->materialize($certificate));
        self::assertSame($certificate->pem, file_get_contents($path));
        self::assertSame(0755, fileperms($baseDirectory) & 0777);
        self::assertSame(0700, fileperms($directory) & 0777);
        self::assertSame(0600, fileperms($path) & 0777);

        foreach ([
            'relative/path',
            '/',
            '/tmp',
            '/app',
            '/tmp/a/../b',
            '/tmp/a/..',
            '/tmp/a/',
            '/tmp//a',
            '/tmp/./a',
            "/tmp/a\0b",
        ] as $invalidDirectory) {
            try {
                new PveCustomCaMaterializer(new NativeAtomicFileMaterializer(), $invalidDirectory);
                self::fail('Expected unsafe materialization root rejection.');
            } catch (InvalidArgumentException $exception) {
                self::assertSame('The custom CA materialization directory is invalid.', $exception->getMessage());
            }
        }

        foreach ([
            'not pem',
            str_repeat('x', 262_145),
            $pem.'trailing',
            "-----BEGIN CERTIFICATE-----\nYWJj\n-----END CERTIFICATE-----\n",
        ] as $invalidPem) {
            try {
                PveCustomCaCertificate::fromPem($invalidPem);
                self::fail('Expected invalid custom CA rejection.');
            } catch (InvalidArgumentException $exception) {
                self::assertSame('The custom CA bundle is invalid.', $exception->getMessage());
            }
        }

        try {
            PveCustomCaCertificate::fromPem(str_repeat($pem, 17));
            self::fail('Expected excessive custom CA count rejection.');
        } catch (InvalidArgumentException $exception) {
            self::assertSame('The custom CA bundle is invalid.', $exception->getMessage());
        }
    }

    public function testMaterializationAlwaysUsesADedicatedLeafBelowAControlledBase(): void
    {
        self::assertNull(MaterializationDirectoryValidator::dedicatedChildPath('/var', 'tmp'));
        self::assertNull(MaterializationDirectoryValidator::dedicatedChildPath('/app', 'var'));
        self::assertNull(MaterializationDirectoryValidator::dedicatedChildPath('/var/tmp', 'bad/leaf'));
        self::assertNull(MaterializationDirectoryValidator::dedicatedChildPath('/var/tmp', ''));
        self::assertNull(MaterializationDirectoryValidator::dedicatedChildPath('/var/tmp', str_repeat('a', 65)));

        $testBase = $this->temporaryDirectory();
        $files = new RecordingAtomicFileMaterializer();
        $certificate = PveCustomCaCertificate::fromPem($this->certificatePem());
        foreach (['/var/tmp', '/app/var', $testBase] as $baseDirectory) {
            $path = (new PveCustomCaMaterializer($files, $baseDirectory))->materialize($certificate);
            $dedicatedDirectory = $baseDirectory.'/proxmox-ca';
            self::assertSame($dedicatedDirectory, $files->lastDirectory);
            self::assertNotSame($baseDirectory, dirname($path));
            self::assertSame($dedicatedDirectory, dirname($path));
        }
    }

    public function testInvalidCustomCaEscapesAsTypedConfigurationError(): void
    {
        $this->expectException(InvalidArgumentException::class);
        PveCustomCaCertificate::fromPem('invalid');
    }

    public function testInvalidCustomCaDirectoryEscapesAsTypedConfigurationError(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new PveCustomCaMaterializer(new NativeAtomicFileMaterializer(), 'relative');
    }

    public function testTokenIdentityAuthenticatorAndPurposeAreStrictAndSecretScoped(): void
    {
        $identity = PveApiTokenIdentity::fromUserAndTokenId('collector@pve', 'inventory-token');
        self::assertSame('PVEAPIToken=collector@pve!inventory-token=', $identity->authorizationPrefix());
        self::assertSame(
            'PVEAPIToken=collector+audit@pve!Inventory_1=',
            PveApiTokenIdentity::fromUserAndTokenId('collector+audit@pve', 'Inventory_1')->authorizationPrefix(),
        );

        foreach ([
            'collector',
            'collector @pve',
            "collector\0audit@pve",
            "collector\x01audit@pve",
            "collector\x1Baudit@pve",
            "collector\x7Faudit@pve",
            str_repeat('u', 65).'@pve',
        ] as $invalidUser) {
            $this->assertInvalidTokenIdentity($invalidUser, 'token');
        }
        foreach (['', '1token', 'x', 'bad!token', str_repeat('t', 65)] as $invalidToken) {
            $this->assertInvalidTokenIdentity('collector@pve', $invalidToken);
        }

        $context = SecretContext::forCredential('credential-pve', SecretPurpose::PveCollectorToken);
        $cipher = new FixedSecretCipher('TOKEN-SENTINEL');
        $authenticator = new PveTokenAuthenticator(
            $identity,
            EncryptedSecret::fromEncoded('opaque-envelope'),
            $context,
            $cipher,
        );
        $headerLength = $authenticator->authorize(static function (string $header): int {
            self::assertStringStartsWith('PVEAPIToken=collector@pve!inventory-token=', $header);
            self::assertStringEndsWith('TOKEN-SENTINEL', $header);
            return strlen($header);
        });
        self::assertGreaterThan(strlen('TOKEN-SENTINEL'), $headerLength);
        self::assertSame(1, $cipher->decryptions);

        $this->expectException(InvalidArgumentException::class);
        new PveTokenAuthenticator(
            $identity,
            EncryptedSecret::fromEncoded('opaque-envelope'),
            SecretContext::forCredential('credential-pve', SecretPurpose::PveBackupToken),
            $cipher,
        );
    }

    public function testAuthenticatorMapsDecryptionFailureWithoutLeakingTheCause(): void
    {
        $authenticator = new PveTokenAuthenticator(
            PveApiTokenIdentity::fromUserAndTokenId('collector@pve', 'token'),
            EncryptedSecret::fromEncoded('opaque-envelope'),
            SecretContext::forCredential('credential-pve', SecretPurpose::PveCollectorToken),
            new ThrowingSecretCipher(),
        );

        try {
            $authenticator->authorize(static fn (): null => null);
            self::fail('Expected credential failure.');
        } catch (PveReadFailure $failure) {
            self::assertSame(PveReadFailureCode::CredentialUnavailable, $failure->failureCode);
            self::assertStringNotContainsString('TOKEN-SENTINEL', $failure->getMessage());
        }
    }

    public function testInvalidTokenIdentityEscapesAsTypedConfigurationError(): void
    {
        $this->expectException(InvalidArgumentException::class);
        PveApiTokenIdentity::fromUserAndTokenId('invalid', 'token');
    }

    public function testRetryPolicyIsBoundedAndReadOnlyAndDelayUsesInjectedJitter(): void
    {
        $policy = new PveRetryPolicy(3);
        foreach ([PveHttpMethod::Get, PveHttpMethod::Head] as $method) {
            self::assertTrue($method->isReadOnly());
            self::assertTrue($policy->shouldRetry($method, 1, true, null));
            foreach ([408, 429, 502, 503, 504] as $status) {
                self::assertTrue($policy->shouldRetry($method, 1, false, $status));
            }
            self::assertFalse($policy->shouldRetry($method, 1, false, 500));
            self::assertFalse($policy->shouldRetry($method, 3, true, null));
        }

        foreach ([PveHttpMethod::Post, PveHttpMethod::Put, PveHttpMethod::Patch, PveHttpMethod::Delete] as $method) {
            self::assertFalse($method->isReadOnly());
            self::assertFalse($policy->shouldRetry($method, 1, true, 503));
        }
        self::assertFalse($policy->shouldRetry(PveHttpMethod::Get, 1, false, null));

        foreach ([0, 6] as $invalidLimit) {
            try {
                new PveRetryPolicy($invalidLimit);
                self::fail('Expected invalid retry limit.');
            } catch (InvalidArgumentException $exception) {
                self::assertSame('The PVE retry limit is invalid.', $exception->getMessage());
            }
        }

        $sleeper = new RecordingSleeper();
        $delay = new PveExponentialJitterDelay($sleeper, new FixedJitterSource(1));
        foreach ([1, 2, 4, 5] as $retryNumber) {
            $delay->pause($retryNumber);
        }
        self::assertSame([2, 3, 9, 9], $sleeper->durations);

        try {
            $delay->pause(0);
            self::fail('Expected invalid retry number.');
        } catch (InvalidArgumentException $exception) {
            self::assertSame('The PVE retry number is invalid.', $exception->getMessage());
        }

        self::assertContains((new PveSystemJitterSource())->seconds(), [0, 1]);
    }

    public function testInvalidRetryLimitEscapesAsTypedConfigurationError(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new PveRetryPolicy(0);
    }

    public function testInvalidRetryNumberEscapesAsTypedConfigurationError(): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new PveExponentialJitterDelay(new RecordingSleeper(), new FixedJitterSource(0)))->pause(0);
    }

    private function assertInvalidTokenIdentity(string $user, string $token): void
    {
        try {
            PveApiTokenIdentity::fromUserAndTokenId($user, $token);
            self::fail('Expected invalid token identity.');
        } catch (InvalidArgumentException $exception) {
            self::assertSame('The PVE API token identity is invalid.', $exception->getMessage());
        }
    }

    private function certificatePem(): string
    {
        $key = openssl_pkey_new(['private_key_bits' => 1024]);
        if (!$key instanceof \OpenSSLAsymmetricKey) {
            throw new RuntimeException('Could not build test key.');
        }
        /** @var \OpenSSLAsymmetricKey $key */
        $csr = openssl_csr_new(['commonName' => 'pve.test'], $key, ['digest_alg' => 'sha256']);
        if (!$csr instanceof \OpenSSLCertificateSigningRequest) {
            throw new RuntimeException('Could not build test CSR.');
        }
        $certificate = openssl_csr_sign($csr, null, $key, 1, ['digest_alg' => 'sha256']);
        if (!$certificate instanceof \OpenSSLCertificate) {
            throw new RuntimeException('Could not build test certificate.');
        }
        $pem = '';
        $exported = openssl_x509_export($certificate, $pem);
        self::assertTrue($exported);
        if (!is_string($pem)) {
            throw new RuntimeException('Could not export test certificate.');
        }
        return $pem;
    }

    private function temporaryDirectory(): string
    {
        $path = sys_get_temp_dir().'/hoddmimir-pve-'.bin2hex(random_bytes(8));
        self::assertTrue(mkdir($path, 0700, true));
        return $path;
    }
}

/** @internal */
final class FixedSecretCipher implements SecretCipher
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
final readonly class ThrowingSecretCipher implements SecretCipher
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
final readonly class FixedJitterSource implements PveJitterSource
{
    public function __construct(private int $jitter)
    {
    }

    public function seconds(): int
    {
        return $this->jitter;
    }
}

/** @internal */
final class RecordingAtomicFileMaterializer implements AtomicFileMaterializer
{
    public ?string $lastDirectory = null;

    public function materialize(
        string $directory,
        string $filename,
        string $contents,
        int $directoryMode,
        int $fileMode,
    ): string {
        $this->lastDirectory = $directory;
        TestCase::assertSame(0700, $directoryMode);
        TestCase::assertSame(0600, $fileMode);
        TestCase::assertNotSame('', $contents);

        return $directory.'/'.$filename;
    }
}
