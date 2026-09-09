<?php

declare(strict_types=1);

namespace App\Tests\Unit\Infrastructure\Proxmox;

use App\Infrastructure\Filesystem\NativeAtomicFileMaterializer;
use App\Infrastructure\Proxmox\Pbs\PbsCertificateFingerprint;
use App\Infrastructure\Proxmox\Pbs\PbsCustomCaMaterializer;
use App\Infrastructure\Proxmox\Pbs\PbsNativeHttpClientFactory;
use App\Infrastructure\Proxmox\Pbs\PbsTlsConfiguration;
use App\Infrastructure\Proxmox\PveCertificateFingerprint;
use App\Infrastructure\Proxmox\PveCustomCaMaterializer;
use App\Infrastructure\Proxmox\PveCustomCaCertificate;
use App\Infrastructure\Proxmox\PveNativeHttpClientFactory;
use App\Infrastructure\Proxmox\PveTlsConfiguration;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final class NativeFingerprintTlsBoundaryTest extends TestCase
{
    private const AUTHORIZATION_SENTINEL = 'HODDMIMIR-TLS-SECRET-SENTINEL';

    #[DataProvider('clientKindProvider')]
    public function testExactFingerprintAcceptsSelfSignedCertificateDespiteUntrustedCaAndHostname(
        string $clientKind,
    ): void {
        $probe = NativeSelfSignedTlsProbe::start();

        try {
            $response = $this->client($clientKind, $probe->directory, $probe->fingerprint)->request(
                'GET',
                $probe->url.'/api2/json/version',
                ['headers' => ['Authorization' => self::AUTHORIZATION_SENTINEL]],
            );

            self::assertSame(401, $response->getStatusCode());
            $probe->await();
            self::assertStringContainsString('Authorization: '.self::AUTHORIZATION_SENTINEL, $probe->capturedRequest());
        } finally {
            $probe->close();
        }
    }

    #[DataProvider('clientKindProvider')]
    public function testWrongFingerprintFailsClosedBeforeAuthorizationHeaderIsSent(string $clientKind): void
    {
        $probe = NativeSelfSignedTlsProbe::start();
        $wrongFingerprint = ('0' === $probe->fingerprint[0] ? '1' : '0').substr($probe->fingerprint, 1);

        try {
            try {
                $response = $this->client($clientKind, $probe->directory, $wrongFingerprint)->request(
                    'GET', $probe->url.'/api2/json/version',
                    ['headers' => ['Authorization' => self::AUTHORIZATION_SENTINEL]],
                );
                $response->getStatusCode();
                self::fail('A mismatching certificate fingerprint must abort the TLS request.');
            } catch (TransportExceptionInterface) {
                // The exact leaf check aborts before any HTTP authorization can be sent.
            }

            $probe->await();
            self::assertStringNotContainsString(self::AUTHORIZATION_SENTINEL, $probe->capturedRequest());
        } finally {
            $probe->close();
        }
    }

    #[DataProvider('clientKindProvider')]
    public function testConnectionDeadlineStopsAStalledTlsHandshakeBeforeSendingHeaders(string $clientKind): void
    {
        $probe = NativeSelfSignedTlsProbe::start(handshakeDelay: 6.5);
        try {
            $started = hrtime(true);
            try {
                $this->client($clientKind, $probe->directory, $probe->fingerprint)->request('GET', $probe->url,
                    ['headers' => ['Authorization' => self::AUTHORIZATION_SENTINEL]])->getStatusCode();
                self::fail('A stalled TLS handshake must exceed the separate connection deadline.');
            } catch (TransportExceptionInterface) {
                $elapsed = (hrtime(true) - $started) / 1e9;
                self::assertGreaterThanOrEqual(4.5, $elapsed);
                self::assertLessThan(6.3, $elapsed);
            }
            $probe->await();
            self::assertStringNotContainsString(self::AUTHORIZATION_SENTINEL, $probe->capturedRequest());
        } finally { $probe->close(); }
    }

    #[DataProvider('clientKindProvider')]
    public function testResponseMayTakeLongerThanTheConnectionDeadline(string $clientKind): void
    {
        $probe = NativeSelfSignedTlsProbe::start(responseDelay: 6.0);
        try {
            $started = hrtime(true);
            $response = $this->client($clientKind, $probe->directory, $probe->fingerprint)->request('GET', $probe->url,
                ['headers' => ['Authorization' => self::AUTHORIZATION_SENTINEL]]);
            self::assertSame(401, $response->getStatusCode());
            self::assertGreaterThanOrEqual(5.5, (hrtime(true) - $started) / 1e9);
            $probe->await();
            self::assertStringContainsString(self::AUTHORIZATION_SENTINEL, $probe->capturedRequest());
        } finally { $probe->close(); }
    }

    #[DataProvider('clientKindProvider')]
    public function testConsecutivePinnedRequestsRequireCertificateEvidenceEachTime(string $clientKind): void
    {
        $probe = NativeSelfSignedTlsProbe::start(requestCount: 2);
        try {
            $client = $this->client($clientKind, $probe->directory, $probe->fingerprint);
            for ($request = 0; $request < 2; ++$request) {
                self::assertSame(401, $client->request('GET', $probe->url, ['headers' => ['Authorization' => self::AUTHORIZATION_SENTINEL]])->getStatusCode());
            }
            $probe->await();
            self::assertSame(2, substr_count($probe->capturedRequest(), self::AUTHORIZATION_SENTINEL));
        } finally { $probe->close(); }
    }

    #[DataProvider('clientKindProvider')]
    public function testCaModesEnforceTrustAndHostnameBeforeSendingHeaders(string $clientKind): void
    {
        foreach (['system-untrusted', 'custom-wrong-host', 'custom-valid'] as $mode) {
            $probe = NativeSelfSignedTlsProbe::start(commonName: 'localhost');
            try {
                $files = new NativeAtomicFileMaterializer();
                $bundle = file_get_contents($probe->directory.'/server.pem'); self::assertIsString($bundle);
                self::assertSame(1, preg_match('/-----BEGIN CERTIFICATE-----.*?-----END CERTIFICATE-----/s', $bundle, $matches));
                /** @var array{0: non-falsy-string} $matches A successful full certificate match. */
                $pem = $matches[0]."\n";
                $client = 'pve' === $clientKind
                    ? (new PveNativeHttpClientFactory(new PveCustomCaMaterializer($files, $probe->directory)))->create(
                        'system-untrusted' === $mode ? PveTlsConfiguration::systemCa() : PveTlsConfiguration::customCa(PveCustomCaCertificate::fromPem($pem)))
                    : (new PbsNativeHttpClientFactory(new PbsCustomCaMaterializer($files, $probe->directory)))->create(
                        'system-untrusted' === $mode ? PbsTlsConfiguration::systemCa() : PbsTlsConfiguration::customCa(\App\Infrastructure\Proxmox\Pbs\PbsCustomCaCertificate::fromPem($pem)));
                $url = 'custom-wrong-host' === $mode ? $probe->url : str_replace('127.0.0.1', 'localhost', $probe->url);
                try {
                    $response = $client->request('GET', $url, ['headers' => ['Authorization' => self::AUTHORIZATION_SENTINEL]]);
                    self::assertSame(401, $response->getStatusCode());
                    self::assertSame('custom-valid', $mode);
                } catch (TransportExceptionInterface) {
                    self::assertNotSame('custom-valid', $mode);
                }
                $probe->await();
                if ('custom-valid' === $mode) self::assertStringContainsString(self::AUTHORIZATION_SENTINEL, $probe->capturedRequest());
                else self::assertStringNotContainsString(self::AUTHORIZATION_SENTINEL, $probe->capturedRequest());
            } finally { $probe->close(); }
        }
    }

    /** @return iterable<string, array{string}> */
    public static function clientKindProvider(): iterable
    {
        yield 'PVE native client' => ['pve'];
        yield 'PBS native client' => ['pbs'];
    }

    private function client(string $clientKind, string $directory, string $fingerprint): HttpClientInterface
    {
        $files = new NativeAtomicFileMaterializer();

        return match ($clientKind) {
            'pve' => (new PveNativeHttpClientFactory(new PveCustomCaMaterializer($files, $directory)))->create(
                PveTlsConfiguration::certificateFingerprint(PveCertificateFingerprint::fromSha256($fingerprint)),
            ),
            'pbs' => (new PbsNativeHttpClientFactory(new PbsCustomCaMaterializer($files, $directory)))->create(
                PbsTlsConfiguration::certificateFingerprint(PbsCertificateFingerprint::fromSha256($fingerprint)),
            ),
            default => throw new RuntimeException('Unknown Proxmox client kind.'),
        };
    }
}

