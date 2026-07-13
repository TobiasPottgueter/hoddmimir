<?php

declare(strict_types=1);

namespace App\Tests\Unit\Presentation\Http;

use App\Application\Configuration\Connection\Onboarding\OnboardingCredentialKind;
use App\Application\Configuration\Connection\Onboarding\OnboardingMode;
use App\Application\Configuration\Connection\Onboarding\OnboardingProduct;
use App\Application\Configuration\Connection\Onboarding\OnboardingTlsMode;
use App\Application\Security\Auth\SecurityIdentifierGenerator;
use App\Presentation\Http\OnboardingCommandFactory;
use App\Infrastructure\Proxmox\Onboarding\NativeOnboardingCustomCaValidator;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

final class OnboardingCommandFactoryTest extends TestCase
{
    private const string CONNECTION = '00112233-4455-6677-8899-aabbccddeeff';
    private const string ENDPOINT = '11112222-3333-4444-8555-666677778888';
    private const string CORRELATION = '99999999-aaaa-4bbb-8ccc-dddddddddddd';

    public function testActivationBuildsClosedPveCommandWithoutRetainingSecretsInPayloadHashInput(): void
    {
        $factory = self::factory();
        $request = $this->request($this->pveBody(), ['HTTP_IDEMPOTENCY_KEY' => 'activate-1', 'HTTP_X_CORRELATION_ID' => self::CORRELATION]);

        $command = $factory->fromRequest($request, OnboardingMode::Activate);

        self::assertSame(OnboardingMode::Activate, $command->mode);
        self::assertSame(str_repeat('i', 16), $command->connectionId);
        self::assertNull($command->endpointId);
        self::assertSame(OnboardingProduct::Pve, $command->product);
        self::assertSame(OnboardingTlsMode::SystemCa, $command->endpoint->tlsMode);
        self::assertSame('hoddmimir@pve!scan', $command->credential(OnboardingCredentialKind::Scan)->tokenId);
        self::assertSame(hex2bin(str_replace('-', '', self::CORRELATION)), $command->correlationId);
        self::assertSame(32, strlen($command->payloadHash));
    }

    public function testRotationTargetsExactConnectionAndEndpointWithCustomCa(): void
    {
        $body = $this->pveBody(7);
        $body['endpointId'] = self::ENDPOINT;
        $body['endpoint']['tlsMode'] = 'custom_ca';
        $body['endpoint']['customCaPem'] = $this->certificatePem();
        $request = $this->request($body, ['HTTP_IDEMPOTENCY_KEY' => 'rotate-1']);

        $command = self::factory()
            ->fromRequest($request, OnboardingMode::Rotate, self::CONNECTION);

        self::assertSame(OnboardingMode::Rotate, $command->mode);
        self::assertSame(hex2bin(str_replace('-', '', self::CONNECTION)), $command->connectionId);
        self::assertSame(hex2bin(str_replace('-', '', self::ENDPOINT)), $command->endpointId);
        self::assertSame(7, $command->expectedRevision);
        self::assertSame(OnboardingTlsMode::CustomCa, $command->endpoint->tlsMode);
        self::assertSame(str_repeat('i', 16), $command->correlationId);
    }

    public function testPbsAcceptsExactlyOneScannerCredentialAndFingerprintTrust(): void
    {
        $body = [
            'expectedRevision' => 0,
            'product' => 'pbs',
            'displayName' => 'PBS',
            'endpoint' => [
                'host' => 'pbs.example.test', 'port' => 8007, 'tlsMode' => 'sha256_fingerprint',
                'customCaPem' => null, 'sha256Fingerprint' => str_repeat('ab', 32),
            ],
            'credentials' => ['scan' => ['tokenId' => 'hoddmimir@pbs!scan', 'tokenSecret' => '00000000-0000-0000-0000-000000000000']],
        ];
        $command = self::factory()
            ->fromRequest($this->request($body, ['HTTP_IDEMPOTENCY_KEY' => 'pbs']), OnboardingMode::Activate);

        self::assertSame(OnboardingProduct::Pbs, $command->product);
        self::assertCount(1, $command->credentials);
        self::assertSame(OnboardingTlsMode::Sha256Fingerprint, $command->endpoint->tlsMode);
    }

