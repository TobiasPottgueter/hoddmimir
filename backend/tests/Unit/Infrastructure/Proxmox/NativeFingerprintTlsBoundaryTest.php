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
            $response = $this->client($clientKind, $probe->directory, $wrongFingerprint)->request(
                'GET',
                $probe->url.'/api2/json/version',
                ['headers' => ['Authorization' => self::AUTHORIZATION_SENTINEL]],
            );

            try {
                $response->getStatusCode();
                self::fail('A mismatching certificate fingerprint must abort the TLS request.');
            } catch (TransportExceptionInterface) {
                // Expected: the exact leaf certificate digest did not match.
            }

            $probe->await();
            self::assertStringNotContainsString(self::AUTHORIZATION_SENTINEL, $probe->capturedRequest());
        } finally {
            $probe->close();
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

    public static function start(): self
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
            ['commonName' => 'hostname-does-not-match.invalid'],
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
            'tls://127.0.0.1:0',
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
            self::serveOneRequest($server, $capturePath);
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
        @rmdir($this->directory);
    }

    /** @param resource $server */
    private static function serveOneRequest($server, string $capturePath): never
    {
        $connection = @stream_socket_accept($server, 8.0);
        fclose($server);
        $request = '';
        if (false !== $connection) {
            stream_set_timeout($connection, 2);
            while (!str_contains($request, "\r\n\r\n") && strlen($request) <= 65_536) {
                $chunk = fread($connection, 8192);
                if (false === $chunk || '' === $chunk) {
                    break;
                }
                $request .= $chunk;
            }
            file_put_contents($capturePath, $request);
            if (str_contains($request, "\r\n\r\n")) {
                fwrite($connection, "HTTP/1.1 401 Unauthorized\r\nContent-Length: 0\r\nConnection: close\r\n\r\n");
            }
            fclose($connection);
        }

        exit(0);
    }
}
