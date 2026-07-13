<?php

declare(strict_types=1);

namespace App\Tests\Unit\Application\Configuration\Connection\Onboarding;

use App\Application\Configuration\Connection\Onboarding\OnboardingActivationCommand;
use App\Application\Configuration\Connection\Onboarding\OnboardingAsciiValidator;
use App\Application\Configuration\Connection\Onboarding\OnboardingCredential;
use App\Application\Configuration\Connection\Onboarding\OnboardingCredentialKind;
use App\Application\Configuration\Connection\Onboarding\OnboardingEndpoint;
use App\Application\Configuration\Connection\Onboarding\OnboardingGuidance;
use App\Application\Configuration\Connection\Onboarding\OnboardingGuidanceCommand;
use App\Application\Configuration\Connection\Onboarding\OnboardingIdentityEvidence;
use App\Application\Configuration\Connection\Onboarding\OnboardingIssueCode;
use App\Application\Configuration\Connection\Onboarding\OnboardingIssueSeverity;
use App\Application\Configuration\Connection\Onboarding\OnboardingMode;
use App\Application\Configuration\Connection\Onboarding\OnboardingMutationResult;
use App\Application\Configuration\Connection\Onboarding\OnboardingMutationStatus;
use App\Application\Configuration\Connection\Onboarding\OnboardingPermission;
use App\Application\Configuration\Connection\Onboarding\OnboardingProduct;
use App\Application\Configuration\Connection\Onboarding\OnboardingRemoteEvidence;
use App\Application\Configuration\Connection\Onboarding\OnboardingRemoteFailure;
use App\Application\Configuration\Connection\Onboarding\OnboardingRoleDefinition;
use App\Application\Configuration\Connection\Onboarding\OnboardingTlsMode;
use App\Application\Configuration\Connection\Onboarding\OnboardingVerification;
use App\Application\Configuration\Connection\Onboarding\OnboardingVerificationIssue;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class OnboardingModelTest extends TestCase
{
    protected function setUp(): void
    {
        if (\function_exists('pcntl_alarm')) {
            \pcntl_async_signals(true);
            \pcntl_signal(\SIGALRM, static function (): never {
                throw new \RuntimeException('The onboarding validator did not terminate.');
            });
            \pcntl_alarm(5);
        }
    }

    protected function tearDown(): void
    {
        if (\function_exists('pcntl_alarm')) {
            \pcntl_alarm(0);
            \pcntl_signal(\SIGALRM, \SIG_DFL);
        }
    }

    public function testValidValueObjectsPreserveNormalizedClosedEvidence(): void
    {
        $credential = new OnboardingCredential(OnboardingCredentialKind::Scan, 'hoddmimir@pve!scan', 'secret-value');
        $permission = new OnboardingPermission('/nodes/node-1', 'Sys.Audit', true, true);
        $role = new OnboardingRoleDefinition('HoddmimirScan', ['VM.Audit', 'Sys.Audit', 'VM.Audit']);
        $identity = new OnboardingIdentityEvidence(OnboardingCredentialKind::Scan, OnboardingProduct::Pve, 8, 4, '8.4.1', [$permission]);
        $remote = new OnboardingRemoteEvidence(true, [$identity], [$role]);

        self::assertSame('secret-value', $credential->secret->consume(static fn (string $secret): string => $secret));
        self::assertSame(['Sys.Audit', 'VM.Audit'], $role->privileges);
        self::assertSame([$permission], $identity->permissions);
        self::assertSame($identity, $remote->identity(OnboardingCredentialKind::Scan));
        self::assertNull($remote->identity(OnboardingCredentialKind::Backup));
        self::assertSame(8006, OnboardingProduct::Pve->defaultPort());
        self::assertSame(8007, OnboardingProduct::Pbs->defaultPort());

        $failure = new OnboardingRemoteFailure(OnboardingIssueCode::AuthenticationFailed, OnboardingCredentialKind::Scan);
        self::assertSame(OnboardingIssueCode::AuthenticationFailed, $failure->failureCode);
        self::assertSame(OnboardingCredentialKind::Scan, $failure->credential);
        self::assertSame('The read-only Proxmox onboarding verification failed.', $failure->getMessage());
    }

    #[DataProvider('invalidTokenIds')]
    public function testCredentialRejectsInvalidTokenIdentifiers(string $tokenId): void
    {
        $this->expectException(InvalidArgumentException::class);
        new OnboardingCredential(OnboardingCredentialKind::Scan, $tokenId, 'secret');
    }

    /** @return iterable<string, array{string}> */
    public static function invalidTokenIds(): iterable
    {
        yield 'too short' => ['a@b!'];
        yield 'too long' => [str_repeat('a', 180).'@realm!token'];
        yield 'wrong shape' => ['hoddmimir@pve'];
        yield 'unsafe character' => ['hoddmimir@pve!scan/token'];
    }

    #[DataProvider('invalidEndpoints')]
    public function testEndpointRejectsInvalidAddressOrTrustMaterial(
        string $host,
        int $port,
        OnboardingTlsMode $mode,
        ?string $ca,
        ?string $fingerprint,
    ): void {
        $this->expectException(InvalidArgumentException::class);
        new OnboardingEndpoint($host, $port, $mode, $ca, $fingerprint);
    }

    /** @return iterable<string, array{string, int, OnboardingTlsMode, ?string, ?string}> */
    public static function invalidEndpoints(): iterable
    {
        yield 'empty host' => ['', 8006, OnboardingTlsMode::SystemCa, null, null];
        yield 'untrimmed host' => [' pve.test', 8006, OnboardingTlsMode::SystemCa, null, null];
        yield 'URL instead of host' => ['https://pve.test', 8006, OnboardingTlsMode::SystemCa, null, null];
        yield 'host with port' => ['pve.test:8006', 8006, OnboardingTlsMode::SystemCa, null, null];
        yield 'invalid IPv4' => ['192.0.2.999', 8006, OnboardingTlsMode::SystemCa, null, null];
        yield 'short IPv4' => ['192.0.2', 8006, OnboardingTlsMode::SystemCa, null, null];
        yield 'empty IPv4 octet' => ['192..2.1', 8006, OnboardingTlsMode::SystemCa, null, null];
        yield 'leading-zero IPv4 octet' => ['192.0.002.1', 8006, OnboardingTlsMode::SystemCa, null, null];
        yield 'long IPv4 octet' => ['192.0000.2.1', 8006, OnboardingTlsMode::SystemCa, null, null];
        yield 'invalid bracketed IPv6' => ['[2001:db8::zz]', 8006, OnboardingTlsMode::SystemCa, null, null];
        yield 'unclosed bracketed IPv6' => ['[2001:db8::1', 8006, OnboardingTlsMode::SystemCa, null, null];
        yield 'closing IPv6 bracket only' => ['2001:db8::1]', 8006, OnboardingTlsMode::SystemCa, null, null];
        yield 'empty bracketed IPv6' => ['[]', 8006, OnboardingTlsMode::SystemCa, null, null];
        yield 'single-leading-colon IPv6' => [':1', 8006, OnboardingTlsMode::SystemCa, null, null];
        yield 'single-trailing-colon IPv6' => ['2001:db8:', 8006, OnboardingTlsMode::SystemCa, null, null];
        yield 'multiple-compressions IPv6' => ['2001::db8::1', 8006, OnboardingTlsMode::SystemCa, null, null];
        yield 'oversized-group IPv6' => ['12345::1', 8006, OnboardingTlsMode::SystemCa, null, null];
        yield 'too-few-groups IPv6' => ['1:2:3:4:5:6:7', 8006, OnboardingTlsMode::SystemCa, null, null];
        yield 'too-many-groups IPv6' => ['1:2:3:4:5:6:7:8:9', 8006, OnboardingTlsMode::SystemCa, null, null];
        yield 'compression-without-space IPv6' => ['1:2:3:4:5:6:7::8', 8006, OnboardingTlsMode::SystemCa, null, null];
        yield 'invalid-embedded-IPv4 IPv6' => ['::ffff:192.0.2.999', 8006, OnboardingTlsMode::SystemCa, null, null];
        yield 'non-numeric embedded IPv4 IPv6' => ['::ffff:192.0.x.1', 8006, OnboardingTlsMode::SystemCa, null, null];
        yield 'empty DNS label' => ['pve..test', 8006, OnboardingTlsMode::SystemCa, null, null];
        yield 'leading DNS hyphen' => ['-pve.test', 8006, OnboardingTlsMode::SystemCa, null, null];
        yield 'trailing DNS hyphen' => ['pve-.test', 8006, OnboardingTlsMode::SystemCa, null, null];
        yield 'final DNS hyphen' => ['pve.test-', 8006, OnboardingTlsMode::SystemCa, null, null];
        yield 'long DNS label' => [str_repeat('a', 64).'.test', 8006, OnboardingTlsMode::SystemCa, null, null];
        yield 'unsafe DNS character' => ['pve_test', 8006, OnboardingTlsMode::SystemCa, null, null];
        yield 'DNS name above protocol maximum' => [str_repeat('a', 63).'.'.str_repeat('b', 63).'.'.str_repeat('c', 63).'.'.str_repeat('d', 62), 8006, OnboardingTlsMode::SystemCa, null, null];
        yield 'host too long' => [str_repeat('a', 256), 8006, OnboardingTlsMode::SystemCa, null, null];
        yield 'port too low' => ['pve.test', 0, OnboardingTlsMode::SystemCa, null, null];
        yield 'port too high' => ['pve.test', 65_536, OnboardingTlsMode::SystemCa, null, null];
        yield 'system trust has CA' => ['pve.test', 8006, OnboardingTlsMode::SystemCa, 'ca', null];
        yield 'system trust has fingerprint' => ['pve.test', 8006, OnboardingTlsMode::SystemCa, null, str_repeat('a', 64)];
        yield 'custom CA missing' => ['pve.test', 8006, OnboardingTlsMode::CustomCa, null, null];
        yield 'custom CA malformed' => ['pve.test', 8006, OnboardingTlsMode::CustomCa, 'ca', null];
        yield 'custom CA too long' => ['pve.test', 8006, OnboardingTlsMode::CustomCa, '-----BEGIN CERTIFICATE-----'.str_repeat('a', 262_144), null];
        yield 'custom CA plus fingerprint' => ['pve.test', 8006, OnboardingTlsMode::CustomCa, '-----BEGIN CERTIFICATE-----', str_repeat('a', 64)];
        yield 'fingerprint missing' => ['pve.test', 8006, OnboardingTlsMode::Sha256Fingerprint, null, null];
        yield 'fingerprint malformed' => ['pve.test', 8006, OnboardingTlsMode::Sha256Fingerprint, null, str_repeat('A', 64)];
        yield 'fingerprint plus CA' => ['pve.test', 8006, OnboardingTlsMode::Sha256Fingerprint, '-----BEGIN CERTIFICATE-----', str_repeat('a', 64)];
    }

    public function testAllTrustModesAcceptOnlyTheirOwnMaterial(): void
    {
        self::assertSame(OnboardingTlsMode::SystemCa, (new OnboardingEndpoint('pve.test', 8006, OnboardingTlsMode::SystemCa, null, null))->tlsMode);
        self::assertSame('[2001:db8::1]', (new OnboardingEndpoint('[2001:db8::1]', 8006, OnboardingTlsMode::SystemCa, null, null))->host);
        self::assertSame('2001:db8::1', (new OnboardingEndpoint('2001:db8::1', 8006, OnboardingTlsMode::SystemCa, null, null))->host);
        self::assertSame('192.0.2.10', (new OnboardingEndpoint('192.0.2.10', 8006, OnboardingTlsMode::SystemCa, null, null))->host);
        foreach (['a', '2001:db8:0:0:0:0:0:1', '::1', '[::]', '::ffff:192.0.2.10', '1:2:3:4:5:6:7::'] as $host) {
            self::assertSame($host, (new OnboardingEndpoint($host, 8006, OnboardingTlsMode::SystemCa, null, null))->host);
        }
        self::assertSame(OnboardingTlsMode::CustomCa, (new OnboardingEndpoint('pve.test', 8006, OnboardingTlsMode::CustomCa, '-----BEGIN CERTIFICATE-----', null))->tlsMode);
        self::assertSame(OnboardingTlsMode::Sha256Fingerprint, (new OnboardingEndpoint('pve.test', 8006, OnboardingTlsMode::Sha256Fingerprint, null, str_repeat('a', 64)))->tlsMode);
    }

    public function testAsciiValidatorCoversEveryClosedIdentifierAndTrustBoundary(): void
    {
        self::assertTrue(OnboardingAsciiValidator::hasNormalizedBoundary('value'));
        foreach (['', ' value', "value\t"] as $value) {
            self::assertFalse(OnboardingAsciiValidator::hasNormalizedBoundary($value));
        }

        self::assertTrue(OnboardingAsciiValidator::isIdempotencyKey('a.B_1:-'));
        foreach (['', str_repeat('a', 129), '-bad', 'bad/key'] as $value) {
            self::assertFalse(OnboardingAsciiValidator::isIdempotencyKey($value));
        }

        self::assertTrue(OnboardingAsciiValidator::isTokenId('user.name@realm-1!token_1'));
        foreach (['user', '@realm!token', 'user@!token', 'user@realm!', 'user@@realm!token', 'user!token@realm', 'user@realm!!token', 'user@realm!bad/token'] as $value) {
            self::assertFalse(OnboardingAsciiValidator::isTokenId($value));
        }

        self::assertTrue(OnboardingAsciiValidator::isGuidanceId('inspect-1'));
        foreach (['', str_repeat('a', 65), 'Bad', 'bad_id'] as $value) {
            self::assertFalse(OnboardingAsciiValidator::isGuidanceId($value));
        }

        self::assertTrue(OnboardingAsciiValidator::containsNewline("a\nb"));
        self::assertFalse(OnboardingAsciiValidator::containsNewline('ab'));
        self::assertTrue(OnboardingAsciiValidator::contains('xx-certificate-yy', 'certificate'));
        foreach ([['value', ''], ['a', 'long'], ['abcdef', 'xyz']] as [$value, $needle]) {
            self::assertFalse(OnboardingAsciiValidator::contains($value, $needle));
        }

        self::assertTrue(OnboardingAsciiValidator::isLowerHex('0a9f', 4));
        self::assertFalse(OnboardingAsciiValidator::isLowerHex('0a9', 4));
        self::assertFalse(OnboardingAsciiValidator::isLowerHex('0agf', 4));

        foreach (['/', '/nodes/node-1'] as $value) {
            self::assertTrue(OnboardingAsciiValidator::isPermissionPath($value));
        }
        foreach (['nodes', '/nodes//node', '/nodes/', '/nodes/bad:path'] as $value) {
            self::assertFalse(OnboardingAsciiValidator::isPermissionPath($value));
        }

        self::assertTrue(OnboardingAsciiValidator::isPrivilege('VM.Audit2'));
        foreach (['', '.Audit', '1VM.Audit', 'VM', 'VM.', 'VM.1Audit', 'VM.Audit/write'] as $value) {
            self::assertFalse(OnboardingAsciiValidator::isPrivilege($value));
        }

        self::assertTrue(OnboardingAsciiValidator::isLowerUuid('01234567-89ab-cdef-0123-456789abcdef'));
        foreach (['short', '01234567_89ab-cdef-0123-456789abcdef', '01234567-89ab-cdef-0123-456789abcdeg'] as $value) {
            self::assertFalse(OnboardingAsciiValidator::isLowerUuid($value));
        }
    }

    #[DataProvider('invalidGuidanceCommands')]
    public function testGuidanceCommandRejectsUnsafeOrUnboundedValues(string $id, string $command, string $purpose): void
    {
        $this->expectException(InvalidArgumentException::class);
        new OnboardingGuidanceCommand($id, $command, $purpose, false);
    }

    /** @return iterable<string, array{string, string, string}> */
    public static function invalidGuidanceCommands(): iterable
    {
        yield 'invalid id' => ['Bad_ID', 'pveum user list', 'Inspect'];
        yield 'id too long' => [str_repeat('a', 65), 'pveum user list', 'Inspect'];
        yield 'empty command' => ['inspect', '', 'Inspect'];
        yield 'multi-line command' => ['inspect', "pveum user list\npveum role list", 'Inspect'];
        yield 'command too long' => ['inspect', str_repeat('a', 1025), 'Inspect'];
        yield 'empty purpose' => ['inspect', 'pveum user list', ''];
        yield 'untrimmed purpose' => ['inspect', 'pveum user list', ' Inspect'];
        yield 'purpose too long' => ['inspect', 'pveum user list', str_repeat('a', 256)];
    }

    public function testGuidanceIsClosedUniqueAndSerializable(): void
    {
        $command = new OnboardingGuidanceCommand('inspect', 'pveum user list', 'Inspect users.', false);
        $guidance = new OnboardingGuidance(OnboardingProduct::Pve, [$command], ['Run each command individually.']);

        self::assertSame([
            'product' => 'pve',
            'commands' => [[
                'id' => 'inspect',
                'command' => 'pveum user list',
                'purpose' => 'Inspect users.',
                'mutatesRemote' => false,
                'containsSecret' => false,
            ]],
            'warnings' => ['Run each command individually.'],
        ], $guidance->toArray());
    }

    /**
     * @param list<OnboardingGuidanceCommand> $commands
     * @param list<string>                    $warnings
     */
    #[DataProvider('invalidGuidance')]
    public function testGuidanceRejectsEmptyDuplicateOrInvalidContent(array $commands, array $warnings): void
    {
        $this->expectException(InvalidArgumentException::class);
        new OnboardingGuidance(OnboardingProduct::Pve, $commands, $warnings);
    }

    /** @return iterable<string, array{list<OnboardingGuidanceCommand>, list<string>}> */
    public static function invalidGuidance(): iterable
    {
        $command = new OnboardingGuidanceCommand('inspect', 'pveum user list', 'Inspect.', false);
        yield 'empty commands' => [[], []];
        yield 'duplicate IDs' => [[$command, $command], []];
        yield 'empty warning' => [[$command], ['']];
        yield 'untrimmed warning' => [[$command], [' warning']];
        yield 'warning too long' => [[$command], [str_repeat('a', 513)]];
    }

    #[DataProvider('invalidPermissions')]
    public function testPermissionRejectsInvalidNormalizedValues(string $path, string $privilege): void
    {
        $this->expectException(InvalidArgumentException::class);
        new OnboardingPermission($path, $privilege, true, true);
    }

    /** @return iterable<string, array{string, string}> */
    public static function invalidPermissions(): iterable
    {
        yield 'relative path' => ['nodes', 'Sys.Audit'];
        yield 'empty segment' => ['/nodes//node', 'Sys.Audit'];
        yield 'invalid privilege' => ['/nodes', 'Sys'];
        yield 'unsafe privilege' => ['/nodes', 'Sys.Audit/write'];
    }

    #[DataProvider('invalidEvidence')]
    public function testIdentityEvidenceRejectsInvalidVersionEvidence(int $major, int $minor, string $version): void
    {
        $this->expectException(InvalidArgumentException::class);
        new OnboardingIdentityEvidence(OnboardingCredentialKind::Scan, OnboardingProduct::Pve, $major, $minor, $version, []);
    }

    /** @return iterable<string, array{int, int, string}> */
    public static function invalidEvidence(): iterable
    {
        yield 'major' => [0, 0, '0.0'];
        yield 'minor' => [8, -1, '8.-1'];
        yield 'empty version' => [8, 0, ''];
        yield 'long version' => [8, 0, str_repeat('a', 256)];
    }

    public function testRemoteEvidenceRejectsDuplicateCredentialKinds(): void
    {
        $identity = new OnboardingIdentityEvidence(OnboardingCredentialKind::Scan, OnboardingProduct::Pve, 8, 4, '8.4', []);
        $this->expectException(InvalidArgumentException::class);
        new OnboardingRemoteEvidence(true, [$identity, $identity]);
    }

    /** @param list<string> $privileges */
    #[DataProvider('invalidRoles')]
    public function testRoleDefinitionRejectsInvalidValues(string $name, array $privileges): void
    {
        $this->expectException(InvalidArgumentException::class);
        new OnboardingRoleDefinition($name, $privileges);
    }

    /** @return iterable<string, array{string, list<string>}> */
    public static function invalidRoles(): iterable
    {
        yield 'empty name' => ['', ['Sys.Audit']];
        yield 'long name' => [str_repeat('a', 65), ['Sys.Audit']];
        yield 'invalid privilege' => ['Role', ['Sys']];
    }

    public function testVerificationExposesIndependentStatesAndOnlyWarnings(): void
    {
        $warning = new OnboardingVerificationIssue(
            OnboardingIssueCode::AdditionalReadOnlyPermission,
            OnboardingIssueSeverity::Warning,
            OnboardingCredentialKind::Scan,
            '/nodes',
            'Sys.Log',
        );
        $verification = new OnboardingVerification(true, true, true, true, OnboardingProduct::Pve, '8.4.1', [$warning]);

        self::assertTrue($verification->passed());
        self::assertSame([
            'tls' => 'tls_verified',
            'product' => 'product_supported',
            'scanPermissions' => 'scan_permissions_verified',
            'backupPermissions' => 'backup_permissions_verified',
            'activation' => 'connection_activated',
            'inventory' => 'first_automatic_scan_pending',
            'detectedProduct' => 'pve',
            'detectedVersion' => '8.4.1',
            'warnings' => [$warning->toArray()],
        ], $verification->toArray(true));
        self::assertSame('first_automatic_scan_pending', $verification->toArray(true)['inventory']);
    }

    public function testVerificationFailsForEveryRequiredStateOrErrorIssue(): void
    {
        $error = new OnboardingVerificationIssue(OnboardingIssueCode::AuthenticationFailed, OnboardingIssueSeverity::Error);
        foreach ([
            new OnboardingVerification(false, true, true, true, null, null, []),
            new OnboardingVerification(true, false, true, true, null, null, []),
            new OnboardingVerification(true, true, false, true, null, null, []),
            new OnboardingVerification(true, true, true, false, null, null, []),
            new OnboardingVerification(true, true, true, null, OnboardingProduct::Pbs, '4.0', [$error]),
        ] as $verification) {
            self::assertFalse($verification->passed());
            self::assertSame('not_started', $verification->toArray(false)['inventory']);
        }
    }

    public function testVerificationRejectsHalfPresentProductEvidence(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new OnboardingVerification(true, true, true, null, OnboardingProduct::Pbs, null, []);
    }

    public function testVerificationIssueRequiresPathAndPrivilegeTogether(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new OnboardingVerificationIssue(OnboardingIssueCode::RequiredPermissionMissing, OnboardingIssueSeverity::Error, null, '/nodes');
    }

    public function testActivationCommandSupportsAllModesAndHashNeverDependsOnSecret(): void
    {
        $activate = $this->command(OnboardingMode::Activate, null, 'secret-one');
        $samePayloadNewSecret = $this->command(OnboardingMode::Activate, null, 'secret-two');
        $rotate = $this->command(OnboardingMode::Rotate, str_repeat('e', 16));
        $update = $this->command(OnboardingMode::EndpointUpdate, str_repeat('e', 16));
        $add = $this->command(OnboardingMode::EndpointAdd);

        self::assertSame($activate->payloadHash, $samePayloadNewSecret->payloadHash);
        self::assertSame(OnboardingCredentialKind::Scan, $activate->credential(OnboardingCredentialKind::Scan)->kind);
        self::assertSame(str_repeat('e', 16), $rotate->endpointId);
        self::assertSame(str_repeat('e', 16), $update->endpointId);
        self::assertNull($add->endpointId);
    }

    /**
     * @param array{
     *     mode?: OnboardingMode,
     *     connectionId?: string,
     *     expectedRevision?: int,
     *     idempotencyKey?: string,
     *     correlationId?: string,
     *     displayName?: string,
     *     endpointId?: ?string
     * } $overrides
     */
    #[DataProvider('invalidCommandEnvelopes')]
    public function testActivationCommandRejectsInvalidEnvelope(array $overrides): void
    {
        $this->expectException(InvalidArgumentException::class);
        new OnboardingActivationCommand(
            $overrides['mode'] ?? OnboardingMode::Activate,
            $overrides['connectionId'] ?? str_repeat('c', 16),
            $overrides['expectedRevision'] ?? 0,
            $overrides['idempotencyKey'] ?? 'safe-key',
            $overrides['correlationId'] ?? str_repeat('r', 16),
            OnboardingProduct::Pve,
            $overrides['displayName'] ?? 'PVE',
            new OnboardingEndpoint('pve.test', 8006, OnboardingTlsMode::SystemCa, null, null),
            $this->pveCredentials(),
            $overrides['endpointId'] ?? null,
        );
    }

    /** @return iterable<string, array{array<string, mixed>}> */
    public static function invalidCommandEnvelopes(): iterable
    {
        yield 'connection ID' => [['connectionId' => 'short']];
        yield 'correlation ID' => [['correlationId' => 'short']];
        yield 'negative revision' => [['expectedRevision' => -1]];
        yield 'empty display name' => [['displayName' => '']];
        yield 'untrimmed display name' => [['displayName' => ' PVE']];
        yield 'long display name' => [['displayName' => str_repeat('a', 191)]];
        yield 'invalid key' => [['idempotencyKey' => 'bad key']];
        yield 'activate endpoint ID' => [['endpointId' => str_repeat('e', 16)]];
        yield 'add endpoint ID' => [['mode' => OnboardingMode::EndpointAdd, 'endpointId' => str_repeat('e', 16)]];
        yield 'rotate missing endpoint ID' => [['mode' => OnboardingMode::Rotate]];
        yield 'rotate invalid endpoint ID' => [['mode' => OnboardingMode::Rotate, 'endpointId' => 'short']];
        yield 'update missing endpoint ID' => [['mode' => OnboardingMode::EndpointUpdate]];
        yield 'update invalid endpoint ID' => [['mode' => OnboardingMode::EndpointUpdate, 'endpointId' => 'short']];
    }

    public function testActivationCommandRejectsDuplicateOrIncompleteCredentialSets(): void
    {
        $scan = new OnboardingCredential(OnboardingCredentialKind::Scan, 'hoddmimir@pve!scan', 'scan-secret');
        foreach ([[$scan, $scan], [$scan]] as $credentials) {
            try {
                new OnboardingActivationCommand(OnboardingMode::Activate, str_repeat('c', 16), 0, 'key', str_repeat('r', 16), OnboardingProduct::Pve, 'PVE',
                    new OnboardingEndpoint('pve.test', 8006, OnboardingTlsMode::SystemCa, null, null), $credentials);
                self::fail('An invalid credential set was accepted.');
            } catch (InvalidArgumentException) {
                self::addToAssertionCount(1);
            }
        }
    }

    public function testUnavailableCredentialLookupFailsClosed(): void
    {
        $command = new OnboardingActivationCommand(OnboardingMode::Activate, str_repeat('c', 16), 0, 'key', str_repeat('r', 16), OnboardingProduct::Pbs, 'PBS',
            new OnboardingEndpoint('pbs.test', 8007, OnboardingTlsMode::SystemCa, null, null), [
                new OnboardingCredential(OnboardingCredentialKind::Scan, 'hoddmimir@pbs!scan', 'secret'),
            ]);
        $this->expectException(InvalidArgumentException::class);
        $command->credential(OnboardingCredentialKind::Backup);
    }

    public function testMutationResultsEnforceEveryStateInvariant(): void
    {
        $passed = new OnboardingVerification(true, true, true, true, OnboardingProduct::Pve, '8.4', []);
        $failed = new OnboardingVerification(false, false, false, false, null, null, []);
        foreach ([OnboardingMutationStatus::Applied, OnboardingMutationStatus::Replayed] as $status) {
            self::assertSame(1, (new OnboardingMutationResult($status, str_repeat('c', 16), 1, $passed))->revision);
        }
        self::assertSame(1, (new OnboardingMutationResult(OnboardingMutationStatus::Conflict, str_repeat('c', 16), 1))->revision);
        foreach ([OnboardingMutationStatus::Rejected, OnboardingMutationStatus::Denied] as $status) {
            self::assertSame($failed, (new OnboardingMutationResult($status, str_repeat('c', 16), null, $failed))->verification);
        }
    }

    #[DataProvider('invalidMutationResults')]
    public function testMutationResultRejectsInvalidStateCombinations(
        OnboardingMutationStatus $status,
        string $id,
        ?int $revision,
        ?string $verification,
    ): void {
        $passed = new OnboardingVerification(true, true, true, true, OnboardingProduct::Pve, '8.4', []);
        $failed = new OnboardingVerification(false, false, false, false, null, null, []);
        $value = match ($verification) {
            'passed' => $passed,
            'failed' => $failed,
            default => null,
        };
        $this->expectException(InvalidArgumentException::class);
        new OnboardingMutationResult($status, $id, $revision, $value);
    }

    /** @return iterable<string, array{OnboardingMutationStatus, string, ?int, ?string}> */
    public static function invalidMutationResults(): iterable
    {
        yield 'ID' => [OnboardingMutationStatus::Applied, 'short', 1, 'passed'];
        yield 'success revision' => [OnboardingMutationStatus::Applied, str_repeat('c', 16), null, 'passed'];
        yield 'success verification' => [OnboardingMutationStatus::Replayed, str_repeat('c', 16), 1, null];
        yield 'success failed verification' => [OnboardingMutationStatus::Applied, str_repeat('c', 16), 1, 'failed'];
        yield 'conflict revision' => [OnboardingMutationStatus::Conflict, str_repeat('c', 16), null, null];
        yield 'conflict verification' => [OnboardingMutationStatus::Conflict, str_repeat('c', 16), 1, 'failed'];
        yield 'rejected revision' => [OnboardingMutationStatus::Rejected, str_repeat('c', 16), 1, 'failed'];
        yield 'rejected verification missing' => [OnboardingMutationStatus::Rejected, str_repeat('c', 16), null, null];
        yield 'rejected passed verification' => [OnboardingMutationStatus::Denied, str_repeat('c', 16), null, 'passed'];
    }

    /** @return list<OnboardingCredential> */
    private function pveCredentials(string $scanSecret = 'scan-secret'): array
    {
        return [
            new OnboardingCredential(OnboardingCredentialKind::Scan, 'hoddmimir@pve!scan', $scanSecret),
            new OnboardingCredential(OnboardingCredentialKind::Backup, 'hoddmimir@pve!backup', 'backup-secret'),
        ];
    }

    private function command(OnboardingMode $mode, ?string $endpointId = null, string $scanSecret = 'scan-secret'): OnboardingActivationCommand
    {
        return new OnboardingActivationCommand($mode, str_repeat('c', 16), 0, 'key', str_repeat('r', 16), OnboardingProduct::Pve, 'PVE',
            new OnboardingEndpoint('pve.test', 8006, OnboardingTlsMode::SystemCa, null, null), $this->pveCredentials($scanSecret), $endpointId);
    }
}
