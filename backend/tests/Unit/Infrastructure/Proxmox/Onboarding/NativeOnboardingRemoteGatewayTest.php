<?php

declare(strict_types=1);

namespace App\Tests\Unit\Infrastructure\Proxmox\Onboarding;

use App\Application\Configuration\Connection\Onboarding\OnboardingActivationCommand;
use App\Application\Configuration\Connection\Onboarding\OnboardingCredential;
use App\Application\Configuration\Connection\Onboarding\OnboardingCredentialKind;
use App\Application\Configuration\Connection\Onboarding\OnboardingEndpoint;
use App\Application\Configuration\Connection\Onboarding\OnboardingEvidenceVerifier;
use App\Application\Configuration\Connection\Onboarding\OnboardingMode;
use App\Application\Configuration\Connection\Onboarding\OnboardingPermission;
use App\Application\Configuration\Connection\Onboarding\OnboardingProduct;
use App\Application\Configuration\Connection\Onboarding\OnboardingRemoteFailure;
use App\Application\Configuration\Connection\Onboarding\OnboardingIssueCode;
use App\Application\Configuration\Connection\Onboarding\OnboardingTlsMode;
use App\Infrastructure\Proxmox\Onboarding\NativeOnboardingRemoteGateway;
use App\Infrastructure\Proxmox\Pbs\PbsHttpClientFactory;
use App\Infrastructure\Proxmox\Pbs\PbsRetryDelay;
use App\Infrastructure\Proxmox\Pbs\PbsTlsConfiguration;
use App\Infrastructure\Proxmox\Pbs\PbsTlsMode;
use App\Infrastructure\Proxmox\PveHttpClientFactory;
use App\Infrastructure\Proxmox\PveRetryDelay;
use App\Infrastructure\Proxmox\PveTlsConfiguration;
use App\Infrastructure\Proxmox\PveTlsMode;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final class NativeOnboardingRemoteGatewayTest extends TestCase
{
    public function testPveUsesOnlyDocumentedReadProbesWithSeparateCredentialsAndPinnedTls(): void
    {
        /** @var list<array{string, string, ?string}> $requests */
        $requests = [];
        $factory = new RecordingPveOnboardingClientFactory(function (string $method, string $url, array $options) use (&$requests): MockResponse {
            $requests[] = [$method, $url, self::authorization($options)];
            $queryString = parse_url($url, PHP_URL_QUERY);
            $query = [];
            if (is_string($queryString)) {
                parse_str($queryString, $query);
            }
            $path = $query['path'] ?? null;
            $body = match (true) {
                str_ends_with($url, '/version') => '{"data":{"release":"8.4","version":"8.4.1","repoid":"abcdef12"}}',
                str_ends_with($url, '/access/roles') => '{"data":[{"roleid":"HoddmimirScan","privs":"VM.Audit,Sys.Audit,Pool.Audit,Datastore.Audit"},{"roleid":"HoddmimirBackup","privs":"VM.Backup,Datastore.AllocateSpace"}]}',
                str_ends_with($url, '/access/acl') => '{"data":[{"path":"/","type":"token","ugid":"hoddmimir@pve!scan","roleid":"HoddmimirScan","propagate":1},{"path":"/vms","type":"token","ugid":"hoddmimir@pve!backup","roleid":"HoddmimirBackup","propagate":1},{"path":"/storage","type":"token","ugid":"hoddmimir@pve!backup","roleid":"HoddmimirBackup","propagate":1}]}',
                is_string($path) => json_encode(['data' => [$path => self::pvePrivileges($path, str_contains(self::authorization($options) ?? '', '!backup='))]], JSON_THROW_ON_ERROR),
                default => '{"data":{"/":{"Sys.Audit":1},"/nodes":{"Sys.Audit":1},"/vms":{"VM.Audit":1,"VM.Backup":1},"/pool":{"Pool.Audit":1},"/storage":{"Datastore.Audit":1,"Datastore.AllocateSpace":1}}}',
            };
            return new MockResponse($body, ['http_code' => 200]);
        });
        $gateway = new NativeOnboardingRemoteGateway($factory, new NoopPveOnboardingDelay(), new UnusedPbsOnboardingClientFactory(), new NoopPbsOnboardingDelay());
        $command = $this->command(OnboardingProduct::Pve, OnboardingTlsMode::Sha256Fingerprint, [
            new OnboardingCredential(OnboardingCredentialKind::Scan, 'hoddmimir@pve!scan', 'SCAN-TOKEN-SENTINEL'),
            new OnboardingCredential(OnboardingCredentialKind::Backup, 'hoddmimir@pve!backup', 'BACKUP-TOKEN-SENTINEL'),
        ]);

        $evidence = $gateway->verify($command);

        self::assertTrue($evidence->tlsVerified);
        self::assertCount(2, $evidence->identities);
        self::assertCount(2, $evidence->roles);
        self::assertSame(PveTlsMode::CertificateFingerprint, $factory->tls[0]->mode);
        self::assertSame(str_repeat('ab', 32), $factory->tls[0]->certificateFingerprint?->sha256);
        self::assertSame([
            ['GET', 'https://proxmox.example.test:8006/api2/json/version'],
            ['GET', 'https://proxmox.example.test:8006/api2/json/version'],
            ['GET', 'https://proxmox.example.test:8006/api2/json/access/roles'],
            ['GET', 'https://proxmox.example.test:8006/api2/json/access/permissions'],
            ['GET', 'https://proxmox.example.test:8006/api2/json/access/permissions'],
            ['GET', 'https://proxmox.example.test:8006/api2/json/access/acl'],
            ['GET', 'https://proxmox.example.test:8006/api2/json/access/permissions?path=%2Faccess'],
            ['GET', 'https://proxmox.example.test:8006/api2/json/access/permissions?path=%2Fnodes%2Fhoddmimir-propagation-probe'],
            ['GET', 'https://proxmox.example.test:8006/api2/json/access/permissions?path=%2Fvms%2F999999999'],
            ['GET', 'https://proxmox.example.test:8006/api2/json/access/permissions?path=%2Fpool%2Fhoddmimir-propagation-probe'],
            ['GET', 'https://proxmox.example.test:8006/api2/json/access/permissions?path=%2Fstorage%2Fhoddmimir-propagation-probe'],
            ['GET', 'https://proxmox.example.test:8006/api2/json/access/permissions?path=%2Fvms%2F999999999'],
            ['GET', 'https://proxmox.example.test:8006/api2/json/access/permissions?path=%2Fstorage%2Fhoddmimir-propagation-probe'],
        ], array_map(static fn (array $request): array => [$request[0], $request[1]], $requests));
        self::assertStringEndsWith('=SCAN-TOKEN-SENTINEL', $requests[0][2] ?? '');
        self::assertStringEndsWith('=BACKUP-TOKEN-SENTINEL', $requests[1][2] ?? '');
        self::assertStringEndsWith('=SCAN-TOKEN-SENTINEL', $requests[2][2] ?? '');
        self::assertStringEndsWith('=BACKUP-TOKEN-SENTINEL', $requests[4][2] ?? '');
        self::assertStringEndsWith('=SCAN-TOKEN-SENTINEL', $requests[10][2] ?? '');
        self::assertStringEndsWith('=BACKUP-TOKEN-SENTINEL', $requests[12][2] ?? '');
    }

    public function testPbsUsesAFullMatrixAndFourPathScopedPermissionGetsWithSystemTrust(): void
    {
        /** @var list<array{string, string, ?string}> $requests */
        $requests = [];
        $factory = new RecordingPbsOnboardingClientFactory(function (string $method, string $url, array $options) use (&$requests): MockResponse {
            $requests[] = [$method, $url, self::authorization($options)];
            if (str_ends_with($url, '/version')) {
                return new MockResponse('{"data":{"version":"4.0.2","release":"4.0","repoid":"abcdef12"}}');
            }
            $queryString = parse_url($url, PHP_URL_QUERY);
            if (!is_string($queryString)) {
                return new MockResponse('{"data":{"/system/status":{"Sys.Audit":true},"/system/tasks":{"Sys.Audit":true},"/datastore":{"Datastore.Audit":true},"/remote":{"Remote.Audit":true}}}');
            }
            parse_str($queryString, $query);
            $path = $query['path'] ?? null;
            self::assertIsString($path);
            $privilege = str_starts_with($path, '/system/') ? 'Sys.Audit' : ('/datastore' === $path ? 'Datastore.Audit' : 'Remote.Audit');
            return new MockResponse(json_encode(['data' => [$path => [$privilege => true]]], JSON_THROW_ON_ERROR));
        });
        $gateway = new NativeOnboardingRemoteGateway(new UnusedPveOnboardingClientFactory(), new NoopPveOnboardingDelay(), $factory, new NoopPbsOnboardingDelay());
        $command = $this->command(OnboardingProduct::Pbs, OnboardingTlsMode::SystemCa, [
            new OnboardingCredential(OnboardingCredentialKind::Scan, 'hoddmimir@pbs!scan', '01234567-89ab-cdef-0123-456789abcdef'),
        ]);

        $evidence = $gateway->verify($command);

        self::assertCount(1, $evidence->identities);
        self::assertCount(8, $evidence->identities[0]->permissions);
        self::assertSame(PbsTlsMode::SystemCa, $factory->tls[0]->mode);
        self::assertSame([
            'https://proxmox.example.test:8007/api2/json/version',
            'https://proxmox.example.test:8007/api2/json/access/permissions',
            'https://proxmox.example.test:8007/api2/json/access/permissions?path=%2Fsystem%2Fstatus',
            'https://proxmox.example.test:8007/api2/json/access/permissions?path=%2Fsystem%2Ftasks',
            'https://proxmox.example.test:8007/api2/json/access/permissions?path=%2Fdatastore',
            'https://proxmox.example.test:8007/api2/json/access/permissions?path=%2Fremote',
        ], array_column($requests, 1));
        self::assertSame(['GET'], array_values(array_unique(array_column($requests, 0))));
        foreach ($requests as $request) {
            self::assertStringEndsWith(':01234567-89ab-cdef-0123-456789abcdef', $request[2] ?? '');
        }
    }

    public function testPbsFullMatrixExposesForbiddenPrivilegesOutsideRequiredPaths(): void
    {
        $factory = new RecordingPbsOnboardingClientFactory(static function (string $method, string $url): MockResponse {
            if (str_ends_with($url, '/version')) {
                return new MockResponse('{"data":{"version":"4.0.2","release":"4.0","repoid":"abcdef12"}}');
            }
            $queryString = parse_url($url, PHP_URL_QUERY);
            if (!is_string($queryString)) {
                return new MockResponse('{"data":{"/system/status":{"Sys.Audit":true},"/system/tasks":{"Sys.Audit":true},"/datastore":{"Datastore.Audit":true},"/remote":{"Remote.Audit":true},"/tape":{"Tape.Modify":true}}}');
            }
            parse_str($queryString, $query);
            $path = $query['path'] ?? null;
            self::assertIsString($path);
            $privilege = str_starts_with($path, '/system/') ? 'Sys.Audit' : ('/datastore' === $path ? 'Datastore.Audit' : 'Remote.Audit');
            return new MockResponse(json_encode(['data' => [$path => [$privilege => true]]], JSON_THROW_ON_ERROR));
        });
        $gateway = new NativeOnboardingRemoteGateway(
            new UnusedPveOnboardingClientFactory(),
            new NoopPveOnboardingDelay(),
            $factory,
            new NoopPbsOnboardingDelay(),
        );
        $command = $this->command(OnboardingProduct::Pbs, OnboardingTlsMode::SystemCa, [
            new OnboardingCredential(OnboardingCredentialKind::Scan, 'hoddmimir@pbs!scan', '01234567-89ab-cdef-0123-456789abcdef'),
        ]);

        $verification = (new OnboardingEvidenceVerifier())->verify($command, $gateway->verify($command));

        self::assertFalse($verification->passed());
        self::assertContains(
            OnboardingIssueCode::ForbiddenPermissionPresent,
            array_map(static fn ($issue): OnboardingIssueCode => $issue->code, $verification->issues),
        );
    }

    public function testInvalidPbsSecretFailsClosedWithoutLeakingItOrSendingARequest(): void
    {
        $calls = 0;
        $factory = new RecordingPbsOnboardingClientFactory(function () use (&$calls): MockResponse {
            ++$calls;
            return new MockResponse('{}');
        });
        $gateway = new NativeOnboardingRemoteGateway(new UnusedPveOnboardingClientFactory(), new NoopPveOnboardingDelay(), $factory, new NoopPbsOnboardingDelay());
        $command = $this->command(OnboardingProduct::Pbs, OnboardingTlsMode::SystemCa, [
            new OnboardingCredential(OnboardingCredentialKind::Scan, 'hoddmimir@pbs!scan', 'INVALID-TOKEN-SENTINEL'),
        ]);

        try {
            $gateway->verify($command);
            self::fail('An invalid PBS token secret was accepted.');
        } catch (OnboardingRemoteFailure $failure) {
            self::assertStringNotContainsString('INVALID-TOKEN-SENTINEL', $failure->getMessage());
            self::assertSame(OnboardingIssueCode::AuthenticationFailed, $failure->failureCode);
            self::assertSame(OnboardingCredentialKind::Scan, $failure->credential);
            self::assertSame(0, $calls);
        }
    }

    public function testPveSystemCaAndCustomCaArePassedToTheStrictClientFactory(): void
    {
        foreach ([OnboardingTlsMode::SystemCa, OnboardingTlsMode::CustomCa] as $mode) {
            $factory = new RecordingPveOnboardingClientFactory(static function (string $method, string $url): MockResponse {
                $queryString = parse_url($url, PHP_URL_QUERY);
                if (is_string($queryString)) {
                    parse_str($queryString, $query);
                    $path = $query['path'] ?? null;
                    if (is_string($path)) {
                        return new MockResponse(json_encode(['data' => [$path => ['Sys.Audit' => 0]]], JSON_THROW_ON_ERROR));
                    }
                }
                $body = match (true) {
                    str_ends_with($url, '/version') => '{"data":{"release":"7.4","version":"7.4.20","repoid":"pve-manager"}}',
                    str_ends_with($url, '/access/roles') => '{"data":[{"roleid":"EmptyRole","privs":""}]}',
                    str_ends_with($url, '/access/acl') => '{"data":[]}',
                    default => '{"data":{"/":{"Sys.Audit":0}}}',
                };
                return new MockResponse($body);
            });
            $gateway = new NativeOnboardingRemoteGateway($factory, new NoopPveOnboardingDelay(), new UnusedPbsOnboardingClientFactory(), new NoopPbsOnboardingDelay());
            $evidence = $gateway->verify($this->command(OnboardingProduct::Pve, $mode, [
                new OnboardingCredential(OnboardingCredentialKind::Scan, 'hoddmimir@pve!scan', 'scan-secret'),
                new OnboardingCredential(OnboardingCredentialKind::Backup, 'hoddmimir@pve!backup', 'backup-secret'),
            ]));
            self::assertTrue($evidence->identities[0]->permissions[0]->granted);
            self::assertFalse($evidence->identities[0]->permissions[0]->propagated);
            self::assertSame([], $evidence->roles[0]->privileges);
            self::assertSame(
                OnboardingTlsMode::SystemCa === $mode ? PveTlsMode::SystemCa : PveTlsMode::CustomCa,
                $factory->tls[0]->mode,
            );
            if (OnboardingTlsMode::CustomCa === $mode) {
                self::assertNotNull($factory->tls[0]->customCa);
            }
        }
    }

    public function testPvePropagationRequiresTheChildProbeAndNoAccessIsCredentialScoped(): void
    {
        $factory = new RecordingPveOnboardingClientFactory(static function (string $method, string $url, array $options): MockResponse {
            $queryString = parse_url($url, PHP_URL_QUERY);
            $query = [];
            if (is_string($queryString)) {
                parse_str($queryString, $query);
            }
            $path = $query['path'] ?? null;
            if (is_string($path)) {
                $backup = str_contains(self::authorization($options) ?? '', '!backup=');
                $privileges = self::pvePrivileges($path, $backup);
                if (!$backup && '/vms/999999999' === $path) {
                    $privileges = ['VM.Audit' => 0];
                }
                return new MockResponse(json_encode(['data' => [$path => $privileges]], JSON_THROW_ON_ERROR));
            }
            return new MockResponse(match (true) {
                str_ends_with($url, '/version') => '{"data":{"release":"8.4","version":"8.4.1","repoid":"abcdef12"}}',
                str_ends_with($url, '/access/roles') => '{"data":[]}',
                str_ends_with($url, '/access/acl') => '{"data":[{"path":"/vms/123","type":"token","ugid":"hoddmimir@pve!scan","roleid":"NoAccess","propagate":0}]}',
                str_contains(self::authorization($options) ?? '', '!backup=') => '{"data":{"/vms":{"VM.Backup":1},"/storage":{"Datastore.AllocateSpace":1}}}',
                default => '{"data":{"/":{"Sys.Audit":1},"/nodes":{"Sys.Audit":1},"/vms":{"VM.Audit":1},"/pool":{"Pool.Audit":1},"/storage":{"Datastore.Audit":1}}}',
            });
        });
        $evidence = (new NativeOnboardingRemoteGateway(
            $factory,
            new NoopPveOnboardingDelay(),
            new UnusedPbsOnboardingClientFactory(),
            new NoopPbsOnboardingDelay(),
        ))->verify($this->command(OnboardingProduct::Pve, OnboardingTlsMode::SystemCa, [
            new OnboardingCredential(OnboardingCredentialKind::Scan, 'hoddmimir@pve!scan', 'scan-secret'),
            new OnboardingCredential(OnboardingCredentialKind::Backup, 'hoddmimir@pve!backup', 'backup-secret'),
        ]));

        $scan = $evidence->identity(OnboardingCredentialKind::Scan);
        $backup = $evidence->identity(OnboardingCredentialKind::Backup);
        self::assertNotNull($scan);
        self::assertNotNull($backup);
        self::assertFalse(self::permission($scan->permissions, '/vms', 'VM.Audit')->propagated);
        self::assertFalse(self::permission($scan->permissions, '/vms/123', 'VM.Audit')->granted);
        self::assertTrue(self::permission($backup->permissions, '/vms', 'VM.Backup')->granted);
        self::assertSame([], array_values(array_filter(
            $backup->permissions,
            static fn (OnboardingPermission $permission): bool => '/vms/123' === $permission->path
                && 'VM.Backup' === $permission->privilege,
        )));
    }

    #[DataProvider('ambiguousPveAclAndScopedResponses')]
    public function testAmbiguousPveAclAndScopedResponsesFailClosed(string $acl, ?string $scoped): void
    {
        $factory = new RecordingPveOnboardingClientFactory(static function (string $method, string $url) use ($acl, $scoped): MockResponse {
            if (str_ends_with($url, '/version')) {
                return new MockResponse('{"data":{"release":"8.4","version":"8.4.1","repoid":"abcdef12"}}');
            }
            if (str_ends_with($url, '/access/roles')) {
                return new MockResponse('{"data":[]}');
            }
            if (str_ends_with($url, '/access/acl')) {
                return new MockResponse($acl);
            }
            if (str_contains($url, '?path=')) {
                return new MockResponse($scoped ?? '{"data":{}}');
            }
            return new MockResponse('{"data":{"/":{"Sys.Audit":1},"/nodes":{"Sys.Audit":1},"/vms":{"VM.Audit":1,"VM.Backup":1},"/pool":{"Pool.Audit":1},"/storage":{"Datastore.Audit":1,"Datastore.AllocateSpace":1}}}');
        });

        try {
            (new NativeOnboardingRemoteGateway(
                $factory,
                new NoopPveOnboardingDelay(),
                new UnusedPbsOnboardingClientFactory(),
                new NoopPbsOnboardingDelay(),
            ))->verify($this->command(OnboardingProduct::Pve, OnboardingTlsMode::SystemCa, [
                new OnboardingCredential(OnboardingCredentialKind::Scan, 'hoddmimir@pve!scan', 'scan-secret'),
                new OnboardingCredential(OnboardingCredentialKind::Backup, 'hoddmimir@pve!backup', 'backup-secret'),
            ]));
            self::fail('Ambiguous PVE authorization evidence was accepted.');
        } catch (OnboardingRemoteFailure $failure) {
            self::assertSame(OnboardingIssueCode::InvalidRemoteResponse, $failure->failureCode);
        }
    }

    /** @return iterable<string, array{string, ?string}> */
    public static function ambiguousPveAclAndScopedResponses(): iterable
    {
        $row = '{"path":"/vms","type":"token","ugid":"hoddmimir@pve!scan","roleid":"HoddmimirScan","propagate":1}';
        $rowValues = ['path' => '/vms', 'type' => 'token', 'ugid' => 'hoddmimir@pve!scan', 'roleid' => 'HoddmimirScan', 'propagate' => 1];
        yield 'ACL object instead of list' => ['{"data":{}}', null];
        yield 'oversized ACL list' => [json_encode(['data' => array_fill(0, 4097, $rowValues)], JSON_THROW_ON_ERROR), null];
        yield 'scalar ACL row' => ['{"data":[false]}', null];
        yield 'duplicate ACL row' => ['{"data":['.$row.','.$row.']}', null];
        yield 'unknown ACL field' => ['{"data":[{"path":"/vms","type":"token","ugid":"hoddmimir@pve!scan","roleid":"HoddmimirScan","propagate":1,"extra":true}]}', null];
        yield 'ACL path wrong type' => ['{"data":[{"path":1,"type":"token","ugid":"hoddmimir@pve!scan","roleid":"HoddmimirScan","propagate":1}]}', null];
        yield 'ACL identity type wrong type' => ['{"data":[{"path":"/vms","type":1,"ugid":"hoddmimir@pve!scan","roleid":"HoddmimirScan","propagate":1}]}', null];
        yield 'ACL identity wrong type' => ['{"data":[{"path":"/vms","type":"token","ugid":1,"roleid":"HoddmimirScan","propagate":1}]}', null];
        yield 'ACL role wrong type' => ['{"data":[{"path":"/vms","type":"token","ugid":"hoddmimir@pve!scan","roleid":1,"propagate":1}]}', null];
        yield 'unknown ACL identity type' => ['{"data":[{"path":"/vms","type":"service","ugid":"hoddmimir@pve!scan","roleid":"HoddmimirScan","propagate":1}]}', null];
        yield 'invalid ACL propagation flag' => ['{"data":[{"path":"/vms","type":"token","ugid":"hoddmimir@pve!scan","roleid":"HoddmimirScan","propagate":true}]}', null];
        yield 'invalid ACL path' => ['{"data":[{"path":"relative","type":"user","ugid":"hoddmimir@pve!scan","roleid":"HoddmimirScan","propagate":1}]}', null];
        yield 'invalid ACL identity' => ['{"data":[{"path":"/vms","type":"group","ugid":"bad/identity","roleid":"HoddmimirScan","propagate":1}]}', null];
        yield 'invalid ACL role' => ['{"data":[{"path":"/vms","type":"token","ugid":"hoddmimir@pve!scan","roleid":"bad/role","propagate":1}]}', null];
        yield 'unscoped answer to scoped probe' => ['{"data":[]}', '{"data":{"/access":{"Sys.Audit":1},"/vms":{"VM.Audit":1}}}'];
    }

    public function testPbsCustomCaAndFingerprintArePassedToTheStrictClientFactory(): void
    {
        foreach ([OnboardingTlsMode::CustomCa, OnboardingTlsMode::Sha256Fingerprint] as $mode) {
            $factory = new RecordingPbsOnboardingClientFactory(static function (string $method, string $url): MockResponse {
                if (str_ends_with($url, '/version')) {
                    return new MockResponse('{"data":{"version":"3.4.2","release":"3.4","repoid":"abcdef12"}}');
                }
                $query = parse_url($url, PHP_URL_QUERY);
                if (!is_string($query)) {
                    return new MockResponse('{"data":{"/system/status":{"Sys.Audit":true},"/system/tasks":{"Sys.Audit":true},"/datastore":{"Datastore.Audit":true},"/remote":{"Remote.Audit":true}}}');
                }
                parse_str($query, $values);
                $path = $values['path'] ?? null;
                if (!is_string($path)) {
                    throw new \LogicException('missing path');
                }
                $privilege = str_starts_with($path, '/system/') ? 'Sys.Audit' : ('/datastore' === $path ? 'Datastore.Audit' : 'Remote.Audit');
                return new MockResponse(json_encode(['data' => [$path => [$privilege => true]]], JSON_THROW_ON_ERROR));
            });
            $gateway = new NativeOnboardingRemoteGateway(new UnusedPveOnboardingClientFactory(), new NoopPveOnboardingDelay(), $factory, new NoopPbsOnboardingDelay());
            $gateway->verify($this->command(OnboardingProduct::Pbs, $mode, [
                new OnboardingCredential(OnboardingCredentialKind::Scan, 'hoddmimir@pbs!scan', '01234567-89ab-cdef-0123-456789abcdef'),
            ]));
            self::assertSame(
                OnboardingTlsMode::CustomCa === $mode ? PbsTlsMode::CustomCa : PbsTlsMode::CertificateFingerprint,
                $factory->tls[0]->mode,
            );
        }
    }

    #[DataProvider('pveRemoteFailures')]
    public function testPveRemoteFailuresAreMappedToClosedSecretFreeOnboardingIssues(
        OnboardingTlsMode $tlsMode,
        MockResponse $response,
        OnboardingIssueCode $expected,
    ): void {
        $factory = new RecordingPveOnboardingClientFactory(
            static fn (): MockResponse => clone $response,
        );
        $gateway = new NativeOnboardingRemoteGateway(
            $factory,
            new NoopPveOnboardingDelay(),
            new UnusedPbsOnboardingClientFactory(),
            new NoopPbsOnboardingDelay(),
        );

        try {
            $gateway->verify($this->command(OnboardingProduct::Pve, $tlsMode, [
                new OnboardingCredential(OnboardingCredentialKind::Scan, 'hoddmimir@pve!scan', 'SCAN-FAILURE-SENTINEL'),
                new OnboardingCredential(OnboardingCredentialKind::Backup, 'hoddmimir@pve!backup', 'BACKUP-FAILURE-SENTINEL'),
            ]));
            self::fail('A failed PVE onboarding probe was accepted.');
        } catch (OnboardingRemoteFailure $failure) {
            self::assertSame($expected, $failure->failureCode);
            self::assertSame(OnboardingCredentialKind::Scan, $failure->credential);
            self::assertStringNotContainsString('SCAN-FAILURE-SENTINEL', $failure->getMessage());
            self::assertStringNotContainsString('BACKUP-FAILURE-SENTINEL', $failure->getMessage());
        }
    }

    /** @return iterable<string, array{OnboardingTlsMode, MockResponse, OnboardingIssueCode}> */
    public static function pveRemoteFailures(): iterable
    {
        yield 'system trust transport' => [
            OnboardingTlsMode::SystemCa,
            new MockResponse('', ['error' => 'certificate handshake failed']),
            OnboardingIssueCode::TlsVerificationFailed,
        ];
        yield 'fingerprint transport' => [
            OnboardingTlsMode::Sha256Fingerprint,
            new MockResponse('', ['error' => 'peer fingerprint mismatch']),
            OnboardingIssueCode::TlsFingerprintMismatch,
        ];
        yield 'authentication' => [
            OnboardingTlsMode::SystemCa,
            new MockResponse('', ['http_code' => 401]),
            OnboardingIssueCode::AuthenticationFailed,
        ];
        yield 'remote unavailable' => [
            OnboardingTlsMode::SystemCa,
            new MockResponse('', ['http_code' => 503]),
            OnboardingIssueCode::RemoteUnavailable,
        ];
        yield 'unsupported version' => [
            OnboardingTlsMode::SystemCa,
            new MockResponse('{"data":{"release":"10.0","version":"10.0.1","repoid":"abcdef12"}}'),
            OnboardingIssueCode::UnsupportedVersion,
        ];
    }

    #[DataProvider('invalidRemoteResponseFailures')]
    public function testNotFoundInvalidEnvelopeAndInvalidResponseFailuresAreClosedForBothProducts(
        OnboardingProduct $product,
        MockResponse $response,
    ): void {
        if (OnboardingProduct::Pve === $product) {
            $pveFactory = new RecordingPveOnboardingClientFactory(static fn (): MockResponse => clone $response);
            $pbsFactory = new UnusedPbsOnboardingClientFactory();
            $credentials = [
                new OnboardingCredential(OnboardingCredentialKind::Scan, 'hoddmimir@pve!scan', 'scan-secret'),
                new OnboardingCredential(OnboardingCredentialKind::Backup, 'hoddmimir@pve!backup', 'backup-secret'),
            ];
        } else {
            $pveFactory = new UnusedPveOnboardingClientFactory();
            $pbsFactory = new RecordingPbsOnboardingClientFactory(static fn (): MockResponse => clone $response);
            $credentials = [
                new OnboardingCredential(OnboardingCredentialKind::Scan, 'hoddmimir@pbs!scan', '01234567-89ab-cdef-0123-456789abcdef'),
            ];
        }
        $gateway = new NativeOnboardingRemoteGateway(
            $pveFactory,
            new NoopPveOnboardingDelay(),
            $pbsFactory,
            new NoopPbsOnboardingDelay(),
        );

        try {
            $gateway->verify($this->command($product, OnboardingTlsMode::SystemCa, $credentials));
            self::fail('Invalid remote onboarding evidence was accepted.');
        } catch (OnboardingRemoteFailure $failure) {
            self::assertSame(OnboardingIssueCode::InvalidRemoteResponse, $failure->failureCode);
            self::assertSame(OnboardingCredentialKind::Scan, $failure->credential);
        }
    }

    /** @return iterable<string, array{OnboardingProduct, MockResponse}> */
    public static function invalidRemoteResponseFailures(): iterable
    {
        foreach ([OnboardingProduct::Pve, OnboardingProduct::Pbs] as $product) {
            yield $product->value.' not found' => [$product, new MockResponse('', ['http_code' => 404])];
            yield $product->value.' invalid envelope' => [$product, new MockResponse('{')];
            yield $product->value.' invalid response' => [$product, new MockResponse('{"data":[]}')];
        }
    }

    public function testBackupCredentialFailureIsAttributedToTheBackupIdentity(): void
    {
        $factory = new RecordingPveOnboardingClientFactory(static function (string $method, string $url, array $options): MockResponse {
            $authorization = self::authorization($options) ?? '';
            if (str_ends_with($url, '/version') && str_contains($authorization, '!backup=')) {
                return new MockResponse('', ['http_code' => 401]);
            }

            return new MockResponse('{"data":{"release":"8.4","version":"8.4.1","repoid":"abcdef12"}}');
        });
        $gateway = new NativeOnboardingRemoteGateway(
            $factory,
            new NoopPveOnboardingDelay(),
            new UnusedPbsOnboardingClientFactory(),
            new NoopPbsOnboardingDelay(),
        );

        try {
            $gateway->verify($this->command(OnboardingProduct::Pve, OnboardingTlsMode::SystemCa, [
                new OnboardingCredential(OnboardingCredentialKind::Scan, 'hoddmimir@pve!scan', 'scan-secret'),
                new OnboardingCredential(OnboardingCredentialKind::Backup, 'hoddmimir@pve!backup', 'backup-secret'),
            ]));
            self::fail('A rejected backup credential was accepted.');
        } catch (OnboardingRemoteFailure $failure) {
            self::assertSame(OnboardingIssueCode::AuthenticationFailed, $failure->failureCode);
            self::assertSame(OnboardingCredentialKind::Backup, $failure->credential);
        }
    }

    #[DataProvider('invalidPvePayloads')]
    public function testMalformedPveRolesAndPermissionMatricesFailClosed(string $roles, string $permissions): void
    {
        $factory = new RecordingPveOnboardingClientFactory(static function (string $method, string $url) use ($roles, $permissions): MockResponse {
            return new MockResponse(match (true) {
                str_ends_with($url, '/version') => '{"data":{"release":"8.4","version":"8.4.1","repoid":"abcdef12"}}',
                str_ends_with($url, '/access/roles') => $roles,
                default => $permissions,
            });
        });
        $gateway = new NativeOnboardingRemoteGateway($factory, new NoopPveOnboardingDelay(), new UnusedPbsOnboardingClientFactory(), new NoopPbsOnboardingDelay());

        try {
            $gateway->verify($this->command(OnboardingProduct::Pve, OnboardingTlsMode::SystemCa, [
                new OnboardingCredential(OnboardingCredentialKind::Scan, 'hoddmimir@pve!scan', 'scan-secret'),
                new OnboardingCredential(OnboardingCredentialKind::Backup, 'hoddmimir@pve!backup', 'backup-secret'),
            ]));
            self::fail('Malformed PVE onboarding evidence was accepted.');
        } catch (OnboardingRemoteFailure $failure) {
            self::assertSame(OnboardingIssueCode::InvalidRemoteResponse, $failure->failureCode);
        }
    }

    /** @return iterable<string, array{string, string}> */
    public static function invalidPvePayloads(): iterable
    {
        $validRoles = '{"data":[]}';
        $validPermissions = '{"data":{"/":{"Sys.Audit":1}}}';
        yield 'roles object' => ['{"data":{}}', $validPermissions];
        yield 'role scalar' => ['{"data":[false]}', $validPermissions];
        yield 'role missing fields' => ['{"data":[{"roleid":"Role"}]}', $validPermissions];
        yield 'role id wrong type' => ['{"data":[{"roleid":1,"privs":"Sys.Audit"}]}', $validPermissions];
        yield 'role privileges wrong type' => ['{"data":[{"roleid":"Role","privs":1}]}', $validPermissions];
        yield 'invalid role privilege' => ['{"data":[{"roleid":"Role","privs":"invalid"}]}', $validPermissions];
        yield 'permission list' => [$validRoles, '{"data":[]}'];
        yield 'invalid permission path' => [$validRoles, '{"data":{"relative":{"Sys.Audit":1}}}'];
        yield 'permission scalar' => [$validRoles, '{"data":{"/":false}}'];
        yield 'invalid grant' => [$validRoles, '{"data":{"/":{"Sys.Audit":true}}}'];
        yield 'invalid privilege' => [$validRoles, '{"data":{"/":{"invalid":1}}}'];
    }

    #[DataProvider('oversizedPveAuthorizationEvidence')]
    public function testPveAuthorizationEvidenceIsBounded(string $roles, string $permissions): void
    {
        $factory = new RecordingPveOnboardingClientFactory(static fn (string $method, string $url): MockResponse => new MockResponse(match (true) {
            str_ends_with($url, '/version') => '{"data":{"release":"8.4","version":"8.4.1","repoid":"abcdef12"}}',
            str_ends_with($url, '/access/roles') => $roles,
            default => $permissions,
        }));
        try {
            (new NativeOnboardingRemoteGateway(
                $factory,
                new NoopPveOnboardingDelay(),
                new UnusedPbsOnboardingClientFactory(),
                new NoopPbsOnboardingDelay(),
            ))->verify($this->command(OnboardingProduct::Pve, OnboardingTlsMode::SystemCa, [
                new OnboardingCredential(OnboardingCredentialKind::Scan, 'hoddmimir@pve!scan', 'scan-secret'),
                new OnboardingCredential(OnboardingCredentialKind::Backup, 'hoddmimir@pve!backup', 'backup-secret'),
            ]));
            self::fail('Oversized PVE authorization evidence was accepted.');
        } catch (OnboardingRemoteFailure $failure) {
            self::assertSame(OnboardingIssueCode::InvalidRemoteResponse, $failure->failureCode);
        }
    }

    /** @return iterable<string, array{string, string}> */
    public static function oversizedPveAuthorizationEvidence(): iterable
    {
        $roles = [];
        for ($index = 0; $index < 4097; ++$index) {
            $roles[] = ['roleid' => 'Role'.$index, 'privs' => 'Sys.Audit'];
        }
        $validRoles = '{"data":[]}';
        $validPermissions = '{"data":{"/":{"Sys.Audit":1}}}';
        yield 'roles' => [json_encode(['data' => $roles], JSON_THROW_ON_ERROR), $validPermissions];

        $paths = [];
        for ($index = 0; $index < 4097; ++$index) {
            $paths['/p'.$index] = [];
        }
        yield 'paths' => [$validRoles, json_encode(['data' => $paths], JSON_THROW_ON_ERROR)];

        $privileges = [];
        for ($index = 0; $index < 257; ++$index) {
            $privileges[self::pvePrivilegeName($index)] = 1;
        }
        yield 'privileges per path' => [$validRoles, json_encode(['data' => ['/p' => $privileges]], JSON_THROW_ON_ERROR)];

        array_pop($privileges);
        $total = [];
        for ($index = 0; $index < 257; ++$index) {
            $total['/p'.$index] = $privileges;
        }
        yield 'total privileges' => [$validRoles, json_encode(['data' => $total], JSON_THROW_ON_ERROR)];
    }

    private static function pvePrivilegeName(int $value): string
    {
        $letters = '';
        for ($position = 0; $position < 4; ++$position) {
            $letters = chr(65 + ($value % 26)).$letters;
            $value = intdiv($value, 26);
        }
        return 'P.'.$letters;
    }

    /** @return array<string, int> */
    private static function pvePrivileges(string $path, bool $backup): array
    {
        if ($backup) {
            return str_starts_with($path, '/vms/')
                ? ['VM.Backup' => 1]
                : ['Datastore.AllocateSpace' => 1];
        }

        return match (true) {
            '/access' === $path, str_starts_with($path, '/nodes/') => ['Sys.Audit' => 1],
            str_starts_with($path, '/vms/') => ['VM.Audit' => 1],
            str_starts_with($path, '/pool/') => ['Pool.Audit' => 1],
            default => ['Datastore.Audit' => 1],
        };
    }

    /** @param list<OnboardingPermission> $permissions */
    private static function permission(array $permissions, string $path, string $privilege): OnboardingPermission
    {
        foreach ($permissions as $permission) {
            if ($permission->path === $path && $permission->privilege === $privilege) {
                return $permission;
            }
        }
        self::fail(sprintf('Permission %s at %s is missing.', $privilege, $path));
    }

    /** @param list<OnboardingCredential> $credentials */
    private function command(OnboardingProduct $product, OnboardingTlsMode $tls, array $credentials): OnboardingActivationCommand
    {
        return new OnboardingActivationCommand(
            OnboardingMode::Activate,
            'connection-id-01',
            0,
            'onboarding-test',
            'correlation-id01',
            $product,
            'Proxmox Test',
            new OnboardingEndpoint(
                'proxmox.example.test',
                $product->defaultPort(),
                $tls,
                OnboardingTlsMode::CustomCa === $tls ? $this->certificatePem() : null,
                OnboardingTlsMode::Sha256Fingerprint === $tls ? str_repeat('ab', 32) : null,
            ),
            $credentials,
        );
    }

    private function certificatePem(): string
    {
        $key = openssl_pkey_new(['private_key_bits' => 1024]);
        if (!$key instanceof \OpenSSLAsymmetricKey) {
            throw new \RuntimeException('Could not create onboarding test key.');
        }
        $csr = openssl_csr_new(['commonName' => 'onboarding.test'], $key, ['digest_alg' => 'sha256']);
        if (!$csr instanceof \OpenSSLCertificateSigningRequest) {
            throw new \RuntimeException('Could not create onboarding test CSR.');
        }
        if (!$key instanceof \OpenSSLAsymmetricKey) {
            throw new \RuntimeException('Onboarding test key was unexpectedly replaced.');
        }
        $certificate = openssl_csr_sign($csr, null, $key, 1, ['digest_alg' => 'sha256']);
        if (!$certificate instanceof \OpenSSLCertificate) {
            throw new \RuntimeException('Could not create onboarding test certificate.');
        }
        $pem = '';
        if (!openssl_x509_export($certificate, $pem)) {
            throw new \RuntimeException('Could not export onboarding test certificate.');
        }
        if (!is_string($pem)) {
            throw new \RuntimeException('Onboarding test certificate export is invalid.');
        }
        return $pem;
    }

    /** @param array<array-key, mixed> $options */
    private static function authorization(array $options): ?string
    {
        $headers = $options['normalized_headers'] ?? null;
        if (!is_array($headers)) {
            return null;
        }
        $authorization = $headers['authorization'] ?? null;
        if (!is_array($authorization)) {
            return null;
        }
        $value = $authorization[0] ?? null;
        return is_string($value) ? $value : null;
    }
}

