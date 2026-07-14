<?php

declare(strict_types=1);

namespace App\Tests\Unit\Infrastructure\Proxmox\Pbs;

use App\Application\Proxmox\Pbs\PbsDatastoreId;
use App\Application\Proxmox\Pbs\PbsReadFailure;
use App\Application\Proxmox\Pbs\PbsReadFailureCode;
use App\Application\Proxmox\Pbs\PbsTaskFilterFamily;
use App\Application\Proxmox\Pbs\PbsTaskListQuery;
use App\Application\Proxmox\Pbs\PbsTaskPass;
use App\Application\Proxmox\Pbs\PbsTaskWindow;
use App\Application\Security\EncryptedSecret;
use App\Application\Security\PlaintextSecret;
use App\Application\Security\SecretCipher;
use App\Application\Security\SecretContext;
use App\Application\Security\SecretPurpose;
use App\Infrastructure\Filesystem\AtomicFileMaterializer;
use App\Infrastructure\Proxmox\Pbs\PbsApiTokenIdentity;
use App\Infrastructure\Proxmox\Pbs\PbsApiUrlBuilder;
use App\Infrastructure\Proxmox\Pbs\PbsCertificateFingerprint;
use App\Infrastructure\Proxmox\Pbs\PbsCustomCaCertificate;
use App\Infrastructure\Proxmox\Pbs\PbsCustomCaMaterializer;
use App\Infrastructure\Proxmox\Pbs\PbsExponentialJitterDelay;
use App\Infrastructure\Proxmox\Pbs\PbsJitterSource;
use App\Infrastructure\Proxmox\Pbs\PbsNativeHttpClientFactory;
use App\Infrastructure\Proxmox\Pbs\PbsRequest;
use App\Infrastructure\Proxmox\Pbs\PbsRetryPolicy;
use App\Infrastructure\Proxmox\Pbs\PbsSystemJitterSource;
use App\Infrastructure\Proxmox\Pbs\PbsTlsConfiguration;
use App\Infrastructure\Proxmox\Pbs\PbsTlsMode;
use App\Infrastructure\Proxmox\Pbs\PbsTokenAuthenticator;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\HttpClient\NativeHttpClient;

final class PbsConfigurationTest extends TestCase
{
    public function testFixedRequestsAndUrlBuilderEncodeOnlyTypedValues(): void
    {
        $builder = new PbsApiUrlBuilder('PBS.Example.Test');
        self::assertSame('https://pbs.example.test:8007/api2/json/version', $builder->build(PbsRequest::version()));
        self::assertSame('https://pbs.example.test:8007/api2/json/ping', $builder->build(PbsRequest::ping()));
        self::assertSame('https://pbs.example.test:8007/api2/json/nodes', $builder->build(PbsRequest::nodes()));
        self::assertSame('https://pbs.example.test:8007/api2/json/access/permissions?path=%2Fsystem%2Fstatus', $builder->build(PbsRequest::permission('/system/status')));
        self::assertSame('https://pbs.example.test:8007/api2/json/nodes/pbs-1/status', $builder->build(PbsRequest::nodeStatus('pbs-1')));
        self::assertSame('https://pbs.example.test:8007/api2/json/nodes/pbs-1/identity', $builder->build(PbsRequest::instanceIdentity('pbs-1')));
        self::assertSame('https://pbs.example.test:8007/api2/json/config/datastore', $builder->build(PbsRequest::datastoreConfigurations()));
        self::assertSame('https://pbs.example.test:8007/api2/json/admin/datastore', $builder->build(PbsRequest::datastores()));
        self::assertSame('https://pbs.example.test:8007/api2/json/admin/datastore/store_a/status?verbose=0', $builder->build(PbsRequest::datastoreStatus(new PbsDatastoreId('store_a'))));
        self::assertSame('https://pbs.example.test:8007/api2/json/admin/prune', $builder->build(PbsRequest::pruneJobs()));
        self::assertSame('https://pbs.example.test:8007/api2/json/admin/sync?sync-direction=all', $builder->build(PbsRequest::syncJobs()));
        self::assertSame('https://pbs.example.test:8007/api2/json/admin/verify', $builder->build(PbsRequest::verifyJobs()));
        self::assertSame(
            'https://pbs.example.test:8007/api2/json/nodes/pbs-1/tasks?start=0&limit=256&typefilter=backup&running=1',
            $builder->build(PbsRequest::tasks('pbs-1', new PbsTaskListQuery(
                PbsTaskFilterFamily::Backup,
                PbsTaskPass::Running,
                0,
                256,
                null,
            ))),
        );
        self::assertSame(
            'https://pbs.example.test:8007/api2/json/nodes/pbs-1/tasks?start=256&limit=256&typefilter=verif&since=100&until=200',
            $builder->build(PbsRequest::tasks('pbs-1', new PbsTaskListQuery(
                PbsTaskFilterFamily::Verify,
                PbsTaskPass::History,
                256,
                256,
                new PbsTaskWindow(100, 200),
            ))),
        );
        self::assertSame(65_536, PbsRequest::version()->maximumBodyBytes);
        self::assertSame(262_144, PbsRequest::nodeStatus('pbs')->maximumBodyBytes);
        self::assertSame(8_388_608, PbsRequest::datastores()->maximumBodyBytes);
        self::assertSame(4_194_304, PbsRequest::pruneJobs()->maximumBodyBytes);
        self::assertSame(2_097_152, PbsRequest::tasks('pbs', new PbsTaskListQuery(
            PbsTaskFilterFamily::Sync,
            PbsTaskPass::Running,
            0,
            1,
            null,
        ))->maximumBodyBytes);
        self::assertSame('https://[2001:db8::1]:8443/api2/json/ping', (new PbsApiUrlBuilder('2001:db8::1', 8443))->build(PbsRequest::ping()));
    }