    public function testVerifiedEndpointAddAndUpdateUseExistingConnectionAndRouteBoundEndpoint(): void
    {
        $factory = self::factory();
        $body = $this->pveBody(3);
        $add = $factory->fromRequest($this->request($body, ['HTTP_IDEMPOTENCY_KEY' => 'add']), OnboardingMode::EndpointAdd, self::CONNECTION);
        $update = $factory->fromRequest($this->request($body, ['HTTP_IDEMPOTENCY_KEY' => 'update']), OnboardingMode::EndpointUpdate, self::CONNECTION, self::ENDPOINT);

        self::assertSame(OnboardingMode::EndpointAdd, $add->mode);
        self::assertNull($add->endpointId);
        self::assertSame(OnboardingMode::EndpointUpdate, $update->mode);
        self::assertSame(hex2bin(str_replace('-', '', self::ENDPOINT)), $update->endpointId);
        self::assertSame($add->connectionId, $update->connectionId);
    }

    /** @param array<string, mixed> $body */
    #[DataProvider('invalidRequests')]
    public function testUnknownMissingMistypedAndCrossProductFieldsFailClosed(
        array $body,
        ?string $key,
        OnboardingMode $mode,
        ?string $routeId,
        ?string $endpointId = null,
    ): void
    {
        $headers = null === $key ? [] : ['HTTP_IDEMPOTENCY_KEY' => $key];
        $this->expectException(InvalidArgumentException::class);
        self::factory()
            ->fromRequest($this->request($body, $headers), $mode, $routeId, $endpointId);
    }

    /** @return iterable<string, array{array<string, mixed>, ?string, OnboardingMode, ?string}|array{array<string, mixed>, ?string, OnboardingMode, ?string, ?string}> */
    public static function invalidRequests(): iterable
    {
        $test = new self('fixture');
        $valid = $test->pveBody();
        yield 'missing idempotency' => [$valid, null, OnboardingMode::Activate, null];
        $unknown = $valid; $unknown['adminToken'] = 'forbidden';
        yield 'unknown admin token' => [$unknown, 'key', OnboardingMode::Activate, null];
        $missing = $valid; unset($missing['credentials']);
        yield 'missing credentials' => [$missing, 'key', OnboardingMode::Activate, null];
        $badRevision = $valid; $badRevision['expectedRevision'] = '0';
        yield 'string revision' => [$badRevision, 'key', OnboardingMode::Activate, null];
        $badEndpoint = $valid; $badEndpoint['endpoint']['insecure'] = true;
        yield 'TLS disable field' => [$badEndpoint, 'key', OnboardingMode::Activate, null];
        $badTrust = $valid; $badTrust['endpoint']['customCaPem'] = 'secret';
        yield 'mixed trust material' => [$badTrust, 'key', OnboardingMode::Activate, null];
        $urlHost = $valid; $urlHost['endpoint']['host'] = 'https://pve.example.test';
        yield 'URL is not a host' => [$urlHost, 'key', OnboardingMode::Activate, null];
        $invalidCa = $valid;
        $invalidCa['endpoint']['tlsMode'] = 'custom_ca';
        $invalidCa['endpoint']['customCaPem'] = "-----BEGIN CERTIFICATE-----\nINVALID\n-----END CERTIFICATE-----";
        yield 'invalid X509 custom CA' => [$invalidCa, 'key', OnboardingMode::Activate, null];
        $badCredentials = $valid; unset($badCredentials['credentials']['backup']);
        yield 'PVE backup missing' => [$badCredentials, 'key', OnboardingMode::Activate, null];
        $extraCredentialField = $valid; $extraCredentialField['credentials']['scan']['password'] = 'forbidden';
        yield 'credential object open' => [$extraCredentialField, 'key', OnboardingMode::Activate, null];
        yield 'activate route ID forbidden' => [$valid, 'key', OnboardingMode::Activate, self::CONNECTION];
        yield 'endpoint add connection missing' => [$valid, 'key', OnboardingMode::EndpointAdd, null];
        yield 'endpoint add path ID forbidden' => [$valid, 'key', OnboardingMode::EndpointAdd, self::CONNECTION, self::ENDPOINT];
        yield 'endpoint update path ID missing' => [$valid, 'key', OnboardingMode::EndpointUpdate, self::CONNECTION];
        yield 'endpoint update path ID invalid' => [$valid, 'key', OnboardingMode::EndpointUpdate, self::CONNECTION, 'not-a-uuid'];
        yield 'rotate endpoint missing' => [$valid, 'key', OnboardingMode::Rotate, self::CONNECTION];
        $rotate = $valid; $rotate['endpointId'] = 'not-a-uuid';
        yield 'rotate endpoint invalid' => [$rotate, 'key', OnboardingMode::Rotate, self::CONNECTION];
        $rotate['endpointId'] = self::ENDPOINT;
        yield 'rotate connection invalid' => [$rotate, 'key', OnboardingMode::Rotate, 'not-a-uuid'];
    }