final class RecordingPveOnboardingClientFactory implements PveHttpClientFactory
{
    /** @var list<PveTlsConfiguration> */ public array $tls = [];
    public function __construct(private readonly \Closure $callback) {}
    public function create(PveTlsConfiguration $tls): HttpClientInterface
    {
        $this->tls[] = $tls;
        return new MockHttpClient($this->callback);
    }
}
final class UnusedPveOnboardingClientFactory implements PveHttpClientFactory
{
    public function create(PveTlsConfiguration $tls): HttpClientInterface { throw new \LogicException('unused'); }
}
final class RecordingPbsOnboardingClientFactory implements PbsHttpClientFactory
{
    /** @var list<PbsTlsConfiguration> */ public array $tls = [];
    public function __construct(private readonly \Closure $callback) {}
    public function create(PbsTlsConfiguration $tls): HttpClientInterface
    {
        $this->tls[] = $tls;
        return new MockHttpClient($this->callback);
    }
}
final class UnusedPbsOnboardingClientFactory implements PbsHttpClientFactory
{
    public function create(PbsTlsConfiguration $tls): HttpClientInterface { throw new \LogicException('unused'); }
}
final readonly class NoopPveOnboardingDelay implements PveRetryDelay { public function pause(int $attempt): void {} }
final readonly class NoopPbsOnboardingDelay implements PbsRetryDelay { public function pause(int $attempt): void {} }