    public function testInvalidHostsPortsNodesAndPermissionPathsFailLocally(): void
    {
        foreach ([['https://pbs', 8007], ['bad/path', 8007], ['pbs', 0], ['pbs', 65536]] as [$host, $port]) {
            try { new PbsApiUrlBuilder($host, $port); self::fail('invalid endpoint'); } catch (InvalidArgumentException) {}
        }
        foreach (["bad\n", '-bad', str_repeat('a', 64)] as $node) {
            try { PbsRequest::nodeStatus($node); self::fail('invalid node'); } catch (InvalidArgumentException) {}
        }
        foreach (['/', '/datastore/ab', '/datastore/bad//child'] as $path) {
            try { PbsRequest::permission($path); self::fail('invalid permission path'); } catch (InvalidArgumentException) {}
        }
        self::assertSame('/datastore', PbsRequest::permission('/datastore')->query['path']);
        self::assertSame('/system/tasks', PbsRequest::permission('/system/tasks')->query['path']);
        self::assertSame('/remote', PbsRequest::permission('/remote')->query['path']);
        self::assertSame('/datastore/store_a', PbsRequest::permission('/datastore/store_a')->query['path']);
        self::assertSame('/datastore/store_a/tenant/pve', PbsRequest::permission('/datastore/store_a/tenant/pve')->query['path']);
        $permissionPrefix = '/datastore/store_a/';
        $maximumPermissionPath = $permissionPrefix.str_repeat('a', 128 - strlen($permissionPrefix));
        self::assertSame(128, strlen($maximumPermissionPath));
        self::assertSame($maximumPermissionPath, PbsRequest::permission($maximumPermissionPath)->query['path']);
        try {
            PbsRequest::permission($maximumPermissionPath.'a');
            self::fail('A PBS permission path beyond the official 128-byte schema was accepted.');
        } catch (InvalidArgumentException) {}
        foreach ([65_535, 268_435_457] as $limit) {
            try {
                PbsRequest::namespaces(new \App\Application\Proxmox\Pbs\PbsDatastoreId('store_a'), $limit);
                self::fail('Invalid PBS content body limit accepted.');
            } catch (InvalidArgumentException) {}
        }
    }

