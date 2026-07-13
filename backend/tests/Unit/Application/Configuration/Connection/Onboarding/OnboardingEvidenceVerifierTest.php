<?php

declare(strict_types=1);

namespace App\Tests\Unit\Application\Configuration\Connection\Onboarding;

use App\Application\Configuration\Connection\Onboarding\OnboardingActivationCommand;
use App\Application\Configuration\Connection\Onboarding\OnboardingCredential;
use App\Application\Configuration\Connection\Onboarding\OnboardingCredentialKind;
use App\Application\Configuration\Connection\Onboarding\OnboardingEndpoint;
use App\Application\Configuration\Connection\Onboarding\OnboardingEvidenceVerifier;
use App\Application\Configuration\Connection\Onboarding\OnboardingIdentityEvidence;
use App\Application\Configuration\Connection\Onboarding\OnboardingIssueCode;
use App\Application\Configuration\Connection\Onboarding\OnboardingIssueSeverity;
use App\Application\Configuration\Connection\Onboarding\OnboardingMode;
use App\Application\Configuration\Connection\Onboarding\OnboardingPermission;
use App\Application\Configuration\Connection\Onboarding\OnboardingProduct;
use App\Application\Configuration\Connection\Onboarding\OnboardingRemoteEvidence;
use App\Application\Configuration\Connection\Onboarding\OnboardingRoleDefinition;
use App\Application\Configuration\Connection\Onboarding\OnboardingTlsMode;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class OnboardingEvidenceVerifierTest extends TestCase
{
    #[DataProvider('pveMajors')]
    public function testPveSevenEightAndNinePassWithExactRolesSeparatedTokensAndPropagatedPermissions(int $major): void
    {
        $verification = (new OnboardingEvidenceVerifier())->verify($this->command(OnboardingProduct::Pve), $this->pveEvidence($major));

        self::assertTrue($verification->passed());
        self::assertTrue($verification->tlsVerified);
        self::assertTrue($verification->productSupported);
        self::assertTrue($verification->scanPermissionsVerified);
        self::assertTrue($verification->backupPermissionsVerified);
        self::assertSame(OnboardingProduct::Pve, $verification->detectedProduct);
        self::assertSame($major.'.4.1', $verification->detectedVersion);
        self::assertSame([], $verification->issues);
    }

    /** @return iterable<string, array{int}> */
    public static function pveMajors(): iterable
    {
        yield 'PVE 7' => [7];
        yield 'PVE 8' => [8];
        yield 'PVE 9' => [9];
    }

    #[DataProvider('pbsMajors')]
    public function testPbsThreeAndFourPassWithoutBackupCredential(int $major): void
    {
        $verification = (new OnboardingEvidenceVerifier())->verify($this->command(OnboardingProduct::Pbs), $this->pbsEvidence($major));

        self::assertTrue($verification->passed());
        self::assertTrue($verification->scanPermissionsVerified);
        self::assertNull($verification->backupPermissionsVerified);
        self::assertSame($major.'.4.1', $verification->detectedVersion);
    }

    /** @return iterable<string, array{int}> */
    public static function pbsMajors(): iterable
    {
        yield 'PBS 3' => [3];
        yield 'PBS 4' => [4];
    }

    public function testAdditionalClosedReadOnlyScannerPermissionIsWarningAndStillPasses(): void
    {
        $evidence = $this->pveEvidence(8);
        $scan = $evidence->identity(OnboardingCredentialKind::Scan);
        self::assertNotNull($scan);
        $permissions = $scan->permissions;
        $permissions[] = new OnboardingPermission('/nodes', 'Sys.Log', true, true);
        $evidence = new OnboardingRemoteEvidence(true, [
            new OnboardingIdentityEvidence($scan->kind, $scan->detectedProduct, $scan->major, $scan->minor, $scan->rawVersion, $permissions),
            $evidence->identity(OnboardingCredentialKind::Backup) ?? self::fail('Missing backup evidence.'),
        ], $evidence->roles);

        $verification = (new OnboardingEvidenceVerifier())->verify($this->command(OnboardingProduct::Pve), $evidence);

        self::assertTrue($verification->passed());
        self::assertCount(1, $verification->issues);
        self::assertSame(OnboardingIssueCode::AdditionalReadOnlyPermission, $verification->issues[0]->code);
        self::assertSame(OnboardingIssueSeverity::Warning, $verification->issues[0]->severity);
    }

    public function testBackupFailureDoesNotEraseIndependentlyVerifiedScannerPermissions(): void
    {
        $verification = (new OnboardingEvidenceVerifier())->verify(
            $this->command(OnboardingProduct::Pve),
            $this->appendPermission(
                $this->pveEvidence(8),
                OnboardingCredentialKind::Backup,
                new OnboardingPermission('/vms', 'VM.PowerMgmt', true, true),
            ),
        );

        self::assertFalse($verification->passed());
        self::assertTrue($verification->scanPermissionsVerified);
        self::assertFalse($verification->backupPermissionsVerified);
    }

    public function testDuplicateRequiredRoleEvidenceFailsClosed(): void
    {
        $evidence = $this->pveEvidence(8);
        $verification = (new OnboardingEvidenceVerifier())->verify(
            $this->command(OnboardingProduct::Pve),
            new OnboardingRemoteEvidence(true, $evidence->identities, [
                ...$evidence->roles,
                new OnboardingRoleDefinition('HoddmimirScan', ['Sys.Audit']),
            ]),
        );

        self::assertFalse($verification->passed());
        self::assertContains(
            OnboardingIssueCode::RoleDefinitionMismatch,
            array_map(static fn ($issue): OnboardingIssueCode => $issue->code, $verification->issues),
        );
    }

    public function testDuplicatePermissionEvidenceUsesTheMostRestrictiveGrantAndIsReportedOnce(): void
    {
        $evidence = $this->appendPermission(
            $this->pveEvidence(8),
            OnboardingCredentialKind::Scan,
            new OnboardingPermission('/pool', 'Pool.Audit', false, false),
        );
        $evidence = $this->appendPermission(
            $evidence,
            OnboardingCredentialKind::Scan,
            new OnboardingPermission('/pool', 'Pool.Audit', true, true),
        );

        $verification = (new OnboardingEvidenceVerifier())->verify($this->command(OnboardingProduct::Pve), $evidence);

        self::assertFalse($verification->passed());
        self::assertSame(1, count(array_filter(
            $verification->issues,
            static fn ($issue): bool => OnboardingIssueCode::NoAccessOverride === $issue->code,
        )));
        self::assertContains(
            OnboardingIssueCode::RequiredPermissionMissing,
            array_map(static fn ($issue): OnboardingIssueCode => $issue->code, $verification->issues),
        );
    }

    #[DataProvider('hardFailureEvidence')]
    public function testEveryClosedEvidenceFailureBlocksActivation(
        OnboardingActivationCommand $command,
        OnboardingRemoteEvidence $evidence,
        OnboardingIssueCode $expected,
    ): void {
        $verification = (new OnboardingEvidenceVerifier())->verify($command, $evidence);

        self::assertFalse($verification->passed());
        self::assertContains($expected, array_map(static fn ($issue): OnboardingIssueCode => $issue->code, $verification->issues));
    }

    /** @return iterable<string, array{OnboardingActivationCommand, OnboardingRemoteEvidence, OnboardingIssueCode}> */
    public static function hardFailureEvidence(): iterable
    {
        $test = new self('fixture');
        $pve = $test->pveEvidence(8);
        $scan = $pve->identity(OnboardingCredentialKind::Scan) ?? throw new \LogicException();
        $backup = $pve->identity(OnboardingCredentialKind::Backup) ?? throw new \LogicException();

        yield 'TLS not verified' => [$test->command(OnboardingProduct::Pve), new OnboardingRemoteEvidence(false, $pve->identities, $pve->roles), OnboardingIssueCode::TlsVerificationFailed];
        yield 'scan identity missing' => [$test->command(OnboardingProduct::Pve), new OnboardingRemoteEvidence(true, [$backup], $pve->roles), OnboardingIssueCode::CredentialMissing];
        yield 'wrong scanner token id' => [$test->command(OnboardingProduct::Pve, 'other@pve!scan'), $pve, OnboardingIssueCode::TokenIdentityInvalid];
        yield 'tokens not separated' => [$test->command(OnboardingProduct::Pve, 'same@pve!token', 'same@pve!token'), $pve, OnboardingIssueCode::TokenIdentitiesNotSeparated];
        yield 'product mismatch' => [$test->command(OnboardingProduct::Pve), new OnboardingRemoteEvidence(true, [
            new OnboardingIdentityEvidence(OnboardingCredentialKind::Scan, OnboardingProduct::Pbs, 4, 0, '4.0.1', $scan->permissions),
            $backup,
        ], $pve->roles), OnboardingIssueCode::ProductMismatch];
        yield 'unsupported PVE major' => [$test->command(OnboardingProduct::Pve), $test->pveEvidence(6), OnboardingIssueCode::UnsupportedVersion];
        yield 'future unsupported PVE major' => [$test->command(OnboardingProduct::Pve), $test->pveEvidence(10), OnboardingIssueCode::UnsupportedVersion];
        yield 'token versions disagree' => [$test->command(OnboardingProduct::Pve), new OnboardingRemoteEvidence(true, [
            $scan,
            new OnboardingIdentityEvidence(OnboardingCredentialKind::Backup, OnboardingProduct::Pve, 8, 5, '8.5.0', $backup->permissions),
        ], $pve->roles), OnboardingIssueCode::VersionEvidenceMismatch];
        yield 'scan role missing' => [$test->command(OnboardingProduct::Pve), new OnboardingRemoteEvidence(true, $pve->identities, [$pve->roles[1]]), OnboardingIssueCode::RoleMissing];
        yield 'backup role differs' => [$test->command(OnboardingProduct::Pve), new OnboardingRemoteEvidence(true, $pve->identities, [
            $pve->roles[0], new OnboardingRoleDefinition('HoddmimirBackup', ['VM.Backup']),
        ]), OnboardingIssueCode::RoleDefinitionMismatch];
        yield 'required permission missing' => [$test->command(OnboardingProduct::Pve), $test->replacePermission($pve, OnboardingCredentialKind::Scan, '/pool', 'Pool.Audit', null), OnboardingIssueCode::RequiredPermissionMissing];
        yield 'permission not propagated' => [$test->command(OnboardingProduct::Pve), $test->replacePermission($pve, OnboardingCredentialKind::Scan, '/pool', 'Pool.Audit', new OnboardingPermission('/pool', 'Pool.Audit', true, false)), OnboardingIssueCode::PermissionNotPropagated];
        yield 'NoAccess overrides grant' => [$test->command(OnboardingProduct::Pve), $test->appendPermission($pve, OnboardingCredentialKind::Scan, new OnboardingPermission('/pool', 'Pool.Audit', false, false)), OnboardingIssueCode::NoAccessOverride];
        yield 'scanner has write permission' => [$test->command(OnboardingProduct::Pve), $test->appendPermission($pve, OnboardingCredentialKind::Scan, new OnboardingPermission('/vms', 'VM.Backup', true, true)), OnboardingIssueCode::ForbiddenPermissionPresent];
        yield 'backup has third permission' => [$test->command(OnboardingProduct::Pve), $test->appendPermission($pve, OnboardingCredentialKind::Backup, new OnboardingPermission('/vms', 'VM.PowerMgmt', true, true)), OnboardingIssueCode::ForbiddenPermissionPresent];
        yield 'PBS tape audit forbidden' => [$test->command(OnboardingProduct::Pbs), $test->appendPermission($test->pbsEvidence(4), OnboardingCredentialKind::Scan, new OnboardingPermission('/tape', 'Tape.Audit', true, true)), OnboardingIssueCode::ForbiddenPermissionPresent];
        yield 'unsupported PBS major' => [$test->command(OnboardingProduct::Pbs), $test->pbsEvidence(2), OnboardingIssueCode::UnsupportedVersion];
        yield 'future unsupported PBS major' => [$test->command(OnboardingProduct::Pbs), $test->pbsEvidence(5), OnboardingIssueCode::UnsupportedVersion];
    }

    private function command(OnboardingProduct $product, ?string $scanId = null, ?string $backupId = null): OnboardingActivationCommand
    {
        $credentials = [new OnboardingCredential(
            OnboardingCredentialKind::Scan,
            $scanId ?? (OnboardingProduct::Pve === $product ? 'hoddmimir@pve!scan' : 'hoddmimir@pbs!scan'),
            OnboardingProduct::Pve === $product ? 'scan-secret' : '00000000-0000-0000-0000-000000000000',
        )];
        if (OnboardingProduct::Pve === $product) {
            $credentials[] = new OnboardingCredential(OnboardingCredentialKind::Backup, $backupId ?? 'hoddmimir@pve!backup', 'backup-secret');
        }
        return new OnboardingActivationCommand(
            OnboardingMode::Activate,
            str_repeat('c', 16),
            0,
            'onboarding-test',
            str_repeat('r', 16),
            $product,
            'Proxmox Test',
            new OnboardingEndpoint('proxmox.example.test', $product->defaultPort(), OnboardingTlsMode::SystemCa, null, null),
            $credentials,
        );
    }

    private function pveEvidence(int $major): OnboardingRemoteEvidence
    {
        $scanPermissions = array_map(
            static fn (array $pair): OnboardingPermission => new OnboardingPermission($pair[0], $pair[1], true, true),
            [['/', 'Sys.Audit'], ['/nodes', 'Sys.Audit'], ['/vms', 'VM.Audit'], ['/pool', 'Pool.Audit'], ['/storage', 'Datastore.Audit']],
        );
        $backupPermissions = [
            new OnboardingPermission('/vms', 'VM.Backup', true, true),
            new OnboardingPermission('/vms', 'Datastore.AllocateSpace', true, true),
            new OnboardingPermission('/storage', 'VM.Backup', true, true),
            new OnboardingPermission('/storage', 'Datastore.AllocateSpace', true, true),
        ];
        return new OnboardingRemoteEvidence(true, [
            new OnboardingIdentityEvidence(OnboardingCredentialKind::Scan, OnboardingProduct::Pve, $major, 4, $major.'.4.1', $scanPermissions),
            new OnboardingIdentityEvidence(OnboardingCredentialKind::Backup, OnboardingProduct::Pve, $major, 4, $major.'.4.1', $backupPermissions),
        ], [
            new OnboardingRoleDefinition('HoddmimirScan', ['Sys.Audit', 'VM.Audit', 'Pool.Audit', 'Datastore.Audit']),
            new OnboardingRoleDefinition('HoddmimirBackup', ['VM.Backup', 'Datastore.AllocateSpace']),
        ]);
    }

    private function pbsEvidence(int $major): OnboardingRemoteEvidence
    {
        return new OnboardingRemoteEvidence(true, [new OnboardingIdentityEvidence(
            OnboardingCredentialKind::Scan,
            OnboardingProduct::Pbs,
            $major,
            4,
            $major.'.4.1',
            [
                new OnboardingPermission('/system/status', 'Sys.Audit', true, true),
                new OnboardingPermission('/system/tasks', 'Sys.Audit', true, true),
                new OnboardingPermission('/datastore', 'Datastore.Audit', true, true),
                new OnboardingPermission('/remote', 'Remote.Audit', true, true),
            ],
        )]);
    }

    private function appendPermission(OnboardingRemoteEvidence $evidence, OnboardingCredentialKind $kind, OnboardingPermission $permission): OnboardingRemoteEvidence
    {
        $identity = $evidence->identity($kind) ?? throw new \LogicException();
        return $this->replaceIdentity($evidence, new OnboardingIdentityEvidence(
            $kind,
            $identity->detectedProduct,
            $identity->major,
            $identity->minor,
            $identity->rawVersion,
            [...$identity->permissions, $permission],
        ));
    }

    private function replacePermission(OnboardingRemoteEvidence $evidence, OnboardingCredentialKind $kind, string $path, string $privilege, ?OnboardingPermission $replacement): OnboardingRemoteEvidence
    {
        $identity = $evidence->identity($kind) ?? throw new \LogicException();
        $permissions = array_values(array_filter(
            $identity->permissions,
            static fn (OnboardingPermission $permission): bool => $permission->path !== $path || $permission->privilege !== $privilege,
        ));
        if (null !== $replacement) {
            $permissions[] = $replacement;
        }
        return $this->replaceIdentity($evidence, new OnboardingIdentityEvidence(
            $kind,
            $identity->detectedProduct,
            $identity->major,
            $identity->minor,
            $identity->rawVersion,
            $permissions,
        ));
    }

    private function replaceIdentity(OnboardingRemoteEvidence $evidence, OnboardingIdentityEvidence $replacement): OnboardingRemoteEvidence
    {
        return new OnboardingRemoteEvidence($evidence->tlsVerified, array_map(
            static fn (OnboardingIdentityEvidence $identity): OnboardingIdentityEvidence => $identity->kind === $replacement->kind ? $replacement : $identity,
            $evidence->identities,
        ), $evidence->roles);
    }
}
