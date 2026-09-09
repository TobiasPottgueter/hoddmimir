<?php

declare(strict_types=1);

namespace App\Tests\Unit\Infrastructure\Persistence\MariaDb;

use App\Application\Inventory\Connection\ConnectionId;
use App\Application\Inventory\Connection\ConnectionReadFailure;
use App\Application\Inventory\Connection\ConnectionReadFailureCode;
use App\Application\Inventory\Connection\EndpointId;
use App\Application\Inventory\Connection\EndpointReadFailure;
use App\Application\Inventory\Connection\EndpointReadFailureCode;
use App\Infrastructure\Persistence\MariaDb\DbalPveEndpointReadConfigurationSource;
use App\Infrastructure\Proxmox\PveTlsMode;
use Doctrine\DBAL\Connection;
use InvalidArgumentException;
use LogicException;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use RuntimeException;

#[AllowMockObjectsWithoutExpectations]
final class DbalPveEndpointReadConfigurationSourceTest extends TestCase
{
    public function testItLoadsEachExclusiveTlsModeFromOneConsistentSelect(): void
    {
        $rows = [
            $this->row(),
            $this->row(tlsMode: 'custom_ca', customCa: $this->certificatePem()),
            $this->row(tlsMode: 'sha256_fingerprint', fingerprint: str_repeat("\xAB", 32)),
        ];

        foreach ([PveTlsMode::SystemCa, PveTlsMode::CustomCa, PveTlsMode::CertificateFingerprint] as $index => $mode) {
            $database = $this->createMock(Connection::class);
            $database->expects(self::once())
                ->method('fetchAssociative')
                ->with(
                    self::callback(static fn (string $sql): bool =>
                        str_contains($sql, 'LEFT JOIN proxmox_connection_endpoints')
                        && str_contains($sql, 'e.enabled = 1')
                        && str_contains($sql, "cr.purpose = 'collector'")
                        && str_contains($sql, "cr.auth_scheme = 'api_token'")),
                    ['connection_id' => str_repeat('c', 16), 'endpoint_id' => str_repeat('e', 16), 'include_disabled' => 0],
                )
                ->willReturn($rows[$index]);

            $configuration = (new DbalPveEndpointReadConfigurationSource($database))->load(
                new ConnectionId(str_repeat('c', 16)),
                new EndpointId(str_repeat('e', 16)),
                7,
            );

            self::assertSame('pve.example.test', $configuration->host);
            self::assertSame(8006, $configuration->port);
            self::assertSame($mode, $configuration->tls->mode);
            self::assertSame(['value' => '[REDACTED]'], $configuration->__debugInfo());
        }
    }

    public function testConnectionStateAndRevisionDriftAreTypedConnectionChanges(): void
    {
        foreach ([
            false,
            $this->row(product: 'pbs'),
            $this->row(connectionEnabled: 0),
            $this->row(revision: 8),
        ] as $row) {
            try {
                $this->source($row)->load($this->connectionId(), $this->endpointId(), 7);
                self::fail('Changed connection state was accepted.');
            } catch (ConnectionReadFailure $failure) {
                self::assertSame(ConnectionReadFailureCode::ConnectionChanged, $failure->failureCode);
            }
        }
    }

    public function testMissingOrMalformedEndpointCredentialAndTlsFailClosedWithStableCodes(): void
    {
        $cases = [
            [$this->row(endpointId: null), EndpointReadFailureCode::RootUnusable],
            [$this->row(credentialId: null), EndpointReadFailureCode::CredentialUnavailable],
            [$this->row(principal: 'invalid'), EndpointReadFailureCode::CredentialUnavailable],
            [$this->row(secretEnvelope: "bad\0envelope"), EndpointReadFailureCode::CredentialUnavailable],
            [$this->row(tlsMode: 'unknown'), EndpointReadFailureCode::Tls],
            [$this->row(customCa: 'unexpected'), EndpointReadFailureCode::Tls],
            [$this->row(host: 'https://pve.example.test'), EndpointReadFailureCode::RootUnusable],
        ];

        foreach ($cases as [$row, $expectedCode]) {
            try {
                $this->source($row)->load($this->connectionId(), $this->endpointId(), 7);
                self::fail('Malformed endpoint configuration was accepted.');
            } catch (EndpointReadFailure $failure) {
                self::assertSame($expectedCode, $failure->failureCode);
            }
        }
    }

    public function testInvalidExpectedRevisionAndDatabaseFailuresPropagate(): void
    {
        $source = $this->source($this->row());
        $this->expectException(InvalidArgumentException::class);
        $source->load($this->connectionId(), $this->endpointId(), 0);
    }

    public function testDatabaseFailuresAreNotMappedToEndpointFailures(): void
    {
        $database = $this->createMock(Connection::class);
        $database->method('fetchAssociative')->willThrowException(new RuntimeException('database unavailable'));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('database unavailable');
        (new DbalPveEndpointReadConfigurationSource($database))->load(
            $this->connectionId(),
            $this->endpointId(),
            7,
        );
    }

    public function testSecretBearingConfigurationCannotBeSerialized(): void
    {
        $configuration = $this->source($this->row())->load($this->connectionId(), $this->endpointId(), 7);

        try {
            serialize($configuration);
            self::fail('Secret-bearing endpoint configuration was serialized.');
        } catch (LogicException $exception) {
            self::assertSame('PVE endpoint read configurations cannot be serialized.', $exception->getMessage());
        }

        $this->expectException(LogicException::class);
        $configuration->__unserialize([]);
    }

    /** @param array<string, mixed>|false $row */
    private function source(array|false $row): DbalPveEndpointReadConfigurationSource
    {
        $database = $this->createMock(Connection::class);
        $database->method('fetchAssociative')->willReturn($row);

        return new DbalPveEndpointReadConfigurationSource($database);
    }

    /**
     * @return array<string, mixed>
     */
    private function row(
        string $product = 'pve',
        int $connectionEnabled = 1,
        int $revision = 7,
        ?string $endpointId = 'eeeeeeeeeeeeeeee',
        string $host = 'pve.example.test',
        string $tlsMode = 'system_ca',
        ?string $customCa = null,
        ?string $fingerprint = null,
        ?string $credentialId = 'dddddddddddddddd',
        string $principal = 'collector@pve',
        string $secretEnvelope = 'opaque-encrypted-secret',
    ): array {
        return [
            'product' => $product,
            'connection_enabled' => $connectionEnabled,
            'connection_revision' => $revision,
            'endpoint_id' => $endpointId,
            'host' => $host,
            'port' => '8006',
            'tls_mode' => $tlsMode,
            'custom_ca_pem' => $customCa,
            'sha256_fingerprint' => $fingerprint,
            'credential_id' => $credentialId,
            'principal' => $principal,
            'token_name' => 'inventory',
            'secret_envelope' => $secretEnvelope,
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
        $csr = openssl_csr_new(['commonName' => 'pve.test'], $key, ['digest_alg' => 'sha256']);
        if (!$csr instanceof \OpenSSLCertificateSigningRequest) {
            throw new RuntimeException('Could not build test CSR.');
        }
        $certificate = openssl_csr_sign($csr, null, $key, 1, ['digest_alg' => 'sha256']);
        if (!$certificate instanceof \OpenSSLCertificate) {
            throw new RuntimeException('Could not build test certificate.');
        }
        $pem = '';
        if (!openssl_x509_export($certificate, $pem)) {
            throw new RuntimeException('Could not export test certificate.');
        }
        if (!is_string($pem)) {
            throw new RuntimeException('Could not export test certificate.');
        }

        return $pem;
    }
}