    public function testTokenHeaderPurposeAndCanonicalSecretAreStrict(): void
    {
        $identity = PbsApiTokenIdentity::fromParts('collector.audit', 'pbs', 'inventory_1');
        self::assertSame('PBSAPIToken collector.audit@pbs!inventory_1:', $identity->authorizationPrefix());
        self::assertSame(
            'PBSAPIToken svc$backup@pbs!inventory_1:',
            PbsApiTokenIdentity::fromParts('svc$backup', 'pbs', 'inventory_1')->authorizationPrefix(),
        );
        foreach ([['bad user', 'pbs', 'token'], ['user', 'bad!', 'token'], ['user', 'pbs', 'bad!'], [str_repeat('u', 60), 'pbs', 'token']] as $parts) {
            try { PbsApiTokenIdentity::fromParts(...$parts); self::fail('invalid identity'); } catch (InvalidArgumentException) {}
        }
        $cipher = new PbsFixedSecretCipher('01234567-89ab-cdef-0123-456789abcdef');
        $auth = new PbsTokenAuthenticator(
            $identity,
            EncryptedSecret::fromEncoded('opaque'),
            SecretContext::forCredential('pbs-credential', SecretPurpose::PbsCollectorToken),
            $cipher,
        );
        self::assertSame(
            'PBSAPIToken collector.audit@pbs!inventory_1:01234567-89ab-cdef-0123-456789abcdef',
            $auth->authorize(static fn (string $header): string => $header),
        );
        self::assertSame(1, $cipher->decryptions);
        try {
            (new PbsTokenAuthenticator($identity, EncryptedSecret::fromEncoded('opaque'), SecretContext::forCredential('pbs-credential', SecretPurpose::PbsCollectorToken), new PbsFixedSecretCipher('not-a-uuid')))
                ->authorize(static fn (): null => null);
            self::fail('invalid secret');
        } catch (PbsReadFailure $failure) { self::assertSame(PbsReadFailureCode::CredentialUnavailable, $failure->failureCode); }
        try {
            (new PbsTokenAuthenticator($identity, EncryptedSecret::fromEncoded('opaque'), SecretContext::forCredential('pbs-credential', SecretPurpose::PbsCollectorToken), new PbsThrowingSecretCipher()))
                ->authorize(static fn (): null => null);
            self::fail('decrypt failure');
        } catch (PbsReadFailure $failure) { self::assertStringNotContainsString('TOKEN-SENTINEL', $failure->getMessage()); }
        $this->expectException(InvalidArgumentException::class);
        new PbsTokenAuthenticator($identity, EncryptedSecret::fromEncoded('opaque'), SecretContext::forCredential('pbs-credential', SecretPurpose::PveCollectorToken), $cipher);
    }

    public function testRetryPolicyAndJitterAreBounded(): void
    {
        $policy = new PbsRetryPolicy(3);
        self::assertTrue($policy->shouldRetry(1, true, null));
        foreach ([408, 429, 502, 503, 504] as $status) { self::assertTrue($policy->shouldRetry(1, false, $status)); }
        self::assertFalse($policy->shouldRetry(1, false, 500));
        self::assertFalse($policy->shouldRetry(1, false, null));
        self::assertFalse($policy->shouldRetry(3, true, null));
        foreach ([0, 6] as $limit) { try { new PbsRetryPolicy($limit); self::fail('invalid retry limit'); } catch (InvalidArgumentException) {} }
        $delay = new PbsExponentialJitterDelay(new PbsFixedJitter(0), 1);
        $delay->pause(1);
        $delay->pause(5);
        foreach ([0, 6] as $attempt) { try { $delay->pause($attempt); self::fail('invalid retry attempt'); } catch (InvalidArgumentException) {} }
        foreach ([0, 10_001] as $base) { try { new PbsExponentialJitterDelay(new PbsFixedJitter(0), $base); self::fail('invalid delay'); } catch (InvalidArgumentException) {} }
        self::assertContains((new PbsSystemJitterSource())->milliseconds(1), [0, 1]);
    }

