<?php

declare(strict_types=1);

namespace App\Tests\Unit\Infrastructure\Persistence\MariaDb;

use App\Application\Inventory\Connection\ConnectionId;
use App\Application\Inventory\Connection\EndpointId;
use App\Application\Inventory\Connection\EndpointReadFailure;
use App\Application\Inventory\Connection\EndpointReadFailureCode;
use App\Application\Security\EncryptedSecret;
use App\Application\Security\PlaintextSecret;
use App\Application\Security\SecretCipher;
use App\Application\Security\SecretContext;
use App\Application\Security\SecretPurpose;
use App\Infrastructure\Persistence\MariaDb\DbalPbsEndpointReadConfigurationSource;
use App\Infrastructure\Proxmox\Pbs\PbsTlsMode;
use Doctrine\DBAL\Connection;
use LogicException;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use RuntimeException;

#[AllowMockObjectsWithoutExpectations]
final class DbalPbsEndpointReadConfigurationSourceTest extends TestCase
{
    public function testPrincipalIsSplitAtLastAtAndUsesPbsSecretPurpose(): void
    {
        $database = $this->createMock(Connection::class);
        $database->expects(self::once())
            ->method('fetchAssociative')
            ->with(
                self::callback(static fn (string $sql): bool =>
                    str_contains($sql, 'LEFT JOIN proxmox_connection_endpoints')
                    && str_contains($sql, 'e.enabled = 1')
                    && str_contains($sql, "cr.purpose = 'collector'")
                    && str_contains($sql, "cr.auth_scheme = 'api_token'")),
                ['connection_id' => str_repeat('c', 16), 'endpoint_id' => str_repeat('e', 16)],
            )
            ->willReturn($this->row(principal: 'svc@operations@pbs'));

        $configuration = (new DbalPbsEndpointReadConfigurationSource($database))->load(
            $this->connectionId(),
            $this->endpointId(),
            7,
        );
        $cipher = new PurposeRecordingSecretCipher();

        self::assertSame(
            'PBSAPIToken svc@operations@pbs!inventory:01234567-89ab-cdef-0123-456789abcdef',
            $configuration->authenticator($cipher)->authorize(static fn (string $header): string => $header),
        );
        self::assertSame(SecretPurpose::PbsCollectorToken, $cipher->purpose);
    }

    public function testItLoadsAllThreeMutuallyExclusiveTlsModes(): void
    {
        $rows = [
            $this->row(),
            $this->row(tlsMode: 'custom_ca', customCa: $this->certificatePem()),
            $this->row(tlsMode: 'sha256_fingerprint', fingerprint: str_repeat("\xAB", 32)),
        ];

        foreach ([PbsTlsMode::SystemCa, PbsTlsMode::CustomCa, PbsTlsMode::CertificateFingerprint] as $index => $mode) {
            $configuration = $this->source($rows[$index])->load(
                $this->connectionId(),
                $this->endpointId(),
                7,
            );

            self::assertSame('pbs.example.test', $configuration->host);
            self::assertSame(8007, $configuration->port);
            self::assertSame($mode, $configuration->tls->mode);
            self::assertSame(['value' => '[REDACTED]'], $configuration->__debugInfo());
        }
    }

    public function testEveryNonExclusiveTlsCombinationFailsClosed(): void
    {
        $cases = [
            $this->row(customCa: $this->certificatePem()),
            $this->row(fingerprint: str_repeat("\xAB", 32)),
            $this->row(tlsMode: 'custom_ca', customCa: $this->certificatePem(), fingerprint: str_repeat("\xAB", 32)),
            $this->row(tlsMode: 'sha256_fingerprint', customCa: $this->certificatePem(), fingerprint: str_repeat("\xAB", 32)),
        ];

        foreach ($cases as $row) {
            try {
                $this->source($row)->load($this->connectionId(), $this->endpointId(), 7);
                self::fail('A non-exclusive PBS TLS configuration was accepted.');
            } catch (EndpointReadFailure $failure) {
                self::assertSame(EndpointReadFailureCode::Tls, $failure->failureCode);
            }
        }
    }

