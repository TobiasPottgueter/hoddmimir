<?php

declare(strict_types=1);

namespace App\Tests\Contract\Proxmox\Onboarding;

use App\Application\Configuration\Connection\Onboarding\OnboardingActivationCommand;
use App\Application\Configuration\Connection\Onboarding\OnboardingCredential;
use App\Application\Configuration\Connection\Onboarding\OnboardingCredentialKind;
use App\Application\Configuration\Connection\Onboarding\OnboardingEndpoint;
use App\Application\Configuration\Connection\Onboarding\OnboardingEvidenceVerifier;
use App\Application\Configuration\Connection\Onboarding\OnboardingIssueCode;
use App\Application\Configuration\Connection\Onboarding\OnboardingMode;
use App\Application\Configuration\Connection\Onboarding\OnboardingPermission;
use App\Application\Configuration\Connection\Onboarding\OnboardingProduct;
use App\Application\Configuration\Connection\Onboarding\OnboardingRemoteEvidence;
use App\Application\Configuration\Connection\Onboarding\OnboardingRemoteFailure;
use App\Application\Configuration\Connection\Onboarding\OnboardingTlsMode;
use App\Application\Configuration\Connection\Onboarding\OnboardingVerificationIssue;
use App\Infrastructure\Proxmox\Onboarding\NativeOnboardingRemoteGateway;
use App\Infrastructure\Proxmox\Pbs\PbsHttpClientFactory;
use App\Infrastructure\Proxmox\Pbs\PbsRetryDelay;
use App\Infrastructure\Proxmox\Pbs\PbsTlsConfiguration;
use App\Infrastructure\Proxmox\PveHttpClientFactory;
use App\Infrastructure\Proxmox\PveRetryDelay;
use App\Infrastructure\Proxmox\PveTlsConfiguration;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final class NativePveOnboardingPermissionSynthesisContractTest extends TestCase
{
    public function testOnlyPveSevenScanPoolRootIsSynthesizedFromTheTargetedChildProbe(): void
    {
        $evidence = $this->verifyFixture($this->fixture(self::missingPveSevenPoolFixture()));
        $verification = (new OnboardingEvidenceVerifier())->verify($this->command(), $evidence);

        self::assertTrue($verification->passed());
        self::assertSame([
            '/:Datastore.Audit',
            '/:Pool.Audit',
            '/:VM.Audit',
            '/nodes:Datastore.Audit',
            '/nodes:Pool.Audit',
            '/nodes:VM.Audit',
            '/pools:Datastore.Audit',
            '/pools:Pool.Audit',
            '/pools:Sys.Audit',
            '/pools:VM.Audit',
            '/storage:Pool.Audit',
            '/storage:Sys.Audit',
            '/storage:VM.Audit',
            '/vms:Datastore.Audit',
            '/vms:Pool.Audit',
            '/vms:Sys.Audit',
        ], array_map(
            static fn (OnboardingVerificationIssue $issue): string => $issue->path.':'.$issue->privilege,
            $verification->issues,
        ));
        $permission = self::permission($evidence, OnboardingCredentialKind::Scan, '/pool', 'Pool.Audit');
        self::assertNotNull($permission);
        self::assertTrue($permission->granted);
        self::assertTrue($permission->propagated);
    }

    public function testPveSevenPoolFallbackRequiresAValidPositiveTargetedProbe(): void
    {
        $document = $this->withProbe(
            $this->fixture(self::missingPveSevenPoolFixture()),
            OnboardingCredentialKind::Scan,
            '/pool/hoddmimir-propagation-probe',
            'Pool.Audit',
            null,
        );
        try {
            $this->verifyFixture($document);
            self::fail('A missing PVE 7 pool child probe was accepted.');
        } catch (OnboardingRemoteFailure $failure) {
            self::assertSame(OnboardingIssueCode::InvalidRemoteResponse, $failure->failureCode);
            self::assertSame(OnboardingCredentialKind::Scan, $failure->credential);
        }

        $document = $this->withProbe(
            $this->fixture(self::missingPveSevenPoolFixture()),
            OnboardingCredentialKind::Scan,
            '/pool/hoddmimir-propagation-probe',
            'Pool.Audit',
            0,
        );
        $evidence = $this->verifyFixture($document);
        $verification = (new OnboardingEvidenceVerifier())->verify($this->command(), $evidence);

        self::assertNull(self::permission($evidence, OnboardingCredentialKind::Scan, '/pool', 'Pool.Audit'));
        self::assertFalse($verification->passed());
        self::assertContains(OnboardingIssueCode::RequiredPermissionMissing, self::issueCodes($verification->issues));
    }

    public function testPveSevenPoolFallbackRequiresAValidPositiveExactRootProbe(): void
    {
        $document = $this->withPoolRootProbe($this->fixture(self::missingPveSevenPoolFixture()), 0);
        $evidence = $this->verifyFixture($document);
        $verification = (new OnboardingEvidenceVerifier())->verify($this->command(), $evidence);

        self::assertNull(self::permission($evidence, OnboardingCredentialKind::Scan, '/pool', 'Pool.Audit'));
        self::assertFalse($verification->passed());
        self::assertContains(OnboardingIssueCode::RequiredPermissionMissing, self::issueCodes($verification->issues));

        $document = $this->withPoolRootProbe($this->fixture(self::missingPveSevenPoolFixture()), null);
        try {
            $this->verifyFixture($document);
            self::fail('A malformed PVE 7 exact pool-root probe was accepted.');
        } catch (OnboardingRemoteFailure $failure) {
            self::assertSame(OnboardingIssueCode::InvalidRemoteResponse, $failure->failureCode);
            self::assertSame(OnboardingCredentialKind::Scan, $failure->credential);
        }
    }

    public function testPveSevenPoolFallbackRequiresPoolAuditAtTheUnscopedRoot(): void
    {
        $document = $this->withoutMatrixPrivilege(
            $this->fixture(self::missingPveSevenPoolFixture()),
            OnboardingCredentialKind::Scan,
            '/',
            'Pool.Audit',
        );
        $evidence = $this->verifyFixture($document);
        $verification = (new OnboardingEvidenceVerifier())->verify($this->command(), $evidence);

        self::assertNull(self::permission($evidence, OnboardingCredentialKind::Scan, '/pool', 'Pool.Audit'));
        self::assertFalse($verification->passed());
        self::assertContains(OnboardingIssueCode::RequiredPermissionMissing, self::issueCodes($verification->issues));
    }

    public function testPveSevenPoolFallbackRejectsPoolAuditZeroAtTheUnscopedRoot(): void
    {
        $document = $this->withScanMatrixRow(
            $this->fixture(self::missingPveSevenPoolFixture()),
            '/',
            ['Sys.Audit' => 1, 'VM.Audit' => 1, 'Pool.Audit' => 0, 'Datastore.Audit' => 1],
        );
        $evidence = $this->verifyFixture($document);
        $verification = (new OnboardingEvidenceVerifier())->verify($this->command(), $evidence);

        self::assertNull(self::permission($evidence, OnboardingCredentialKind::Scan, '/pool', 'Pool.Audit'));
        self::assertFalse($verification->passed());
        self::assertContains(OnboardingIssueCode::RequiredPermissionMissing, self::issueCodes($verification->issues));
    }

    #[DataProvider('unsupportedMissingRootCases')]
    public function testEveryMissingRootOutsideThePveSevenScanPoolExceptionFailsClosed(
        string $fixture,
        OnboardingCredentialKind $kind,
        string $root,
        string $privilege,
    ): void {
        $document = $this->withoutMatrixRoot($this->fixture($fixture), $kind, $root);
        $evidence = $this->verifyFixture($document);
        $verification = (new OnboardingEvidenceVerifier())->verify($this->command(), $evidence);

        self::assertNull(self::permission($evidence, $kind, $root, $privilege));
        self::assertFalse($verification->passed());
        self::assertContains(OnboardingIssueCode::RequiredPermissionMissing, self::issueCodes($verification->issues));
    }

    #[DataProvider('pveSevenPoolNoAccessCases')]
    public function testKnownNoAccessEvidenceIsConservativelyAppliedToThePveSevenPoolFallback(
        string $type,
        string $identity,
        string $path,
        int $propagate,
        bool $blocked,
    ): void {
        $document = $this->withNoAccess(
            $this->fixture(self::missingPveSevenPoolFixture()),
            $type,
            $identity,
            $path,
            $propagate,
        );
        $evidence = $this->verifyFixture($document);
        $verification = (new OnboardingEvidenceVerifier())->verify($this->command(), $evidence);
        $permission = self::permission($evidence, OnboardingCredentialKind::Scan, '/pool', 'Pool.Audit');

        if ($blocked) {
            self::assertFalse(self::hasPropagatedGrant(
                $evidence,
                OnboardingCredentialKind::Scan,
                '/pool',
                'Pool.Audit',
            ));
            self::assertFalse($verification->passed());
            self::assertContains(OnboardingIssueCode::NoAccessOverride, self::issueCodes($verification->issues));
            self::assertContains(OnboardingIssueCode::RequiredPermissionMissing, self::issueCodes($verification->issues));
            return;
        }

        self::assertNotNull($permission);
        self::assertTrue($permission->propagated);
        self::assertTrue($verification->passed());
    }

    public function testRelevantPropagatedNoAccessOutsidePoolDoesNotBlockThePveSevenPoolFallback(): void
    {
        $document = $this->withNoAccess(
            $this->fixture(self::missingPveSevenPoolFixture()),
            'token',
            'hoddmimir@pve!scan',
            '/nodes/blocked',
            1,
        );
        $evidence = $this->verifyFixture($document);
        $verification = (new OnboardingEvidenceVerifier())->verify($this->command(), $evidence);

        self::assertTrue(self::hasPropagatedGrant(
            $evidence,
            OnboardingCredentialKind::Scan,
            '/pool',
            'Pool.Audit',
        ));
        self::assertFalse($verification->passed());
        self::assertContains(OnboardingIssueCode::NoAccessOverride, self::issueCodes($verification->issues));
    }

    public function testAnyGroupNoAccessIsRelevantButANonPropagatedAncestorDoesNotBlockPool(): void
    {
        $document = $this->withNoAccess(
            $this->fixture(self::missingPveSevenPoolFixture()),
            'group',
            'Foreign',
            '/',
            0,
        );
        $evidence = $this->verifyFixture($document);
        $verification = (new OnboardingEvidenceVerifier())->verify($this->command(), $evidence);

        self::assertTrue(self::hasPropagatedGrant(
            $evidence,
            OnboardingCredentialKind::Scan,
            '/pool',
            'Pool.Audit',
        ));
        self::assertTrue(self::hasDeniedPermission(
            $evidence,
            OnboardingCredentialKind::Scan,
            '/',
            'Sys.Audit',
        ));
        self::assertFalse($verification->passed());
        self::assertContains(OnboardingIssueCode::NoAccessOverride, self::issueCodes($verification->issues));
    }

    /** @param array<string, int> $privileges */
    #[DataProvider('contradictoryPoolMatrixRows')]
    public function testContradictoryEffectivePoolChildEvidencePreventsPveSevenFallback(array $privileges): void
    {
        $document = $this->withScanMatrixRow(
            $this->fixture(self::missingPveSevenPoolFixture()),
            '/pool/explicit-gap',
            $privileges,
        );
        $evidence = $this->verifyFixture($document);
        $verification = (new OnboardingEvidenceVerifier())->verify($this->command(), $evidence);

        self::assertNull(self::permission($evidence, OnboardingCredentialKind::Scan, '/pool', 'Pool.Audit'));
        self::assertFalse($verification->passed());
        self::assertContains(OnboardingIssueCode::RequiredPermissionMissing, self::issueCodes($verification->issues));
    }

    /** @param array<string, int> $privileges */
    #[DataProvider('contradictoryRequiredFamilyRows')]
    public function testContradictoryEffectiveChildEvidenceIsMaterializedForEveryCredentialFamily(
        OnboardingCredentialKind $kind,
        string $path,
        array $privileges,
        string $requiredPrivilege,
    ): void {
        $document = OnboardingCredentialKind::Scan === $kind
            ? $this->withScanMatrixRow($this->fixture(self::missingPveSevenPoolFixture()), $path, $privileges)
            : $this->withBackupMatrixRow($this->fixture(self::missingPveSevenPoolFixture()), $path, $privileges);
        $evidence = $this->verifyFixture($document);
        $verification = (new OnboardingEvidenceVerifier())->verify($this->command(), $evidence);
        $permission = self::permission($evidence, $kind, $path, $requiredPrivilege);

        self::assertNotNull($permission);
        self::assertFalse($permission->granted);
        self::assertFalse($verification->passed());
        self::assertContains(OnboardingIssueCode::NoAccessOverride, self::issueCodes($verification->issues));
    }

    /** @return iterable<string, array{string, OnboardingCredentialKind, string, string}> */
    public static function unsupportedMissingRootCases(): iterable
    {
        $pveSeven = self::regularFixture(7);
        yield 'PVE 7 scan root' => [$pveSeven, OnboardingCredentialKind::Scan, '/', 'Sys.Audit'];
        yield 'PVE 7 scan nodes' => [$pveSeven, OnboardingCredentialKind::Scan, '/nodes', 'Sys.Audit'];
        yield 'PVE 7 scan vms' => [$pveSeven, OnboardingCredentialKind::Scan, '/vms', 'VM.Audit'];
        yield 'PVE 7 scan storage' => [$pveSeven, OnboardingCredentialKind::Scan, '/storage', 'Datastore.Audit'];
        yield 'PVE 7 backup vms' => [$pveSeven, OnboardingCredentialKind::Backup, '/vms', 'VM.Backup'];
        yield 'PVE 7 backup storage' => [$pveSeven, OnboardingCredentialKind::Backup, '/storage', 'Datastore.AllocateSpace'];
        yield 'PVE 8 scan pool' => [self::regularFixture(8), OnboardingCredentialKind::Scan, '/pool', 'Pool.Audit'];
        yield 'PVE 9 scan pool' => [self::regularFixture(9), OnboardingCredentialKind::Scan, '/pool', 'Pool.Audit'];
    }

    /** @return iterable<string, array{string, string, string, int, bool}> */
    public static function pveSevenPoolNoAccessCases(): iterable
    {
        yield 'exact scan token at pool root' => ['token', 'hoddmimir@pve!scan', '/pool', 0, true];
        yield 'owning user at pool child' => ['user', 'hoddmimir@pve', '/pool/blocked', 0, true];
        yield 'Bots group at pool child' => ['group', 'Bots', '/pool/blocked', 1, true];
        yield 'propagated Bots group at ancestor' => ['group', 'Bots', '/', 1, true];
        yield 'backup token at pool child' => ['token', 'hoddmimir@pve!backup', '/pool/blocked', 0, false];
        yield 'foreign token at pool child' => ['token', 'foreign@pve!token', '/pool/blocked', 0, false];
        yield 'foreign group at pool child' => ['group', 'Foreign', '/pool/blocked', 0, true];
        yield 'propagated foreign group at ancestor' => ['group', 'Foreign', '/', 1, true];
    }

    /** @return iterable<string, array{array<string, int>}> */
    public static function contradictoryPoolMatrixRows(): iterable
    {
        yield 'required privilege omitted' => [['VM.Audit' => 1]];
        yield 'required privilege not propagated' => [['Pool.Audit' => 0]];
    }

    /** @return iterable<string, array{OnboardingCredentialKind, string, array<string, int>, string}> */
    public static function contradictoryRequiredFamilyRows(): iterable
    {
        yield 'scan VM descendant' => [
            OnboardingCredentialKind::Scan,
            '/vms/123',
            ['Sys.Audit' => 1],
            'VM.Audit',
        ];
        yield 'backup storage descendant' => [
            OnboardingCredentialKind::Backup,
            '/storage/gap',
            ['VM.Backup' => 1],
            'Datastore.AllocateSpace',
        ];
    }

    private static function regularFixture(int $major): string
    {
        return dirname(__DIR__, 3).sprintf('/Fixtures/Proxmox/Pve/%d/onboarding-evidence.json', $major);
    }

    private static function missingPveSevenPoolFixture(): string
    {
        return dirname(__DIR__, 3).'/Fixtures/Proxmox/Pve/7/onboarding-evidence-missing-pool-root.json';
    }

    /** @return array<string, mixed> */
    private function fixture(string $path): array
    {
        $raw = file_get_contents($path);
        self::assertIsString($raw);
        $document = json_decode($raw, true, 64, JSON_THROW_ON_ERROR);
        self::assertIsArray($document);
        /** @var array<string, mixed> $document */
        return $document;
    }

    /** @param array<string, mixed> $document */
    private function verifyFixture(array $document): OnboardingRemoteEvidence
    {
        $factory = new ContractPveOnboardingClientFactory(static function (string $method, string $url, array $options) use ($document): MockResponse {
            $queryString = parse_url($url, PHP_URL_QUERY);
            $query = [];
            if (is_string($queryString)) {
                parse_str($queryString, $query);
            }
            $backup = str_contains(self::authorization($options) ?? '', '!backup=');
            $path = $query['path'] ?? null;
            $response = self::fixtureResponse($document, $backup, is_string($path) ? $path : null, $url);

            return new MockResponse(self::encodeEnvelope($response));
        });

        return (new NativeOnboardingRemoteGateway(
            $factory,
            new ContractPveOnboardingDelay(),
            new UnusedContractPbsOnboardingClientFactory(),
            new UnusedContractPbsOnboardingDelay(),
        ))->verify($this->command());
    }

    private function command(): OnboardingActivationCommand
    {
        return new OnboardingActivationCommand(
            OnboardingMode::Activate,
            'connection-id-01',
            0,
            'pve-contract',
            'correlation-id01',
            OnboardingProduct::Pve,
            'PVE Contract',
            new OnboardingEndpoint('pve-contract.example.test', 8006, OnboardingTlsMode::SystemCa, null, null),
            [
                new OnboardingCredential(OnboardingCredentialKind::Scan, 'hoddmimir@pve!scan', 'sanitized-scan-secret'),
                new OnboardingCredential(OnboardingCredentialKind::Backup, 'hoddmimir@pve!backup', 'sanitized-backup-secret'),
            ],
        );
    }

    private static function permission(
        OnboardingRemoteEvidence $evidence,
        OnboardingCredentialKind $kind,
        string $path,
        string $privilege,
    ): ?OnboardingPermission {
        $identity = $evidence->identity($kind);
        self::assertNotNull($identity);
        foreach ($identity->permissions as $permission) {
            if ($permission->path === $path && $permission->privilege === $privilege) {
                return $permission;
            }
        }

        return null;
    }

    private static function hasPropagatedGrant(
        OnboardingRemoteEvidence $evidence,
        OnboardingCredentialKind $kind,
        string $path,
        string $privilege,
    ): bool {
        $identity = $evidence->identity($kind);
        self::assertNotNull($identity);
        foreach ($identity->permissions as $permission) {
            if ($permission->path === $path
                && $permission->privilege === $privilege
                && $permission->granted
                && $permission->propagated) {
                return true;
            }
        }

        return false;
    }

    private static function hasDeniedPermission(
        OnboardingRemoteEvidence $evidence,
        OnboardingCredentialKind $kind,
        string $path,
        string $privilege,
    ): bool {
        $identity = $evidence->identity($kind);
        self::assertNotNull($identity);
        foreach ($identity->permissions as $permission) {
            if ($permission->path === $path
                && $permission->privilege === $privilege
                && !$permission->granted) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param list<OnboardingVerificationIssue> $issues
     * @return list<OnboardingIssueCode>
     */
    private static function issueCodes(array $issues): array
    {
        return array_map(static fn (OnboardingVerificationIssue $issue): OnboardingIssueCode => $issue->code, $issues);
    }

    /** @param array<string, mixed> $envelope */
    private static function encodeEnvelope(array $envelope): string
    {
        if ([] === ($envelope['data'] ?? null)) {
            $envelope['data'] = new \stdClass();
        }

        return json_encode($envelope, JSON_THROW_ON_ERROR);
    }

    /**
     * @param array<string, mixed> $document
     * @return array<string, mixed>
     */
    private static function fixtureResponse(array $document, bool $backup, ?string $path, string $url): array
    {
        if (str_ends_with($url, '/version')) {
            return self::envelope($document['version'] ?? null);
        }
        if (str_ends_with($url, '/access/roles')) {
            return self::envelope($document['roles'] ?? null);
        }
        if (str_ends_with($url, '/access/acl')) {
            return self::envelope($document['acl'] ?? null);
        }
        if (null === $path) {
            return self::envelope($document[$backup ? 'backupPermissions' : 'scanPermissions'] ?? null);
        }
        if (!$backup && '/pool' === $path) {
            return self::envelope($document['scanPoolRootProbe'] ?? [
                'data' => ['/pool' => ['Pool.Audit' => 1]],
            ]);
        }
        $probes = $document[$backup ? 'backupPropagationProbes' : 'scanPropagationProbes'] ?? null;
        if (!is_array($probes)) {
            throw new \LogicException('The fixture propagation probes are invalid.');
        }

        return self::envelope($probes[$path] ?? null);
    }

    /** @return array<string, mixed> */
    private static function envelope(mixed $value): array
    {
        if (!is_array($value)) {
            throw new \LogicException('The fixture envelope is invalid.');
        }
        /** @var array<string, mixed> $value */
        return $value;
    }

    /**
     * @param array<string, mixed> $document
     * @return array<string, mixed>
     */
    private function withProbe(
        array $document,
        OnboardingCredentialKind $kind,
        string $child,
        string $privilege,
        ?int $value,
    ): array {
        $key = OnboardingCredentialKind::Scan === $kind ? 'scanPropagationProbes' : 'backupPropagationProbes';
        $probes = $document[$key] ?? null;
        if (!is_array($probes)) {
            self::fail('The fixture propagation probes are invalid.');
        }
        $probes[$child] = null === $value
            ? ['data' => []]
            : ['data' => [$child => [$privilege => $value]]];
        $document[$key] = $probes;

        return $document;
    }

    /**
     * @param array<string, mixed> $document
     * @return array<string, mixed>
     */
    private function withoutMatrixRoot(array $document, OnboardingCredentialKind $kind, string $root): array
    {
        $key = OnboardingCredentialKind::Scan === $kind ? 'scanPermissions' : 'backupPermissions';
        $envelope = $document[$key] ?? null;
        if (!is_array($envelope)) {
            self::fail('The fixture permission envelope is invalid.');
        }
        $matrix = $envelope['data'] ?? null;
        if (!is_array($matrix)) {
            self::fail('The fixture permission matrix is invalid.');
        }
        unset($matrix[$root]);
        $envelope['data'] = $matrix;
        $document[$key] = $envelope;

        return $document;
    }

    /**
     * @param array<string, mixed> $document
     * @return array<string, mixed>
     */
    private function withoutMatrixPrivilege(
        array $document,
        OnboardingCredentialKind $kind,
        string $path,
        string $privilege,
    ): array {
        $key = OnboardingCredentialKind::Scan === $kind ? 'scanPermissions' : 'backupPermissions';
        $envelope = $document[$key] ?? null;
        if (!is_array($envelope)) {
            self::fail('The fixture permission envelope is invalid.');
        }
        $matrix = $envelope['data'] ?? null;
        if (!is_array($matrix) || !is_array($matrix[$path] ?? null)) {
            self::fail('The fixture permission matrix path is invalid.');
        }
        unset($matrix[$path][$privilege]);
        $envelope['data'] = $matrix;
        $document[$key] = $envelope;

        return $document;
    }

    /**
     * @param array<string, mixed> $document
     * @return array<string, mixed>
     */
    private function withPoolRootProbe(array $document, ?int $value): array
    {
        $document['scanPoolRootProbe'] = null === $value
            ? ['data' => []]
            : ['data' => ['/pool' => ['Pool.Audit' => $value]]];

        return $document;
    }

    /**
     * @param array<string, mixed> $document
     * @return array<string, mixed>
     */
    private function withNoAccess(
        array $document,
        string $type,
        string $identity,
        string $path,
        int $propagate,
    ): array {
        $acl = $document['acl'] ?? null;
        if (!is_array($acl)) {
            self::fail('The fixture ACL envelope is invalid.');
        }
        $rows = $acl['data'] ?? null;
        if (!is_array($rows) || !array_is_list($rows)) {
            self::fail('The fixture ACL rows are invalid.');
        }
        $rows[] = [
            'path' => $path,
            'type' => $type,
            'ugid' => $identity,
            'roleid' => 'NoAccess',
            'propagate' => $propagate,
        ];
        $acl['data'] = $rows;
        $document['acl'] = $acl;

        return $document;
    }

    /**
     * @param array<string, mixed> $document
     * @param array<string, int>   $privileges
     * @return array<string, mixed>
     */
    private function withScanMatrixRow(array $document, string $path, array $privileges): array
    {
        $envelope = $document['scanPermissions'] ?? null;
        if (!is_array($envelope)) {
            self::fail('The fixture scan permission envelope is invalid.');
        }
        $matrix = $envelope['data'] ?? null;
        if (!is_array($matrix)) {
            self::fail('The fixture scan permission matrix is invalid.');
        }
        $matrix[$path] = $privileges;
        $envelope['data'] = $matrix;
        $document['scanPermissions'] = $envelope;

        return $document;
    }

    /**
     * @param array<string, mixed> $document
     * @param array<string, int>   $privileges
     * @return array<string, mixed>
     */
    private function withBackupMatrixRow(array $document, string $path, array $privileges): array
    {
        $envelope = $document['backupPermissions'] ?? null;
        if (!is_array($envelope)) {
            self::fail('The fixture backup permission envelope is invalid.');
        }
        $matrix = $envelope['data'] ?? null;
        if (!is_array($matrix)) {
            self::fail('The fixture backup permission matrix is invalid.');
        }
        $matrix[$path] = $privileges;
        $envelope['data'] = $matrix;
        $document['backupPermissions'] = $envelope;

        return $document;
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

final readonly class ContractPveOnboardingClientFactory implements PveHttpClientFactory
{
    public function __construct(private \Closure $callback)
    {
    }

    public function create(PveTlsConfiguration $tls): HttpClientInterface
    {
        return new MockHttpClient($this->callback);
    }
}

final readonly class UnusedContractPbsOnboardingClientFactory implements PbsHttpClientFactory
{
    public function create(PbsTlsConfiguration $tls): HttpClientInterface
    {
        throw new \LogicException('unused');
    }
}

final readonly class ContractPveOnboardingDelay implements PveRetryDelay
{
    public function pause(int $attempt): void
    {
    }
}

final readonly class UnusedContractPbsOnboardingDelay implements PbsRetryDelay
{
    public function pause(int $attempt): void
    {
        throw new \LogicException('unused');
    }
}