    /**
     * @return array{
     *     expectedRevision: int,
     *     product: string,
     *     displayName: string,
     *     endpoint: array{host: string, port: int, tlsMode: string, customCaPem: ?string, sha256Fingerprint: ?string},
     *     credentials: array{
     *         scan: array{tokenId: string, tokenSecret: string},
     *         backup: array{tokenId: string, tokenSecret: string}
     *     }
     * }
     */
    private function pveBody(int $revision = 0): array
    {
        return [
            'expectedRevision' => $revision,
            'product' => 'pve',
            'displayName' => 'PVE',
            'endpoint' => ['host' => 'pve.example.test', 'port' => 8006, 'tlsMode' => 'system_ca', 'customCaPem' => null, 'sha256Fingerprint' => null],
            'credentials' => [
                'scan' => ['tokenId' => 'hoddmimir@pve!scan', 'tokenSecret' => 'scan-secret'],
                'backup' => ['tokenId' => 'hoddmimir@pve!backup', 'tokenSecret' => 'backup-secret'],
            ],
        ];
    }

    /**
     * @param array<string, mixed>  $body
     * @param array<string, string> $server
     */
    private function request(array $body, array $server): Request
    {
        return Request::create('/api/v1/connections/onboarding/activate', 'POST', server: $server, content: json_encode($body, JSON_THROW_ON_ERROR));
    }

    private static function factory(): OnboardingCommandFactory
    {
        return new OnboardingCommandFactory(new FixedOnboardingHttpIds(), new NativeOnboardingCustomCaValidator());
    }

    private function certificatePem(): string
    {
        $key = openssl_pkey_new(['private_key_bits' => 1024]);
        if (!$key instanceof \OpenSSLAsymmetricKey) {
            self::fail('Unable to create the test certificate key.');
        }
        $csr = openssl_csr_new(['commonName' => 'onboarding.test'], $key, ['digest_alg' => 'sha256']);
        if (!$csr instanceof \OpenSSLCertificateSigningRequest) {
            self::fail('Unable to create the test certificate request.');
        }
        if (!$key instanceof \OpenSSLAsymmetricKey) {
            self::fail('The test certificate key was replaced unexpectedly.');
        }
        $certificate = openssl_csr_sign($csr, null, $key, 1, ['digest_alg' => 'sha256']);
        if (!$certificate instanceof \OpenSSLCertificate) {
            self::fail('Unable to sign the test certificate.');
        }
        $pem = '';
        self::assertTrue(openssl_x509_export($certificate, $pem));
        if (!is_string($pem)) {
            self::fail('Unable to export the test certificate.');
        }
        return $pem;
    }
}

final class FixedOnboardingHttpIds implements SecurityIdentifierGenerator
{
    public function generate(): string { return str_repeat('i', 16); }
}