    public function testSecretBearingConfigurationIsRedactedAndCannotBeSerialized(): void
    {
        $configuration = $this->source($this->row())->load(
            $this->connectionId(),
            $this->endpointId(),
            7,
        );

        self::assertSame(['value' => '[REDACTED]'], $configuration->__debugInfo());
        self::assertStringNotContainsString('opaque-encrypted-secret', var_export($configuration, true));

        try {
            serialize($configuration);
            self::fail('Secret-bearing PBS endpoint configuration was serialized.');
        } catch (LogicException $exception) {
            self::assertSame('PBS endpoint read configurations cannot be serialized.', $exception->getMessage());
        }

        $this->expectException(LogicException::class);
        $configuration->__unserialize([]);
    }

    /** @param array<string, mixed> $row */
    private function source(array $row): DbalPbsEndpointReadConfigurationSource
    {
        $database = $this->createMock(Connection::class);
        $database->method('fetchAssociative')->willReturn($row);

        return new DbalPbsEndpointReadConfigurationSource($database);
    }

    /** @return array<string, mixed> */
    private function row(
        string $tlsMode = 'system_ca',
        ?string $customCa = null,
        ?string $fingerprint = null,
        string $principal = 'collector@pbs',
    ): array {
        return [
            'product' => 'pbs',
            'connection_enabled' => 1,
            'connection_revision' => 7,
            'endpoint_id' => str_repeat('e', 16),
            'host' => 'pbs.example.test',
            'port' => '8007',
            'tls_mode' => $tlsMode,
            'custom_ca_pem' => $customCa,
            'sha256_fingerprint' => $fingerprint,
            'credential_id' => str_repeat('d', 16),
            'principal' => $principal,
            'token_name' => 'inventory',
            'secret_envelope' => 'opaque-encrypted-secret',
        ];
    }

    private function connectionId(): ConnectionId
    {
        return new ConnectionId(str_repeat('c', 16));
    }

    private function endpointId(): EndpointId
    {
        return new EndpointId(str_repeat('e', 16));
    }

    private function certificatePem(): string
    {
        $key = openssl_pkey_new(['private_key_bits' => 1024]);
        if (!$key instanceof \OpenSSLAsymmetricKey) {
            throw new RuntimeException('Could not build test key.');
        }
        /** @var \OpenSSLAsymmetricKey $key */
        $csr = openssl_csr_new(['commonName' => 'pbs.test'], $key, ['digest_alg' => 'sha256']);
        if (!$csr instanceof \OpenSSLCertificateSigningRequest) {
            throw new RuntimeException('Could not build test CSR.');
        }
        /** @var \OpenSSLCertificateSigningRequest $csr */
        $certificate = openssl_csr_sign($csr, null, $key, 1, ['digest_alg' => 'sha256']);
        if (!$certificate instanceof \OpenSSLCertificate) {
            throw new RuntimeException('Could not build test certificate.');
        }
        $pem = '';
        if (!openssl_x509_export($certificate, $pem) || !is_string($pem)) {
            throw new RuntimeException('Could not export test certificate.');
        }

        return $pem;
    }
}

/** @internal */
final class PurposeRecordingSecretCipher implements SecretCipher
{
    public ?SecretPurpose $purpose = null;

    public function encrypt(PlaintextSecret $plaintext, SecretContext $context): EncryptedSecret
    {
        return EncryptedSecret::fromEncoded('unused');
    }

    public function decrypt(EncryptedSecret $encrypted, SecretContext $context): PlaintextSecret
    {
        $this->purpose = $context->purpose();

        return PlaintextSecret::fromString('01234567-89ab-cdef-0123-456789abcdef');
    }

    public function primaryKeyId(): string
    {
        return 'test';
    }
}