/** @internal */
final class NativeSelfSignedTlsProbe
{
    private bool $awaited = false;

    private function __construct(
        public readonly string $directory,
        public readonly string $url,
        public readonly string $fingerprint,
        private readonly string $capturePath,
        private readonly int $processId,
    ) {
    }

    public static function start(float $handshakeDelay = 0.0, float $responseDelay = 0.0, string $commonName = 'hostname-does-not-match.invalid', int $requestCount = 1): self
    {
        $directory = sys_get_temp_dir().'/hoddmimir-native-tls-'.bin2hex(random_bytes(12));
        if (!mkdir($directory, 0700, true)) {
            throw new RuntimeException('TLS probe directory could not be created.');
        }

        $bundlePath = $directory.'/server.pem';
        $capturePath = $directory.'/request.txt';
        file_put_contents($capturePath, '');

        $privateKey = openssl_pkey_new([
            'private_key_bits' => 2048,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ]);
        if (!$privateKey instanceof \OpenSSLAsymmetricKey) {
            throw new RuntimeException('TLS probe key could not be generated.');
        }
        /** @var \OpenSSLAsymmetricKey $privateKey */
        $request = openssl_csr_new(
            ['commonName' => $commonName],
            $privateKey,
            ['digest_alg' => 'sha256'],
        );
        if (!$request instanceof \OpenSSLCertificateSigningRequest) {
            throw new RuntimeException('TLS probe CSR could not be generated.');
        }
        $certificate = openssl_csr_sign($request, null, $privateKey, 1, ['digest_alg' => 'sha256']);
        if (!$certificate instanceof \OpenSSLCertificate) {
            throw new RuntimeException('TLS probe certificate could not be generated.');
        }
        $certificatePem = '';
        $privateKeyPem = '';
        if (!openssl_x509_export($certificate, $certificatePem) || !openssl_pkey_export($privateKey, $privateKeyPem)) {
            throw new RuntimeException('TLS probe certificate could not be exported.');
        }
        if (!is_string($certificatePem) || !is_string($privateKeyPem)) {
            throw new RuntimeException('TLS probe certificate export was invalid.');
        }
        if (false === file_put_contents($bundlePath, $certificatePem.$privateKeyPem)) {
            throw new RuntimeException('TLS probe certificate could not be stored.');
        }
        chmod($bundlePath, 0600);

        $fingerprint = openssl_x509_fingerprint($certificate, 'sha256');
        if (false === $fingerprint) {
            throw new RuntimeException('TLS probe fingerprint could not be calculated.');
        }

        $context = stream_context_create(['ssl' => [
            'local_cert' => $bundlePath,
            'verify_peer' => false,
            'allow_self_signed' => true,
            'crypto_method' => STREAM_CRYPTO_METHOD_TLS_SERVER,
        ]]);
        $server = stream_socket_server(
            ($handshakeDelay > 0 ? 'tcp' : 'tls').'://127.0.0.1:0',
            $errorCode,
            $errorMessage,
            STREAM_SERVER_BIND | STREAM_SERVER_LISTEN,
            $context,
        );
        if (false === $server) {
            throw new RuntimeException(sprintf('TLS probe server failed: %d %s', $errorCode, $errorMessage));
        }
        $address = stream_socket_get_name($server, false);
        if (false === $address) {
            fclose($server);
            throw new RuntimeException('TLS probe address could not be determined.');
        }

        $processId = pcntl_fork();
        if (-1 === $processId) {
            fclose($server);
            throw new RuntimeException('TLS probe process could not be started.');
        }
        if (0 === $processId) {
            for ($i = 0; $i < $requestCount; ++$i) self::serveOneRequest($server, $capturePath, $handshakeDelay, $responseDelay);
            fclose($server);
            exit(0);
        }
        fclose($server);

        return new self($directory, 'https://'.$address, strtolower($fingerprint), $capturePath, $processId);
    }