    public function testTlsModesAreExclusiveAndPinningReplacesCaAndHostnameTrust(): void
    {
        $files = new PbsRecordingMaterializer();
        $ca = PbsCustomCaCertificate::fromPem($this->certificatePem());
        self::assertSame(hash('sha256', $ca->pem), $ca->fingerprint());
        $materializer = new PbsCustomCaMaterializer($files, '/app/var');
        $factory = new PbsNativeHttpClientFactory($materializer);
        $pin = PbsCertificateFingerprint::fromSha256(implode(':', str_split(str_repeat('AB', 32), 2)));
        $configs = [PbsTlsConfiguration::systemCa(), PbsTlsConfiguration::customCa($ca), PbsTlsConfiguration::certificateFingerprint($pin)];
        foreach (array_slice($configs, 0, 2) as $config) {
            $options = $factory->options($config);
            self::assertTrue($options['verify_peer']); self::assertTrue($options['verify_host']); self::assertSame(0, $options['max_redirects']);
        }
        self::assertSame(PbsTlsMode::SystemCa, $configs[0]->mode);
        self::assertSame('/app/var/pbs-ca/ca-'.$ca->fingerprint().'.pem', $factory->options($configs[1])['cafile']);
        $fingerprintOptions = $factory->options($configs[2]);
        self::assertFalse($fingerprintOptions['verify_peer']);
        self::assertFalse($fingerprintOptions['verify_host']);
        self::assertSame(0, $fingerprintOptions['max_redirects']);
        self::assertArrayNotHasKey('cafile', $fingerprintOptions);
        self::assertSame(['sha256' => str_repeat('ab', 32)], $fingerprintOptions['peer_fingerprint']);
        self::assertSame('/app/var/pbs-ca', $files->directory);
        self::assertSame(0700, $files->directoryMode); self::assertSame(0600, $files->fileMode);
        self::assertInstanceOf(NativeHttpClient::class, $factory->create(PbsTlsConfiguration::systemCa()));
        foreach (['bad', str_repeat('g', 64)] as $value) { try { PbsCertificateFingerprint::fromSha256($value); self::fail('invalid pin'); } catch (InvalidArgumentException) {} }
        foreach (['bad', str_repeat('x', 262_145)] as $value) { try { PbsCustomCaCertificate::fromPem($value); self::fail('invalid ca'); } catch (InvalidArgumentException) {} }
        foreach (['relative', '/', '/tmp', '/app'] as $base) { try { new PbsCustomCaMaterializer($files, $base); self::fail('invalid directory'); } catch (InvalidArgumentException) {} }
    }

    private function certificatePem(): string
    {
        $key = openssl_pkey_new(['private_key_bits' => 1024]);
        if (!$key instanceof \OpenSSLAsymmetricKey) { throw new RuntimeException('test key'); }
        /** @var \OpenSSLAsymmetricKey $key */
        $csr = openssl_csr_new(['commonName' => 'pbs.test'], $key, ['digest_alg' => 'sha256']);
        if (!$csr instanceof \OpenSSLCertificateSigningRequest) { throw new RuntimeException('test csr'); }
        $certificate = openssl_csr_sign($csr, null, $key, 1, ['digest_alg' => 'sha256']);
        if (!$certificate instanceof \OpenSSLCertificate) { throw new RuntimeException('test cert'); }
        $pem = '';
        self::assertTrue(openssl_x509_export($certificate, $pem));
        if (!is_string($pem)) { throw new RuntimeException('test pem'); }
        return $pem;
    }
}

/** @internal */
final class PbsFixedSecretCipher implements SecretCipher
{
    public int $decryptions = 0;
    public function __construct(private readonly string $secret) {}
    public function encrypt(PlaintextSecret $plaintext, SecretContext $context): EncryptedSecret { return EncryptedSecret::fromEncoded('unused'); }
    public function decrypt(EncryptedSecret $encrypted, SecretContext $context): PlaintextSecret { ++$this->decryptions; return PlaintextSecret::fromString($this->secret); }
    public function primaryKeyId(): string { return 'test'; }
}
/** @internal */
final class PbsThrowingSecretCipher implements SecretCipher
{
    public function encrypt(PlaintextSecret $plaintext, SecretContext $context): EncryptedSecret { throw new RuntimeException('TOKEN-SENTINEL'); }
    public function decrypt(EncryptedSecret $encrypted, SecretContext $context): PlaintextSecret { throw new RuntimeException('TOKEN-SENTINEL'); }
    public function primaryKeyId(): string { return 'test'; }
}
/** @internal */
final readonly class PbsFixedJitter implements PbsJitterSource
{
    public function __construct(private int $value) {}
    public function milliseconds(int $maximum): int { return $this->value; }
}
/** @internal */
final class PbsRecordingMaterializer implements AtomicFileMaterializer
{
    public string $directory = ''; public int $directoryMode = 0; public int $fileMode = 0;
    public function materialize(string $directory, string $filename, string $contents, int $directoryMode, int $fileMode): string
    { $this->directory = $directory; $this->directoryMode = $directoryMode; $this->fileMode = $fileMode; return $directory.'/'.$filename; }
}