    public function await(): void
    {
        if ($this->awaited) {
            return;
        }

        $status = 0;
        $waitedProcessId = pcntl_waitpid($this->processId, $status);
        $this->awaited = true;
        if (!is_int($status)) {
            throw new RuntimeException('TLS probe server returned an invalid process status.');
        }
        if ($waitedProcessId !== $this->processId || !pcntl_wifexited($status) || 0 !== pcntl_wexitstatus($status)) {
            throw new RuntimeException('TLS probe server did not exit cleanly.');
        }
    }

    public function capturedRequest(): string
    {
        $request = file_get_contents($this->capturePath);
        if (false === $request) {
            throw new RuntimeException('TLS probe request could not be read.');
        }

        return $request;
    }

    public function close(): void
    {
        if (!$this->awaited) {
            $this->await();
        }
        @unlink($this->capturePath);
        @unlink($this->directory.'/server.pem');
        foreach (glob($this->directory.'/*') ?: [] as $file) @unlink($file);
        @rmdir($this->directory);
    }

    /** @param resource $server */
    private static function serveOneRequest($server, string $capturePath, float $handshakeDelay, float $responseDelay): void
    {
        $connection = @stream_socket_accept($server, 8.0);
        $request = '';
        if (false !== $connection) {
            if ($handshakeDelay > 0) {
                usleep((int) ($handshakeDelay * 1e6));
                if (true !== @stream_socket_enable_crypto($connection, true, STREAM_CRYPTO_METHOD_TLS_SERVER)) { fclose($connection); return; }
            }
            stream_set_timeout($connection, 2);
            while (!str_contains($request, "\r\n\r\n") && strlen($request) <= 65_536) {
                $chunk = fread($connection, 8192);
                if (false === $chunk || '' === $chunk) {
                    break;
                }
                $request .= $chunk;
            }
            file_put_contents($capturePath, $request, FILE_APPEND);
            if (str_contains($request, "\r\n\r\n")) {
                if ($responseDelay > 0) usleep((int) ($responseDelay * 1e6));
                fwrite($connection, "HTTP/1.1 401 Unauthorized\r\nContent-Length: 0\r\nConnection: close\r\n\r\n");
            }
            fclose($connection);
        }

    }
}
